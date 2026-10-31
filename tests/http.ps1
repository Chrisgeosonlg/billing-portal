param([string]$Base = 'http://127.0.0.1:18083')
$ErrorActionPreference = 'Stop'
$script:session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
function Get-Page([string]$Path = '') { return Invoke-WebRequest -UseBasicParsing -Uri "$Base/index.php$Path" -WebSession $script:session }
function Submit([string]$Path, [hashtable]$Data) {
    $page = Get-Page $Path
    $Data.csrf = [regex]::Match($page.Content, 'name="csrf" value="([^"]+)"').Groups[1].Value
    return Invoke-WebRequest -UseBasicParsing -Uri "$Base/index.php$Path" -WebSession $script:session -Method Post -Body $Data
}
function Assert-Text($Page, [string]$Text) { if (!$Page.Content.Contains($Text)) { throw "Expected page to contain: $Text" } }
$page = Submit '' @{action='setup';setup_key=('test-only-'*5);email='test@example.com';password='Test-password-12345'}
Assert-Text $page 'A clear view of your business.'
$page = Submit '?page=customers' @{action='customer';name='Test Customer';email='customer@example.com';phone='123';address='Test address'}
Assert-Text $page 'Test Customer'
$page = Submit '?page=new' @{action='document';kind='quotation';customer_id='1';document_date='2026-09-15';due_date='2027-12-31';'description[0]'='Professional services';'quantity[0]'='2.5';'price[0]'='1000.10';discount='0';tax_rate='18';notes='Test terms'}
Assert-Text $page 'QUO-000001'
Assert-Text $page '2,950.30'
$page = Submit '?page=customers&edit=1' @{action='customer';id='1';name='Changed Customer';email='customer@example.com';phone='123';address='Changed address'}
$page = Get-Page '?page=view&id=1'
Assert-Text $page 'Test Customer'
if ($page.Content.Contains('Changed Customer')) { throw 'Customer snapshot changed' }
$page = Submit '?page=view&id=1' @{action='convert';id='1';due_date='2027-12-31'}
Assert-Text $page 'INV-000002'
$page = Submit '?page=view&id=1' @{action='convert';id='1';due_date='2027-12-31'}
Assert-Text $page 'INV-000002'
$page = Submit '?page=view&id=2' @{action='payment';id='2';amount='1000';payment_date='2026-09-15';reference='Bank test'}
Assert-Text $page '1,950.30'
$page = Submit '?page=view&id=2' @{action='payment';id='2';amount='9999';payment_date='2026-09-15';reference='Overpayment test'}
Assert-Text $page 'no more than the outstanding balance'
$page = Submit '?page=view&id=2' @{action='payment';id='2';amount='1950.30';payment_date='2026-09-15';reference='Final payment'}
$page = Get-Page '?page=documents'
Assert-Text $page 'Paid'
try {
    Invoke-WebRequest -UseBasicParsing -Uri "$Base/index.php?page=customers" -WebSession $script:session -Method Post -Body @{action='customer';name='Invalid request'} | Out-Null
    throw 'Missing CSRF was accepted'
} catch { if ($_.Exception.Response.StatusCode.value__ -ne 403) { throw } }
$page = Submit '' @{action='logout'}
Assert-Text $page 'Sign in to your workspace'
$page = Get-Page '?page=view&id=2'
Assert-Text $page 'Sign in to your workspace'
$page = Submit '' @{action='login';email='test@example.com';password='Test-password-12345'}
Assert-Text $page 'A clear view of your business.'
Write-Output 'PASS: setup, login, customers, snapshots, quotation totals, conversion idempotency, partial/final payments, overpayment rejection, CSRF and private access'
