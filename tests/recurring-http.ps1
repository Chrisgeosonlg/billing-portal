param([string]$Base = 'http://127.0.0.1:18083')
# Local preview test, run after http.ps1. Never upload this file.
$ErrorActionPreference = 'Stop'
$script:session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
function Get-Page([string]$Path = '') { return Invoke-WebRequest -UseBasicParsing -Uri "$Base/index.php$Path" -WebSession $script:session }
function Submit([string]$Path, [hashtable]$Data) {
    $page = Get-Page $Path
    $Data.csrf = [regex]::Match($page.Content, 'name="csrf" value="([^"]+)"').Groups[1].Value
    return Invoke-WebRequest -UseBasicParsing -Uri "$Base/index.php$Path" -WebSession $script:session -Method Post -Body $Data
}
function Assert-Text($Page, [string]$Text) { if (!$Page.Content.Contains($Text)) { $shown = [regex]::Matches($Page.Content, 'role="(?:alert|status)"[^>]*>([^<]*)') | ForEach-Object { $_.Groups[1].Value }; throw "Expected page to contain: $Text. Page message: $shown (line $((Get-PSCallStack)[1].ScriptLineNumber))" } }
$tomorrow = (Get-Date).AddDays(1).ToString('yyyy-MM-dd')
$yesterday = (Get-Date).AddDays(-1).ToString('yyyy-MM-dd')
$page = Submit '' @{action='login';email='test@example.com';password='Test-password-12345'}
$page = Get-Page '?page=view&id=1'
Assert-Text $page 'Start recurring schedule'
$page = Submit '?page=view&id=1' @{action='recurring_save';template_id='1';next_date=$yesterday;interval_unit='month';interval_count='1';end_date='';recipient='customer@example.com'}
Assert-Text $page 'cannot be in the past'
$page = Submit '?page=view&id=1' @{action='recurring_save';template_id='1';next_date=$tomorrow;interval_unit='week';interval_count='0';end_date='';recipient='customer@example.com'}
Assert-Text $page 'Repeat every 1 to 365'
$page = Submit '?page=view&id=1' @{action='recurring_save';template_id='1';next_date=$tomorrow;interval_unit='month';interval_count='1';end_date='';recipient='not-an-email'}
Assert-Text $page 'Enter the email address'
$page = Submit '?page=view&id=1' @{action='recurring_save';template_id='1';next_date=$tomorrow;interval_unit='month';interval_count='1';end_date='';recipient='customer@example.com'}
Assert-Text $page 'Recurring schedule saved'
Assert-Text $page 'Every month'
$page = Get-Page '?page=recurring'
Assert-Text $page 'QUO-000001'
Assert-Text $page 'Active'
$id = [regex]::Match($page.Content, 'name="id" value="(\d+)"><input type="hidden" name="return"').Groups[1].Value
$page = Submit '?page=recurring' @{action='recurring_pause';id=$id;return='recurring'}
Assert-Text $page 'Schedule paused'
Assert-Text $page 'Paused'
$page = Submit '?page=recurring' @{action='recurring_resume';id=$id;return='recurring'}
Assert-Text $page "next copy will be sent on $tomorrow"
# Resuming a stopped schedule keeps its next future date rather than skipping it.
$page = Submit '?page=view&id=1' @{action='recurring_stop';id=$id}
Assert-Text $page 'Schedule stopped'
$page = Submit '?page=view&id=1' @{action='recurring_resume';id=$id}
Assert-Text $page "next copy will be sent on $tomorrow"
$page = Get-Page '?page=dashboard'
Assert-Text $page 'next 14 days'
$page = Submit '?page=view&id=1' @{action='recurring_save';template_id='1';next_date=$tomorrow;interval_unit='';interval_count='1';end_date='';recipient='customer@example.com'}
Assert-Text $page 'One-off'
$page = Submit '?page=view&id=1' @{action='recurring_stop';id=$id}
Assert-Text $page 'Schedule stopped'
Assert-Text $page 'Ended'
$page = Get-Page '?page=view&id=2'
Assert-Text $page 'Make this a recurring service'
# Create document: the recurring tick box saves the document and its schedule together, or neither.
$today = (Get-Date).ToString('yyyy-MM-dd'); $nextMonth = (Get-Date).AddMonths(1).ToString('yyyy-MM-dd')
$page = Get-Page '?page=new'
Assert-Text $page 'This is a recurring service'
$before = [regex]::Matches((Get-Page '?page=documents').Content, 'INV-\d{6}').Count
$doc = @{action='document';kind='invoice';customer_id='1';document_date=$today;due_date=(Get-Date).AddDays(14).ToString('yyyy-MM-dd');'description[]'='Monthly support retainer';'quantity[]'='1';'price[]'='50000';discount='0';tax_rate='0';notes='';recurring='1';next_date=$today;interval_unit='month';interval_count='1';end_date='';recipient=''}
$page = Submit '?page=new' $doc
Assert-Text $page 'must be after the document date'
if ([regex]::Matches((Get-Page '?page=documents').Content, 'INV-\d{6}').Count -ne $before) { throw 'Invoice saved although its schedule was rejected' }
$doc.next_date = $nextMonth
$page = Submit '?page=new' $doc
Assert-Text $page 'Invoice saved as a recurring service'
Assert-Text $page "on $nextMonth"
Assert-Text $page 'Every month'
$doc.Remove('recurring'); $doc.next_date = ''
$page = Submit '?page=new' $doc
Assert-Text $page 'Make this a recurring service'
# Monthly report page.
$page = Get-Page '?page=reports'
Assert-Text $page 'Report emails'
Assert-Text $page 'Pending invoices'
$page = Submit '?page=reports' @{action='report_settings';recipients='boss@example.com, not-an-email';enabled='1'}
Assert-Text $page 'Check the report email address'
$page = Submit '?page=reports' @{action='report_settings';recipients='';enabled='1'}
Assert-Text $page 'Enter at least one email address'
$page = Submit '?page=reports' @{action='report_settings';recipients='Boss@example.com; accounts@example.com';enabled='1'}
Assert-Text $page 'will be emailed to boss@example.com, accounts@example.com'
$page = Submit '?page=reports' @{action='report_send_now'}
if (!$page.Content.Contains('accepted by the mail service') -and !$page.Content.Contains('did not accept the report')) { throw 'Send now gave no result' }
$page = Submit '?page=reports' @{action='report_send_now'}
Assert-Text $page 'wait one minute'
$page = Get-Page '?page=reports&month=2026-09'
Assert-Text $page 'September 2026'
try { Invoke-WebRequest -UseBasicParsing -Uri "$Base/cron.php" | Out-Null; throw 'cron.php ran over HTTP' }
catch { if ($_.Exception.Response.StatusCode.value__ -ne 404) { throw } }
$page = Submit '' @{action='logout'}
$page = Get-Page '?page=recurring'
Assert-Text $page 'Sign in to your workspace'
'PASS: recurring setup validation, save, pause/resume, stop/resume keeps the next date, one-off, stop, register, dashboard, recurring invoices, create-with-schedule, monthly report settings/preview/send, cron blocked over HTTP, private access'
