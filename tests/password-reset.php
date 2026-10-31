<?php
require __DIR__.'/../app/password-reset-lib.php';
$config=['app_url'=>'https://billing.example.com'];
$db=new PDO('mysql:host=127.0.0.1;port=13367;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$database='bp_reset_test_'.bin2hex(random_bytes(5));
$db->exec('CREATE DATABASE '.$database); $db->exec('USE '.$database);
function query(string $sql,array $params=[]): PDOStatement { global $db; $s=$db->prepare($sql); $s->execute($params); return $s; }
function one(string $sql,array $params=[]): array { return query($sql,$params)->fetch()?:[]; }
function check_reset(bool $pass,string $label): void { if(!$pass) throw new Exception($label); }
try {
    $db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
    query('INSERT INTO users(email,password_hash) VALUES (?,?)',['reset@example.com',password_hash('Original-password-123',PASSWORD_DEFAULT)]);
    $messages=[];
    $sender=function($to,$body) use (&$messages):bool { $messages[]=$body; return true; };
    request_password_reset('unknown@example.com',$sender);
    check_reset(count($messages)===0,'Unknown address sent email');
    request_password_reset('RESET@example.com',$sender);
    check_reset(count($messages)===1,'Known address not sent');
    preg_match('/token=([a-f0-9]{64})/',$messages[0],$match); $token=$match[1];
    check_reset(one('SELECT token_hash FROM password_resets')['token_hash']===hash('sha256',$token),'Token must be hashed at rest');
    request_password_reset('reset@example.com',$sender);
    check_reset(count($messages)===1,'Cooldown bypassed');
    check_reset((bool)reset_record($token),'Valid link rejected');
    check_reset(!reset_record(str_repeat('0',64)),'Invalid token accepted');
    foreach ([['short','short'],['Valid-password-123','Different-password']] as [$password,$confirmation]) {
        try { complete_password_reset($token,$password,$confirmation); throw new Exception('Invalid password accepted'); } catch (RuntimeException $expected) {}
    }
    check_reset((bool)reset_record($token),'Validation failure consumed token');
    $oldHash=one('SELECT password_hash FROM users')['password_hash'];
    query('UPDATE users SET failed_attempts=5,locked_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)');
    complete_password_reset($token,'New-password-12345','New-password-12345');
    $user=one('SELECT * FROM users');
    check_reset(password_verify('New-password-12345',$user['password_hash']),'New password rejected');
    check_reset(!password_verify('Original-password-123',$user['password_hash']),'Old password still valid');
    check_reset(hash('sha256',$oldHash)!==hash('sha256',$user['password_hash']),'Session fingerprint unchanged');
    check_reset(!$user['locked_until'] && (int)$user['failed_attempts']===0,'Lockout not reset');
    try { complete_password_reset($token,'Another-password-123','Another-password-123'); throw new Exception('Token reused'); } catch(RuntimeException $expected) {}
    query('UPDATE password_resets SET requested_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 6 MINUTE)');
    request_password_reset('reset@example.com',$sender);
    preg_match('/token=([a-f0-9]{64})/',$messages[1],$match); $token=$match[1];
    query('UPDATE password_resets SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE)');
    check_reset(!reset_record($token),'Expired token accepted');
    query('UPDATE password_resets SET requested_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 6 MINUTE)');
    request_password_reset('reset@example.com',$sender);
    preg_match('/token=([a-f0-9]{64})/',$messages[2],$match); $token=$match[1];
    query('UPDATE users SET password_hash=?',[password_hash('Changed-in-account-123',PASSWORD_DEFAULT)]);
    check_reset(!reset_record($token),'Password change did not invalidate link');
    query('UPDATE password_resets SET requested_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 6 MINUTE)');
    request_password_reset('reset@example.com',fn()=>false);
    check_reset(one('SELECT token_hash FROM password_resets')['token_hash']===null,'Failed send left active token');
    echo "PASS: account lookup, hashed token, cooldown, password validation, expiry, one-use links, password replacement, lockout clearance, session fingerprint, account-change invalidation and mail failure. No email sent.\n";
} finally { $db->exec('DROP DATABASE '.$database); }
