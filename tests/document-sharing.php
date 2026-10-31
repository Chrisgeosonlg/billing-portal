<?php
require __DIR__.'/../app/lib.php';
require __DIR__.'/../app/document-sharing-lib.php';
require __DIR__.'/../app/document-pdf.php';
$config=require $argv[1].'/app/config.php';
if (!str_contains($config['dsn'],'host=127.0.0.1;port=13367;dbname=billing_portal_test')) throw new RuntimeException('Not local preview');
$config['app_url']='https://billing.example.com';
$db=new PDO($config['dsn'],$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function query(string $sql,array $params=[]):PDOStatement {global $db;$s=$db->prepare($sql);$s->execute($params);return $s;}
function one(string $sql,array $params=[]):array{return query($sql,$params)->fetch()?:[];}
function sharing_check(bool $condition,string $message):void {if(!$condition)throw new Exception($message);}
$_SESSION['user_id']=1;
$doc=one("SELECT * FROM documents WHERE kind='invoice' ORDER BY id LIMIT 1");
if(!$doc)throw new RuntimeException('A local sample invoice is required');
$db->beginTransaction();
try {
    $share=create_document_share($doc);
    sharing_check((int)shared_document($share['token'])['id']===(int)$doc['id'],'Valid share rejected');
    sharing_check(!shared_document('bad'),'Bad token accepted');
    sharing_check(one('SELECT token_hash FROM document_shares WHERE id=?',[$share['id']])['token_hash']===hash('sha256',$share['token']),'Token is not hashed');
    $pdf=document_pdf($doc,100);
    sharing_check(str_starts_with($pdf,'%PDF-') && str_contains($pdf,'%%EOF'),'Invalid PDF');
    file_put_contents($argv[1].'/sample-invoice.pdf',$pdf);
    $captured=[];
    $sent=send_document_email($doc,'customer@example.com',$share['url'],$pdf,function(...$args)use(&$captured){$captured=$args;return true;});
    sharing_check($sent && $captured[0]==='customer@example.com','Wrong recipient');
    sharing_check(str_contains($captured[2],chunk_split(base64_encode($pdf))),'Attachment bytes differ');
    sharing_check(str_contains($captured[2],'filename="'.number($doc).'.pdf"'),'Missing attachment name');
    sharing_check(!send_document_email($doc,'customer@example.com',$share['url'],$pdf,fn()=>false),'Mail failure not surfaced');
    try {send_document_email($doc,"a@example.com\r\nBcc: b@example.com",$share['url'],$pdf,fn()=>true);throw new Exception('Header injection accepted');} catch(RuntimeException $expected){}
    query('UPDATE document_shares SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=?',[$share['id']]);
    sharing_check(!shared_document($share['token']),'Expired link accepted');
    query('UPDATE document_shares SET expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),revoked_at=UTC_TIMESTAMP() WHERE id=?',[$share['id']]);
    sharing_check(!shared_document($share['token']),'Revoked link accepted');
    $long=$doc;$long['items_json']=json_encode(array_fill(0,30,['description'=>str_repeat('Long service description ',12),'quantity'=>100,'price'=>1000,'amount'=>1000])); $long['notes']=str_repeat("Additional terms and conditions.\n",100);
    $longPdf=document_pdf($long);
    sharing_check(preg_match_all('~/Type /Page\b~',$longPdf)>1,'Long invoice has no page breaks');
    file_put_contents($argv[1].'/sample-multipage.pdf',$longPdf);
    echo "PASS: hashed public links, expiry, revocation, PDF generation and pagination, MIME attachment, recipient validation and mail failure. No email sent.\n";
} finally {$db->rollBack();}
