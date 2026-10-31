// Local preview test. Never upload this file. Usage: node marks-http.mjs <folder with signature.png, stamp.png, stamp.jpg, interlaced.png>
import assert from 'node:assert/strict';
import fs from 'node:fs';
const images=process.argv[2]; assert.ok(images,'Pass the test image folder');
const base='http://127.0.0.1:18083/';
function browser(){let cookie='';return async function request(path,body){const r=await fetch(new URL(path,base),{method:body?'POST':'GET',body,headers:cookie?{Cookie:cookie}:{},redirect:'manual'});const c=r.headers.get('set-cookie');if(c)cookie=c.split(';')[0];if(r.status===303)return request(r.headers.get('location'));const bytes=Buffer.from(await r.arrayBuffer());return {status:r.status,text:bytes.toString('latin1'),type:r.headers.get('content-type'),bytes};};}
const staff=browser(),guest=browser();
const csrf=p=>p.text.match(/name="csrf" value="([^"]+)"/)[1];
const notice=p=>(p.text.match(/role="(?:alert|status)"[^>]*>([^<]*)/)||[])[1];
const images_in=bytes=>(bytes.toString('latin1').match(/\/Subtype \/Image/g)||[]).length;
const today=new Date().toISOString().slice(0,10), due=new Date(Date.now()+14*864e5).toISOString().slice(0,10);
let page=await staff('index.php');
page=await staff('index.php',new URLSearchParams({csrf:csrf(page),action:'login',email:'test@example.com',password:'Test-password-12345'}));
// Start clean so the test can be repeated.
page=await staff('index.php?page=marks');
for (const type of ['stamp','signature']) page=await staff('index.php?page=marks',new URLSearchParams({csrf:csrf(page),action:'mark_remove',type}));
assert.match(page.text,/href="index\.php\?page=marks">Stamp &amp; signature<\/a>/,'Menu item');
assert.equal((page.text.match(/Not uploaded/g)||[]).length,2);
const upload=(type,file,p,mime='image/png')=>{const f=new FormData();f.set('csrf',csrf(p));f.set('action','mark_upload');f.set('type',type);f.set('image',new Blob([fs.readFileSync(`${images}/${file}`)],{type:mime}),file);return staff('index.php?page=marks',f);};
// A document before any image exists only points to the settings page.
page=await staff('index.php?page=new');
page=await staff('index.php?page=new',new URLSearchParams({csrf:csrf(page),action:'document',kind:'invoice',customer_id:'1',document_date:today,due_date:due,'description[]':'Professional services','quantity[]':'1','price[]':'100000',discount:'0',tax_rate:'0',notes:''}));
const id=page.text.match(/name="action" value="attach_files"><input type="hidden" name="id" value="(\d+)"/)[1], view=`index.php?page=view&id=${id}`;
assert.match(page.text,/Upload your company stamp or signature under/); assert.doesNotMatch(page.text,/name="marks\[\]"/);
const plain=images_in((await staff(`document.php?id=${id}`)).bytes);
// Rejections.
page=await staff('index.php?page=marks');
page=await upload('stamp','stamp.jpg',page,'image/jpeg'); assert.match(notice(page),/Upload a PNG image/);
page=await upload('signature','interlaced.png',page); assert.match(notice(page),/cannot be placed in a PDF \(Interlacing not supported\)/);
page=await upload('logo','stamp.png',page); assert.match(notice(page),/Choose the stamp or the signature/);
assert.equal((await staff('index.php?page=marks',(()=>{const f=new FormData();f.set('action','mark_upload');f.set('type','stamp');return f;})())).status,403,'CSRF required');
// Upload both and the signatory.
page=await upload('stamp','stamp.png',page); assert.match(notice(page),/Company stamp saved/);
page=await upload('signature','signature.png',page); assert.match(notice(page),/Signature saved/);
assert.match(page.text,/<img src="mark\.php\?type=stamp&amp;v=\d+"/); assert.match(page.text,/<img src="mark\.php\?type=signature&amp;v=\d+"/);
page=await staff('index.php?page=marks',new URLSearchParams({csrf:csrf(page),action:'signatory_save',signatory_name:'Jane Mushi <b>',signatory_title:'Managing Director'}));
assert.match(notice(page),/Signatory details saved/); assert.match(page.text,/value="Jane Mushi &lt;b&gt;"/);
let image=await staff('mark.php?type=stamp'); assert.equal(image.type,'image/png'); assert.ok(image.bytes.equals(fs.readFileSync(`${images}/stamp.png`)));
assert.equal((await guest('mark.php?type=stamp')).status,404,'Images are private');
assert.equal((await staff('mark.php?type=../config')).status,404);
assert.equal((await guest('marks-page.php')).status,404);
// Nothing is added until chosen on the document.
page=await staff(view);
assert.equal((page.text.match(/name="marks\[\]" value="(stamp|signature)" >/g)||[]).length,2,'Both offered, unticked');
assert.doesNotMatch(page.text,/class="doc-marks"/);
assert.equal(images_in((await staff(`document.php?id=${id}`)).bytes),plain);
// Choose both.
page=await staff(view,new URLSearchParams([['csrf',csrf(page)],['action','doc_marks'],['id',id],['marks[]','stamp'],['marks[]','signature'],['marks[]','logo']]));
assert.match(notice(page),/now shows the company stamp and signature/);
assert.match(page.text,/class="doc-marks"/); assert.match(page.text,/<strong>Jane Mushi &lt;b&gt;<\/strong><span>Managing Director<\/span>/); assert.match(page.text,/class="doc-stamp"/);
const both=images_in((await staff(`document.php?id=${id}`)).bytes); assert.ok(both>plain,'PDF has the images');
page=await staff(view,new URLSearchParams({csrf:csrf(page),action:'share_document',id}));
const url=page.text.match(/id="customer-link" readonly value="([^"]+)"/)[1].replaceAll('&amp;','&');
assert.equal(images_in((await guest(url+'&download=1')).bytes),both,'Customer link PDF matches');
// Stamp only.
page=await staff(view,new URLSearchParams([['csrf',csrf(page)],['action','doc_marks'],['id',id],['marks[]','stamp']]));
assert.match(notice(page),/now shows the company stamp on/); assert.doesNotMatch(page.text,/class="doc-signature"/); assert.match(page.text,/class="doc-stamp"/);
const stampOnly=images_in((await staff(`document.php?id=${id}`)).bytes); assert.ok(stampOnly>plain && stampOnly<both);
// Removing the image everywhere stops it showing.
page=await staff('index.php?page=marks');
page=await staff('index.php?page=marks',new URLSearchParams({csrf:csrf(page),action:'mark_remove',type:'stamp'}));
page=await staff(view); assert.doesNotMatch(page.text,/class="doc-marks"/); assert.equal(images_in((await staff(`document.php?id=${id}`)).bytes),plain);
page=await upload('stamp','stamp.png',await staff('index.php?page=marks'),'image/png');
// Untick all.
page=await staff(view); page=await staff(view,new URLSearchParams({csrf:csrf(page),action:'doc_marks',id}));
assert.match(notice(page),/no longer shows a stamp or signature/);
// A quotation's choice carries over to its invoice.
page=await staff('index.php?page=new');
page=await staff('index.php?page=new',new URLSearchParams({csrf:csrf(page),action:'document',kind:'quotation',customer_id:'1',document_date:today,due_date:due,'description[]':'Consultation','quantity[]':'1','price[]':'50000',discount:'0',tax_rate:'0',notes:''}));
const quote=page.text.match(/name="action" value="attach_files"><input type="hidden" name="id" value="(\d+)"/)[1];
assert.doesNotMatch(page.text,/class="stamp /,'Quotations have no paid stamp');
page=await staff(`index.php?page=view&id=${quote}`,new URLSearchParams([['csrf',csrf(page)],['action','doc_marks'],['id',quote],['marks[]','signature']]));
page=await staff(`index.php?page=view&id=${quote}`,new URLSearchParams({csrf:csrf(page),action:'convert',id:quote,due_date:due}));
assert.match(page.text,/INV-/); assert.match(page.text,/class="doc-signature"/); assert.match(page.text,/name="marks\[\]" value="signature" checked/);
console.log(`PASS: menu page, PNG-only/interlaced/type/CSRF checks, private image URLs, signatory (escaped), per-document choice (none/both/stamp only) on screen, PDF and customer link, removing an image, quotation-to-invoice carry-over. Invoice ${id}.`);
