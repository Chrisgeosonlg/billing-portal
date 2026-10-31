<?php
if (!isset($db) || empty($_SESSION['user_id'])) { http_response_code(404); exit; }
$customer=one('SELECT * FROM customers WHERE id=?',[(int)($_GET['id']??0)]);
if (!$customer): ?><section class="card">Customer not found. <a href="index.php?page=customers">Back to all customers</a></section>
<?php else:
$docs=query('SELECT d.*,COALESCE((SELECT SUM(amount) FROM payments WHERE document_id=d.id),0) AS paid FROM documents d WHERE d.customer_id=? ORDER BY d.id DESC',[$customer['id']])->fetchAll();
$invoices=array_filter($docs,fn($d)=>$d['kind']==='invoice');
$invoiced=array_sum(array_map(fn($d)=>(int)$d['total'],$invoices)); $paid=array_sum(array_map(fn($d)=>(int)$d['paid'],$invoices));
$schedules=recurring_ready() ? query("SELECT q.*,d.kind FROM recurring_quotations q JOIN documents d ON d.id=q.template_id WHERE d.customer_id=? AND q.status<>'ended' ORDER BY q.next_date IS NULL,q.next_date",[$customer['id']])->fetchAll() : [];
?>
<div class="toolbar"><a href="index.php?page=customers">← All customers</a><div class="actions"><a class="button quiet" href="index.php?page=customers&edit=<?=$customer['id']?>#customer-form">Edit details</a><a class="button" href="index.php?page=new&customer=<?=$customer['id']?>">Create document</a></div></div>
<div class="split"><section class="card"><h2><?=e($customer['name'])?></h2>
<p class="pre"><?=e($customer['address']?:'No address recorded')?></p>
<p><?=$customer['email']!==''?'<a href="mailto:'.e($customer['email']).'">'.e($customer['email']).'</a>':'<span class="muted">No email</span>'?><br><?=$customer['phone']!==''?e($customer['phone']):'<span class="muted">No phone</span>'?></p>
<p class="muted">Added <?=e(substr((string)$customer['created_at'],0,10))?></p>
<?php if ($schedules): ?><h3>Recurring services</h3><?php foreach ($schedules as $s): ?><p><a href="index.php?page=view&id=<?=$s['template_id']?>#recurring"><?=e(number(['kind'=>$s['kind'],'id'=>$s['template_id']]))?></a> · <?=e(recurring_label($s))?> · next <?=e($s['next_date']??'—')?> <span class="badge"><?=e(recurring_status($s))?></span></p><?php endforeach ?><?php endif ?>
</section>
<section><div class="metrics"><section class="card"><span>Documents</span><strong><?=count($docs)?></strong></section><section class="card"><span>Invoiced · <?=e($config['currency'])?></span><strong><?=money($invoiced)?></strong></section><section class="card"><span>Outstanding · <?=e($config['currency'])?></span><strong><?=money($invoiced-$paid)?></strong></section></div>
<section class="card"><h2>Proformas & Tax Invoices</h2><?php require __DIR__.'/table.php'; ?></section></section></div>
<?php endif ?>
