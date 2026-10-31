<?php
declare(strict_types=1);
require_once __DIR__.'/lib.php';

// The portal's own address from app_url. HTTPS is required, except for a local preview on this computer.
function portal_url(): string {
    global $config;
    $url = rtrim($config['app_url'] ?? 'https://billing.example.com', '/');
    $parts = parse_url($url);
    $local = in_array($parts['host'] ?? '', ['127.0.0.1','localhost'], true);
    if (!$parts || !in_array($parts['scheme'] ?? '', $local ? ['https','http'] : ['https'], true) || empty($parts['host']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || preg_match('/[\r\n]/', $url)) throw new RuntimeException('Invalid app_url setting.');
    return $url;
}
// Creates a one-use link to set the password, replacing any earlier link. It stops working once the password changes.
function password_link(array $user, int $minutes): string {
    $token = bin2hex(random_bytes(32));
    query('INSERT INTO password_resets (user_id,token_hash,password_digest,requested_at,expires_at) VALUES (?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE)) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash),password_digest=VALUES(password_digest),requested_at=VALUES(requested_at),expires_at=VALUES(expires_at)', [$user['id'],hash('sha256',$token),hash('sha256',$user['password_hash']),$minutes]);
    return portal_url().'/reset.php?token='.$token;
}
function reset_email(string $to, string $body): bool { return portal_mail($to, 'Reset your '.brand('short_name').' staff portal password', $body); }
function portal_mail(string $to, string $subject, string $body): bool {
    global $config;
    $from = $config['mail_from'] ?? $config['email'] ?? '';
    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $from)) return false;
    return function_exists('mail') && @mail($to, $subject, $body, [
        'From' => brand('short_name').' Staff Portal <'.$from.'>',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'MIME-Version' => '1.0',
    ]);
}

function request_password_reset(string $email, ?callable $sender = null): void {
    global $db, $config;
    $start = microtime(true);
    try {
        $db->beginTransaction();
        $user = one('SELECT * FROM users WHERE email=? FOR UPDATE', [strtolower(trim($email))]);
        // Paused and removed users get no link; the response looks the same as for an unknown address.
        if (!$user || ($user['status'] ?? 'active') !== 'active') { $db->commit(); return; }
        $recent = one('SELECT user_id FROM password_resets WHERE user_id=? AND requested_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)', [$user['id']]);
        if ($recent) { $db->commit(); return; }
        $body = "A password reset was requested for your ".brand('short_name')." staff portal account.\r\n\r\nOpen this link to choose a new password:\r\n".password_link($user, 30)."\r\n\r\nThis link expires in 30 minutes and can be used once.\r\nIf you did not request this, ignore this email. Your password has not changed.\r\n";
        $sent = ($sender ?? 'reset_email')($user['email'], $body);
        if (!$sent) {
            query('UPDATE password_resets SET token_hash=NULL WHERE user_id=?', [$user['id']]);
            error_log('Billing Portal password reset email was not accepted. Check the hosting mail service and mail_from setting.');
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    } finally {
        // Apply the same minimum response delay for unknown and known addresses.
        $remaining = 500000 - (int)((microtime(true)-$start)*1000000);
        if ($remaining > 0) usleep($remaining);
    }
}

function reset_record(string $token): array {
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return [];
    $record = one('SELECT r.*,u.* FROM password_resets r JOIN users u ON u.id=r.user_id WHERE r.token_hash=? AND r.expires_at>UTC_TIMESTAMP()', [hash('sha256',$token)]);
    return $record && ($record['status'] ?? 'active') === 'active' && hash_equals($record['password_digest'], hash('sha256',$record['password_hash'])) ? $record : [];
}

// Returns the user's id.
function complete_password_reset(string $token, string $password, string $confirmation): int {
    global $db;
    if (strlen($password)<PASSWORD_MIN || strlen($password)>72 || str_contains($password,"\0")) throw new RuntimeException('Choose a password of '.PASSWORD_MIN.' to 72 characters.');
    if (!hash_equals($password,$confirmation)) throw new RuntimeException('The passwords do not match.');
    $record = reset_record($token);
    if (!$record) throw new RuntimeException('This reset link is invalid or has expired. Request a new link.');
    $db->beginTransaction();
    try {
        query('SELECT id FROM users WHERE id=? FOR UPDATE', [$record['user_id']]);
        $record = reset_record($token);
        if (!$record) throw new RuntimeException('This reset link is invalid or has expired. Request a new link.');
        query('UPDATE users SET password_hash=?,failed_attempts=0,locked_until=NULL WHERE id=?', [password_hash($password,PASSWORD_DEFAULT),$record['user_id']]);
        query('UPDATE password_resets SET token_hash=NULL WHERE user_id=?', [$record['user_id']]);
        $db->commit();
        return (int)$record['user_id'];
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}
