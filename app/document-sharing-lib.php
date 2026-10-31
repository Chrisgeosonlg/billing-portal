<?php
declare(strict_types=1);

function document_share_url(string $token): string {
    global $config;
    $url=rtrim($config['app_url']??'https://billing.example.com','/');
    $parts=parse_url($url);
    $local=PHP_SAPI==='cli-server' && in_array($parts['host']??'',['127.0.0.1','localhost'],true);
    if (!$parts || empty($parts['host']) || (($parts['scheme']??'')!=='https' && !$local) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || preg_match('/[\r\n]/',$url)) throw new RuntimeException('Set app_url to the HTTPS address of your ERP in config.php.');
    return $url.'/share.php?token='.$token;
}
function shared_document(string $token): array {
    if (!preg_match('/^[a-f0-9]{64}$/D',$token)) return [];
    return one('SELECT d.* FROM document_shares s JOIN documents d ON d.id=s.document_id WHERE s.token_hash=? AND s.revoked_at IS NULL AND s.expires_at>UTC_TIMESTAMP()',[hash('sha256',$token)]);
}
function create_document_share(array $doc, string $recipient='', ?int $createdBy=null): array {
    $token=bin2hex(random_bytes(32)); $url=document_share_url($token);
    query('INSERT INTO document_shares(document_id,token_hash,expires_at,recipient,mail_status,created_by) VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY),?,?,?)',[$doc['id'],hash('sha256',$token),$recipient,$recipient===''?'none':'pending',$createdBy??$_SESSION['user_id']]);
    global $db; return ['id'=>(int)$db->lastInsertId(),'token'=>$token,'url'=>$url];
}
// $attachments: supporting PDFs from attachments_list(), sent after the document itself.
function send_document_email(array $doc, string $recipient, string $url, string $pdf, ?callable $sender=null, array $attachments=[]): bool {
    global $config;
    $from=$config['mail_from']??$config['email']??'';
    if (!filter_var($recipient,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$recipient) || !filter_var($from,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$from)) throw new RuntimeException('Check the recipient and the mail_from address in config.php.');
    $company=json_decode($doc['company_snapshot'],true,512,JSON_THROW_ON_ERROR);
    $boundary='bp_'.bin2hex(random_bytes(18));
    $text="Hello,\r\n\r\nPlease find your ".kind_name($doc['kind']).' '.number($doc)." attached as a PDF.\r\n".($attachments?'Also attached: '.implode(', ',array_column($attachments,'name')).".\r\n":'')."You can also view and download it here:\r\n".$url."\r\n\r\nThe download link expires in 30 days.\r\n\r\nKind regards,\r\n".$company['company']."\r\n".$company['phone'];
    $body='--'.$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($text)).'--'.$boundary."\r\nContent-Type: application/pdf; name=\"".number($doc).".pdf\"\r\nContent-Disposition: attachment; filename=\"".number($doc).".pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($pdf));
    foreach ($attachments as $file) {
        $bytes=file_get_contents($file['path']);
        if ($bytes===false) throw new RuntimeException('An attachment could not be read.');
        $body.='--'.$boundary."\r\nContent-Type: application/pdf; name=\"".$file['name']."\"\r\nContent-Disposition: attachment; filename=\"".$file['name']."\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($bytes));
    }
    $body.='--'.$boundary."--\r\n";
    $headers=['From'=>brand('brand').' <'.$from.'>','MIME-Version'=>'1.0','Content-Type'=>'multipart/mixed; boundary="'.$boundary.'"'];
    $subject=brand('short_name').' '.kind_name($doc['kind']).' '.number($doc);
    return $sender ? (bool)$sender($recipient,$subject,$body,$headers) : (function_exists('mail') && @mail($recipient,$subject,$body,$headers));
}
// Email status per document: the latest successful send, or else the latest attempt.
// [document_id => ['recipient','mail_status','sent_at' (local DateTime),'times' (successful sends)]]. Empty if sharing is not installed.
function last_sent(array $docIds): array {
    $docIds = array_values(array_unique(array_map('intval', $docIds)));
    if (!$docIds) return [];
    try { $rows = query("SELECT document_id,recipient,mail_status,created_at FROM document_shares WHERE recipient<>'' AND document_id IN (".implode(',', array_fill(0, count($docIds), '?')).") ORDER BY id DESC", $docIds)->fetchAll(); }
    catch (PDOException $error) { return []; }
    $zone = new DateTimeZone(date_default_timezone_get()); $sent = [];
    foreach ($rows as $row) {
        $id = (int)$row['document_id']; $ok = $row['mail_status'] === 'accepted';
        $entry = ['recipient' => $row['recipient'], 'mail_status' => $row['mail_status'], 'sent_at' => (new DateTimeImmutable($row['created_at'], new DateTimeZone('UTC')))->setTimezone($zone), 'times' => 0];
        if (!isset($sent[$id]) || ($ok && $sent[$id]['mail_status'] !== 'accepted')) $sent[$id] = ['times' => $sent[$id]['times'] ?? 0] + $entry;
        if ($ok) $sent[$id]['times']++;
    }
    return $sent;
}
