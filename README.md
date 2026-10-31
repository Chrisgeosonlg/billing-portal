# Billing Portal

A web billing system for service businesses: customers, proformas/quotations and tax invoices, payments, recurring billing, PDF documents with stamp and signature, email and WhatsApp sharing, staff assignment and monthly reports. Plain PHP + MySQL, no Composer or Node build — it runs on ordinary shared hosting (DirectAdmin/cPanel).

Each client gets their own copy, database and config.php, so one codebase can be sold to many businesses.

## Project layout

```
billing-portal/
├── app/        ← the portal itself; upload this folder's contents to the client's subdomain
├── tests/      ← local PHP/HTTP checks (use a separate test database)
└── deploy/     ← deploy.ps1, uploads app/ to a client's host over FTPS
```

## Setting up a new client

1. Copy `app/config.example.php` to `app/config.php` and fill in the client's database, `brand`, `short_name`, `company`, address, phone, email, currency and payment details.
2. Replace the three logos in `app/images/`: `logo.png` (documents and PDFs; RGB PNG without transparency, about 1200×400), `logo-light.png` (sidebar, light artwork on a transparent background) and `logo-icon.png` (browser tab icon, square).
3. Follow the installation steps below on the client's hosting.

For a sales demo, keep the default Billing Portal logos, set `brand` to the prospect's name in a separate demo database, and add a few sample customers and documents.

# Installation (DirectAdmin)

## 1. Prepare the subdomain

In DirectAdmin, check the document root shown for `billing.example.com` under Subdomain Management. Use that exact folder in File Manager; hosting providers can configure different paths. Do not upload into the main website's folder.

Enable an SSL certificate covering `billing.example.com` and ensure the address opens using HTTPS. If DNS is managed elsewhere, the subdomain must point to your hosting server. Ask your host for its correct DNS value if needed. The portal refuses public HTTP connections.

PHP 8.3 with PDO MySQL, sessions, and a 64-bit runtime is required. These are normally available on PHP hosting; ask the host if a connection error mentions PDO. The local verification used PHP 8.2.12 and MariaDB, so verify this deployment on your PHP 8.3 account before entering real records.

## 2. Upload the application

Upload and extract `billing-portal-upload.zip` into the subdomain's document root. `index.php`, `style.css`, and `.htaccess` should be directly inside that folder, not inside another `app` folder. The ZIP contains application files only; database schema and instructions are supplied separately to keep them out of the public folder.

## 3. Import the database tables

Open phpMyAdmin, select the full portal database name you already created, choose Import, select the supplied `schema.sql`, and run the import. Use an empty database dedicated to this application. Six tables should appear: users, customers, documents, payments, password_resets, document_shares.

## 4. Configure privately

Using DirectAdmin File Manager, copy `config.example.php` to `config.php`. Update:

- `dsn`: use your full database name including the hosting prefix. Keep `localhost` unless your provider gives another database host.
- `username` and `password`: the dedicated database user's credentials.
- `setup_key`: a new random secret of at least 32 characters from a password manager. Replace the example completely.
- Company address, email, phone, currency and bank/payment details. These are copied into each new document, so complete them before issuing documents.

Keep PHP single quotes around each value. Within a value, write a literal apostrophe as `\'` and a backslash as `\\`. Do not send these credentials in chat or commit `config.php` to Git. Set private file permissions such as 600 if supported by the hosting account's PHP configuration. Do not leave backup copies of configuration files in the public folder.

## 5. Create the administrator

Visit `https://billing.example.com`. Enter your private setup key, your email and a new password of 8–72 characters. After the first account is created, public setup is disabled automatically. You can then clear `setup_key` in the configuration file. There is no public registration. Password recovery is available from the sign-in page.

## 6. Verify before real use

1. Sign out and back in. Check that customers and documents require login.
2. Add a clearly labelled test customer and update its contact details.
3. Create a test quotation with two services, a discount, and your intended tax setting. Check totals against a manual calculation.
4. Convert it to an invoice and confirm the dates, service lines and total.
5. Record a partial payment and verify the remaining balance, then record the rest.
6. Open Print / save PDF and choose Save as PDF. Disable browser headers and footers for a clean document.
7. Confirm a phone can sign in and see the same records.
8. Arrange database backups with your hosting provider and test a restore to a separate database.

