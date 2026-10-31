<?php
// Local preview test helper. Never upload this file.
$config=require $argv[1].'/app/config.php';
if (!str_contains($config['dsn'],'host=127.0.0.1;port=13367;dbname=billing_portal_test')) throw new RuntimeException('Not the local test database');
$db=new PDO($config['dsn'],$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function query(string $sql,array $params=[]):PDOStatement {global $db;$s=$db->prepare($sql);$s->execute($params);return $s;}
function one(string $sql,array $params=[]):array{return query($sql,$params)->fetch()?:[];}
require __DIR__.'/../app/password-reset-lib.php';
$user=one('SELECT * FROM users WHERE email=?',['test@example.com']);
if (!$user) throw new RuntimeException('Preview test user missing');
if (($argv[2]??'')==='restore') {
    query('UPDATE users SET password_hash=? WHERE id=?',[$argv[3],$user['id']]);
    query('DELETE FROM password_resets WHERE user_id=?',[$user['id']]);
    exit;
}
query('DELETE FROM password_resets WHERE user_id=?',[$user['id']]);
request_password_reset($user['email'],function($to,$body)use($user){preg_match('/token=([a-f0-9]{64})/',$body,$m);echo json_encode(['token'=>$m[1],'hash'=>$user['password_hash']]);return true;});
