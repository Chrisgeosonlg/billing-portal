<?php
declare(strict_types=1);

// Monthly business report, emailed on the 25th (23rd in February) and on the last day of every month.
const REPORT_STYLES = [
    'wrap'=>'font-family:Arial,Helvetica,sans-serif;color:#18344b;font-size:14px;line-height:1.5;max-width:780px',
    'h1'=>'font-size:22px;margin:0 0 4px',
    'h2'=>'font-size:17px;margin:28px 0 6px',
    'muted'=>'color:#6c7c89;font-size:12px;margin:4px 0 10px',
    'callout'=>'background:#fff4e0;border-left:4px solid #e69500;border-radius:4px;padding:12px 14px;margin:18px 0',
    'good'=>'background:#e6f4ea;border-left:4px solid #2e8b57;border-radius:4px;padding:12px 14px;margin:18px 0',
    'cards'=>'width:100%;border-collapse:separate;border-spacing:6px;margin:0 -6px',
    'card'=>'background:#f3f6f8;border-radius:8px;padding:12px;vertical-align:top;width:33%',
    'card-paid'=>'background:#e6f4ea;border-radius:8px;padding:12px;vertical-align:top;width:33%',
    'card-pending'=>'background:#fff4e0;border-radius:8px;padding:12px;vertical-align:top;width:33%',
    'label'=>'color:#5b6b78;font-size:12px',
    'big'=>'display:block;font-size:20px;font-weight:bold;margin-top:2px',
    'table'=>'width:100%;border-collapse:collapse;font-size:13px',
    'th'=>'text-align:left;background:#f7f9fa;color:#5b6b78;font-size:11px;text-transform:uppercase;padding:8px;border-bottom:1px solid #e0e7eb',
    'td'=>'padding:8px;border-bottom:1px solid #e8edf0;vertical-align:top',
    'num'=>'text-align:right;white-space:nowrap',
    'small'=>'display:block;color:#6c7c89;font-size:12px',
    'row-overdue'=>'background:#fdf1ef',
    'paid'=>'display:inline-block;background:#e6f4ea;color:#1e6b34;border-radius:12px;padding:2px 9px;font-size:11px;font-weight:bold;white-space:nowrap',
    'pending'=>'display:inline-block;background:#fff4e0;color:#8a5a00;border-radius:12px;padding:2px 9px;font-size:11px;font-weight:bold;white-space:nowrap',
    'overdue'=>'display:inline-block;background:#fdecea;color:#a12a1a;border-radius:12px;padding:2px 9px;font-size:11px;font-weight:bold;white-space:nowrap',
    'neutral'=>'display:inline-block;background:#eef2f5;color:#5b6b78;border-radius:12px;padding:2px 9px;font-size:11px;white-space:nowrap',
];

