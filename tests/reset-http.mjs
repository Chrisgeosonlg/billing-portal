import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import assert from 'node:assert/strict';
const preview=readFileSync(join(process.env.TEMP,'billing-portal-test-path.txt'),'utf8').trim();
const php='C:/xampp/php/php.exe';
const fixture=JSON.parse(readFileSync(process.argv[2],'utf8').replace(/^\uFEFF/,''));
const base='http://127.0.0.1:18083/';
function browser(){
 let cookie='';
 return async function request(path,body){
  const r=await fetch(new URL(path,base),{method:body?'POST':'GET',body,headers:cookie?{Cookie:cookie}:{},redirect:'manual'});
  const c=r.headers.get('set-cookie');if(c)cookie=c.split(';')[0];
  if(r.status===303)return request(r.headers.get('location'));
  return {status:r.status,text:await r.text()};
 };
}
const token=p=>p.text.match(/name="csrf" value="([^"]+)"/)[1];
try {
 const old=browser(),reset=browser();
 let p=await old('index.php');assert.match(p.text,/Forgot password\?/);
 p=await old('index.php',new URLSearchParams({csrf:token(p),action:'login',email:'test@example.com',password:'Test-password-12345'}));
 assert.match(p.text,/A clear view of your business/);
 p=await reset('reset.php?token='+fixture.token);assert.match(p.text,/Confirm new password/);
 assert.ok(!p.text.includes(fixture.token),'Secret token leaked into form');
 assert.equal((await reset('reset.php?mode=reset',new URLSearchParams({password:'Temporary-password-123',confirmation:'Temporary-password-123'}))).status,403);
 p=await reset('reset.php?mode=reset',new URLSearchParams({csrf:token(p),password:'Temporary-password-123',confirmation:'Temporary-password-123'}));
 assert.match(p.text,/Your password has been reset/);
 assert.match((await old('index.php')).text,/Sign in to your workspace/);
 p=await reset('index.php',new URLSearchParams({csrf:token(p),action:'login',email:'test@example.com',password:'Temporary-password-123'}));
 assert.match(p.text,/A clear view of your business/);
 assert.match((await browser()('reset.php?token='+fixture.token)).text,/invalid or has expired/);
 console.log('PASS: login link, token exchange, CSRF, password-reset form, success notice, old-session revocation, new login and link replay rejection. No email sent.');
} finally { /* The PowerShell runner restores the local fixture. */ }
