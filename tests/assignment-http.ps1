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
function Assert-Text($Page, [string]$Text) { if (!$Page.Content.Contains($Text)) { $shown = [regex]::Matches($Page.Content, 'role="(?:alert|status)"[^>]*>([^<]*)') | ForEach-Object { $_.Groups[1].Value }; throw "Expected page to contain: $Text. Message: $shown (line $((Get-PSCallStack)[1].ScriptLineNumber))" } }
$today = (Get-Date).ToString('yyyy-MM-dd'); $due = (Get-Date).AddDays(14).ToString('yyyy-MM-dd')
$page = Submit '' @{action='login';email='test@example.com';password='Test-password-12345'}
$page = Get-Page '?page=new'
Assert-Text $page 'Assign to staff'
Assert-Text $page 'list="staff-emails"'
$doc = @{action='document';kind='quotation';customer_id='1';document_date=$today;due_date=$due;'description[]'='Consultation';'quantity[]'='1';'price[]'='120000';discount='0';tax_rate='0';notes='';assigned_to='not-an-email'}
$page = Submit '?page=new' $doc
Assert-Text $page 'valid staff email'
$doc.assigned_to = 'Juma.Staff@Example.com'; $doc.notify_staff = '1'
$page = Submit '?page=new' $doc
Assert-Text $page 'assigned to juma.staff@example.com'
# No mail server on this PC: the notification is reported as not sent, but the assignment is kept.
if (!$page.Content.Contains('emailed to them') -and !$page.Content.Contains('could not be sent')) { throw 'No notification result shown' }
Assert-Text $page 'Assigned to <strong>juma.staff@example.com</strong>'
$quote = [regex]::Match($page.Content, 'name="id" value="(\d+)"><label>Staff email').Groups[1].Value
# The email is remembered and offered next time.
$page = Get-Page '?page=new'
Assert-Text $page '<option value="juma.staff@example.com">'
$doc.Remove('notify_staff'); $doc.assigned_to = 'amina@example.com'
$page = Submit '?page=new' $doc
Assert-Text $page 'assigned to amina@example.com.'
$page = Get-Page '?page=new'
if ($page.Content.IndexOf('amina@example.com') -gt $page.Content.IndexOf('juma.staff@example.com')) { throw 'Most recent email not first' }
# Change, then convert: the invoice keeps the assignment.
$page = Submit "?page=view&id=$quote" @{action='assign_document';id=$quote;assigned_to='amina@example.com'}
Assert-Text $page 'assigned to amina@example.com.'
$page = Submit "?page=view&id=$quote" @{action='convert';id=$quote;due_date=$due}
Assert-Text $page 'Assigned to <strong>amina@example.com</strong>'
$page = Get-Page '?page=documents&staff=amina%40example.com'
Assert-Text $page 'Staff: amina@example.com'
if ($page.Content.Contains('Staff: juma.staff@example.com')) { throw 'Staff filter shows other staff' }
$page = Get-Page '?page=documents&staff=none'
if ($page.Content.Contains('Staff: ')) { throw 'Not-assigned filter shows assigned documents' }
# An unknown staff value shows everyone rather than an error.
$page = Get-Page '?page=documents&staff=unknown%40example.com'
Assert-Text $page 'Staff: amina@example.com'
Assert-Text $page '<option value="" >Everyone</option>'.Replace(' >','>')
# Clearing removes the assignment.
$page = Submit "?page=view&id=$quote" @{action='assign_document';id=$quote;assigned_to=''}
Assert-Text $page 'no longer assigned'
Assert-Text $page 'Not assigned to anyone yet'
$page = Submit '' @{action='logout'}
'PASS: assign on create, validation, notification result, remembered suggestions (most recent first), reassign, clear, quotation-to-invoice carry-over, staff filter'
