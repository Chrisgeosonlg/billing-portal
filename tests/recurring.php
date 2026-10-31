<?php
// Local preview test. Never upload this file.
require __DIR__.'/../app/lib.php';
require __DIR__.'/../app/document-sharing-lib.php';
require __DIR__.'/../app/document-pdf.php';
require __DIR__.'/../app/recurring-lib.php';
date_default_timezone_set('Africa/Dar_es_Salaam');
function recurring_check(bool $condition, string $message): void { if (!$condition) throw new Exception($message); }

$o='recurring_occurrence';
recurring_check($o('2026-01-31',1,1,'month')==='2026-02-28','Month end clamps in February');
recurring_check($o('2026-01-31',2,1,'month')==='2026-03-31','Month end restored in March');
recurring_check($o('2028-01-31',1,1,'month')==='2028-02-29','Leap February');
recurring_check($o('2026-11-15',1,3,'month')==='2027-02-15','Quarterly crosses the year');
recurring_check($o('2028-02-29',1,1,'year')==='2029-02-28','Leap day yearly');
recurring_check($o('2026-10-26',2,2,'week')==='2026-11-23','Fortnightly');
recurring_check($o('2026-12-30',1,5,'day')==='2027-01-04','Days cross the year');
$s=['anchor_date'=>'2026-01-10','sequence'=>0,'interval_count'=>1,'interval_unit'=>'month','end_date'=>null];
recurring_check(recurring_after($s,'2026-01-10')===['sequence'=>1,'next_date'=>'2026-02-10','status'=>'active'],'Advances one month');
recurring_check(recurring_after($s,'2026-04-20')['next_date']==='2026-05-10','Missed dates are skipped, not sent late');
recurring_check(recurring_after(['end_date'=>'2026-02-01']+$s,'2026-01-10')['status']==='ended','End date stops the schedule');
recurring_check(recurring_after(['interval_unit'=>null]+$s,'2026-01-10')['next_date']===null,'One-off waits for a new date');


