<?php
if (!isset($doc,$db) || empty($_SESSION['user_id'])) { http_response_code(404); exit; }
$sharingReady=true;
try { $history=query('SELECT recipient,mail_status,created_at,expires_at,revoked_at FROM document_shares WHERE document_id=? ORDER BY id DESC LIMIT 10',[$doc['id']])->fetchAll(); }
catch (PDOException $error) { $sharingReady=false; $history=[]; }
$attachments=attachments_list((int)$doc['id']);
$latest=$_SESSION['share_link']??null;
if ($latest && ((int)$latest['document_id']!==(int)$doc['id'] || !shared_document($latest['token']))) $latest=null;
$sentInfo??=null;
[$sendStatus,$sendTone]=!$sentInfo ? ['Not emailed yet',''] : ($sentInfo['mail_status']==='accepted' ? ['✓ Sent '.$sentInfo['sent_at']->format('j M'),'ok'] : ['Email failed','bad']);
panel_start('send','Send to customer',$sendStatus,$sendTone,true); ?>
<?php if (!$sharingReady): ?><p>Import <strong>document-sharing-schema.sql</strong> into your ERP database to enable email and customer links.</p><p><a class="button" href="document.php?id=<?=$doc['id']?>">Download PDF</a></p><?php else: ?>
<form method="post" class="email-form"><?=csrf()?><input type="hidden" name="action" value="email_document"><input type="hidden" name="id" value="<?=$doc['id']?>">
<label>Email to<input type="email" name="recipient" value="<?=e($c['email'])?>" maxlength="190" required></label>
<?php if ($attachments): ?><fieldset class="attach-choice"><legend>Also attach</legend><?php foreach ($attachments as $file): ?><label class="check"><input type="checkbox" name="attach[]" value="<?=$file['id']?>" checked> <?=e($file['name'])?></label><?php endforeach ?></fieldset><?php endif ?>
<button class="wide">Email PDF & link</button></form>
<div class="button-row"><a class="button quiet" href="document.php?id=<?=$doc['id']?>">Download PDF</a><form method="post"><?=csrf()?><input type="hidden" name="id" value="<?=$doc['id']?>"><button class="quiet" name="action" value="share_document">Create customer link</button></form></div>
<?php if ($latest): ?><label>Customer download link<input id="customer-link" readonly value="<?=e($latest['url'])?>"></label><div class="button-row"><button type="button" id="copy-customer-link" class="quiet">Copy link</button><a class="button" target="_blank" rel="noopener noreferrer" href="https://wa.me/?text=<?=rawurlencode('Hello, here is your '.kind_name($doc['kind']).' '.number($doc).' from '.brand('brand').'. Download the PDF: '.$latest['url'])?>">Share on WhatsApp</a></div><p id="copy-link-status" role="status"></p><?php endif ?>
<p class="muted">The email includes the PDF and a download link valid for 30 days. Anyone with a link can download this document without signing in.</p>
<details class="sub"><summary>Link settings and activity<?=$history?' ('.count($history).')':''?></summary>
<p class="muted">Revoking stops all links for this document from working. It cannot remove PDFs already downloaded or emailed. Creating another link keeps earlier links active.</p>
<form method="post"><?=csrf()?><input type="hidden" name="id" value="<?=$doc['id']?>"><button class="quiet" name="action" value="revoke_document_links">Revoke all links</button></form>
<?php if ($history): ?><ul class="activity"><?php foreach($history as $event): ?><li><strong><?=e($event['recipient']?:'Link only')?></strong><span><?=e((new DateTimeImmutable($event['created_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('j M Y, H:i'))?> · <?=e(['none'=>'Link created','pending'=>'Email unconfirmed','accepted'=>'Email sent','failed'=>'Email failed'][$event['mail_status']])?> · <?=e($event['revoked_at']?'Link revoked':($event['expires_at']<gmdate('Y-m-d H:i:s')?'Link expired':'Link active'))?></span></li><?php endforeach ?></ul><?php endif ?>
</details>
<?php endif ?>
<?php panel_end();
