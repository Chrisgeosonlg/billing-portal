<?php
// Local preview test. Never upload this file.
require __DIR__.'/../app/lib.php';
require __DIR__.'/../app/document-sharing-lib.php';
require __DIR__.'/../app/document-pdf.php';
require_once __DIR__.'/../app/attachments-lib.php';
function check(bool $condition, string $message): void { if (!$condition) throw new Exception('FAIL: '.$message); }

check(attachment_name('TRA EFD receipt.PDF')==='TRA EFD receipt.pdf','Keeps a normal name');
check(attachment_name("..\\..\\evil\"\r\nX: y.pdf")==='evil-X- y.pdf' && attachment_name('../a/b.pdf')==='b.pdf','Strips paths, quotes and line breaks');
check(attachment_name('.pdf')==='attachment.pdf','Falls back when nothing is left');
check(strlen(attachment_name(str_repeat('a',300).'.pdf'))===84,'Name length capped');
check(attachment_size(2048)==='2 KB' && attachment_size(3*1048576)==='3.0 MB','Readable sizes');

$invoice=['kind'=>'invoice','total'=>100000];
check(payment_stamp($invoice,0)===['PENDING','pending'],'Unpaid is pending');
check(payment_stamp($invoice,1)===['PARTLY PAID','part'],'Part payment');
check(payment_stamp($invoice,100000)===['PAID','paid'],'Paid in full');
check(payment_stamp(['kind'=>'quotation','total'=>100000],0)===null,'Quotations have no stamp');

$config=['mail_from'=>'office@example.com'];
$doc=['id'=>7,'kind'=>'invoice','document_date'=>'2026-10-28','due_date'=>'2026-11-11','total'=>100000,'subtotal'=>100000,'discount'=>0,'tax_bps'=>0,'tax'=>0,'notes'=>'',
      'items_json'=>json_encode([['description'=>'Professional services','quantity'=>100,'price'=>100000,'amount'=>100000]]),
      'customer_snapshot'=>json_encode(['name'=>'Client','email'=>'client@example.com','phone'=>'1','address'=>'']),
      'company_snapshot'=>json_encode(['company'=>'Example Company Limited','address'=>'Dar','email'=>'office@example.com','phone'=>'1','currency'=>'TZS','payment_details'=>'Bank'])];
$tmp=tempnam(sys_get_temp_dir(),'att'); file_put_contents($tmp,'%PDF-1.4 efd receipt');
$sent=[];
send_document_email($doc,'client@example.com','https://erp.example.com/share.php?token=x',document_pdf($doc,0),function(...$a)use(&$sent){$sent[]=$a;return true;},[['name'=>'TRA EFD receipt.pdf','path'=>$tmp]]);
[,,$body]=$sent[0];
check(substr_count($body,'Content-Type: application/pdf')===2,'Invoice and receipt both attached');
check(strpos($body,'filename="INV-000007.pdf"')<strpos($body,'filename="TRA EFD receipt.pdf"'),'Invoice comes first');
check(str_contains($body,chunk_split(base64_encode('%PDF-1.4 efd receipt'))),'Receipt bytes intact');
check(str_ends_with($body,"--\r\n") && preg_match_all('/^--bp_[a-f0-9]+--\r$/m',$body)===1,'One closing boundary');
preg_match('/base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/',$body,$m);
check(str_contains(base64_decode(str_replace("\r\n",'',$m[1])),'Also attached: TRA EFD receipt.pdf.'),'Message names the attachment');
$sent=[]; send_document_email($doc,'client@example.com','https://x',document_pdf($doc,0),function(...$a)use(&$sent){$sent[]=$a;return true;});
check(substr_count($sent[0][2],'Content-Type: application/pdf')===1 && !str_contains($sent[0][2],'Also attached'),'No attachments: unchanged email');
unlink($tmp);
// Optional: write sample PDFs for a visual check of the stamp.
if ($out=getenv('STAMP_PDF_DIR')) foreach (['pending'=>0,'part'=>40000,'paid'=>100000] as $name=>$paid) file_put_contents($out.'/stamp-'.$name.'.pdf',document_pdf($doc,$paid));
echo "PASS: file names, sizes, stamp states, email with and without supporting PDFs. No email sent.\n";
