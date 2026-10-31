<?php
declare(strict_types=1);
require_once __DIR__.'/company-lib.php';

const RECURRING_UNITS = ['day'=>['day','days'],'week'=>['week','weeks'],'month'=>['month','months'],'year'=>['year','years']];

function recurring_ready(): bool {
    try { query('SELECT id FROM recurring_quotations LIMIT 1'); query('SELECT id FROM recurring_runs LIMIT 1'); return true; }
    catch (PDOException $error) { return false; }
}
function recurring_label(array $s): string {
    if (empty($s['interval_unit'])) return 'One-off · next date set manually';
    $count=(int)$s['interval_count']; [$single,$plural]=RECURRING_UNITS[$s['interval_unit']];
    return $count===1 ? 'Every '.$single : 'Every '.$count.' '.$plural;
}
function recurring_status(array $s): string {
    if ($s['status']==='active') return $s['next_date']===null ? 'Needs next date' : 'Active';
    return $s['status']==='paused' ? 'Paused' : 'Ended';
}
// The n-th occurrence counted from the anchor. Months keep the anchor's day, clamped to the month's last day (31 Jan → 28/29 Feb → 31 Mar).
function recurring_occurrence(string $anchor, int $n, int $count, string $unit): string {
    $date=new DateTimeImmutable($anchor);
    if ($unit==='day' || $unit==='week') return $date->modify('+'.($n*$count*($unit==='week'?7:1)).' days')->format('Y-m-d');
    if ($unit!=='month' && $unit!=='year') throw new RuntimeException('Unknown repeat unit.');
    $month=(int)$date->format('n')-1+$n*$count*($unit==='year'?12:1);
    $year=(int)$date->format('Y')+intdiv($month,12); $month=$month%12+1;
    $last=(int)(new DateTimeImmutable(sprintf('%04d-%02d-01',$year,$month)))->format('t');
    return sprintf('%04d-%02d-%02d',$year,$month,min((int)$date->format('j'),$last));
}
// Schedule state after the current occurrence has been handled: the first occurrence later than $today.
function recurring_after(array $s, string $today): array {
    if (empty($s['interval_unit'])) return ['sequence'=>(int)$s['sequence'],'next_date'=>null,'status'=>'active'];
    $sequence=(int)$s['sequence'];
    do { $next=recurring_occurrence($s['anchor_date'],++$sequence,(int)$s['interval_count'],$s['interval_unit']); } while ($next<=$today);
    if (!empty($s['end_date']) && $next>$s['end_date']) return ['sequence'=>$sequence,'next_date'=>null,'status'=>'ended'];
    return ['sequence'=>$sequence,'next_date'=>$next,'status'=>'active'];
}
function recurring_valid_days(array $template): int {
    return min(3650,(new DateTimeImmutable($template['document_date']))->diff(new DateTimeImmutable($template['due_date']))->days);
}
// Validates the posted schedule fields and creates or replaces the schedule for a quotation or invoice. Saving restarts counting from the next send date.
function recurring_save(array $template, int $userId): array {
    $today=date('Y-m-d'); $kind=kind_name($template['kind']);
    if (one('SELECT id FROM recurring_runs WHERE document_id=?',[$template['id']])) throw new RuntimeException('This '.$kind.' was created by a recurring schedule. Change the original schedule instead.');
    if (field('next_date')==='') throw new RuntimeException('Choose the next automatic send date.');
    $next=date_input(field('next_date'));
    if ($next<$today) throw new RuntimeException('The next send date cannot be in the past.');
    if ($next<=$template['document_date']) throw new RuntimeException('The next send date must be after the document date ('.$template['document_date'].'). This '.$kind.' already covers that date.');
    $unit=field('interval_unit'); $count=null;
    if ($unit==='') $unit=null;
    elseif (!isset(RECURRING_UNITS[$unit])) throw new RuntimeException('Choose how often to repeat.');
    elseif (!preg_match('/^\d{1,3}$/D',field('interval_count')) || ($count=(int)field('interval_count'))<1 || $count>365) throw new RuntimeException('Repeat every 1 to 365 days, weeks, months or years.');
    $end=field('end_date'); $end=$end===''?null:date_input($end);
    if ($end!==null && $end<$next) throw new RuntimeException('The end date must be on or after the next send date.');
    $recipient=field('recipient');
    if ($recipient==='') $recipient=(string)(one('SELECT email FROM customers WHERE id=?',[$template['customer_id']])['email']??'');
    if (!filter_var($recipient,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter the email address the recurring '.$kind.' should be sent to.');
    $values=[$recipient,$next,$next,$count,$unit,$end,recurring_valid_days($template)];
    $existing=one('SELECT id FROM recurring_quotations WHERE template_id=?',[$template['id']]);
    if ($existing && one('SELECT id FROM recurring_runs WHERE schedule_id=? AND run_date=?',[$existing['id'],$next])) throw new RuntimeException('This schedule already sent a '.$kind.' for '.$next.'. Choose a later next send date.');
    if ($existing) query("UPDATE recurring_quotations SET recipient=?,anchor_date=?,sequence=0,next_date=?,interval_count=?,interval_unit=?,end_date=?,valid_days=?,status='active' WHERE id=?",[...$values,$existing['id']]);
    else query("INSERT INTO recurring_quotations (recipient,anchor_date,next_date,interval_count,interval_unit,end_date,valid_days,template_id,created_by) VALUES (?,?,?,?,?,?,?,?,?)",[...$values,$template['id'],$userId]);
    return one('SELECT * FROM recurring_quotations WHERE template_id=?',[$template['id']]);
}
// Schedule fields shared by the Create document form and the document page. $value(key, fallback) supplies current values.
function recurring_fields(callable $value, string $recipientHint): void { ?>
<div class="form-grid">
<label>Next automatic send date<input type="date" name="next_date" min="<?=date('Y-m-d',strtotime('+1 day'))?>" value="<?=e($value('next_date',date('Y-m-d',strtotime('+1 month'))))?>"></label>
<label>Repeat<select name="interval_unit"><option value="">Don't repeat. I'll set the next date after each send</option><?php foreach (RECURRING_UNITS as $unit=>[$single,$plural]): ?><option value="<?=$unit?>" <?=$value('interval_unit','month')===$unit?'selected':''?>>Every … <?=$plural?></option><?php endforeach ?></select></label>
<label>Every how many?<input type="number" name="interval_count" min="1" max="365" step="1" value="<?=e($value('interval_count','1'))?>"></label>
<label>End date (optional)<input type="date" name="end_date" value="<?=e($value('end_date',''))?>"></label>
<label>Send to email<input type="email" name="recipient" maxlength="190" value="<?=e($value('recipient',''))?>" placeholder="<?=e($recipientHint)?>"></label>
</div>
<p class="muted">Example: "Every … months" with 1 sends monthly on the same day; with 3 it sends quarterly. Each copy is created by the portal's daily job and emailed without review, so check the services and email first.</p>
<?php }
// Creates and emails every due quotation or invoice once. Safe to run repeatedly: each occurrence is recorded under a unique key.
function recurring_run_due(string $today, ?callable $sender=null): array {
    global $db, $config;
    $results=[];
    $ids=query("SELECT id FROM recurring_quotations WHERE status='active' AND next_date<=? ORDER BY next_date,id",[$today])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $doc=null; $share=null; $runId=null;
        try {
            $db->beginTransaction();
            $s=one("SELECT * FROM recurring_quotations WHERE id=? AND status='active' AND next_date<=? FOR UPDATE",[$id,$today]);
            if (!$s) { $db->commit(); continue; }
            $after=recurring_after($s,$today);
            if (one('SELECT id FROM recurring_runs WHERE schedule_id=? AND run_date=?',[$s['id'],$s['next_date']])) {
                query('UPDATE recurring_quotations SET sequence=?,next_date=?,status=? WHERE id=?',[$after['sequence'],$after['next_date'],$after['status'],$s['id']]);
                $db->commit(); $results[]='Schedule '.$id.': already sent for '.$s['next_date'].', skipped.'; continue;
            }
            $template=one('SELECT * FROM documents WHERE id=?',[$s['template_id']]);
            $customer=one('SELECT * FROM customers WHERE id=?',[$template['customer_id']]);
            $company=company_details();
            $due=(new DateTimeImmutable($today))->modify('+'.(int)$s['valid_days'].' days')->format('Y-m-d');
            query("INSERT INTO documents (kind,customer_id,customer_snapshot,company_snapshot,document_date,due_date,notes,items_json,subtotal,discount,tax_bps,tax,total,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",[$template['kind'],$customer['id'],json_encode($customer,JSON_THROW_ON_ERROR),json_encode($company,JSON_THROW_ON_ERROR),$today,$due,$template['notes'],$template['items_json'],$template['subtotal'],$template['discount'],$template['tax_bps'],$template['tax'],$template['total'],$s['created_by']]);
            $newId=(int)$db->lastInsertId();
            doc_no_assign($newId,$template['kind']);
            // Copies keep the original's staff assignment (when that feature is installed).
            if (array_key_exists('assigned_to',$template)) query('UPDATE documents SET assigned_to=? WHERE id=?',[$template['assigned_to'],$newId]);
            if (function_exists('doc_marks_copy')) doc_marks_copy((int)$template['id'],$newId);
            $doc=one('SELECT * FROM documents WHERE id=?',[$newId]);
            $share=create_document_share($doc,$s['recipient'],(int)$s['created_by']);
            query('INSERT INTO recurring_runs (schedule_id,run_date,document_id,share_id) VALUES (?,?,?,?)',[$s['id'],$s['next_date'],$doc['id'],$share['id']]);
            $runId=(int)$db->lastInsertId();
            query('UPDATE recurring_quotations SET sequence=?,next_date=?,status=? WHERE id=?',[$after['sequence'],$after['next_date'],$after['status'],$s['id']]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Billing Portal: recurring schedule '.$id.' failed: '.$error->getMessage());
            $results[]='Schedule '.$id.': could not create the document.'; continue;
        }
        try { $accepted=send_document_email($doc,$s['recipient'],$share['url'],document_pdf($doc),$sender); }
        catch (Throwable $error) { $accepted=false; error_log('Billing Portal: recurring email failed for '.number($doc).': '.$error->getMessage()); }
        $status=$accepted?'accepted':'failed';
        query('UPDATE document_shares SET mail_status=? WHERE id=?',[$status,$share['id']]);
        query('UPDATE recurring_runs SET mail_status=? WHERE id=?',[$status,$runId]);
        $results[]='Schedule '.$id.': '.number($doc).' '.($accepted?'emailed to '.$s['recipient']:'created, but the email was not accepted').'.';
    }
    return $results;
}
