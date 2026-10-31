<?php
if (!isset($db) || empty($_SESSION['user_id'])) { http_response_code(404); exit; }
?>
<section class="card"><div class="section-head"><h2>Recurring services</h2><a class="button" href="index.php?page=new">Create a recurring document</a></div>
<?php if (!recurring_ready()): ?><p>Import <strong>recurring-schema.sql</strong> into your ERP database to enable recurring services.</p><?php else:
$schedules=query("SELECT q.*,d.kind,d.customer_snapshot,(SELECT MAX(run_date) FROM recurring_runs WHERE schedule_id=q.id) AS last_run,(SELECT mail_status FROM recurring_runs WHERE schedule_id=q.id ORDER BY id DESC LIMIT 1) AS last_mail FROM recurring_quotations q JOIN documents d ON d.id=q.template_id ORDER BY q.status='ended',q.next_date IS NULL,q.next_date,q.id")->fetchAll(); ?>
<p class="muted">Tick <strong>This is a recurring service</strong> when creating a Proforma or Tax Invoice, or open an existing one and choose <strong>Make this a recurring service</strong>. Due documents are created and emailed by the daily scheduled job.</p>
<div class="table-wrap"><table><thead><tr><th>Based on</th><th>Customer</th><th>Repeat</th><th>Next send</th><th>Last sent</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($schedules as $s): $customer=json_decode($s['customer_snapshot'],true); ?>
<tr><td><a href="index.php?page=view&id=<?=$s['template_id']?>#recurring"><?=e(number(['kind'=>$s['kind'],'id'=>$s['template_id']]))?></a></td><td><?=e($customer['name'])?><small><?=e($s['recipient'])?></small></td><td><?=e(recurring_label($s))?><?php if ($s['end_date']): ?><small>Ends <?=e($s['end_date'])?></small><?php endif ?></td><td><?=e($s['next_date']??'—')?></td><td><?=e($s['last_run']??'—')?><?php if ($s['last_mail']==='failed'): ?><small>Email failed</small><?php endif ?></td><td><span class="badge"><?=e(recurring_status($s))?></span></td>
<td><form method="post" class="inline"><?=csrf()?><input type="hidden" name="id" value="<?=$s['id']?>"><input type="hidden" name="return" value="recurring"><?php if ($s['status']==='active' && $s['next_date']!==null): ?><button class="quiet" name="action" value="recurring_pause">Pause</button><?php elseif ($s['status']!=='active'): ?><button class="quiet" name="action" value="recurring_resume">Resume</button><?php else: ?><a href="index.php?page=view&id=<?=$s['template_id']?>#recurring">Set date</a><?php endif ?></form></td></tr>
<?php endforeach ?><?php if (!$schedules): ?><tr><td colspan="7">No recurring services yet.</td></tr><?php endif ?></tbody></table></div>
<?php endif ?></section>