Test documents consume numbers and remain in the register. For a clean launch, run acceptance testing in a separate database first, then switch the configuration to a fresh imported database and create your production administrator.

## Scope and operation

### Customer list

**Customers** shows every customer in the system: the total count, 50 per page, search by name, email, phone or address, and sorting by name, newest, oldest or highest outstanding balance. Each row shows the customer's quotation and invoice counts and unpaid balance. **Download list (CSV)** saves the list (following the current search) for Excel. Click a customer's name to open their page with contact details, recurring services, totals and all their quotations and invoices, and **Create document** for that customer. Use **+ Add a customer** at the top to add one.

### Editing and deleting (Proformas, Tax Invoices and customers)

Each row on **Customers** and **Proformas & Tax Invoices** has **View · Edit · Delete**. Proformas and Tax Invoices can be edited (customer, dates, services, prices, discount, tax, notes) until a payment is recorded; after that they show View and Delete only, so payments always match the invoice. Edits are recorded in the history; PDFs and links already sent are not updated, so send the document again.

Deleting is for the administrator only. Tick rows (or the header box) and a bar appears with **Mark all on this page**, **Delete selected**, **Delete all** (everything matching the current search or staff filter) and **Clear**. Every delete asks first. If it includes Tax Invoices with payments, or customers with documents, a second warning lists what else will be lost, and the server refuses unless it was confirmed. Deleting a customer deletes their documents and payments; deleting a document removes its payments, customer links, attached files and recurring schedule (copies already created stay). Deletion cannot be undone; download the CSV first if you may need the list. Every deletion is recorded in the activity history.

### Assigning documents to staff

On **Create document**, enter a staff member's email in **Assign to staff** (optional). Emails used before are suggested as you type, most recent first. With **Email them the details and PDF** ticked, the staff member receives the customer's contact details, dates, total and the PDF. Change or clear the assignment later under **Assigned staff** on the document page. The assignment is kept when a quotation is converted to an invoice and on recurring copies. **Quotations & invoices** can be filtered by staff member, and the monthly report shows the staff member next to each unpaid invoice.

To enable it on the hosted portal, import **assignment-schema.sql** once in phpMyAdmin (it adds one empty column to the documents table). Until then the assignment fields stay hidden.

### Importing customer lists

On Customers, choose **Import Excel / CSV**. Download the Excel template, replace the example row, and fill in Name, Email, Phone and Address. Name is required; the other fields are optional. Each contact or company becomes one customer record. Separate contact-person fields and multiple contacts per company are not included in this version.

Upload `.xlsx` or UTF-8 `.csv`, review the preview, then choose **Import customers**. Only ready rows are inserted. Blank rows are ignored. Missing names and invalid email addresses are flagged and skipped. Matching names or non-empty emails are treated as duplicates, both within the file and against existing records, and skipped without overwriting. Duplicates are checked again on confirmation. All inserts run in one database transaction. Uploads are not kept in the public folder; the preview expires with the session.

The first worksheet is read, with column headings in row 1. Use at most 1,000 data rows, 50 columns, and 2 MB per upload. Formulas are rejected: paste values before uploading. Save old `.xls` files as `.xlsx`. Format phone numbers as Text **before** entering them, to preserve leading zeros and + signs. Unknown columns are ignored; the preview shows exactly what will be imported. Use only one name column, such as Name, Customer Name, Company Name or Contact Name.

Excel import requires PHP Phar, SimpleXML and ZIP decompression support. CSV works without those Excel-specific extensions. PHP needs writable upload and temporary directories and an upload limit of at least 2 MB. No database migration is needed for this feature. For an existing deployment, upload the updated application files and templates, keeping your private `config.php`.

### Billing and accounts

