<#
Uploads the Billing Portal (app folder) to the hosted portal over encrypted FTP (FTPS).

  First time, to save the FTP login:   .\erp-deploy\deploy.ps1 -Setup
  See what would be uploaded:          .\erp-deploy\deploy.ps1 -DryRun
  Upload changed files:                .\erp-deploy\deploy.ps1
  Upload every file again:             .\erp-deploy\deploy.ps1 -All

Never uploads config.php, .sql files or README.md, and never deletes anything on the server.
The login is stored encrypted for this Windows user on this PC only (Windows DPAPI), in
%LOCALAPPDATA%\billing-portal-deploy, outside the project, OneDrive and Git.
#>
param([switch]$Setup, [switch]$DryRun, [switch]$All)
$ErrorActionPreference = 'Stop'
$source = (Resolve-Path (Join-Path $PSScriptRoot '..\erp')).Path
$store = Join-Path $env:LOCALAPPDATA 'billing-portal-deploy'
$settingsPath = Join-Path $store 'settings.json'
$manifestPath = Join-Path $store 'manifest.json'
$excluded = @('config.php', 'README.md', '.gitignore')

function Quote([string]$value) { '"' + ($value -replace '\\', '\\' -replace '"', '\"') + '"' }
function Url([string]$path) {
    $segments = @(($settings.remote + '/' + $path).Split('/') | Where-Object { $_ -ne '' } | ForEach-Object { [uri]::EscapeDataString($_) })
    $url = 'ftp://' + $settings.host + ':' + $settings.port + '/' + ($segments -join '/')
    if ($path -eq '' -and $segments.Count) { $url += '/' }
    $url
}
# Runs curl with the login passed on standard input, so the password never appears in a command line.
function Invoke-Ftp([string[]]$options) {
    $secure = ConvertTo-SecureString $settings.password
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try { $config = 'user = ' + (Quote ($settings.user + ':' + [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr))) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
    $arguments = @('--config', '-', '--ssl-reqd', '--silent', '--show-error', '--connect-timeout', '20', '--max-time', '300')
    if ($settings.caCert) { $arguments += @('--cacert', $settings.caCert) }
    if ($settings.sslNoRevoke) { $arguments += '--ssl-no-revoke' }
    $start = New-Object Diagnostics.ProcessStartInfo 'curl.exe'
    $start.Arguments = (($arguments + $options) | ForEach-Object { '"' + ($_ -replace '"', '\"') + '"' }) -join ' '
    $start.UseShellExecute = $false; $start.CreateNoWindow = $true
    $start.RedirectStandardInput = $true; $start.RedirectStandardOutput = $true; $start.RedirectStandardError = $true
    # .NET Framework writes a byte-order mark into the input stream when the console uses UTF-8, which breaks curl's config parser.
    $consoleEncoding = [Console]::InputEncoding
    try { [Console]::InputEncoding = New-Object Text.UTF8Encoding($false) } catch {}
    try { $process = [Diagnostics.Process]::Start($start) } finally { try { [Console]::InputEncoding = $consoleEncoding } catch {} }
    $stdout = $process.StandardOutput.ReadToEndAsync(); $stderr = $process.StandardError.ReadToEndAsync()
    $bytes = (New-Object Text.UTF8Encoding($false)).GetBytes($config + "`n")
    $process.StandardInput.BaseStream.Write($bytes, 0, $bytes.Length); $process.StandardInput.Close()
    [Array]::Clear($bytes, 0, $bytes.Length); $config = $null
    $process.WaitForExit()
    [pscustomobject]@{ ok = ($process.ExitCode -eq 0); code = $process.ExitCode; output = ($stdout.Result + $stderr.Result).Trim() }
}
function Test-Remote {
    $list = Invoke-Ftp @('--list-only', (Url ''))
    if (!$list.ok) { throw "Could not connect (curl error $($list.code)): $($list.output)`nCheck the server, username, password and folder with .\erp-deploy\deploy.ps1 -Setup." }
    if (-not (($list.output -split "`r?`n") -contains 'index.php')) { Write-Warning "index.php was not found in the remote folder '$($settings.remote)'. Make sure it is the Billing Portal folder for the client's billing subdomain, not their main website." }
    else { Write-Host "Connected. The remote folder contains the ERP (index.php found)." }
}

if ($Setup) {
    New-Item -ItemType Directory -Force $store | Out-Null
    Write-Host 'Enter the FTP account details from DirectAdmin > FTP Management.'
    $ftpHost = (Read-Host 'FTP server, e.g. ftp.example.com').Trim()
    $user = (Read-Host 'FTP username, e.g. billingdeploy@example.com').Trim()
    $password = Read-Host 'FTP password' -AsSecureString
    Write-Host 'ERP folder as seen by this FTP account. Leave blank if the account opens directly in the ERP folder;'
    Write-Host 'for the main DirectAdmin account it is usually domains/example.com/public_html/billing'
    $remote = (Read-Host 'ERP folder').Trim().Trim('/')
    $site = (Read-Host 'Portal address [https://billing.example.com]').Trim().TrimEnd('/')
    if (!$site) { $site = 'https://billing.example.com' }
    $settings = [pscustomobject]@{ host = $ftpHost; port = 21; user = $user; password = (ConvertFrom-SecureString $password); remote = $remote; site = $site; caCert = ''; sslNoRevoke = $false }
    $settings | ConvertTo-Json | Set-Content -Encoding utf8 $settingsPath
    if (Test-Path $manifestPath) { Remove-Item $manifestPath }
    Write-Host "Saved, encrypted for $env:USERNAME on this PC. Testing the connection..."
    Test-Remote
    Write-Host 'Next: run .\erp-deploy\deploy.ps1 -DryRun to see what will be uploaded.'
    exit 0
}

if (!(Test-Path $settingsPath)) { throw 'No FTP login saved yet. Run .\erp-deploy\deploy.ps1 -Setup first.' }
$settings = Get-Content -Raw $settingsPath | ConvertFrom-Json
$previous = @{}
if (Test-Path $manifestPath) { (Get-Content -Raw $manifestPath | ConvertFrom-Json).PSObject.Properties | ForEach-Object { $previous[$_.Name] = $_.Value } }
$firstDeploy = $previous.Count -eq 0

# Database files, stray test scripts and customer attachments (private-files) are never uploaded.
$files = Get-ChildItem $source -Recurse -File -Force | Where-Object { $_.Name -notin $excluded -and $_.Extension -notin @('.sql', '.ps1', '.mjs') } | ForEach-Object {
    [pscustomobject]@{ path = $_.FullName; rel = $_.FullName.Substring($source.Length + 1).Replace('\', '/'); hash = (Get-FileHash $_.FullName -Algorithm SHA256).Hash }
} | Where-Object { $_.rel -notlike 'private-files/*' }
# Upload the entry points last so the portal never runs new pages against old libraries.
$changed = @($files | Where-Object { $All -or $previous[$_.rel] -ne $_.hash } | Sort-Object { $_.rel -in @('index.php', '.htaccess') }, rel)
# Database files are compared only once a complete deploy has recorded them (the sql:baseline marker).
$sqlChanged = @(Get-ChildItem $source -Filter *.sql | Where-Object { $previous['sql:baseline'] -and $previous['sql:' + $_.Name] -ne (Get-FileHash $_.FullName -Algorithm SHA256).Hash })

Write-Host "$($changed.Count) of $($files.Count) files to upload to $($settings.host)/$($settings.remote)"
$changed | ForEach-Object { Write-Host "  $($_.rel)" }
if ($DryRun) { Write-Host 'Dry run: nothing was uploaded.'; exit 0 }
if ($changed.Count -eq 0) { Write-Host 'Nothing changed since the last deploy.'; exit 0 }

Test-Remote
$failed = @()
foreach ($file in $changed) {
    if ($failed -and $file.rel -in @('index.php', '.htaccess')) { $failed += $file.rel; Write-Warning "  held back $($file.rel) until the failed files above are uploaded"; continue }
    $result = Invoke-Ftp @('--ftp-create-dirs', '--upload-file', $file.path, (Url $file.rel))
    if ($result.ok) { $previous[$file.rel] = $file.hash; Write-Host "  uploaded $($file.rel)"; continue }
    # Some hosts' upload scanners refuse to rewrite certain files (e.g. the PDF library). If the server already has identical content, that is fine.
    $check = Join-Path $env:TEMP ('billing-portal-deploy-check-' + [guid]::NewGuid().ToString('N'))
    $download = Invoke-Ftp @('--output', $check, (Url $file.rel))
    $same = $download.ok -and (Test-Path $check) -and (Get-FileHash $check -Algorithm SHA256).Hash -eq $file.hash
    if (Test-Path $check) { Remove-Item $check }
    if ($same) { $previous[$file.rel] = $file.hash; Write-Host "  $($file.rel): the server refused the upload but already has this exact version" }
    else { $failed += $file.rel; Write-Warning "  FAILED $($file.rel): $($result.output)" }
}
if (!$failed) { Get-ChildItem $source -Filter *.sql | ForEach-Object { $previous['sql:' + $_.Name] = (Get-FileHash $_.FullName -Algorithm SHA256).Hash }; $previous['sql:baseline'] = 'set' }
$previous | ConvertTo-Json | Set-Content -Encoding utf8 $manifestPath

if ($failed) { Write-Warning "$($failed.Count) file(s) failed. Fix the problem and run the deploy again; only unfinished files are retried."; exit 1 }
Write-Host "Uploaded $($changed.Count) file(s)."
$status = & curl.exe --silent --output NUL --write-out '%{http_code}' --max-time 30 ($settings.site + '/index.php')
Write-Host "Portal check: $($settings.site) answered HTTP $status$(if ($status -ne '200') { ' - open it in a browser and check it works' })."
if ($firstDeploy) { Write-Host 'Reminder: database changes are not uploaded. Import any new .sql update (e.g. recurring-schema.sql) in phpMyAdmin if you have not already.' }
foreach ($sql in $sqlChanged) { Write-Warning "Database update may be needed: $($sql.Name) changed. Import it in phpMyAdmin (see erp/README.md)." }
