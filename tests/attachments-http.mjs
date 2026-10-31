// Local preview test. Never upload this file.
import assert from 'node:assert/strict';
const base='http://127.0.0.1:18083/';
function browser(){let cookie='';return async function request(path,body){const r=await fetch(new URL(path,base),{method:body?'POST':'GET',body,headers:cookie?{Cookie:cookie}:{},redirect:'manual'});const c=r.headers.get('set-cookie');if(c)cookie=c.split(';')[0];if(r.status===303)return request(r.headers.get('location'));const bytes=Buffer.from(await r.arrayBuffer());return {status:r.status,text:bytes.toString(),type:r.headers.get('content-type'),disposition:r.headers.get('content-disposition'),bytes};};}
const staff=browser(),guest=browser();
const csrf=p=>p.text.match(/name="csrf" value="([^"]+)"/)[1];
const notice=p=>(p.text.match(/role="(?:alert|status)"[^>]*>([^<]*)/)||[])[1];
const pdf=label=>Buffer.from(`%PDF-1.4\n% ${label}\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n`);
const today=new Date().toISOString().slice(0,10), due=new Date(Date.now()+14*864e5).toISOString().slice(0,10);
let page=await staff('index.php');
page=await staff('index.php',new URLSearchParams({csrf:csrf(page),action:'login',email:'test@example.com',password:'Test-password-12345'}));
page=await staff('index.php?page=new');
page=await staff('index.php?page=new',new URLSearchParams({csrf:csrf(page),action:'document',kind:'invoice',customer_id:'1',document_date:today,due_date:due,'description[]':'Professional services','quantity[]':'1','price[]':'100000',discount:'0',tax_rate:'0',notes:''}));
const id=page.text.match(/name="action" value="attach_files"><input type="hidden" name="id" value="(\d+)"/)[1];
const view=`index.php?page=view&id=${id}`;
// Payment stamp: pending, partly paid, paid.
assert.match(page.text,/<div class="stamp stamp-pending">PENDING<\/div>/);
assert.match(page.text,/Supporting documents/);
assert.doesNotMatch(page.text,/Also attach/,'No checkboxes before anything is attached');
const upload=(files,p)=>{const f=new FormData();f.set('csrf',csrf(p));f.set('action','attach_files');f.set('id',id);for(const [name,bytes] of files)f.append('attachments[]',new Blob([bytes],{type:'application/pdf'}),name);return staff(view,f);};
// Rejections.
page=await upload([['photo.pdf',Buffer.from('GIF89a not really a pdf')]],page);
assert.match(notice(page),/is not a PDF file/);
page=await upload([['huge.pdf',Buffer.concat([pdf('big'),Buffer.alloc(5*1024*1024)])]],page);
assert.match(notice(page),/larger than 5 MB|hosting upload limit/);
page=await upload([],page); assert.match(notice(page),/Choose at least one PDF/);
assert.equal((await staff(view,(()=>{const f=new FormData();f.set('action','attach_files');f.set('id',id);f.append('attachments[]',new Blob([pdf('x')]),'x.pdf');return f;})())).status,403,'CSRF required');
// Two files, one with an unsafe name.
page=await upload([['TRA EFD receipt.pdf',pdf('efd')],['../../evil<b>name>.PDF',pdf('second')]],page);
assert.match(notice(page),/Attached TRA EFD receipt\.pdf, evil-b-name\.pdf/);
assert.match(page.text,/Also attach/); assert.equal((page.text.match(/name="attach\[\]" value="[a-f0-9]{32}" checked/g)||[]).length,2);
const ids=[...page.text.matchAll(/document\.php\?id=\d+&amp;file=([a-f0-9]{32})/g)].map(m=>m[1]);
assert.equal(ids.length,2);
let file=await staff(`document.php?id=${id}&file=${ids[0]}`);
assert.equal(file.type,'application/pdf'); assert.equal(file.disposition,'attachment; filename="TRA EFD receipt.pdf"'); assert.ok(file.bytes.equals(pdf('efd')));
assert.equal((await staff(`document.php?id=${id}&file=${'0'.repeat(32)}`)).status,404);
assert.equal((await staff(`document.php?id=1&file=${ids[0]}`)).status,404,'File belongs to another document');
assert.match((await guest(`document.php?id=${id}&file=${ids[0]}`)).text,/Sign in to your workspace/);
// Email with one of the two ticked. No mail server here, so it is reported as not accepted.
page=await staff(view,new URLSearchParams([['csrf',csrf(page)],['action','email_document'],['id',id],['recipient','client@example.com'],['attach[]',ids[0]],['attach[]','../config']]));
assert.match(page.text,/id="toast">.*(Invoice INV-\d+ sent<\/strong><p>Emailed to client@example\.com with the PDF and 1 attachment|was not sent)/);
// Customer link lists and serves the attachments.
page=await staff(view,new URLSearchParams({csrf:csrf(page),action:'share_document',id}));
const url=page.text.match(/id="customer-link" readonly value="([^"]+)"/)[1].replaceAll('&amp;','&');
let landing=await guest(url);
assert.match(landing.text,/Supporting documents/); assert.match(landing.text,/TRA EFD receipt\.pdf/); assert.match(landing.text,/evil-b-name\.pdf/);
file=await guest(`${url}&file=${ids[1]}`); assert.ok(file.bytes.equals(pdf('second')));
assert.equal((await guest(`${url}&file=nothex`)).status,404);
// Limit of five per document.
page=await staff(view);
page=await upload([['a.pdf',pdf('a')],['b.pdf',pdf('b')],['c.pdf',pdf('c')],['d.pdf',pdf('d')]],page);
assert.match(notice(page),/at most 5 attachments/);
// Remove.
page=await staff(view,new URLSearchParams({csrf:csrf(page),action:'remove_attachment',id,file:ids[1]}));
assert.match(notice(page),/Removed evil-b-name\.pdf/);
assert.equal((await guest(`${url}&file=${ids[1]}`)).status,404);
page=await staff(view,new URLSearchParams({csrf:csrf(page),action:'remove_attachment',id,file:ids[1]}));
assert.match(notice(page),/already removed/);
// Partly paid, then paid: the stamp changes on the page and in the PDF.
page=await staff(view,new URLSearchParams({csrf:csrf(page),action:'payment',id,amount:'40000',payment_date:today,reference:'M-Pesa 1'}));
assert.match(page.text,/<div class="stamp stamp-part">PARTLY PAID<\/div>/);
page=await staff(view,new URLSearchParams({csrf:csrf(page),action:'payment',id,amount:'60000',payment_date:today,reference:'M-Pesa 2'}));
assert.match(page.text,/<div class="stamp stamp-paid">PAID<\/div>/);
console.log(`PASS: invoice ${id}: stamp pending/partly paid/paid, PDF-only uploads, size/count limits, CSRF, safe file names, staff and customer-link downloads, per-document isolation, email with selected attachments, removal. No email sent.`);