function report_ready(): bool {
    try { query('SELECT id FROM report_settings LIMIT 1'); query('SELECT id FROM report_runs LIMIT 1'); return true; }
    catch (PDOException $error) { return false; }
}
// The two report days of a month.
function report_days(int $year, int $month): array {
    $first=new DateTimeImmutable(sprintf('%04d-%02d-01',$year,$month));
    return [$first->format('Y-m-').($month===2?'23':'25'), $first->format('Y-m-t')];
}
// The report day the daily job should send on $today. A missed run is caught up for up to 3 days.
function report_due_day(string $today): ?string {
    $t=new DateTimeImmutable($today); $previous=$t->modify('first day of last month');
    $days=[...report_days((int)$previous->format('Y'),(int)$previous->format('n')),...report_days((int)$t->format('Y'),(int)$t->format('n'))];
    $due=null;
    foreach ($days as $day) if ($day<=$today) $due=$day;
    return $due!==null && $due>=$t->modify('-3 days')->format('Y-m-d') ? $due : null;
}
function report_upcoming(string $today, int $count=3): array {
    $t=new DateTimeImmutable($today); $out=[];
    for ($m=0; count($out)<$count; $m++) {
        $month=$t->modify('first day of this month')->modify("+$m month");
        foreach (report_days((int)$month->format('Y'),(int)$month->format('n')) as $day) if ($day>=$today && count($out)<$count) $out[]=$day;
    }
    return $out;
}
// Accepts up to 5 addresses separated by commas, semicolons, spaces or new lines.
function report_parse_recipients(string $input): array {
    $emails=array_values(array_unique(array_map('strtolower',preg_split('/[\s,;]+/',trim($input),-1,PREG_SPLIT_NO_EMPTY))));
    if (count($emails)>5) throw new RuntimeException('Register at most 5 report email addresses.');
    foreach ($emails as $email) if (strlen($email)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Check the report email address: '.$email);
    return $emails;
}
function report_settings(): array {
    $row=one('SELECT * FROM report_settings WHERE id=1');
    return ['enabled'=>(bool)($row['enabled']??false),'recipients'=>$row ? report_parse_recipients($row['recipients']) : []];
}

// Figures for the month containing $day, from the 1st up to and including $day. Payment status is as currently recorded.
function report_data(string $day): array {
    $start=substr($day,0,8).'01';
    $isMonthEnd=$day===(new DateTimeImmutable($day))->format('Y-m-t');
    $paidSql='SELECT document_id,SUM(amount) AS paid,MAX(payment_date) AS paid_on FROM payments GROUP BY document_id';
    $quotations=query("SELECT q.id,q.kind,q.customer_snapshot,q.document_date,q.due_date,q.total,i.id AS invoice_id FROM documents q LEFT JOIN documents i ON i.source_id=q.id WHERE q.kind='quotation' AND q.document_date BETWEEN ? AND ? ORDER BY q.document_date,q.id",[$start,$day])->fetchAll();
    $issued=query("SELECT d.id,d.total,COALESCE(x.paid,0) AS paid FROM documents d LEFT JOIN ($paidSql) x ON x.document_id=d.id WHERE d.kind='invoice' AND d.document_date BETWEEN ? AND ?",[$start,$day])->fetchAll();
    $paidInvoices=query("SELECT d.id,d.kind,d.customer_snapshot,d.document_date,d.total,x.paid,x.paid_on FROM documents d JOIN ($paidSql) x ON x.document_id=d.id WHERE d.kind='invoice' AND x.paid>=d.total AND x.paid_on BETWEEN ? AND ? ORDER BY x.paid_on,d.id",[$start,$day])->fetchAll();
    $pending=query("SELECT d.*,COALESCE(x.paid,0) AS paid,c.name,c.email,c.phone FROM documents d LEFT JOIN ($paidSql) x ON x.document_id=d.id LEFT JOIN customers c ON c.id=d.customer_id WHERE d.kind='invoice' AND d.document_date<=? AND COALESCE(x.paid,0)<d.total ORDER BY d.due_date,d.id",[$day])->fetchAll();
    $received=(int)one('SELECT COALESCE(SUM(amount),0) AS total FROM payments WHERE payment_date BETWEEN ? AND ?',[$start,$day])['total'];
    $sum=fn(array $rows,callable $f)=>array_sum(array_map($f,$rows));
    $converted=array_filter($quotations,fn($q)=>$q['invoice_id']!==null);
    $issuedPaid=array_filter($issued,fn($d)=>(int)$d['paid']>=(int)$d['total']);
    $issuedOpen=array_filter($issued,fn($d)=>(int)$d['paid']<(int)$d['total']);
    $overdue=array_filter($pending,fn($d)=>$d['due_date']<$day);
    return [
        'day'=>$day,'start'=>$start,'month_end'=>$isMonthEnd,
        'quotations'=>$quotations,'paid_invoices'=>$paidInvoices,'pending'=>$pending,
        'summary'=>[
            'quotations'=>count($quotations),'quotations_value'=>$sum($quotations,fn($q)=>(int)$q['total']),
            'converted'=>count($converted),'converted_value'=>$sum($converted,fn($q)=>(int)$q['total']),
            'invoices'=>count($issued),'invoices_value'=>$sum($issued,fn($d)=>(int)$d['total']),
            'issued_paid'=>count($issuedPaid),'issued_paid_value'=>$sum($issuedPaid,fn($d)=>(int)$d['total']),
            'issued_open'=>count($issuedOpen),'issued_open_balance'=>$sum($issuedOpen,fn($d)=>(int)$d['total']-(int)$d['paid']),
            'received'=>$received,
            'pending'=>count($pending),'pending_balance'=>$sum($pending,fn($d)=>(int)$d['total']-(int)$d['paid']),
            'overdue'=>count($overdue),'overdue_balance'=>$sum($overdue,fn($d)=>(int)$d['total']-(int)$d['paid']),
        ],
    ];
}
function report_period(array $r): string {
    $start=new DateTimeImmutable($r['start']); $day=new DateTimeImmutable($r['day']);
    return $start->format('F Y').' · 1–'.$day->format('j F').($r['month_end']?' (month-end report)':' (mid-month report)');
}
function report_days_between(string $from, string $to): int { return (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%r%a'); }
// Status and follow-up advice for an unpaid invoice, as at the report day.
function report_followup(array $d, string $day): array {
    $days=report_days_between($day,$d['due_date']); $part=(int)$d['paid']>0 ? 'Part paid · ' : '';
    if ($days<0) return ['overdue',$part.'Overdue '.(-$days).' day'.($days===-1?'':'s'),'Call the customer now and agree a payment date.'];
    if ($days===0) return ['pending',$part.'Due today','Confirm payment with the customer today.'];
    return ['pending',$part.'Due in '.$days.' day'.($days===1?'':'s'),'Send a friendly payment reminder.'];
}
function report_portal_link(): ?string {
    global $config;
    $url=rtrim((string)($config['app_url']??''),'/'); $parts=parse_url($url);
    return $parts && ($parts['scheme']??'')==='https' && !empty($parts['host']) && !isset($parts['query']) && !isset($parts['user']) ? $url.'/index.php?page=view&id=' : null;
}

// Renders the report. $email true gives inline styles for mail clients; false gives classes for the portal page.
function report_html(array $r, bool $email): string {
    global $config;
    $a=fn(string ...$keys)=>$email ? ' style="'.implode(';',array_map(fn($k)=>REPORT_STYLES[$k],$keys)).'"' : ' class="'.implode(' ',array_map(fn($k)=>'r-'.$k,$keys)).'"';
    $base=$email ? report_portal_link() : 'index.php?page=view&id=';
    $doc=fn(array $d)=>$base===null ? e(number($d)) : '<a href="'.e($base.$d['id']).'">'.e(number($d)).'</a>';
    $cur=e($config['currency']??''); $m=fn(int $v)=>$cur.' '.money($v); $s=$r['summary']; $day=$r['day'];
    $customer=fn(array $d)=>e(json_decode($d['customer_snapshot'],true)['name']??'');
    $card=fn(string $style,string $label,string $big,string $note)=>'<td'.$a($style).'><span'.$a('label').'>'.$label.'</span><strong'.$a('big').'>'.$big.'</strong><span'.$a('small').'>'.$note.'</span></td>';
    $rate=$s['quotations'] ? round($s['converted']*100/$s['quotations']).'%' : '—';
    $h='<div'.$a('wrap').'><h1'.$a('h1').'>'.e(brand('brand')).' · Monthly report</h1><p'.$a('muted').'>'.e(report_period($r)).' · Prepared '.date('d M Y, H:i').'</p>';
    if ($s['pending']) $h.='<div'.$a('callout').'><strong>Follow-up needed:</strong> '.$s['pending'].' Tax Invoice'.($s['pending']===1?' is':'s are').' not paid in full, '.$m($s['pending_balance']).' outstanding'.($s['overdue']?', of which '.$s['overdue'].' overdue ('.$m($s['overdue_balance']).')':'').'. Please contact these customers; the list is below, most overdue first.</div>';
    else $h.='<div'.$a('good').'><strong>All Tax Invoices are paid in full.</strong> Nothing to follow up.</div>';
    $h.='<table'.$a('cards').' role="presentation"><tr>'
        .$card('card','Proformas issued',(string)$s['quotations'],$m($s['quotations_value']))
        .$card('card','Turned into Tax Invoices',$s['converted'].' · '.$rate,$m($s['converted_value']))
        .$card('card','Tax Invoices issued',(string)$s['invoices'],$m($s['invoices_value']))
        .'</tr><tr>'
        .$card('card-paid','Issued this month, paid in full',(string)$s['issued_paid'],$m($s['issued_paid_value']))
        .$card('card-pending','Issued this month, not paid in full',(string)$s['issued_open'],$m($s['issued_open_balance']).' outstanding')
        .$card('card','Payments received',$m($s['received']),'1–'.(int)substr($day,8).' '.(new DateTimeImmutable($day))->format('M'))
        .'</tr></table>';
    // Headings of amount columns are right-aligned like their figures.
    $th=fn(array $cols)=>'<tr>'.implode('',array_map(fn($c)=>in_array($c,['Balance','Amount','Value'],true) ? '<th'.$a('th','num').'>'.$c.'</th>' : '<th'.$a('th').'>'.$c.'</th>',$cols)).'</tr>';
    $h.='<h2'.$a('h2').'>Pending Tax Invoices · follow up</h2><p'.$a('muted').'>All Tax Invoices with a balance, from any month.</p>';
    if ($r['pending']) {
        $h.='<table'.$a('table').'>'.$th(['Tax Invoice','Customer & contact','Due','Balance','Status & action']);
        foreach (array_slice($r['pending'],0,200) as $d) {
            [$tone,$status,$action]=report_followup($d,$day);
            $contact=implode(' · ',array_filter([$d['phone']??'',$d['email']??'']));
            $h.='<tr'.($tone==='overdue'?$a('row-overdue'):'').'><td'.$a('td').'>'.$doc($d).'<span'.$a('small').'>'.e($d['document_date']).'</span></td><td'.$a('td').'>'.e($d['name']??json_decode($d['customer_snapshot'],true)['name']??'').($contact!==''?'<span'.$a('small').'>'.e($contact).'</span>':'').'</td><td'.$a('td').'>'.e($d['due_date']).'</td><td'.$a('td','num').'><strong>'.$m((int)$d['total']-(int)$d['paid']).'</strong><span'.$a('small').'>of '.$m((int)$d['total']).'</span></td><td'.$a('td').'><span'.$a($tone).'>'.e($status).'</span><span'.$a('small').'>'.e($action).'</span>'.(!empty($d['assigned_to'])?'<span'.$a('small').'>Staff: '.e($d['assigned_to']).'</span>':'').'</td></tr>';
        }
        $h.='<tr><td'.$a('td').' colspan="3"><strong>Total outstanding</strong></td><td'.$a('td','num').'><strong>'.$m($s['pending_balance']).'</strong></td><td'.$a('td').'></td></tr></table>';
        if (count($r['pending'])>200) $h.='<p'.$a('muted').'>Showing the first 200. See the portal for the rest.</p>';
    } else $h.='<p>No pending Tax Invoices.</p>';
    $h.='<h2'.$a('h2').'>Paid Tax Invoices</h2><p'.$a('muted').'>Tax Invoices that became paid in full this month.</p>';
    if ($r['paid_invoices']) {
        $h.='<table'.$a('table').'>'.$th(['Tax Invoice','Customer','Paid in full on','Amount','']);
        foreach ($r['paid_invoices'] as $d) $h.='<tr><td'.$a('td').'>'.$doc($d).'</td><td'.$a('td').'>'.$customer($d).'</td><td'.$a('td').'>'.e($d['paid_on']).'</td><td'.$a('td','num').'>'.$m((int)$d['total']).'</td><td'.$a('td').'><span'.$a('paid').'>Paid</span></td></tr>';
        $h.='</table>';
    } else $h.='<p>No Tax Invoices were paid in full this month yet.</p>';
    $h.='<h2'.$a('h2').'>Proformas this month</h2>';
    if ($r['quotations']) {
        $h.='<table'.$a('table').'>'.$th(['Proforma','Customer','Date','Value','Outcome']);
        foreach ($r['quotations'] as $q) {
            $outcome=$q['invoice_id']!==null ? '<span'.$a('paid').'>Invoiced</span><span'.$a('small').'>'.$doc(['kind'=>'invoice','id'=>$q['invoice_id']]).'</span>' : ($q['due_date']<$day ? '<span'.$a('neutral').'>Expired '.e($q['due_date']).'</span>' : '<span'.$a('pending').'>Awaiting customer</span><span'.$a('small').'>Valid until '.e($q['due_date']).'</span>');
            $h.='<tr><td'.$a('td').'>'.$doc($q).'</td><td'.$a('td').'>'.$customer($q).'</td><td'.$a('td').'>'.e($q['document_date']).'</td><td'.$a('td','num').'>'.$m((int)$q['total']).'</td><td'.$a('td').'>'.$outcome.'</td></tr>';
        }
        $h.='</table>';
    } else $h.='<p>No Proformas issued this month yet.</p>';
    return $h.'<p'.$a('muted').'>Sent automatically by the '.e(brand('short_name')).' portal on the 25th (23rd in February) and the last day of each month. Payment status is as recorded in the portal when the report was prepared.</p></div>';
}
function report_text(array $r): string {
    global $config;
    $s=$r['summary']; $cur=$config['currency']??''; $m=fn(int $v)=>$cur.' '.money($v);
    $t=brand('brand')." - Monthly report\r\n".report_period($r)."\r\n\r\n";
    $t.=$s['pending'] ? "FOLLOW-UP NEEDED: {$s['pending']} Tax Invoice(s) not paid in full, ".$m($s['pending_balance'])." outstanding".($s['overdue']?", {$s['overdue']} overdue (".$m($s['overdue_balance']).")":'').".\r\n\r\n" : "All Tax Invoices are paid in full.\r\n\r\n";
    $t.="Proformas issued: {$s['quotations']} (".$m($s['quotations_value']).")\r\nTurned into Tax Invoices: {$s['converted']} (".$m($s['converted_value']).")\r\nTax Invoices issued: {$s['invoices']} (".$m($s['invoices_value']).")\r\nIssued this month, paid in full: {$s['issued_paid']}\r\nIssued this month, not paid in full: {$s['issued_open']} (".$m($s['issued_open_balance'])." outstanding)\r\nPayments received: ".$m($s['received'])."\r\n\r\nPENDING TAX INVOICES - FOLLOW UP\r\n";
    foreach (array_slice($r['pending'],0,200) as $d) { [, $status,$action]=report_followup($d,$r['day']); $t.=number($d).' | '.($d['name']??'').' | '.trim(($d['phone']??'').' '.($d['email']??'')).' | due '.$d['due_date'].' | balance '.$m((int)$d['total']-(int)$d['paid']).' | '.$status.' - '.$action.(!empty($d['assigned_to'])?' | staff '.$d['assigned_to']:'')."\r\n"; }
    if (!$r['pending']) $t.="None.\r\n";
    $t.="\r\nPAID TAX INVOICES\r\n";
    foreach ($r['paid_invoices'] as $d) $t.=number($d).' | '.(json_decode($d['customer_snapshot'],true)['name']??'').' | paid '.$d['paid_on'].' | '.$m((int)$d['total'])."\r\n";
    if (!$r['paid_invoices']) $t.="None yet.\r\n";
    return $t;
}
function report_send(array $r, array $recipients, ?callable $sender=null): bool {
    global $config;
    $from=$config['mail_from']??$config['email']??'';
    if (!$recipients) throw new RuntimeException('Register at least one report email address on the Reports page.');
    foreach ($recipients as $to) if (!filter_var($to,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n,]/',$to)) throw new RuntimeException('Check the report email addresses.');
    if (!filter_var($from,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$from)) throw new RuntimeException('Check the mail_from address in config.php.');
    $boundary='rep_'.bin2hex(random_bytes(18));
    $html='<!doctype html><html><head><meta charset="utf-8"></head><body>'.report_html($r,true).'</body></html>';
    $body='--'.$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode(report_text($r))).'--'.$boundary."\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($html)).'--'.$boundary."--\r\n";
    $headers=['From'=>brand('brand').' <'.$from.'>','MIME-Version'=>'1.0','Content-Type'=>'multipart/alternative; boundary="'.$boundary.'"'];
    $day=new DateTimeImmutable($r['day']);
    $subject=brand('short_name').' monthly report - '.$day->format('F Y').' (1-'.$day->format('j M').')';
    $to=implode(', ',$recipients);
    return $sender ? (bool)$sender($to,$subject,$body,$headers) : (function_exists('mail') && @mail($to,$subject,$body,$headers));
}
// Daily job: sends the scheduled report once per report day. A failed email is retried on the next run within the catch-up window.
function report_run_due(string $today, ?callable $sender=null): string {
    if (!report_ready()) return 'Monthly report: import reports-schema.sql to enable it.';
    $day=report_due_day($today);
    if ($day===null) return 'No monthly report due today.';
    $settings=report_settings();
    if (!$settings['enabled'] || !$settings['recipients']) return 'Monthly report for '.$day.' is due, but no report email is registered or reports are switched off (Reports page).';
    $list=implode(',',$settings['recipients']);
    $claimed=query("INSERT IGNORE INTO report_runs (report_date,recipients) VALUES (?,?)",[$day,$list])->rowCount()
        || query("UPDATE report_runs SET mail_status='pending',recipients=? WHERE report_date=? AND mail_status='failed'",[$list,$day])->rowCount();
    if (!$claimed) return 'Monthly report for '.$day.' was already sent.';
    try { $accepted=report_send(report_data($day),$settings['recipients'],$sender); }
    catch (Throwable $error) { $accepted=false; error_log('Monthly report failed: '.$error->getMessage()); }
    query('UPDATE report_runs SET mail_status=? WHERE report_date=?',[$accepted?'accepted':'failed',$day]);
    return 'Monthly report for '.$day.($accepted?' emailed to '.$list:' was not accepted by the mail service; it will be retried on the next daily run').'.';
}
