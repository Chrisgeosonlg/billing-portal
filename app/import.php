<?php
if (!isset($db) || empty($_SESSION['user_id'])) { http_response_code(404); exit; }
$preview = $_SESSION['customer_import'] ?? null;
?>
<section class="card">
<div class="section-head"><h2>Import customers & contacts</h2><a href="index.php?page=customers">Back to customers</a></div>
<p>Upload an Excel workbook (.xlsx) or CSV. Each row becomes one customer record with its contact details.</p>
<p><a href="customer-import-template.xlsx" download>Download Excel template</a> · <a href="customer-import-template.csv" download>Download CSV template</a></p>
<p class="muted">Columns: Name (required), Email, Phone, Address. Use the first worksheet with headings in row 1. Maximum 1,000 rows and 2 MB. Format phone numbers as Text to keep + signs and leading zeros. Older .xls files must be saved as .xlsx.</p>
<form method="post" enctype="multipart/form-data"><?=csrf()?><input type="hidden" name="action" value="import_preview"><input type="hidden" name="MAX_FILE_SIZE" value="2097152"><label>Customer spreadsheet<input type="file" name="spreadsheet" accept=".xlsx,.csv" required></label><button>Upload & preview</button></form>
</section>
<?php if ($preview): $rows=import_classify($preview['rows'],query('SELECT name,email FROM customers')->fetchAll()); $counts=array_count_values(array_column($rows,'status')); ?>
<section class="card"><h2>Review before importing</h2><p><strong><?=($counts['Ready']??0)?></strong> ready · <strong><?=($counts['Duplicate']??0)?></strong> duplicates · <strong><?=($counts['Invalid']??0)?></strong> invalid</p><p class="muted">Only ready rows will be added. Matching names or email addresses are skipped, including duplicates within this file. Existing customers stay unchanged. Invalid rows are skipped; correct them in your file and upload again.</p>
<div class="table-wrap import-preview"><table><thead><tr><th>Row</th><th>Name</th><th>Email</th><th>Phone</th><th>Address</th><th>Result</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?=$row['row']?></td><?php foreach ($row['values'] as $value): ?><td><?=e($value)?></td><?php endforeach ?><td><?=e($row['status'])?><?php if ($row['errors']): ?><small><?=e(implode('; ',$row['errors']))?></small><?php endif ?></td></tr><?php endforeach ?></tbody></table></div>
<form method="post" class="inline"><?=csrf()?><input type="hidden" name="import_token" value="<?=e($preview['token'])?>"><?php if ($counts['Ready']??0): ?><button name="action" value="import_confirm">Import <?=($counts['Ready']??0)?> customers</button><?php endif ?><button name="action" value="import_cancel" class="quiet">Discard preview</button></form></section>
<?php endif ?>
