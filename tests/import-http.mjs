import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
const base = 'http://127.0.0.1:18083/';
let cookie = '';
async function request(path, body) {
  const response = await fetch(new URL(path, base), { method: body ? 'POST' : 'GET', body, headers: cookie ? { Cookie: cookie } : {}, redirect: 'manual' });
  const setCookie = response.headers.get('set-cookie');
  if (setCookie) cookie = setCookie.split(';')[0];
  if (response.status === 303) return request(response.headers.get('location'));
  return { status: response.status, text: await response.text() };
}
function token(page) { return page.text.match(/name="csrf" value="([^"]+)"/)[1]; }
let page = await request('index.php?page=import');
assert.match(page.text, /Sign in to your workspace/);
page = await request('index.php', new URLSearchParams({ csrf: token(page), action: 'login', email: 'test@example.com', password: 'Test-password-12345' }));
assert.match(page.text, /A clear view of your business/);
page = await request('index.php?page=customers');
assert.match(page.text, /Import Excel \/ CSV/);
const marker = `Import Test ${Date.now()}`;
const csv = `Name,Email,Phone,Address\n${marker},,+255700000099,"Test address"\n${marker},,0712345678,Duplicate\nInvalid customer,broken-email,,\n`;
async function upload(content, filename) {
  const p = await request('index.php?page=import');
  const body = new FormData();
  body.set('csrf', token(p)); body.set('action', 'import_preview');
  body.set('spreadsheet', new Blob([content]), filename);
  return request('index.php?page=import', body);
}
page = await upload(csv, 'customers.csv');
assert.match(page.text, /Import 1 customers/);
assert.match(page.text, /Invalid email/);
assert.match(page.text, /Duplicate/);
// The preview must not create records.
assert.doesNotMatch((await request(`index.php?page=customers&q=${encodeURIComponent(marker)}`)).text, /<strong>Import Test/);
const confirm = new URLSearchParams({ csrf: token(page), action: 'import_confirm', import_token: page.text.match(/name="import_token" value="([^"]+)"/)[1] });
page = await request('index.php?page=import', confirm);
assert.match(page.text, /1 added, 1 duplicates skipped, 1 invalid rows skipped/);
assert.match(page.text, /\+255700000099/);
assert.equal((await request('index.php?page=import', confirm)).status, 403, 'Repeat confirmation rejected');
page = await upload(csv, 'again.csv');
assert.doesNotMatch(page.text, /Import 1 customers/);
assert.match(page.text, /<strong>2<\/strong> duplicates/);
page = await upload(readFileSync(new URL('../app/customer-import-template.xlsx', import.meta.url)), 'customers.xlsx');
assert.match(page.text, /Example Customer/);
assert.match(page.text, /\+255700000000/);
page = await request('index.php?page=import', new URLSearchParams({ csrf: token(page), action: 'import_cancel' }));
assert.doesNotMatch(page.text, /Review before importing/);
page = await upload(csv, 'old.xls');
assert.match(page.text, /Choose an .xlsx or .csv file/);
console.log(`PASS: authenticated upload, preview without writes, CSV confirmation, duplicate skipping, invalid rows, repeat-submit rejection, XLSX upload, cancel and unsupported extension. Test customer: ${marker}`);
