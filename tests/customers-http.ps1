param([string]$Base = 'http://127.0.0.1:18083')
# Local preview test, run after http.ps1. Never upload this file. Adds 60 test customers to the local test database.
$ErrorActionPreference = 'Stop'
$script:session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
function Get-Page([string]$Path = '') { return Invoke-WebRequest -UseBasicParsing -Uri "$Base/index.php$Path" -WebSession $script:session }
function Submit([string]$Path, [hashtable]$Data) {
    $page = Get-Page $Path
    $Data.csrf = [regex]::Match($page.Content, 'name="csrf" value="([^"]+)"').Groups[1].Value
    return Invoke-WebRequest -UseBasicParsing -Uri "$Base/index.php$Path" -WebSession $script:session -Method Post -Body $Data
}
function Assert-Text($Page, [string]$Text) { if (!$Page.Content.Contains($Text)) { throw "Expected page to contain: $Text (line $((Get-PSCallStack)[1].ScriptLineNumber))" } }
$csv = Invoke-WebRequest -UseBasicParsing -Uri "$Base/index.php?page=customers&export=csv"
if ($csv.Content -match 'Name,Email') { throw 'CSV downloaded without signing in' }
$page = Submit '' @{action='login';email='test@example.com';password='Test-password-12345'}
$page = Submit '?page=customers' @{action='customer';name='=HYPERLINK("http://x")';email='formula@example.com';phone='+255 700 000 001';address='Formula Street'}
Assert-Text $page 'Customer saved'
$values = (1..60 | ForEach-Object { "('Bulk Customer {0:D2}','bulk{0}@example.com','0700{0:D6}','Bulk address')" -f $_ }) -join ','
& C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 13367 -u bp_test -ptest-only-pass billing_portal_test -e "DELETE d FROM documents d JOIN customers c ON c.id=d.customer_id WHERE c.name LIKE 'Bulk Customer %'; DELETE FROM customers WHERE name LIKE 'Bulk Customer %'; INSERT INTO customers (name,email,phone,address) VALUES $values"
$total = [int](& C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 13367 -u bp_test -ptest-only-pass billing_portal_test -N -e "SELECT COUNT(*) FROM customers")
$page = Get-Page '?page=customers'
Assert-Text $page "You have <strong>$total</strong> customers"
Assert-Text $page "Showing 1$([char]0x2013)50 of $total"
Assert-Text $page 'Page 1 of 2'
$page = Get-Page '?page=customers&p=2'
Assert-Text $page "Showing 51$([char]0x2013)$total of $total"
Assert-Text $page 'Previous'
$page = Get-Page '?page=customers&q=0700000007'
Assert-Text $page 'Bulk Customer 07'
Assert-Text $page "Showing 1$([char]0x2013)1 of 1"
$page = Get-Page '?page=customers&q=100%25'
Assert-Text $page 'No customers match your search'
$page = Get-Page '?page=customers&sort=newest'
if ($page.Content.IndexOf('Bulk Customer 60') -gt $page.Content.IndexOf('Bulk Customer 01') -and $page.Content.Contains('Bulk Customer 01')) { throw 'Newest first not sorted' }
& C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 13367 -u bp_test -ptest-only-pass billing_portal_test -e "INSERT INTO documents (kind,customer_id,customer_snapshot,company_snapshot,document_date,due_date,notes,items_json,subtotal,discount,tax_bps,tax,total,created_by) SELECT 'invoice',id,'{}','{}',CURDATE(),CURDATE(),'','[]',99999900,0,0,0,99999900,1 FROM customers WHERE name='Bulk Customer 30'"
$page = Get-Page '?page=customers&sort=balance'
Assert-Text $page '999,999.00'
if ($page.Content.IndexOf('Bulk Customer 30') -gt $page.Content.IndexOf('Bulk Customer 01')) { throw 'Highest balance not first' }
$csv = Invoke-WebRequest -UseBasicParsing -Uri "$Base/index.php?page=customers&export=csv&q=Bulk" -WebSession $script:session
$text = [Text.Encoding]::UTF8.GetString($csv.RawContentStream.ToArray())
if (!$text.StartsWith([string][char]0xFEFF) -or !$text.Contains('Name,Email,Phone,Address,Added,Quotations,Invoices')) { throw 'CSV header or BOM missing' }
if (([regex]::Matches($text, 'Bulk Customer')).Count -ne 60) { throw 'CSV does not follow the search' }
$csv = Invoke-WebRequest -UseBasicParsing -Uri "$Base/index.php?page=customers&export=csv&q=HYPERLINK" -WebSession $script:session
$text = [Text.Encoding]::UTF8.GetString($csv.RawContentStream.ToArray())
if (!$text.Contains("""'=HYPERLINK") -or !$text.Contains("'+255 700 000 001")) { throw 'Formula cells not neutralised' }
$page = Get-Page '?page=customer&id=1'
Assert-Text $page 'QUO-000001'
Assert-Text $page 'INV-000002'
Assert-Text $page 'Create document'
$page = Get-Page '?page=new&customer=1'
if ($page.Content -notmatch '<option value="1" selected[ >]') { throw 'Customer not preselected' }
$page = Get-Page '?page=customer&id=999999'
Assert-Text $page 'Customer not found'
$page = Get-Page '?page=customers&edit=1'
Assert-Text $page 'Edit customer'
$page = Submit '' @{action='logout'}
$page = Get-Page '?page=customer&id=1'
Assert-Text $page 'Sign in to your workspace'
'PASS: customer count, paging, search (phone, wildcard-safe), sorting, balances, CSV download with search and formula protection, customer page, preselected document customer, private access'
