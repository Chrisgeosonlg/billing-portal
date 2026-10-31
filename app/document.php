<?php
require __DIR__.'/bootstrap.php';
if (empty($_SESSION['user_id'])) go('index.php');
require __DIR__.'/document-pdf.php';
require_once __DIR__.'/attachments-lib.php';
$doc=one('SELECT * FROM documents WHERE id=?',[(int)($_GET['id']??0)]);
if (!$doc) { http_response_code(404); exit('Document not found.'); }
if (isset($_GET['file'])) {
    $file=attachment_get((int)$doc['id'],(string)$_GET['file']);
    if (!$file) { http_response_code(404); exit('Attachment not found.'); }
    attachment_download($file);
}
try { download_document_pdf($doc); }
catch (Throwable $error) { error_log('Billing Portal: PDF generation failed: '.$error->getMessage()); http_response_code(503); exit('PDF generation is unavailable. Ask the administrator to check the PHP error log.'); }