- All amounts use integer hundredths with server-side calculations. Tax is applied after the discount. Default tax is 0%; set the appropriate rate for each document.
- Each type has its own running number, continuing the paper books: Proformas **PF-1128, PF-1129…** and Tax Invoices **INV-1701, INV-1702…**. The administrator can change where each continues from under **Company details** (never to a number already used). Deleted numbers are not reused, so gaps are normal. Documents created before this numbering keep their original QUO-/INV- number. The portal adds the numbering to the database by itself on first use; if the error log says it could not, import numbering-schema.sql.
- Saved documents are fixed records. This version has no draft editor, cancellation, credit notes, payment corrections or deletion. Review entries before saving. These workflows should be added before relying on this for more complex billing.
- A quotation is treated as accepted when the administrator converts it. There is no customer approval link or delivery tracking.
- One administrator account is supported in this version. Individual staff accounts and permissions are a future extension.
- Payments are manually recorded; no bank connection or online payment processing is included.
- PDF downloads and email attachments are generated on the server. Browser printing remains available.
- This is an internal billing portal. No government fiscal/e-invoicing integration is implemented.
- Customer data is shared in MySQL, not stored in browser local storage. Sessions expire after 30 minutes of inactivity. Five failed sign-in attempts lock an account for 15 minutes.
- Keep the configured currency consistent once documents have been issued; dashboard aggregates assume a single currency.
- Back up both the database and private configuration. If the administrator loses the password, use Forgot password on the sign-in page. The hosting mail service must work and the account email must be accessible.

