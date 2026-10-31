<?php
if (!isset($db) || empty($_SESSION['user_id']) || !is_admin()) { http_response_code(404); exit; }
$details=company_details(); $total=(int)one('SELECT COUNT(*) AS n FROM documents')['n'];
$value=fn(string $key)=>($_POST['action']??'')==='company_save' ? (string)($_POST[$key]??'') : $details[$key];
?>
<section class="card"><h2>Company details</h2>
<p class="muted">Printed at the top and bottom of every Proforma and Tax Invoice, on screen and in the PDF. New documents use these details from the moment you save.</p>
<form method="post"><?=csrf()?><input type="hidden" name="action" value="company_save">
<div class="form-grid"><label>Company name<input name="company" maxlength="190" required value="<?=e($value('company'))?>"></label><label>Email<input type="email" name="email" maxlength="190" value="<?=e($value('email'))?>"></label><label>Phone<input name="phone" maxlength="190" value="<?=e($value('phone'))?>"></label></div>
<div class="form-grid"><label>Address<textarea name="address" rows="3" maxlength="1000"><?=e($value('address'))?></textarea></label><label>Bank / payment details<textarea name="payment_details" rows="3" maxlength="1000" placeholder="Bank name, account name and account number"><?=e($value('payment_details'))?></textarea></label></div>
<?php if ($next=doc_no_next()): ?><h3>Document numbers</h3><div class="form-grid"><label>Next Proforma number<span class="input-prefix"><span>PF-</span><input type="number" name="next_proforma" min="1" max="99999999" required value="<?=e($_POST['next_proforma']??$next['quotation'])?>"></span></label><label>Next Tax Invoice number<span class="input-prefix"><span>INV-</span><input type="number" name="next_invoice" min="1" max="99999999" required value="<?=e($_POST['next_invoice']??$next['invoice'])?>"></span></label></div><p class="muted">Each type counts up by one from here. Change these only to continue from another numbering, for example a paper invoice book. Numbers already used cannot be given again.</p><?php endif ?>
<p class="muted">Payment details are shown on Tax Invoices only. The currency (<?=e($details['currency'])?>) stays as set by the installer.</p>
<label class="check"><input type="checkbox" name="apply_existing" value="1"> Also update the <?=$total?> Proforma<?=$total===1?'':'s'?> and Tax Invoice<?=$total===1?'':'s'?> already created</label>
<p class="muted">Leave it unticked to keep earlier documents exactly as issued. PDFs already emailed to customers do not change either way.</p>
<button>Save company details</button></form></section>
