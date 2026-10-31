<?php
// Daily hosting cron job: creates and emails recurring quotations and invoices that are due, then the monthly report on its report days. Command line only.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/lib.php';
date_default_timezone_set('Africa/Dar_es_Salaam');
$config = require __DIR__.'/config.php';
if (!empty($config['timezone']) && in_array($config['timezone'], timezone_identifiers_list(), true)) date_default_timezone_set($config['timezone']);
$db = new PDO($config['dsn'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec("SET time_zone = '+00:00'");
function query(string $sql, array $params = []): PDOStatement { global $db; $s = $db->prepare($sql); $s->execute($params); return $s; }
function one(string $sql, array $params = []): array { return query($sql, $params)->fetch() ?: []; }
numbering_ready();
require __DIR__.'/document-sharing-lib.php';
require __DIR__.'/document-pdf.php';
require __DIR__.'/recurring-lib.php';
require __DIR__.'/report-lib.php';
$today = date('Y-m-d'); $status = 0;
if (recurring_ready()) {
    $results = recurring_run_due($today);
    echo $results ? implode(PHP_EOL, $results).PHP_EOL : "No recurring documents due.\n";
} else { fwrite(STDERR, "Import recurring-schema.sql into the ERP database first.\n"); $status = 1; }
echo report_run_due($today).PHP_EOL;
exit($status);
