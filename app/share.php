<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/document-sharing-lib.php';
require_once __DIR__.'/attachments-lib.php';
header('Referrer-Policy: no-referrer'); header('X-Robots-Tag: noindex, nofollow, noarchive');
$token=(string)($_GET['token']??'');
try { $doc=shared_document($token); }
catch (PDOException $error) { $doc=[]; }
if (!$doc) { http_response_code(404); exit('This document link is invalid, expired or has been revoked. Please ask '.brand('brand').' for a new link.'); }
if (isset($_GET['file'])) {
    $file=attachment_get((int)$doc['id'],(string)$_GET['file']);
    if (!$file) { http_response_code(404); exit('This attachment is no longer available. Please contact '.brand('brand').'.'); }
    attachment_download($file);
}
if (isset($_GET['download'])) {
    require __DIR__.'/document-pdf.php';
    try { download_document_pdf($doc); }
    catch (Throwable $error) { error_log('Billing Portal: shared PDF generation failed: '.$error->getMessage()); http_response_code(503); exit('PDF download is temporarily unavailable. Please contact '.brand('brand').'.'); }
}
$company=json_decode($doc['company_snapshot'],true); $attachments=attachments_list((int)$doc['id']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Document from <?=e(brand('brand'))?></title><link rel="stylesheet" href="style.css?v=28"><link rel="icon" href="<?=company_logo_url('icon')?>" type="image/png"></head><body class="customer-share"><main><section class="card auth"><img class="document-logo" src="<?=company_logo_url('document')?>" alt="<?=e(brand('brand'))?>"><p class="eyebrow"><?=e($company['company'])?></p><h1>Your <?=e(kind_name($doc['kind']))?></h1><p><?=e(number($doc))?> · <?=e($doc['document_date'])?></p><p>Download your document as a PDF to view, save or print it.</p><a class="button" href="share.php?token=<?=e($token)?>&amp;download=1">Download PDF</a><?php if ($attachments): ?><h2>Supporting documents</h2><ul class="attachment-list"><?php foreach ($attachments as $file): ?><li><a href="share.php?token=<?=e($token)?>&amp;file=<?=$file['id']?>"><?=e($file['name'])?></a> <span class="muted"><?=attachment_size($file['size'])?></span></li><?php endforeach ?></ul><?php endif ?><p class="muted">For questions, contact <?=e($company['email'])?> or <?=e($company['phone'])?>.</p></section></main></body></html>