Implementation references: [PHP password hashing](https://www.php.net/password-hash), [PHP session cookies](https://www.php.net/session-set-cookie-params), [DirectAdmin domain SSL](https://docs.directadmin.com/webservices/ssl/ssl-and-letsencrypt-for-domains.html).

## Password recovery — updating an existing deployment

1. Upload the latest application ZIP and replace application files, keeping your private `config.php`. This update signs out older sessions once; sign in again.
2. Select the existing portal database in phpMyAdmin and import `password-reset-schema.sql`. This creates one new table and preserves customers, documents and users. Do not delete existing tables.
3. In `config.php`, add `'app_url' => 'https://billing.example.com',` and `'mail_from' => 'office@example.com',` inside the returned array. Confirm the sender is an address your host permits. If omitted, the code defaults to the ERP URL and the existing company email. Do not change database credentials.
4. From the hosted login page, choose Forgot password and enter the administrator's actual sign-in email. Check the inbox and spam folder. Open the link, enter and confirm a new password, then sign in. Test the old password is rejected.

Links expire in 30 minutes and can be used once. Requests are limited to one per account every five minutes. Responses do not disclose whether an address exists. Password changes invalidate outstanding reset links and existing signed-in sessions. Reset tokens are stored only as hashes; the link uses the configured app_url, never the incoming Host header.

Email uses the hosting provider's PHP mail service. If delivery fails, ask the host to enable PHP mail for the subdomain and authorize the mail_from address. The application logs delivery-submission failures without logging links or passwords. PHP accepting a message does not guarantee inbox delivery. SMTP credentials and email delivery have not been configured or verified on the live host. The local preview displays the recovery screens but does not send real email; automated tests capture messages without contacting a mail service.

References: [OWASP password recovery](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html), [PHP mail delivery behavior](https://www.php.net/manual/en/function.mail.php).
## Email documents and share PDF download links

Open a quotation or invoice and scroll to **Send to customer**:

- **Download PDF** generates a real PDF with the client logo, saved company/customer details, service lines, totals, notes and invoice payment details. Invoices show payments recorded as of generation. Existing emailed/downloaded files do not update.
- **Email PDF & link** sends to the editable recipient shown on the form, initially taken from the saved customer email. Review it before clicking. The message contains a PDF attachment and a download link. Status distinguishes accepted by the mail service, failure, and an unconfirmed submission; acceptance is not proof of inbox delivery. Sending is limited to once per minute per document.
- **Create customer link** creates a random link valid for 30 days. Copy it or use **Share on WhatsApp** to open a prepared message, choose the recipient and send it yourself. Customers do not need a portal account. The page offers a PDF download.
- **Revoke all links** immediately disables every previously created link for that document. It does not remove previously downloaded or emailed PDFs. Creating another link does not invalidate earlier links. Tokens are hashed in the database. The most recently created link is available in the creating staff session; after signing out, create a new one if needed.

### Sent confirmation

While an email is going out the button reads **Sending…**. Afterwards a pop-up appears at the top right: green **"Invoice INV-… sent"** when the mail server accepted it (it closes itself after 12 seconds), or red **"… was not sent"** when it refused (it stays until closed). The document then shows **✓ Sent to … with the date and time** under its dates (or a red "Not sent" note), and the document register shows **✓ Sent [date]**, **Email failed** or **Not emailed yet** under each status. One successful send keeps a document marked as sent even if a later re-send fails. Emails sent by recurring schedules count too. "Sent" means the mail server accepted the message; it cannot confirm it reached the inbox.

### Supporting documents (e.g. tax or fiscal receipt)

Under **Send to customer → Supporting documents**, attach PDFs that belong with the quotation or invoice: up to 5 per document, 5 MB each and 8 MB in total (so emails are not rejected). Only real PDF files are accepted, and your host's PHP upload limit (`upload_max_filesize`, `post_max_size`) also applies.

- **Email PDF & link** lists them under **Also attach**, ticked by default. Untick any you do not want to send.
- The customer link page lists them for download too, until the link expires or is revoked.
- **Remove** deletes the file from the portal. Copies already emailed or downloaded are not affected.
- Files are stored in `private-files/` inside the portal folder (or `attachments_dir` in config.php), never in the database. The portal blocks direct web access to that folder; files are only served to signed-in staff or through a valid customer link. Include the folder in your hosting backups. The deploy script never uploads or deletes it.
- Recurring copies and quotation-to-invoice conversion do not copy attachments; attach the new receipt to each invoice.

### Company stamp and signature (optional)

Open **Stamp & signature** in the menu and upload a PNG of the company stamp and/or the signatory's signature (transparent background, cropped close, up to 1 MB; interlaced or 16-bit PNGs are rejected with a message to re-save them). Optionally enter the signatory's name and title to print under the signature.

Nothing is added automatically. On each quotation or invoice, under **Send to customer → Stamp & signature**, tick **Add company stamp**, **Add signature** or both (the choice saves immediately). The document then shows them at the bottom on screen and when printed, and in Download PDF, emailed PDFs and customer links. A quotation's choice carries over when it is converted to an invoice and to recurring copies. Removing an image on the settings page removes it from every document's future PDFs; PDFs already sent are unchanged.

The images are stored in `private-files/marks/` and shown only to signed-in staff. Every signed-in user can apply them, so give portal accounts only to people allowed to sign on the company's behalf.

### Paid / unpaid tag

Every Tax Invoice shows its payment status as a faded watermark-style label, right-aligned above the TAX INVOICE heading, on screen, when printed and in the PDF: **PAID** (green) when payments cover the total, **PARTLY PAID** (amber) after a part payment, otherwise **UNPAID** (red). Proformas have no tag. The PDF uses the payments recorded when it is generated, so an emailed PDF does not change later; send it again after recording payment if the customer needs the PAID copy.

### Company details

The administrator can change the company name, email, phone, address and bank/payment details under **Company details** in the portal. New Proformas and Tax Invoices use them from the moment they are saved; tick **Also update the documents already created** to rewrite them on earlier documents as well (amounts and payments are not touched). The values in config.php are only the starting point: once saved in the portal, the portal's copy (private-files/company/details.json) is used. The currency stays in config.php.

### Users and activity

The first account is the **administrator**. Under **Users & activity** (shown only to the administrator) it can add up to 3 staff users; set `'max_users' => 3` in config.php to change the limit. Enter the person's name and email; they receive an email with a link to create their own password (valid 3 days, works once). If the email cannot be sent, the page shows the link to pass on by hand. **Forgot password?** on the sign-in page works for every user.

- **Pause** signs the user out on their next click and blocks sign-in and password reset until **Resume**.
- **Remove** frees their place. The account is kept, not deleted, so documents, payments and history still show their name. The same email can be added again later.
- Staff can do all daily work (customers, documents, payments, sending, recurring, reports). Only the administrator manages users.
- The **Activity** list records who signed in and who added or changed customers, created, converted, emailed or assigned documents, recorded payments, attached files and changed settings. Filter it by user. Each document also has a **History** panel, the document list shows who created each document, and each payment shows who recorded it.
- Each person can set the name shown in the records under **Your account**.

To enable it on the hosted portal, import **users-schema.sql** once in phpMyAdmin. It adds the user columns and the activity_log table, and makes your existing account the administrator. Activity is recorded from that point on; documents created earlier still show who created them.

### Update an existing hosted portal

1. Upload the latest `billing-portal-upload.zip`, extract it into the portal document root and replace application files, including the new vendor directory. Keep the existing private `config.php`.
2. In phpMyAdmin, select your portal database and import **document-sharing-schema.sql**. This adds the document_shares table without modifying customer, invoice or payment records. Fresh installations use the full schema.sql instead.
3. Confirm `app_url` is `https://billing.example.com` and `mail_from` is an address your host permits, for example the existing company email. These values can be added inside config.php's returned array. Existing installations default to that URL and the company email when omitted. Do not put the local preview address in your live configuration.
4. Confirm PHP mbstring and zlib are enabled. The bundled tFPDF library and DejaVu fonts require no Composer install or writable application directory. Font coverage includes Latin text and common symbols; full emoji/CJK shaping is not supported.
5. Generate a link for a test quotation and open it in a signed-out browser. Download and review the PDF. Revoke its links and verify the old address stops working. Repeat for an invoice.
6. Send a test document to an inbox you control. Check its PDF attachment and link, plus the recipient and totals. Live email delivery has not been verified from this workspace. If submission or delivery fails, your host must check PHP mail, authorized sender and spam filtering. Sharing a link works independently of email delivery.

Local preview links use localhost and are usable only on this computer. A customer needs a link created by the hosted portal. Shared pages send no-referrer/noindex/no-store headers. Public link access returns only the linked document, not the staff portal. Anyone possessing a valid link can access that document until it expires or is revoked.

PDF dependency source and licenses are in vendor/tfpdf. See [tFPDF documentation](https://www.fpdf.org/en/script/script92.php).

## Recurring services (quotations and invoices)

Two ways to set one up:

- **When creating a document:** tick **This is a recurring service** on Create document and fill in the dates. The document and its schedule are saved together. The document you save is the first one; the next send date must be after its date.
- **On an existing quotation or invoice:** open it and choose **Make this a recurring service** under **Recurring service**.

The fields:

- **Next automatic send date**: the day the next copy goes out.
- **Repeat**: every N days, weeks, months or years, or **Don't repeat**. With Don't repeat, one quotation is sent and the schedule then shows **Needs next date** until you choose another date.
- **End date** (optional): no quotations are sent after it.
- **Send to email**: leave blank to use the customer's email.

A recurring invoice creates a new invoice (new INV number), due the same number of days after its date as the original. Everything below about quotations applies to invoices in the same way.

On each send date the portal creates a **new** quotation (new QUO number) with the original quotation's services, prices, discount, tax and notes. It is dated that day, valid for the same number of days as the original, and uses the current customer and company details. It then emails the PDF and a 30-day download link automatically, without review. To change prices or services, create a new quotation and set up a schedule on it, then stop the old schedule.

Monthly schedules keep the same day of the month. A schedule starting on the 31st sends on the last day of shorter months. If the daily job does not run for a few days, each schedule sends one quotation on the next run and skips the missed dates; it does not send a backlog. Dates passed while a schedule is paused are skipped on resume. Saving a schedule restarts it from the chosen next send date.

**Recurring** in the menu lists all schedules with pause, resume and status. The Overview page shows schedules due in the next 14 days and any automatic emails that failed in the last 30 days; open a failed quotation and send it manually from **Send to customer**.

### Enable on the hosted portal

1. Upload the updated application files, keeping your private `config.php`. The new files are `recurring-lib.php`, `recurring-panel.php`, `recurring-page.php` and `cron.php`; `.htaccess`, `index.php` and `document-sharing-lib.php` changed.
2. In phpMyAdmin, select the portal database and import **recurring-schema.sql**. It adds two tables and changes no existing records. Import `document-sharing-schema.sql` first if you have not already.
3. In DirectAdmin, open **Cron Jobs** and add a daily job, for example minute `0`, hour `8`, every day/month/weekday, with the command:

   `/usr/local/bin/php /home/USERNAME/domains/example.com/public_html/billing/cron.php`

   Replace the path with the real portal document root from File Manager. If your account runs several PHP versions, ask your host for the PHP 8.3 command-line path. Cron hours use the server's clock; the portal decides which quotations are due using Dar es Salaam time. Running the job more than once a day is safe: each scheduled date is sent once.
4. Test: set up a schedule on a test quotation addressed to an inbox you control, with today's date as the next send date. Run the cron command once (DirectAdmin can email the job output), check the new quotation appears, the email arrives with the PDF, and the schedule's next date has moved forward. Then stop the test schedule.

`cron.php` only runs from the command line; web requests are refused. Live email delivery depends on the host's PHP mail service, as described above.

## Monthly report

**Reports** in the menu: register up to 5 email addresses, preview the report for any month, and send this month's report immediately.

The same daily job (`cron.php`) emails the report automatically on the **25th** (the **23rd** in February) and on the **last day** of each month. The 25th/23rd report covers the 1st to that day; the month-end report covers the whole month. If the job misses a report day, it sends on the next run within 3 days. Each report day is sent once; a report the mail service refused is retried on the next run.

The report shows:

- quotations issued, how many turned into invoices, and invoices issued;
- invoices issued this month paid in full and not paid in full, and payments received;
- **Pending invoices · follow up**: every invoice with a balance (any month), most overdue first, with the customer's phone and email and a suggested action. Overdue rows are highlighted red;
- **Paid invoices**: invoices that became paid in full this month, marked green;
- this month's quotations: invoiced, awaiting the customer, or expired.

To enable it on the hosted portal: upload the updated files, import **reports-schema.sql** in phpMyAdmin (two new tables; no existing records change), then open **Reports** and save the email addresses. The daily cron job above must be set up.

## Deploying updates from this PC

`erp-deploy\deploy.ps1` (next to the `app` folder, never uploaded itself) sends changed app files to the hosted portal over encrypted FTP (FTPS). It never uploads `config.php`, `.sql` files or this README, and never deletes anything on the server.

**One-time setup**

1. In DirectAdmin, open **FTP Management** and create an FTP account just for deploys, for example `billingdeploy`, with its folder set to the portal document root (e.g. `domains/example.com/public_html/billing`) and a long random password.
2. In PowerShell, in the billing-portal folder, run `.\erp-deploy\deploy.ps1 -Setup` and enter the FTP server (usually `ftp.example.com`), the full FTP username (e.g. `billingdeploy@example.com`), the password, and the ERP folder: leave it blank when the account opens directly in the ERP folder. It tests the connection and checks that `index.php` is there.

The login is encrypted with Windows DPAPI for your Windows user on this PC and saved in `%LOCALAPPDATA%\billing-portal-deploy`, outside the project, OneDrive and Git. Run `-Setup` again to change it.

**Each update**

- `.\erp-deploy\deploy.ps1 -DryRun` lists what would be uploaded.
- `.\erp-deploy\deploy.ps1` uploads only files that changed since the last deploy, entry pages last, then checks the portal answers.
- `.\erp-deploy\deploy.ps1 -All` uploads everything again, for example after files were changed on the server by hand.

Database changes are never applied automatically: when a `.sql` file changes, the script reminds you to import it in phpMyAdmin. If a file fails, run the deploy again; only unfinished files are retried.

Connection problems: a certificate error usually means the host's FTP certificate is issued to the server's own name; use that server name (DirectAdmin shows it, or ask the host) instead of `ftp.example.com`. If the error mentions a revocation check (`CRYPT_E_NO_REVOCATION_CHECK`), set `"sslNoRevoke": true` in `%LOCALAPPDATA%\billing-portal-deploy\settings.json`. For a host that uses its own certificate, put its PEM file path in `"caCert"`. Never switch to plain, unencrypted FTP.
