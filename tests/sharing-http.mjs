import assert from 'node:assert/strict';
const base='http://127.0.0.1:18083/';
function browser(){let cookie='';return async function request(path,body){const r=await fetch(new URL(path,base),{method:body?'POST':'GET',body,headers:cookie?{Cookie:cookie}:{},redirect:'manual'});const c=r.headers.get('set-cookie');if(c)cookie=c.split(';')[0];if(r.status===303)return request(r.headers.get('location'));const bytes=Buffer.from(await r.arrayBuffer());return {status:r.status,text:bytes.toString(),type:r.headers.get('content-type'),disposition:r.headers.get('content-disposition'),bytes};};}
const staff=browser(),guest=browser();
const csrf=p=>p.text.match(/name="csrf" value="([^"]+)"/)[1];
let page=await staff('index.php');
page=await staff('index.php',new URLSearchParams({csrf:csrf(page),action:'login',email:'test@example.com',password:'Test-password-12345'}));
assert.match(page.text,/A clear view of your business/);
for(const id of [1,2]){
 page=await staff(`index.php?page=view&id=${id}`);assert.match(page.text,/Email PDF & link/);
 assert.match((await guest(`document.php?id=${id}`)).text,/Sign in to your workspace/);
 assert.equal((await staff(`index.php?page=view&id=${id}`,new URLSearchParams({action:'share_document',id:String(id)}))).status,403);
 page=await staff(`index.php?page=view&id=${id}`,new URLSearchParams({csrf:csrf(page),action:'share_document',id:String(id)}));
 assert.match(page.text,/Customer link created/);
 const url=page.text.match(/id="customer-link" readonly value="([^"]+)"/)[1].replaceAll('&amp;','&');
 const landing=await guest(url);assert.equal(landing.status,200);assert.match(landing.text,/Download PDF/);assert.doesNotMatch(landing.text,/name="password"/);
 const download=await guest(url+'&download=1');assert.equal(download.type,'application/pdf');assert.match(download.disposition,/attachment; filename="(QUO|INV)-/);assert.ok(download.bytes.subarray(0,5).equals(Buffer.from('%PDF-')));
 const direct=await staff(`document.php?id=${id}`);assert.equal(direct.type,'application/pdf');
 page=await staff(`index.php?page=view&id=${id}`,new URLSearchParams({csrf:csrf(page),action:'revoke_document_links',id:String(id)}));
 assert.match(page.text,/have been revoked/);assert.equal((await guest(url)).status,404);assert.equal((await guest(url+'&download=1')).status,404);
}
assert.equal((await guest('share.php?token=bad')).status,404);
console.log('PASS: staff-only PDF, CSRF, quotation/invoice public landing and PDF downloads, invalid links and revocation. No email sent.');
