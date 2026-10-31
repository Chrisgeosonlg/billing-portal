// Local preview test. Never upload this file.
// Needs a local SMTP sink on 127.0.0.1:25 that accepts mail and refuses recipients containing "reject@".
import assert from 'node:assert/strict';
const base='http://127.0.0.1:18083/';
function browser(){let cookie='';return async function request(path,body){const r=await fetch(new URL(path,base),{method:body?'POST':'GET',body,headers:cookie?{Cookie:cookie}:{},redirect:'manual'});const c=r.headers.get('set-cookie');if(c)cookie=c.split(';')[0];if(r.status===303)return request(r.headers.get('location'));return {status:r.status,text:await r.text()};};}
const staff=browser();
const csrf=p=>p.text.match(/name="csrf" value="([^"]+)"/)[1];
const today=new Date().toISOString().slice(0,10), due=new Date(Date.now()+14*864e5).toISOString().slice(0,10);
const row=(p,id)=>p.text.match(new RegExp(`page=view&id=${id}">[^<]+</a>[\\s\\S]*?</tr>`))[0];
let page=await staff('index.php');
page=await staff('index.php',new URLSearchParams({csrf:csrf(page),action:'login',email:'test@example.com',password:'Test-password-12345'}));
const create=async()=>{let p=await staff('index.php?page=new');p=await staff('index.php?page=new',new URLSearchParams({csrf:csrf(p),action:'document',kind:'invoice',customer_id:'1',document_date:today,due_date:due,'description[]':'Professional services','quantity[]':'1','price[]':'100000',discount:'0',tax_rate:'0',notes:''}));return [p,p.text.match(/name="action" value="email_document"><input type="hidden" name="id" value="(\d+)"/)[1]];};
// Before sending.
let [view,id]=await create();
assert.doesNotMatch(view.text,/class="sent-mark/); assert.doesNotMatch(view.text,/id="toast"/);
assert.match(row(await staff('index.php?page=documents'),id),/Not emailed yet/);
// Successful send: green pop-up, sent mark, list shows it.
page=await staff(`index.php?page=view&id=${id}`,new URLSearchParams({csrf:csrf(view),action:'email_document',id,recipient:'client@example.com'}));
const number=`INV-${id.padStart(6,'0')}`;
assert.match(page.text,new RegExp(`<div class="toast toast-success" role="status" id="toast">.*<strong>Invoice ${number} sent</strong><p>Emailed to client@example\\.com with the PDF and a download link\\.`));
assert.match(page.text,/<p class="sent-mark no-print sent-ok">✓ Sent to client@example\.com<small>\d{1,2} \w{3} \d{4}, \d\d:\d\d<\/small><\/p>/);
assert.match(row(await staff('index.php?page=documents'),id),/<small class="sent-ok">✓ Sent \d{1,2} \w{3}<\/small>/);
// The pop-up shows once only.
page=await staff(`index.php?page=view&id=${id}`); assert.doesNotMatch(page.text,/id="toast"/); assert.match(page.text,/sent-ok">✓ Sent to/);
// Failed send: red pop-up that stays, marked as not sent.
[view,id]=await create();
page=await staff(`index.php?page=view&id=${id}`,new URLSearchParams({csrf:csrf(view),action:'email_document',id,recipient:'reject@example.com'}));
assert.match(page.text,/<div class="toast toast-error" role="alert" id="toast">.*<strong>Invoice INV-\d+ was not sent<\/strong><p>The mail server did not accept the email to reject@example\.com\./);
assert.match(page.text,/sent-failed">Not sent: the email to reject@example\.com failed/);
assert.match(row(await staff('index.php?page=documents'),id),/<small class="sent-failed">Email failed<\/small>/);
// A successful send stays "Sent" even if a later attempt fails (the one-minute limit applies, so wait).
console.log('waiting 61 s for the per-document email limit…');
await new Promise(r=>setTimeout(r,61000));
page=await staff(`index.php?page=view&id=${id}`);
page=await staff(`index.php?page=view&id=${id}`,new URLSearchParams({csrf:csrf(page),action:'email_document',id,recipient:'accounts@example.com'}));
assert.match(page.text,/toast-success/); assert.match(page.text,/✓ Sent to accounts@example\.com/);
await new Promise(r=>setTimeout(r,61000));
page=await staff(`index.php?page=view&id=${id}`,new URLSearchParams({csrf:csrf(page),action:'email_document',id,recipient:'reject@example.com'}));
assert.match(page.text,/toast-error/); assert.match(page.text,/sent-ok">✓ Sent to accounts@example\.com/,'Earlier success still shown');
assert.match(row(await staff('index.php?page=documents'),id),/✓ Sent/);
console.log('PASS: not-emailed state, success pop-up (once), sent mark with time, register status, failure pop-up and mark, earlier success kept after a later failure.');
