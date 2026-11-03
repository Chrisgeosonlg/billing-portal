<?php
// Serves an uploaded company logo. Logos are public (the sign-in page and customer download links show them).
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/company-lib.php';
$path=company_logo_file((string)($_GET['which']??''));
if (!$path) { http_response_code(404); exit; }
header('Content-Type: '.(str_ends_with($path,'.png')?'image/png':'image/jpeg')); header('Content-Length: '.filesize($path)); header('Cache-Control: public, max-age=31536000, immutable'); header('X-Content-Type-Options: nosniff');
readfile($path);
