<?php
// Local preview test. Never upload this file.
require __DIR__.'/../app/lib.php';
require __DIR__.'/../app/document-pdf.php';
require __DIR__.'/../app/assignment-lib.php';
date_default_timezone_set('Africa/Dar_es_Salaam');
function check(bool $condition, string $message): void { if (!$condition) throw new Exception('FAIL: '.$message); }

check(assignment_email('  Staff@Example.com ')==='staff@example.com','Email trimmed and lower-cased');
check(assignment_email('')===null,'Blank means unassigned');
foreach (['not-an-email',"a@example.com\r\nBcc: x@example.com",'a@example.com,b@example.com',str_repeat('a',190).'@example.com'] as $bad) {
    try { assignment_email($bad); check(false,'Accepted '.$bad); } catch (RuntimeException $ex) {}
}
$config=['mail_from'=>'office@example.com','currency'=>'TZS'];
$doc=['id'=>42,'kind'=>'invoice','document_date'=>'2026-10-28','due_date'=>'2026-11-11','total'=>8500000,'subtotal'=>8500000,'discount'=>0,'tax_bps'=>0,'tax'=>0,'notes'=>'',
      'items_json'=>json_encode([['description'=>'Professional services','quantity'=>100,'price'=>8500000,'amount'=>8500000]]),
      'customer_snapshot'=>json_encode(['name'=>"Azam\r\nBcc: evil@example.com",'email'=>'client@example.com','phone'=>'+255 700 000 000','address'=>'Mlimani City']),
      'company_snapshot'=>json_encode(['company'=>'Example Company Limited','address'=>'Dar','email'=>'office@example.com','phone'=>'1','currency'=>'TZS','payment_details'=>'Bank'])];
$sent=[];
$ok=assignment_notify($doc,'staff@example.com','admin@example.com',document_pdf($doc,0),function(...$args)use(&$sent){$sent[]=$args;return true;});
[$to,$subject,$body,$headers]=$sent[0];
check($ok && $to==='staff@example.com','Sent to the staff member');
check(!preg_match('/[\r\n]/',$subject) && str_contains($subject,'Invoice INV-000042 assigned to you'),'Subject has no line breaks');
check(str_contains($body,'filename="INV-000042.pdf"') && str_contains($headers['Content-Type'],'multipart/mixed'),'PDF attached');
preg_match('/base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/',$body,$m); $text=base64_decode(str_replace("\r\n",'',$m[1]));
check(str_contains($text,'admin@example.com has assigned you invoice INV-000042') && str_contains($text,'+255 700 000 000') && str_contains($text,'TZS 85,000.00') && str_contains($text,'Payment due: 2026-11-11'),'Details in the message');
try { assignment_notify($doc,"x@example.com\r\nBcc: y@example.com",'',  'pdf',fn()=>true); check(false,'Header injection accepted'); } catch (RuntimeException $ex) {}
echo "PASS: staff email validation, notification recipient, subject, details, PDF attachment and header-injection protection. No email sent.\n";