$config=require $argv[1].'/app/config.php';
if (!str_contains($config['dsn'],'host=127.0.0.1;port=13367;dbname=billing_portal_test')) throw new RuntimeException('Not the local test database');
$config['app_url']='https://billing.example.com';
$db=new PDO($config['dsn'],$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("SET time_zone = '+00:00'");
function query(string $sql,array $params=[]):PDOStatement {global $db;$s=$db->prepare($sql);$s->execute($params);return $s;}
function one(string $sql,array $params=[]):array{return query($sql,$params)->fetch()?:[];}
recurring_check(recurring_ready(),'Recurring tables missing');
$template=one("SELECT * FROM documents WHERE kind='quotation' ORDER BY id LIMIT 1");
if (!$template) throw new RuntimeException('A local sample quotation is required');
// Schedules left on the sample documents by an earlier HTTP test run (local test database only).
foreach (query("SELECT id FROM recurring_quotations WHERE template_id IN (SELECT id FROM documents WHERE id<=2)")->fetchAll(PDO::FETCH_COLUMN) as $old) { query('DELETE FROM recurring_runs WHERE schedule_id=?',[$old]); query('DELETE FROM recurring_quotations WHERE id=?',[$old]); }
$today=date('Y-m-d'); $maxDoc=(int)one('SELECT MAX(id) AS id FROM documents')['id'];
query("INSERT INTO recurring_quotations (template_id,recipient,anchor_date,next_date,interval_count,interval_unit,end_date,valid_days,created_by) VALUES (?,?,?,?,1,'month',NULL,14,1)",[$template['id'],'customer@example.com',$today,$today]);
$scheduleId=(int)$db->lastInsertId();
try {
    $captured=[];
    $out=recurring_run_due($today,function(...$args)use(&$captured){$captured[]=$args;return true;});
    recurring_check(count($out)===1 && count($captured)===1,'Exactly one quotation emailed');
    $run=one('SELECT * FROM recurring_runs WHERE schedule_id=?',[$scheduleId]);
    $doc=one('SELECT * FROM documents WHERE id=?',[$run['document_id']]);
    recurring_check((int)$doc['id']>$maxDoc && $doc['kind']==='quotation','New quotation created');
    recurring_check($doc['document_date']===$today && $doc['due_date']===date('Y-m-d',strtotime('+14 days')),'Dated today, valid 14 days');
    recurring_check($doc['items_json']===$template['items_json'] && (int)$doc['total']===(int)$template['total'],'Services and totals copied');
    recurring_check($captured[0][0]==='customer@example.com' && str_contains($captured[0][2],'filename="'.number($doc).'.pdf"'),'PDF emailed to recipient');
    recurring_check($run['mail_status']==='accepted' && one('SELECT mail_status FROM document_shares WHERE id=?',[$run['share_id']])['mail_status']==='accepted','Email status recorded');
    $s=one('SELECT * FROM recurring_quotations WHERE id=?',[$scheduleId]);
    recurring_check($s['next_date']===recurring_occurrence($today,1,1,'month') && (int)$s['sequence']===1,'Schedule advanced a month');
    recurring_check(recurring_run_due($today,fn()=>throw new Exception('Sent twice'))===[],'Second run the same day sends nothing');

    query("UPDATE recurring_quotations SET next_date=? WHERE id=?",[$today,$scheduleId]);
    $out=recurring_run_due($today,fn()=>throw new Exception('Re-sent an occurrence'));
    recurring_check(str_contains($out[0],'already sent') && one('SELECT next_date FROM recurring_quotations WHERE id=?',[$scheduleId])['next_date']>$today,'Already-sent date skipped and advanced');

    $yesterday=date('Y-m-d',strtotime('-1 day'));
    query("UPDATE recurring_quotations SET next_date=?,end_date=? WHERE id=?",[$yesterday,$today,$scheduleId]);
    $out=recurring_run_due($today,fn()=>false);
    recurring_check(str_contains($out[0],'not accepted'),'Mail failure reported');
    recurring_check(one('SELECT mail_status FROM recurring_runs WHERE schedule_id=? ORDER BY id DESC',[$scheduleId])['mail_status']==='failed','Failure recorded');
    $s=one('SELECT * FROM recurring_quotations WHERE id=?',[$scheduleId]);
    recurring_check($s['status']==='ended' && $s['next_date']===null,'Schedule ended after its end date');

    // Recurring invoices: the copy is an invoice, due after the same number of days as the original.
    $invoice=one("SELECT * FROM documents WHERE kind='invoice' ORDER BY id LIMIT 1");
    $_POST=['next_date'=>$today,'interval_unit'=>'month','interval_count'=>'1','end_date'=>'','recipient'=>''];
    try { recurring_save(['document_date'=>$today]+$invoice,1); recurring_check(false,'Next date on the document date accepted'); } catch (RuntimeException $ex) { recurring_check(str_contains($ex->getMessage(),'after the document date'),'Next date must follow the document date'); }
    $_POST['next_date']=date('Y-m-d',strtotime('+1 day'));
    $_POST['interval_count']='0';
    try { recurring_save($invoice,1); recurring_check(false,'Zero interval accepted'); } catch (RuntimeException $ex) { recurring_check(str_contains($ex->getMessage(),'1 to 365'),'Interval validated'); }
    $_POST['interval_count']='1';
    $customerEmail=one('SELECT email FROM customers WHERE id=?',[$invoice['customer_id']])['email'];
    $saved=recurring_save($invoice,1);
    $invoiceSchedule=(int)$saved['id'];
    recurring_check($saved['recipient']===$customerEmail && $saved['next_date']===$_POST['next_date'] && (int)$saved['valid_days']===recurring_valid_days($invoice),'Blank recipient uses the customer email');
    query('UPDATE recurring_quotations SET next_date=? WHERE id=?',[$today,$invoiceSchedule]);
    $captured=[];
    recurring_run_due($today,function(...$args)use(&$captured){$captured[]=$args;return true;});
    $copy=one('SELECT d.* FROM recurring_runs r JOIN documents d ON d.id=r.document_id WHERE r.schedule_id=?',[$invoiceSchedule]);
    recurring_check($copy && $copy['kind']==='invoice' && $copy['due_date']===date('Y-m-d',strtotime('+'.recurring_valid_days($invoice).' days')),'Invoice copied as an invoice with the same payment term');
    recurring_check(count($captured)===1 && str_contains($captured[0][1],'Invoice '.number($copy)),'Invoice emailed');
    echo "PASS: month-end and leap-year dates, catch-up skipping, end dates, one-off schedules, quotation and invoice copies, save validation, default recipient, PDF email, idempotent daily run and mail failure. No email sent.\n";
} finally {
    foreach (array_filter([$scheduleId,$invoiceSchedule??null]) as $sid) {
        $docs=query('SELECT document_id,share_id FROM recurring_runs WHERE schedule_id=?',[$sid])->fetchAll();
        query('DELETE FROM recurring_runs WHERE schedule_id=?',[$sid]);
        query('DELETE FROM recurring_quotations WHERE id=?',[$sid]);
        foreach ($docs as $d) { query('DELETE FROM document_shares WHERE document_id=?',[$d['document_id']]); query('DELETE FROM documents WHERE id=?',[$d['document_id']]); }
    }
}
