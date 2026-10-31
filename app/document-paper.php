<?php
// The document as the customer sees it: same layout as the PDF. Needs $doc, $c, $company, $paid, $balance.
if (!isset($doc,$c,$company) || empty($_SESSION['user_id'])) { http_response_code(404); exit; }
$invoice=$doc['kind']==='invoice'; $currency=$company['currency'];
$qty=fn(int $v)=>rtrim(rtrim(money($v),'0'),'.');
?>
<article class="card document paper">
<header class="paper-head">
<div class="paper-brand"><img class="document-logo" src="images/logo.png" alt="<?=e(brand('brand'))?>"><div class="paper-company"><strong class="paper-name"><?=e($company['company'])?></strong><p class="pre"><?=e($company['address'])?></p><p><?=e(trim($company['email'].' · '.$company['phone'],' ·'))?></p></div></div>
<div class="paper-title"><?php if ($stamp=payment_stamp($doc,(int)$paid)): ?><span class="status-tag status-<?=$stamp[1]?>"><?=e($stamp[0])?></span><?php endif ?><h2><?=e(strtoupper(kind_name($doc['kind'])))?></h2><p class="paper-number">No. <?=e(number($doc))?></p><?php if ($sentInfo=last_sent([$doc['id']])[(int)$doc['id']]??null): ?><p class="sent-mark no-print <?=$sentInfo['mail_status']==='accepted'?'sent-ok':'sent-failed'?>"><?php if ($sentInfo['mail_status']==='accepted'): ?>✓ Sent to <?=e($sentInfo['recipient'])?><small><?=e($sentInfo['sent_at']->format('j M Y, H:i'))?><?=$sentInfo['times']>1?' · sent '.$sentInfo['times'].' times':''?></small><?php else: ?><?=$sentInfo['mail_status']==='failed'?'Not sent: the email to '.e($sentInfo['recipient']).' failed':'Email to '.e($sentInfo['recipient']).' not confirmed'?><small><?=e($sentInfo['sent_at']->format('j M Y, H:i'))?></small><?php endif ?></p><?php endif ?></div>
</header>
<div class="paper-info">
<div class="paper-client"><span class="paper-label"><?=$invoice?'Billed to':'Prepared for'?></span><h3><?=e($c['name'])?></h3><?php if ($c['address']!==''): ?><p class="pre"><?=e($c['address'])?></p><?php endif ?><p><?=e(trim($c['email'].' · '.$c['phone'],' ·'))?></p></div>
<dl class="paper-meta"><dt><?=$invoice?'Invoice date':'Proforma date'?></dt><dd><?=e($doc['document_date'])?></dd><dt><?=$invoice?'Due date':'Valid until'?></dt><dd><?=e($doc['due_date'])?></dd><dt><?=$invoice?'Amount due':'Total'?></dt><dd class="paper-due"><?=e($currency)?> <?=money($invoice?max(0,$balance):(int)$doc['total'])?></dd></dl>
</div>
<div class="table-wrap"><table class="paper-items"><thead><tr><th>#</th><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead><tbody><?php foreach (json_decode($doc['items_json'],true) as $i=>$item): ?><tr><td class="paper-index"><?=$i+1?></td><td><?=e($item['description'])?></td><td class="num"><?=$qty((int)$item['quantity'])?></td><td class="num"><?=money((int)$item['price'])?></td><td class="num"><strong><?=money((int)$item['amount'])?></strong></td></tr><?php endforeach ?></tbody></table></div>
<div class="paper-summary">
<div class="paper-pay"><?php if ($invoice && $company['payment_details']!==''): ?><span class="paper-label">Payment details</span><p class="pre"><?=e($company['payment_details'])?></p><?php endif ?></div>
<div class="paper-totals"><dl><dt>Subtotal</dt><dd><?=money((int)$doc['subtotal'])?></dd><?php if ((int)$doc['discount']>0): ?><dt>Discount</dt><dd>- <?=money((int)$doc['discount'])?></dd><?php endif ?><?php if ((int)$doc['tax_bps']>0): ?><dt>Tax (<?=$qty((int)$doc['tax_bps'])?>%)</dt><dd><?=money((int)$doc['tax'])?></dd><?php endif ?><?php if ($invoice): ?><dt class="strong">Total</dt><dd class="strong"><?=money((int)$doc['total'])?></dd><dt>Paid</dt><dd><?=money((int)$paid)?></dd><?php endif ?></dl>
<div class="paper-grand"><span><?=$invoice?'Balance due':'Total'?> · <?=e($currency)?></span><strong><?=money($invoice?$balance:(int)$doc['total'])?></strong></div></div>
</div>
<?php if ($doc['notes']!==''): ?><div class="paper-notes"><span class="paper-label">Notes &amp; terms</span><p class="pre"><?=e($doc['notes'])?></p></div><?php endif ?>
<?php if ($docMarks=doc_marks((int)$doc['id'])): $person=signatory(); ?><div class="doc-marks"><?php if (in_array('signature',$docMarks,true)): ?><div class="doc-signature"><img src="mark.php?type=signature&amp;v=<?=filemtime(mark_path('signature'))?>" alt="Signature"><strong><?=e($person['name']?:'Authorised signature')?></strong><?php if ($person['name'] || $person['title']): ?><span><?=e($person['title']?:'Authorised signature')?></span><?php endif ?></div><?php endif ?><?php if (in_array('stamp',$docMarks,true)): ?><img class="doc-stamp" src="mark.php?type=stamp&amp;v=<?=filemtime(mark_path('stamp'))?>" alt="Company stamp"><?php endif ?></div><?php endif ?>
<footer class="paper-foot"><strong>Thank you for your business.</strong><span><?=e(implode(' · ',array_filter([$company['company'],$company['email'],$company['phone']])))?></span></footer>
</article>
