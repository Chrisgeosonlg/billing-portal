<?php
// Local preview test. Never upload this file. Seeds data in a transaction that is rolled back.
require __DIR__.'/../app/lib.php';
require __DIR__.'/../app/report-lib.php';
date_default_timezone_set('Africa/Dar_es_Salaam');
function check(bool $condition, string $message): void { if (!$condition) throw new Exception('FAIL: '.$message); }

check(report_days(2026,10)===['2026-10-25','2026-10-31'],'October days');
check(report_days(2027,2)===['2027-02-23','2027-02-28'],'February uses the 23rd');
check(report_days(2028,2)===['2028-02-23','2028-02-29'],'Leap February ends on the 29th');
check(report_days(2026,4)===['2026-04-25','2026-04-30'],'30-day month');
foreach (['2026-10-25'=>'2026-10-25','2026-10-24'=>null,'2026-10-28'=>'2026-10-25','2026-10-29'=>null,'2026-10-31'=>'2026-10-31','2026-11-02'=>'2026-10-31','2026-11-04'=>null,
          '2027-02-23'=>'2027-02-23','2027-02-25'=>'2027-02-23','2027-02-27'=>null,'2027-02-28'=>'2027-02-28','2028-02-29'=>'2028-02-29','2028-02-28'=>null,'2027-01-01'=>'2026-12-31','2027-01-25'=>'2027-01-25'] as $today=>$expected)
    check(report_due_day($today)===$expected,"Due day for $today");
check(report_upcoming('2026-10-28')===['2026-10-31','2026-11-25','2026-11-30'],'Upcoming days');
check(report_upcoming('2027-01-30',3)===['2027-01-31','2027-02-23','2027-02-28'],'Upcoming crosses into February');
check(report_parse_recipients(" a@example.com; B@example.com\n a@example.com ")===['a@example.com','b@example.com'],'Recipients parsed, lower-cased, de-duplicated');
foreach (['a@example.com,b@example.com,c@example.com,d@example.com,e@example.com,f@example.com','a@example.com, not-an-email',"a@example.com\r\nBcc: x@example.com"] as $bad) {
    try { report_parse_recipients($bad); check(false,'Accepted '.$bad); } catch (RuntimeException $ex) {}
}

