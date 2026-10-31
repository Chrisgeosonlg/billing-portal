<?php
if (!isset($db) || empty($_SESSION['user_id'])) { http_response_code(404); exit; }
if (!report_ready()): ?><section class="card"><h2>Monthly report</h2><p>Import <strong>reports-schema.sql</strong> into your ERP database in phpMyAdmin to enable the monthly email report.</p></section>
<?php else:
$settings=report_settings(); $saved=(bool)one('SELECT id FROM report_settings WHERE id=1');
$recipients=($_POST['action']??'')==='report_settings' ? (string)($_POST['recipients']??'') : implode(', ',$settings['recipients']);
$month=preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',(string)($_GET['month']??'')) ? $_GET['month'] : date('Y-m');
$day=$month===date('Y-m') ? date('Y-m-d') : min(date('Y-m-d'),(new DateTimeImmutable($month.'-01'))->format('Y-m-t'));
$runs=query('SELECT * FROM report_runs ORDER BY report_date DESC LIMIT 12')->fetchAll();
?>
<div class="split"><section class="card"><h2>Report emails</h2>
<p class="muted">The report is emailed automatically on the <strong>25th</strong> (the <strong>23rd</strong> in February) and on the <strong>last day</strong> of every month, by the portal's daily scheduled job. Next: <?=e(implode(', ',report_upcoming(date('Y-m-d'))))?>.</p>
<form method="post"><?=csrf()?><input type="hidden" name="action" value="report_settings">
<label>Send the report to<textarea name="recipients" maxlength="1000" rows="3" placeholder="manager@example.com, accounts@example.com"><?=e($recipients)?></textarea></label>
<p class="muted">Up to 5 addresses, separated by commas.</p>
<label class="check"><input type="checkbox" name="enabled" value="1" <?=(!$saved || $settings['enabled'])?'checked':''?>> Send the monthly report automatically</label>
<button>Save report settings</button></form>
<?php if ($settings['recipients']): ?><form method="post" class="inline"><?=csrf()?><input type="hidden" name="action" value="report_send_now"><button class="quiet">Email this month's report now</button></form><?php endif ?>
<?php if ($runs): ?><h3>Sent automatically</h3><div class="table-wrap"><table><thead><tr><th>Report day</th><th>Email</th></tr></thead><tbody><?php foreach ($runs as $run): ?><tr><td class="nowrap"><?=e($run['report_date'])?><small><?=e(str_replace(',',', ',$run['recipients']))?></small></td><td><?=e(['pending'=>'Sending','accepted'=>'Accepted','failed'=>'Failed, will retry'][$run['mail_status']])?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?>
</section>
<section class="card report-preview"><div class="section-head"><h2>Preview</h2><form method="get" class="inline"><input type="hidden" name="page" value="reports"><label>Month<input type="month" name="month" value="<?=e($month)?>" max="<?=date('Y-m')?>"></label><button class="quiet">Show</button></form></div>
<?=report_html(report_data($day),false)?>
</section></div>
<?php endif ?>
