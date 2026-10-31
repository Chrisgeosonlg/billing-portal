<?php
// Shows the stamp or signature image to signed-in staff only.
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/attachments-lib.php';
require_once __DIR__.'/marks-lib.php';
if (empty($_SESSION['user_id'])) { http_response_code(404); exit; }
$path=mark_path((string)($_GET['type']??''));
if (!$path) { http_response_code(404); exit; }
header('Content-Type: image/png'); header('Content-Length: '.filesize($path)); header('Cache-Control: private, max-age=86400');
readfile($path);
