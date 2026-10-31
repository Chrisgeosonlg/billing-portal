<?php
declare(strict_types=1);

// Assigning quotations and invoices to a staff member by email. Previously used emails are offered as suggestions.
function assignment_ready(): bool {
    static $ready=null;
    if ($ready!==null) return $ready;
    try { query('SELECT assigned_to FROM documents LIMIT 1'); return $ready=true; }
    catch (PDOException $error) { return $ready=false; }
}
// Blank means unassigned.
function assignment_email(string $input): ?string {
    $email=strtolower(trim($input));
    if ($email==='') return null;
    if (strlen($email)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n,]/',$email)) throw new RuntimeException('Enter a valid staff email address, or leave it blank.');
    return $email;
}
// Staff emails used before (most recently used first), then portal users not used yet.
function assignment_suggestions(int $limit=50): array {
    if (!assignment_ready()) return [];
    $used=query('SELECT assigned_to FROM documents WHERE assigned_to IS NOT NULL GROUP BY assigned_to ORDER BY MAX(id) DESC LIMIT '.max(1,$limit))->fetchAll(PDO::FETCH_COLUMN);
    $users=users_ready() ? query("SELECT email FROM users WHERE status='active' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) : [];
    return array_slice(array_values(array_unique([...$used,...$users])),0,max(1,$limit));
}
function assignment_datalist(): string {
    $options=array_map(fn($email)=>'<option value="'.e($email).'">',assignment_suggestions());
    return '<datalist id="staff-emails">'.implode('',$options).'</datalist>';
}
// Emails the staff member the document details with the PDF attached.
function assignment_notify(array $doc, string $staff, string $assignedBy, string $pdf, ?callable $sender=null): bool {
    global $config;
    $from=$config['mail_from']??$config['email']??'';
    if (!filter_var($staff,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$staff) || !filter_var($from,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$from)) throw new RuntimeException('Check the staff email and the mail_from address in config.php.');
    $customer=json_decode($doc['customer_snapshot'],true,512,JSON_THROW_ON_ERROR);
    $company=json_decode($doc['company_snapshot'],true,512,JSON_THROW_ON_ERROR);
    $link=function_exists('report_portal_link') ? report_portal_link() : null;
    $text="Hello,\r\n\r\n".($assignedBy!==''?$assignedBy:'An administrator')." has assigned you the ".kind_name($doc['kind']).' '.number($doc).".\r\n\r\n"
        ."Customer: ".$customer['name']."\r\n"
        .(($customer['phone']??'')!==''?"Phone: ".$customer['phone']."\r\n":'')
        .(($customer['email']??'')!==''?"Email: ".$customer['email']."\r\n":'')
        .(($customer['address']??'')!==''?"Address / service location: ".$customer['address']."\r\n":'')
        ."Date: ".$doc['document_date']."\r\n".($doc['kind']==='invoice'?'Payment due: ':'Valid until: ').$doc['due_date']."\r\n"
        ."Total: ".($company['currency']??'').' '.money((int)$doc['total'])."\r\n\r\n"
        ."The PDF is attached.".($link?"\r\nStaff portal: ".$link.$doc['id']:'')."\r\n\r\n".brand('brand');
    $boundary='asg_'.bin2hex(random_bytes(18));
    $body='--'.$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($text)).'--'.$boundary."\r\nContent-Type: application/pdf; name=\"".number($doc).".pdf\"\r\nContent-Disposition: attachment; filename=\"".number($doc).".pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($pdf)).'--'.$boundary."--\r\n";
    $headers=['From'=>brand('brand').' <'.$from.'>','MIME-Version'=>'1.0','Content-Type'=>'multipart/mixed; boundary="'.$boundary.'"'];
    $subject=brand('short_name').' '.kind_name($doc['kind']).' '.number($doc).' assigned to you - '.preg_replace('/[\x00-\x1F\x7F]+/',' ',(string)$customer['name']);
    return $sender ? (bool)$sender($staff,$subject,$body,$headers) : (function_exists('mail') && @mail($staff,$subject,$body,$headers));
}
// Assigns (or clears) and optionally notifies. Returns the notice to show.
function assignment_apply(array $doc, ?string $staff, bool $notify, ?callable $sender=null): string {
    query('UPDATE documents SET assigned_to=? WHERE id=?',[$staff,$doc['id']]);
    if ($staff===null) return number($doc).' is no longer assigned to anyone.';
    if (!$notify) return number($doc).' assigned to '.$staff.'.';
    $doc['assigned_to']=$staff;
    try {
        require_once __DIR__.'/document-pdf.php';
        $paid=(int)one('SELECT COALESCE(SUM(amount),0) AS paid FROM payments WHERE document_id=?',[$doc['id']])['paid'];
        $assignedBy=(string)(one('SELECT email FROM users WHERE id=?',[$_SESSION['user_id']??0])['email']??'');
        $accepted=assignment_notify($doc,$staff,$assignedBy,document_pdf($doc,$paid),$sender);
    } catch (Throwable $error) { $accepted=false; error_log('Assignment email failed for '.number($doc).': '.$error->getMessage()); }
    return number($doc).' assigned to '.$staff.'. '.($accepted?'The details and PDF were emailed to them.':'The notification email could not be sent; please let them know directly.');
}