$config=require $argv[1].'/app/config.php';
if (!str_contains($config['dsn'],'host=127.0.0.1;port=13367;dbname=billing_portal_test')) throw new RuntimeException('Not the local test database');
$config['app_url']='https://erp.example.com';
$db=new PDO($config['dsn'],$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("SET time_zone = '+00:00'");
function query(string $sql,array $params=[]):PDOStatement {global $db;$s=$db->prepare($sql);$s->execute($params);return $s;}
function one(string $sql,array $params=[]):array{return query($sql,$params)->fetch()?:[];}
check(report_ready(),'Report tables missing');

$db->beginTransaction();
try {
    query('DELETE FROM report_settings'); query('DELETE FROM report_runs');
    $baseline=report_data('2031-03-25')['summary']; $baselineEnd=report_data('2031-03-31')['summary'];
    $customer=one('SELECT * FROM customers ORDER BY id LIMIT 1');
    $doc=function(string $kind,string $date,string $due,int $total,?int $source=null,string $name='Seed Customer') use ($customer) {
        query("INSERT INTO documents (kind,customer_id,customer_snapshot,company_snapshot,source_id,document_date,due_date,notes,items_json,subtotal,discount,tax_bps,tax,total,created_by) VALUES (?,?,?,'{}',?,?,?,'','[]',?,0,0,0,?,1)",[$kind,$customer['id'],json_encode(['name'=>$name]),$source,$date,$due,$total,$total]);
        global $db; return (int)$db->lastInsertId();
    };
    $pay=fn(int $id,int $amount,string $date)=>query("INSERT INTO payments (document_id,amount,payment_date,reference,created_by) VALUES (?,?,?,'test',1)",[$id,$amount,$date]);
    $q1=$doc('quotation','2031-03-02','2031-04-01',100000);
    $i1=$doc('invoice','2031-03-05','2031-03-20',100000,$q1,'<b>Paid & Co</b>'); $pay($i1,100000,'2031-03-10');
    $q2=$doc('quotation','2031-03-10','2031-04-09',50000);
    $q3=$doc('quotation','2031-03-01','2031-03-15',20000);
    $i2=$doc('invoice','2031-03-12','2031-03-20',80000); $pay($i2,30000,'2031-03-14');
    $i3=$doc('invoice','2031-03-20','2031-04-19',60000);
    $i4=$doc('invoice','2031-02-10','2031-02-28',40000); $pay($i4,40000,'2031-03-03');
    $i5=$doc('invoice','2031-03-28','2031-04-27',10000);

    $r=report_data('2031-03-25'); $s=$r['summary'];
    $delta=fn(string $k)=>$s[$k]-$baseline[$k];
    check(!$r['month_end'] && $r['start']==='2031-03-01','Mid-month period');
    check($delta('quotations')===3 && $delta('quotations_value')===170000,'Quotations counted');
    check($delta('converted')===1 && $delta('converted_value')===100000,'Converted quotations counted');
    check($delta('invoices')===3 && $delta('invoices_value')===240000,'Invoices issued in the period (later invoice excluded)');
    check($delta('issued_paid')===1 && $delta('issued_paid_value')===100000,'Paid in full');
    check($delta('issued_open')===2 && $delta('issued_open_balance')===110000,'Not paid in full');
    check($delta('received')===170000,'Payments received in the period');
    check($delta('pending')===2 && $delta('pending_balance')===110000 && $delta('overdue')===1 && $delta('overdue_balance')===50000,'Pending and overdue');
    $paidIds=array_map('intval',array_column($r['paid_invoices'],'id'));
    check(in_array($i1,$paidIds,true) && in_array($i4,$paidIds,true) && !in_array($i2,$paidIds,true),'Paid list includes older invoices settled this month');
    $pendingIds=array_map('intval',array_column($r['pending'],'id'));
    check(in_array($i2,$pendingIds,true) && in_array($i3,$pendingIds,true) && !in_array($i5,$pendingIds,true),'Pending list');
    $row=fn(int $id)=>array_values(array_filter($r['pending'],fn($d)=>(int)$d['id']===$id))[0];
    check(report_followup($row($i2),'2031-03-25')[0]==='overdue' && report_followup($row($i2),'2031-03-25')[1]==='Part paid · Overdue 5 days','Overdue, part paid');
    check(report_followup($row($i3),'2031-03-25')[1]==='Due in 25 days','Due soon');
    check(report_followup(['due_date'=>'2031-03-25','paid'=>0],'2031-03-25')[1]==='Due today' && report_followup(['due_date'=>'2031-03-24','paid'=>0],'2031-03-25')[1]==='Overdue 1 day','Singular wording');
    $end=report_data('2031-03-31');
    check($end['month_end'] && $end['summary']['invoices']-$baselineEnd['invoices']===4,'Month-end report covers the whole month');

    $html=report_html($r,true);
    check(str_contains($html,'Follow-up needed') && str_contains($html,'Overdue 5 days') && str_contains($html,'Awaiting customer') && str_contains($html,'Expired 2031-03-15') && str_contains($html,'>Invoiced<'),'Report sections');
    check(str_contains($html,'href="https://erp.example.com/index.php?page=view&amp;id='.$i2.'"'),'Email links to the portal');
    check(str_contains($html,'&lt;b&gt;Paid &amp;amp; Co&lt;/b&gt;') || str_contains($html,'&lt;b&gt;Paid &amp; Co&lt;/b&gt;'),'Customer names escaped');
    check(!str_contains($html,'<b>Paid'),'No raw HTML from data');
    check(!str_contains(report_html($r,false),'style="'),'Portal version has no inline styles (blocked by the security policy)');
    $config['app_url']='http://insecure.example.com';
    check(!str_contains(report_html($r,true),'insecure.example.com'),'No links without an HTTPS app_url');
    $config['app_url']='https://erp.example.com';

    check(str_contains(report_run_due('2031-03-25',fn()=>throw new Exception('sent')),'no report email is registered'),'No recipients: nothing sent');
    query("INSERT INTO report_settings (id,recipients,enabled) VALUES (1,'boss@example.com,accounts@example.com',1)");
    $sent=[];
    $sender=function(...$args)use(&$sent){$sent[]=$args;return true;};
    check(report_run_due('2031-03-24',$sender)==='No monthly report due today.' && !$sent,'Not a report day');
    $out=report_run_due('2031-03-25',$sender);
    check(count($sent)===1 && str_contains($out,'emailed to boss@example.com,accounts@example.com'),'Report emailed on the 25th');
    [$to,$subject,$body,$headers]=$sent[0];
    check($to==='boss@example.com, accounts@example.com' && $subject==='Billing monthly report - March 2031 (1-25 Mar)','Recipients and subject');
    check(str_contains($headers['Content-Type'],'multipart/alternative') && !preg_match('/[\r\n]/',$subject),'MIME headers');
    preg_match_all('/base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/',$body,$parts);
    $decoded=array_map(fn($p)=>base64_decode(str_replace("\r\n",'',$p)),$parts[1]);
    check(count($decoded)===2 && str_contains($decoded[0],'FOLLOW-UP NEEDED') && str_contains($decoded[1],'Overdue 5 days'),'Text and HTML parts');
    check(one("SELECT mail_status FROM report_runs WHERE report_date='2031-03-25'")['mail_status']==='accepted','Run recorded');
    check(str_contains(report_run_due('2031-03-25',$sender),'already sent') && str_contains(report_run_due('2031-03-27',$sender),'already sent') && count($sent)===1,'Sent once per report day, even when re-run');
    check(str_contains(report_run_due('2031-03-31',fn()=>false),'retried'),'Mail failure reported');
    check(one("SELECT mail_status FROM report_runs WHERE report_date='2031-03-31'")['mail_status']==='failed','Failure recorded');
    check(str_contains(report_run_due('2031-04-01',$sender),'emailed') && count($sent)===2 && one("SELECT mail_status FROM report_runs WHERE report_date='2031-03-31'")['mail_status']==='accepted','Failed report retried the next day');
    query('UPDATE report_settings SET enabled=0');
    check(str_contains(report_run_due('2031-04-25',$sender),'switched off') && count($sent)===2,'Disabled: nothing sent');
    echo "PASS: report days (25th, 23rd in February, month end, leap years), catch-up, recipients, figures, follow-up status, escaping, links, email format, once per day, retry and switch-off. No email sent.\n";
} finally { $db->rollBack(); }
