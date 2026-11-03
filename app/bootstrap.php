<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/users-lib.php';
require_once __DIR__ . '/company-lib.php';
date_default_timezone_set('Africa/Dar_es_Salaam');
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('Cache-Control: no-store');
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if (!$https && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403); exit('Please open the portal using HTTPS. Enable an SSL certificate for this subdomain in DirectAdmin.');
}
session_name('billing_portal');
session_set_cookie_params(['httponly' => true, 'secure' => $https, 'samesite' => 'Strict', 'path' => '/']);
if (!session_start()) { http_response_code(503); exit('Session storage is unavailable. Ask the hosting provider to check PHP session permissions.'); }
if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > 1800) $_SESSION = [];
$_SESSION['last_seen'] = time();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
function csrf(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function go(string $path = 'index.php'): never { $_SESSION['csrf'] = bin2hex(random_bytes(32)); header('Location: ' . $path, true, 303); exit; }
if (!is_file(__DIR__ . '/config.php')) { http_response_code(503); exit('Portal configuration is not installed. Follow the installation README.'); }
$config = require __DIR__ . '/config.php';
if (!empty($config['timezone']) && in_array($config['timezone'], timezone_identifiers_list(), true)) date_default_timezone_set($config['timezone']);
try {
    $db = new PDO($config['dsn'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec("SET time_zone = '+00:00'");
} catch (Throwable $error) { error_log('Billing Portal database connection failed.'); http_response_code(503); exit('Database connection unavailable. Check the private configuration and PHP PDO MySQL extension.'); }
function query(string $sql, array $params = []): PDOStatement { global $db; $s = $db->prepare($sql); $s->execute($params); return $s; }
function one(string $sql, array $params = []): array { return query($sql, $params)->fetch() ?: []; }
try { query('SELECT id FROM users LIMIT 1'); }
catch (PDOException $error) { http_response_code(503); exit('Database tables are not installed. Import schema.sql into your ERP database using phpMyAdmin.'); }
numbering_ready();
// PHP drops the whole form when an upload exceeds post_max_size, which would otherwise look like an expired session.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) { http_response_code(413); exit('The files are larger than your hosting allows (' . ini_get('post_max_size') . ' per upload). Go back and choose smaller files.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Your session expired. Reload the page and try again.'); }
// Changing a password, or the administrator pausing or removing the user, ends their signed-in sessions on the next request.
if (!empty($_SESSION['user_id'])) {
    $sessionUser = one('SELECT * FROM users WHERE id=?', [$_SESSION['user_id']]);
    if (!$sessionUser || ($sessionUser['status'] ?? 'active') !== 'active' || !hash_equals(hash('sha256',$sessionUser['password_hash']), (string)($_SESSION['password_digest']??''))) {
        $_SESSION=[]; session_regenerate_id(true); go('index.php');
    }
}
