<?php
if (!isset($doc,$db) || empty($_SESSION['user_id'])) { http_response_code(404); exit; }
$recurringReady=recurring_ready();
$origin=$recurringReady ? one('SELECT q.template_id FROM recurring_runs r JOIN recurring_quotations q ON q.id=r.schedule_id WHERE r.document_id=?',[$doc['id']]) : [];
$kindWord=kind_name($doc['kind']);
$s=$recurringReady && !$origin ? one('SELECT * FROM recurring_quotations WHERE template_id=?',[$doc['id']]) : [];
$form=($_POST['action']??'')==='recurring_save' ? $_POST : [];
[$recurStatus,$recurTone]=!$recurringReady ? ['Not installed',''] : ($origin ? ['Automatic copy',''] : (!$s ? ['Off',''] : ($s['status']==='active' && $s['next_date']!==null ? [recurring_label($s).' · next '.(new DateTimeImmutable($s['next_date']))->format('j M'),'ok'] : [recurring_status($s),'warn'])));
panel_start('recurring','Recurring service',$recurStatus,$recurTone,false,(bool)$form);
?>
<?php if (!$recurringReady): ?><p>Import <strong>recurring-schema.sql</strong> into your ERP database to enable recurring services.</p>
<?php elseif ($origin): ?><p>This <?=e($kindWord)?> was created and emailed automatically by a recurring schedule. <a href="index.php?page=view&id=<?=$origin['template_id']?>#recurring">View the schedule</a></p>
<?php else:
$value=fn(string $key,$fallback)=>(string)($form[$key]??$s[$key]??$fallback);
$runs=$s ? query('SELECT r.*,d.kind FROM recurring_runs r JOIN documents d ON d.id=r.document_id WHERE r.schedule_id=? ORDER BY r.id DESC LIMIT 10',[$s['id']])->fetchAll() : [];
?>
<?php if ($s): ?>
<p><span class="badge"><?=e(recurring_status($s))?></span> <?=e(recurring_label($s))?> · Next send: <strong><?=e($s['next_date']??'—')?></strong><?=$s['end_date']?' · Ends '.e($s['end_date']):''?> · To <?=e($s['recipient'])?></p>
<form method="post" class="inline"><?=csrf()?><input type="hidden" name="id" value="<?=$s['id']?>"><?php if ($s['status']==='active' && $s['next_date']!==null): ?><button class="quiet" name="action" value="recurring_pause">Pause</button><?php elseif ($s['status']!=='active'): ?><button class="quiet" name="action" value="recurring_resume">Resume</button><?php endif ?><?php if ($s['status']!=='ended'): ?><button class="quiet" name="action" value="recurring_stop">Stop schedule</button><?php endif ?></form>
<?php else: ?>
<p class="muted">Is this <?=e($kindWord)?> for a service you provide regularly? The portal can create a new <?=e($kindWord)?> with these services, prices, discount, tax and notes on each send date, <?=$doc['kind']==='invoice'?'due':'valid'?> <?=recurring_valid_days($doc)?> days later, and email the PDF and a 30-day download link.</p>
<?php endif ?>
<details class="recurring-opt"<?=$form?' open':''?>><summary class="<?=$s?'quiet button':'button'?>"><?=$s?'Change schedule':'Make this a recurring service'?></summary>
<form method="post"><?=csrf()?><input type="hidden" name="action" value="recurring_save"><input type="hidden" name="template_id" value="<?=$doc['id']?>">
<?php recurring_fields($value,$c['email']!==''?'Leave blank to use '.$c['email']:'Customer email'); ?>
<button><?=$s?'Save & activate schedule':'Start recurring schedule'?></button></form></details>
<?php if ($runs): ?><h3>Sent by this schedule</h3><div class="table-wrap"><table><thead><tr><th>Scheduled for</th><th>Document</th><th>Email</th></tr></thead><tbody><?php foreach ($runs as $run): ?><tr><td><?=e($run['run_date'])?></td><td><a href="index.php?page=view&id=<?=$run['document_id']?>"><?=e(number(['kind'=>$run['kind'],'id'=>$run['document_id']]))?></a></td><td><?=e(['pending'=>'Submission unconfirmed','accepted'=>'Accepted by mail service','failed'=>'Failed. Send it manually from the document'][$run['mail_status']])?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?>
<?php endif; panel_end();
