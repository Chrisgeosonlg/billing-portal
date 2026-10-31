<?php
declare(strict_types=1);

// Staff users (added by the administrator, who set their own password from an emailed link) and the activity history.
function users_ready(): bool {
    static $ready=null;
    if ($ready!==null) return $ready;
    try { query('SELECT role,status FROM users LIMIT 1'); query('SELECT id FROM activity_log LIMIT 1'); return $ready=true; }
    catch (PDOException $error) { return $ready=false; }
}
// Staff accounts allowed besides the administrator. Set 'max_users' in config.php to change it.
function users_max(): int { global $config; return max(1,(int)($config['max_users']??3)); }
function current_user(): array {
    static $user=null;
    if ($user===null) $user=one('SELECT * FROM users WHERE id=?',[(int)($_SESSION['user_id']??0)]);
    return $user;
}
// Before users-schema.sql is imported, the single original account is the administrator.
function is_admin(): bool { $u=current_user(); return $u && ($u['role']??((int)$u['id']===1?'admin':'staff'))==='admin'; }
function user_label(array $user): string { return trim((string)($user['name']??''))!=='' ? $user['name'] : (string)$user['email']; }
// id => display name for every account, including removed ones, so old records keep their author.
function user_names(): array {
    static $names=null;
    if ($names===null) { $names=[]; foreach (query('SELECT * FROM users')->fetchAll() as $u) $names[(int)$u['id']]=user_label($u); }
    return $names;
}
function user_name(?int $id): string { return $id===null ? 'Automatic' : (user_names()[$id] ?? 'Unknown user'); }
function user_state(array $user): array {
    if ($user['status']==='removed') return ['Removed',''];
    if ($user['status']==='paused') return ['Paused','bad'];
    return $user['password_hash']==='' ? ['Invitation sent','warn'] : ['Active','ok'];
}
function staff_count(): int { return (int)one("SELECT COUNT(*) AS n FROM users WHERE role='staff' AND status<>'removed'")['n']; }

// Records who did what. Never blocks the action itself; rolled back with it when the action fails.
function activity(string $summary, ?int $documentId=null, ?int $customerId=null, ?int $userId=null): void {
    if (!users_ready()) return;
    try { query('INSERT INTO activity_log (user_id,summary,document_id,customer_id) VALUES (?,?,?,?)',[$userId ?? (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null),mb_substr($summary,0,500),$documentId,$customerId]); }
    catch (PDOException $error) { error_log('Billing Portal activity log failed.'); }
}
function activity_list(?int $userId, ?int $documentId, int $limit, int $offset=0): array {
    if (!users_ready()) return [];
    $where=[]; $params=[];
    if ($userId!==null) { $where[]='user_id=?'; $params[]=$userId; }
    if ($documentId!==null) { $where[]='document_id=?'; $params[]=$documentId; }
    return query('SELECT * FROM activity_log'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY id DESC LIMIT '.max(1,$limit).' OFFSET '.max(0,$offset),$params)->fetchAll();
}
function local_time(?string $utc, string $format='j M Y, H:i'): string {
    if (!$utc) return '';
    return (new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format($format);
}

// Adds a staff user (or brings back a removed one) and emails the link to create a password.
function user_add(string $name, string $email, ?callable $sender=null): array {
    global $db;
    $name=trim(preg_replace('/[\x00-\x1F\x7F]+/',' ',$name)); $email=strtolower(trim($email));
    if ($name==='' || mb_strlen($name)>120) throw new RuntimeException('Enter the user\'s name (up to 120 characters).');
    if (strlen($email)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n,]/',$email)) throw new RuntimeException('Enter a valid email address.');
    $db->beginTransaction();
    try {
        // Lock the administrator row so two additions cannot both pass the limit.
        query('SELECT id FROM users WHERE id=1 FOR UPDATE');
        $existing=one('SELECT * FROM users WHERE email=? FOR UPDATE',[$email]);
        if ($existing && $existing['status']!=='removed') throw new RuntimeException($email.' already has an account.');
        if (staff_count()>=users_max()) throw new RuntimeException('You already have '.users_max().' users, the maximum. Remove a user before adding another.');
        if ($existing) {
            query("UPDATE users SET name=?,password_hash='',status='active',failed_attempts=0,locked_until=NULL WHERE id=?",[$name,$existing['id']]);
            $id=(int)$existing['id'];
        } else {
            query("INSERT INTO users (email,name,password_hash,role,status) VALUES (?,?,'','staff','active')",[$email,$name]);
            $id=(int)$db->lastInsertId();
        }
        $user=one('SELECT * FROM users WHERE id=?',[$id]);
        $result=user_invite($user,$sender);
        activity('Added user '.$name.' ('.$email.')'.($result['sent']?' and emailed the invitation':' – invitation email failed'));
        $db->commit();
        return $result+['user'=>$user];
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}
// Emails a fresh 3-day link to create a password. Returns the link so it can be passed on by hand if email fails.
function user_invite(array $user, ?callable $sender=null): array {
    require_once __DIR__.'/password-reset-lib.php';
    $link=password_link($user,72*60);
    $admin=user_label(current_user() ?: ['email'=>brand('short_name')]);
    $body="Hello ".$user['name'].",\r\n\r\n".$admin." has added you to the ".brand('brand')." staff portal.\r\n\r\nOpen this link to create your password:\r\n".$link."\r\n\r\nThe link expires in 3 days and can be used once. Then sign in at ".portal_url()." with this email address (".$user['email'].").\r\nIf you forget your password later, use \"Forgot password?\" on the sign-in page.\r\n";
    $sent=($sender ?? 'portal_mail')($user['email'],'You have been added to the '.brand('short_name').' staff portal',$body);
    return ['sent'=>(bool)$sent,'link'=>$link];
}
function user_change(int $id, string $action): array {
    $user=one("SELECT * FROM users WHERE id=? AND role='staff' AND status<>'removed'",[$id]);
    if (!$user) throw new RuntimeException('User not found.');
    $label=user_label($user);
    if ($action==='user_pause') {
        query("UPDATE users SET status='paused' WHERE id=?",[$id]);
        query('DELETE FROM password_resets WHERE user_id=?',[$id]);
        activity('Paused user '.$label);
        return [$label.' is paused and has been signed out. Their records stay in place.'];
    }
    if ($action==='user_resume') {
        query("UPDATE users SET status='active',failed_attempts=0,locked_until=NULL WHERE id=?",[$id]);
        activity('Resumed user '.$label);
        return [$label.' can sign in again.'];
    }
    if ($action==='user_remove') {
        // The account row stays so documents and history still show who did the work.
        query("UPDATE users SET status='removed',password_hash='' WHERE id=?",[$id]);
        query('DELETE FROM password_resets WHERE user_id=?',[$id]);
        activity('Removed user '.$label.' ('.$user['email'].')');
        return [$label.' has been removed and can no longer sign in. Their past work still shows their name.'];
    }
    if ($action==='user_resend') {
        if ($user['password_hash']!=='') throw new RuntimeException($label.' has already created a password. They can use "Forgot password?" if needed.');
        if ($user['status']!=='active') throw new RuntimeException('Resume '.$label.' before sending a new invitation.');
        $result=user_invite($user);
        activity('Resent the invitation to '.$label.($result['sent']?'':' – email failed'));
        return [$result['sent'] ? 'A new invitation was emailed to '.$user['email'].'. Earlier links no longer work.' : 'The invitation email could not be sent.', $result['sent'] ? null : $result['link']];
    }
    throw new RuntimeException('Unknown action.');
}
