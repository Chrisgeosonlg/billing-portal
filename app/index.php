<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/import-lib.php';
require __DIR__ . '/document-sharing-lib.php';
require __DIR__ . '/recurring-lib.php';
require __DIR__ . '/report-lib.php';
require __DIR__ . '/customers-lib.php';
require __DIR__ . '/assignment-lib.php';
require_once __DIR__ . '/attachments-lib.php';
require_once __DIR__ . '/marks-lib.php';
require_once __DIR__ . '/company-lib.php';
require_once __DIR__ . '/documents-lib.php';
$error = '';
$page = (string)($_GET['page'] ?? 'dashboard');
$installed = (bool)one('SELECT id FROM users LIMIT 1');
if (!$installed) $page = 'setup';
elseif (empty($_SESSION['user_id'])) $page = 'login';
elseif (!in_array($page, ['dashboard','customers','customer','import','new','documents','recurring','reports','marks','view','account','users','company'], true) || (in_array($page, ['users','company'], true) && !is_admin())) $page = 'dashboard';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = field('action');
        if ($page === 'setup' && $action === 'setup') {
            $key = (string)($config['setup_key'] ?? '');
            if (strlen($key) < 32 || str_starts_with($key, 'REPLACE_') || !hash_equals($key, field('setup_key', 250, true))) throw new RuntimeException('Setup key is incorrect or has not been configured.');
            $email = field('email', 190, true); $password = field('password', 72, true);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < PASSWORD_MIN) throw new RuntimeException('Enter a valid email and a password of at least '.PASSWORD_MIN.' characters.');
            // A fixed primary key ensures only one initial administrator can be created.
            query(users_ready() ? "INSERT INTO users (id,email,password_hash,role) VALUES (1,?,?,'admin')" : 'INSERT INTO users (id,email,password_hash) VALUES (1,?,?)', [strtolower($email),password_hash($password, PASSWORD_DEFAULT)]);
            session_regenerate_id(true); $_SESSION['user_id'] = 1; $_SESSION['password_digest'] = hash('sha256',one('SELECT password_hash FROM users WHERE id=1')['password_hash']); go();
        } elseif ($page === 'login' && $action === 'login') {
            $db->beginTransaction();
            $user = one('SELECT *, (locked_until > UTC_TIMESTAMP()) AS locked FROM users WHERE email=? FOR UPDATE', [strtolower(field('email'))]);
            $valid = password_verify(field('password', 72), $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
            if (!$user || $user['locked'] || !$valid) {
                if ($user && !$user['locked']) query('UPDATE users SET failed_attempts=failed_attempts+1, locked_until=IF(failed_attempts>=5,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE),NULL) WHERE id=?', [$user['id']]);
                $db->commit(); throw new RuntimeException('Sign-in failed. Check your details or try again in 15 minutes.');
            }
            if (($user['status'] ?? 'active') !== 'active') { $db->commit(); throw new RuntimeException('This account is paused. Please contact your administrator.'); }
            query('UPDATE users SET failed_attempts=0,locked_until=NULL WHERE id=?', [$user['id']]);
            if (users_ready()) query('UPDATE users SET last_login_at=UTC_TIMESTAMP() WHERE id=?', [$user['id']]);
            activity('Signed in', null, null, (int)$user['id']);
            $db->commit(); session_regenerate_id(true); $_SESSION['user_id'] = (int)$user['id']; $_SESSION['password_digest'] = hash('sha256',$user['password_hash']); go();
        } elseif (!empty($_SESSION['user_id'])) {
            if ($action === 'activity_clear') {
                if (!is_admin()) throw new RuntimeException('Only the administrator can clear the activity history.');
                if (!users_ready()) throw new RuntimeException('Import users-schema.sql in phpMyAdmin to enable users.');
                $cleared = query('DELETE FROM activity_log')->rowCount();
                activity('Cleared the activity history ('.$cleared.' entr'.($cleared===1?'y':'ies').')');
                $_SESSION['notice'] = 'Activity history cleared. New activity is recorded from now on.';
                go('index.php?page=users#activity');
            }
            if (in_array($action,['user_add','user_pause','user_resume','user_remove','user_resend'],true)) {
                if (!is_admin()) throw new RuntimeException('Only the administrator can manage users.');
                if (!users_ready()) throw new RuntimeException('Import users-schema.sql in phpMyAdmin to enable users.');
                if ($action==='user_add') {
                    $added=user_add(field('name',120,true),field('email',190,true));
                    $_SESSION['notice']=$added['sent'] ? 'Invitation emailed to '.$added['user']['email'].'. They open the link in it to create their password (valid for 3 days).' : 'User added, but the invitation email could not be sent.';
                    if (!$added['sent']) $_SESSION['invite_link']=['email'=>$added['user']['email'],'link'=>$added['link']];
                } else {
                    [$notice,$link]=user_change((int)($_POST['id']??0),$action)+[1=>null];
                    $_SESSION['notice']=$notice;
                    if ($link) $_SESSION['invite_link']=['email'=>one('SELECT email FROM users WHERE id=?',[(int)$_POST['id']])['email'],'link'=>$link];
                }
                go('index.php?page=users');
            }
            if ($action==='company_save') {
                if (!is_admin()) throw new RuntimeException('Only the administrator can change the company details.');
                foreach (['quotation'=>'next_proforma','invoice'=>'next_invoice'] as $k=>$f) if (isset($_POST[$f]) && numbering_ready() && (int)$_POST[$f]!==(doc_no_next()[$k]??0)) { doc_no_set_next($k,(int)$_POST[$f]); activity('Set the next '.kind_name($k).' number to '.DOC_PREFIX[$k].(int)$_POST[$f]); }
                $details=company_save($_POST);
                $updated=!empty($_POST['apply_existing']) ? company_apply_existing($details) : null;
                activity('Changed the company details'.($updated!==null?' and updated '.$updated.' existing document'.($updated===1?'':'s'):''));
                $_SESSION['notice']='Company details saved. New documents use them from now on.'.($updated!==null?' '.$updated.' existing document'.($updated===1?' was':'s were').' updated too.':'');
                go('index.php?page=company');
            }
            if (in_array($action,['mark_upload','mark_remove','signatory_save'],true)) {
                $type=field('type'); $label=MARK_TYPES[$type]??'';
                if ($action==='mark_upload') { mark_save($type,$_FILES['image']??[]); $_SESSION['notice']=$label.' saved. Tick it on a document under Send to customer to add it to that PDF.'; activity('Uploaded the '.strtolower($label).' image'); }
                elseif ($action==='mark_remove') { mark_remove($type); $_SESSION['notice']=$label.' removed. Documents no longer show it.'; activity('Removed the '.strtolower($label).' image'); }
                else { signatory_save(field('signatory_name',80),field('signatory_title',80)); $_SESSION['notice']='Signatory details saved.'; activity('Updated the signatory details'); }
                go('index.php?page=marks');
            }
            if ($action==='doc_marks') {
                $id=(int)($_POST['id']??0);
                if (!($marked=one('SELECT id,kind FROM documents WHERE id=?',[$id]))) throw new RuntimeException('Document not found.');
                doc_marks_save($id,array_filter((array)($_POST['marks']??[]),'is_string'));
                $chosen=doc_marks($id);
                activity('Changed the stamp and signature shown on '.number($marked),$id);
                $_SESSION['notice']=$chosen ? 'This document now shows the '.strtolower(implode(' and ',array_map(fn($t)=>MARK_TYPES[$t],$chosen))).' on screen, in the PDF, emails and customer links.' : 'This document no longer shows a stamp or signature.';
                go('index.php?page=view&id='.$id.'#marks');
            }
            if ($action==='attach_files' || $action==='remove_attachment') {
                $id=(int)($_POST['id']??0);
                if (!($attachedTo=one('SELECT id,kind FROM documents WHERE id=?',[$id]))) throw new RuntimeException('Document not found.');
                if ($action==='attach_files') { $names=attachments_add($id,$_FILES['attachments']??[]); $_SESSION['notice']='Attached '.implode(', ',$names).'. It will be included when you email this document.'; activity('Attached '.implode(', ',$names).' to '.number($attachedTo),$id); }
                else { $removed=attachment_remove($id,field('file')); $_SESSION['notice']='Removed '.$removed.'.'; activity('Removed the attachment '.$removed.' from '.number($attachedTo),$id); }
                go('index.php?page=view&id='.$id.'#attachments');
            }
            if (in_array($action,['share_document','email_document','revoke_document_links'],true)) {
                try { query('SELECT id FROM document_shares LIMIT 1'); }
                catch (PDOException $ex) { throw new RuntimeException('Import document-sharing-schema.sql in phpMyAdmin to enable document sharing.'); }
                $id=(int)($_POST['id']??0);
                $db->beginTransaction();
                $doc=one('SELECT * FROM documents WHERE id=? FOR UPDATE',[$id]);
                if (!$doc) throw new RuntimeException('Document not found.');
                if ($action==='revoke_document_links') {
                    query('UPDATE document_shares SET revoked_at=UTC_TIMESTAMP() WHERE document_id=? AND revoked_at IS NULL',[$id]);
                    activity('Revoked the customer links for '.number($doc),$id);
                    $db->commit(); unset($_SESSION['share_link']); $_SESSION['notice']='All customer links for this document have been revoked.';
                    go('index.php?page=view&id='.$id.'#send');
                }
                $recipient=$action==='email_document'?field('recipient',190,true):'';
                if ($recipient!=='' && !filter_var($recipient,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid recipient email.');
                if ($action==='email_document' && one("SELECT id FROM document_shares WHERE document_id=? AND mail_status<>'none' AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE)",[$id])) throw new RuntimeException('Please wait one minute before emailing this document again.');
                if ($action==='email_document') {
                    require_once __DIR__.'/document-pdf.php';
                    $paid=(int)one('SELECT COALESCE(SUM(amount),0) AS paid FROM payments WHERE document_id=?',[$id])['paid'];
                    $pdf=document_pdf($doc,$paid);
                    $extra=[];
                    foreach ((array)($_POST['attach']??[]) as $fileId) if (is_string($fileId) && ($file=attachment_get($id,$fileId))) $extra[]=$file;
                }
                $share=create_document_share($doc,$recipient); $db->commit();
                $_SESSION['share_link']=['document_id'=>$id]+$share;
                if ($action==='email_document') {
                    try { $accepted=send_document_email($doc,$recipient,$share['url'],$pdf,null,$extra); }
                    catch (Throwable $mailError) { $accepted=false; error_log('Billing Portal: document email submission failed.'); }
                    query('UPDATE document_shares SET mail_status=? WHERE id=?',[$accepted?'accepted':'failed',$share['id']]);
                    $what=kind_name($doc['kind']).' '.number($doc);
                    activity(($accepted?'Emailed ':'Tried to email (not sent) ').$what.' to '.$recipient,$id);
                    $_SESSION['toast']=$accepted
                        ? ['tone'=>'success','title'=>$what.' sent','text'=>'Emailed to '.$recipient.' with the PDF'.($extra?' and '.count($extra).' attachment'.(count($extra)>1?'s':''):'').' and a download link. If the customer cannot find it, ask them to check their spam folder.']
                        : ['tone'=>'error','title'=>$what.' was not sent','text'=>'The mail server did not accept the email to '.$recipient.'. Try again in a minute, or copy the customer link below and send it on WhatsApp while your host checks email sending.'];
                } else { $_SESSION['notice']='Customer link created. It expires in 30 days.'; activity('Created a customer download link for '.number($doc),$id); }
                go('index.php?page=view&id='.$id.'#send');
            }
            if (in_array($action,['recurring_save','recurring_pause','recurring_resume','recurring_stop'],true)) {
                if (!recurring_ready()) throw new RuntimeException('Import recurring-schema.sql in phpMyAdmin to enable recurring services.');
                $today=date('Y-m-d');
                if ($action==='recurring_save') {
                    $template=one('SELECT * FROM documents WHERE id=?',[(int)($_POST['template_id']??0)]);
                    if (!$template) throw new RuntimeException('Document not found.');
                    $s=recurring_save($template,(int)$_SESSION['user_id']);
                    activity('Saved the recurring schedule for '.number($template).' (next send '.$s['next_date'].' to '.$s['recipient'].')',(int)$template['id']);
                    $_SESSION['notice']='Recurring schedule saved. The next '.kind_name($template['kind']).' will be emailed to '.$s['recipient'].' on '.$s['next_date'].'.';
                    go('index.php?page=view&id='.$template['id'].'#recurring');
                }
                $s=one('SELECT * FROM recurring_quotations WHERE id=?',[(int)($_POST['id']??0)]);
                if (!$s) throw new RuntimeException('Recurring schedule not found.');
                if ($action==='recurring_pause') { query("UPDATE recurring_quotations SET status='paused' WHERE id=?",[$s['id']]); $_SESSION['notice']='Schedule paused. Nothing will be sent until you resume it.'; }
                elseif ($action==='recurring_stop') { query("UPDATE recurring_quotations SET status='ended',next_date=NULL WHERE id=?",[$s['id']]); $_SESSION['notice']='Schedule stopped.'; }
                else {
                    $next=$s['next_date'];
                    // Dates missed while paused are skipped rather than sent late.
                    if ($next===null || $next<$today) {
                        if (empty($s['interval_unit'])) throw new RuntimeException('Choose a new next send date for this schedule and save it.');
                        // Start from the occurrence that was next when paused/stopped, so a future date is not skipped.
                        $after=recurring_after(['sequence'=>(int)$s['sequence']-1]+$s,date('Y-m-d',strtotime('-1 day')));
                        if ($after['status']==='ended') throw new RuntimeException('This schedule has passed its end date. Choose new dates and save it.');
                        query('UPDATE recurring_quotations SET sequence=?,next_date=? WHERE id=?',[$after['sequence'],$after['next_date'],$s['id']]);
                        $next=$after['next_date'];
                    }
                    query("UPDATE recurring_quotations SET status='active' WHERE id=?",[$s['id']]);
                    $_SESSION['notice']='Schedule resumed. The next copy will be sent on '.$next.'.';
                }
                $template=one('SELECT id,kind FROM documents WHERE id=?',[$s['template_id']]);
                activity(['recurring_pause'=>'Paused','recurring_stop'=>'Stopped','recurring_resume'=>'Resumed'][$action].' the recurring schedule for '.number($template),(int)$template['id']);
                go(($_POST['return']??'')==='recurring' ? 'index.php?page=recurring' : 'index.php?page=view&id='.$s['template_id'].'#recurring');
            }
            if (in_array($action,['report_settings','report_send_now'],true)) {
                if (!report_ready()) throw new RuntimeException('Import reports-schema.sql in phpMyAdmin to enable the monthly report.');
                if ($action==='report_settings') {
                    $emails=report_parse_recipients(field('recipients',1000));
                    $enabled=!empty($_POST['enabled']);
                    if ($enabled && !$emails) throw new RuntimeException('Enter at least one email address for the monthly report, or untick automatic sending.');
                    query('INSERT INTO report_settings (id,recipients,enabled,updated_by) VALUES (1,?,?,?) ON DUPLICATE KEY UPDATE recipients=VALUES(recipients),enabled=VALUES(enabled),updated_by=VALUES(updated_by)',[implode(',',$emails),(int)$enabled,$_SESSION['user_id']]);
                    activity('Changed the monthly report settings');
                    $_SESSION['notice']=$enabled ? 'Report settings saved. The monthly report will be emailed to '.implode(', ',$emails).'.' : 'Report settings saved. Automatic monthly reports are switched off.';
                } else {
                    if (time()-(int)($_SESSION['report_sent_at']??0)<60) throw new RuntimeException('Please wait one minute before sending the report again.');
                    $emails=report_settings()['recipients'];
                    $_SESSION['report_sent_at']=time();
                    $accepted=report_send(report_data(date('Y-m-d')),$emails);
                    activity($accepted?'Sent the monthly report to '.implode(', ',$emails):'Tried to send the monthly report (not sent)');
                    $_SESSION['notice']=$accepted ? 'This month\'s report was accepted by the mail service for '.implode(', ',$emails).'. This does not confirm delivery.' : 'The mail service did not accept the report email. Ask your host to check email sending.';
                }
                go('index.php?page=reports');
            }
            if ($action === 'import_preview') {
                unset($_SESSION['customer_import']);
                $upload = $_FILES['spreadsheet'] ?? [];
                if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) throw new RuntimeException('Upload failed. Choose a file under 2 MB and check your hosting upload limit.');
                $extension = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
                $rows = import_validate(import_rows($upload['tmp_name'], $extension));
                $_SESSION['customer_import'] = ['rows'=>$rows,'token'=>bin2hex(random_bytes(24)),'created'=>time()];
                go('index.php?page=import');
            }
            if ($action === 'import_cancel') { unset($_SESSION['customer_import']); go('index.php?page=import'); }
            if ($action === 'import_confirm') {
                $preview = $_SESSION['customer_import'] ?? null;
                if (!$preview || time()-$preview['created']>1800 || !hash_equals($preview['token'],field('import_token'))) throw new RuntimeException('The preview expired. Upload the spreadsheet again.');
                $db->beginTransaction();
                // Serialize imports for this account; re-check duplicates at confirmation.
                query('SELECT id FROM users WHERE id=? FOR UPDATE',[$_SESSION['user_id']]);
                $rows = import_classify($preview['rows'],query('SELECT name,email FROM customers FOR UPDATE')->fetchAll());
                $added=0; $duplicates=0; $invalid=0;
                foreach ($rows as $row) {
                    if ($row['status']==='Duplicate') { $duplicates++; continue; }
                    if ($row['status']==='Invalid') { $invalid++; continue; }
                    $v=$row['values'];
                    query('INSERT INTO customers (name,email,phone,address) VALUES (?,?,?,?)',[$v['name'],$v['email'],$v['phone'],$v['address']]); $added++;
                }
                activity("Imported $added customers from a spreadsheet");
                $db->commit(); unset($_SESSION['customer_import']);
                $_SESSION['notice']="Import complete: $added added, $duplicates duplicates skipped, $invalid invalid rows skipped.";
                go('index.php?page=customers&sort=newest');
            }
            if ($action === 'logout') { activity('Signed out'); $_SESSION=[]; session_regenerate_id(true); go(); }
            if ($action === 'profile') {
                if (!users_ready()) throw new RuntimeException('Import users-schema.sql in phpMyAdmin to enable names.');
                $name = trim(preg_replace('/[\x00-\x1F\x7F]+/',' ',field('name',120,true)));
                query('UPDATE users SET name=? WHERE id=?',[$name,$_SESSION['user_id']]);
                activity('Changed their display name to '.$name);
                $_SESSION['notice'] = 'Your name is now shown as '.$name.' on documents and in the activity history.';
                go('index.php?page=account');
            }
            if ($action === 'password') {
                $user = one('SELECT * FROM users WHERE id=?', [$_SESSION['user_id']]);
                $password = field('password',72,true);
                if (!password_verify(field('current_password',72,true),$user['password_hash']) || strlen($password)<PASSWORD_MIN) throw new RuntimeException('Check your current password. The new password must have at least '.PASSWORD_MIN.' characters.');
                query('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$user['id']]);
                activity('Changed their password');
                $_SESSION=[]; session_regenerate_id(true); go();
            }
            if ($action === 'documents_delete' || $action === 'customers_delete') {
                if (!is_admin()) throw new RuntimeException('Only the administrator can delete '.($action==='documents_delete'?'Proformas and Tax Invoices.':'customers.'));
                // One row's Delete button, the ticked rows, or everything matching the current filter.
                $only = (int)($_POST['only'] ?? 0);
                $chosen = $only ? [$only] : (!empty($_POST['all']) ? null : (array)($_POST['ids'] ?? []));
                $confirmed = ($_POST['confirmed'] ?? '') === '1';
                if ($action === 'documents_delete') {
                    $staff = (string)($_POST['staff'] ?? '');
                    $deleted = documents_delete($chosen, $staff, $confirmed);
                    $_SESSION['notice'] = $deleted ? 'Deleted '.documents_summary($deleted).'.' : 'No documents were deleted.';
                    go('index.php?page=documents'.($staff!=='' ? '&staff='.rawurlencode($staff) : ''));
                }
                $search = trim(substr((string)($_POST['q'] ?? ''),0,190));
                [$names,$docs] = customers_delete($chosen, $search, $confirmed);
                $_SESSION['notice'] = $names ? 'Deleted '.count($names).' customer'.(count($names)===1?'':'s').($docs?' and '.documents_summary($docs):'').'.' : 'No customers were deleted.';
                go('index.php?page=customers'.($search!=='' && $chosen!==null ? '&q='.rawurlencode($search) : ''));
            }
            if ($action === 'document_update') {
                $id = (int)($_POST['id'] ?? 0);
                $customer = one('SELECT * FROM customers WHERE id=?',[(int)($_POST['customer_id'] ?? 0)]);
                if (!$customer) throw new RuntimeException('Choose an existing customer.');
                $date = date_input(field('document_date')); $due = date_input(field('due_date'));
                if ($due < $date) throw new RuntimeException('The due or expiry date must be on or after the document date.');
                $t = totals((array)($_POST['description'] ?? []),(array)($_POST['quantity'] ?? []),(array)($_POST['price'] ?? []),field('discount'),field('tax_rate'));
                $db->beginTransaction();
                $doc = one('SELECT * FROM documents WHERE id=? FOR UPDATE',[$id]);
                if (!$doc) throw new RuntimeException('Document not found.');
                // Recorded payments must keep matching the invoice they were made against.
                if (one('SELECT id FROM payments WHERE document_id=? LIMIT 1',[$id])) throw new RuntimeException('This document has payments recorded, so it can no longer be edited.');
                query('UPDATE documents SET customer_id=?,customer_snapshot=?,document_date=?,due_date=?,notes=?,items_json=?,subtotal=?,discount=?,tax_bps=?,tax=?,total=? WHERE id=?',[$customer['id'],json_encode($customer,JSON_THROW_ON_ERROR),$date,$due,field('notes',5000),json_encode($t['items'],JSON_THROW_ON_ERROR),$t['subtotal'],$t['discount'],$t['tax_bps'],$t['tax'],$t['total'],$id]);
                activity('Edited '.kind_name($doc['kind']).' '.number($doc).((int)$doc['total']!==$t['total']?' (total '.money((int)$doc['total']).' → '.money($t['total']).')':''),$id,(int)$customer['id']);
                $db->commit();
                $_SESSION['notice'] = 'Saved changes to '.number($doc).'. If the customer already has it, send it to them again.';
                go('index.php?page=view&id='.$id);
            }
            if ($action === 'customer') {
                $values = [field('name',190,true),field('email'),field('phone',80),field('address',2000)];
                if ($values[1] !== '' && !filter_var($values[1],FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid customer email.');
                $id = (int)($_POST['id'] ?? 0);
                if ($id) { query('UPDATE customers SET name=?,email=?,phone=?,address=? WHERE id=?', [...$values,$id]); activity('Updated customer '.$values[0],null,$id); }
                else { query('INSERT INTO customers (name,email,phone,address) VALUES (?,?,?,?)',$values); $id = (int)$db->lastInsertId(); activity('Added customer '.$values[0],null,$id); }
                $_SESSION['notice'] = 'Customer saved: '.$values[0].'.';
                go('index.php?page=customer&id='.$id);
            }
            if ($action === 'document') {
                $customer = one('SELECT * FROM customers WHERE id=?',[(int)($_POST['customer_id'] ?? 0)]);
                if (!$customer) throw new RuntimeException('Choose an existing customer.');
                $kind = field('kind');
                if (!in_array($kind,['quotation','invoice'],true)) throw new RuntimeException('Choose a document type.');
                $date = date_input(field('document_date')); $due = date_input(field('due_date'));
                if ($due < $date) throw new RuntimeException('The due or expiry date must be on or after the document date.');
                $t = totals((array)($_POST['description'] ?? []),(array)($_POST['quantity'] ?? []),(array)($_POST['price'] ?? []),field('discount'),field('tax_rate'));
                $company = company_details();
                $recurring = !empty($_POST['recurring']);
                if ($recurring && !recurring_ready()) throw new RuntimeException('Import recurring-schema.sql in phpMyAdmin to enable recurring services.');
                $staff = assignment_ready() ? assignment_email(field('assigned_to')) : null;
                // The document and its schedule are saved together, or not at all.
                $db->beginTransaction();
                query('INSERT INTO documents (kind,customer_id,customer_snapshot,company_snapshot,document_date,due_date,notes,items_json,subtotal,discount,tax_bps,tax,total,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$kind,$customer['id'],json_encode($customer,JSON_THROW_ON_ERROR),json_encode($company,JSON_THROW_ON_ERROR),$date,$due,field('notes',5000),json_encode($t['items'],JSON_THROW_ON_ERROR),$t['subtotal'],$t['discount'],$t['tax_bps'],$t['tax'],$t['total'],$_SESSION['user_id']]);
                $id = (int)$db->lastInsertId();
                doc_no_assign($id,$kind);
                activity('Created '.kind_name($kind).' '.number(['kind'=>$kind,'id'=>$id]).' for '.$customer['name'],$id,(int)$customer['id']);
                if ($recurring) {
                    $s = recurring_save(one('SELECT * FROM documents WHERE id=?',[$id]),(int)$_SESSION['user_id']);
                    $_SESSION['notice'] = kind_name($kind).' saved as a recurring service. The next one will be created and emailed to '.$s['recipient'].' on '.$s['next_date'].'.';
                }
                $db->commit();
                if ($staff !== null) {
                    $assigned = assignment_apply(one('SELECT * FROM documents WHERE id=?',[$id]),$staff,!empty($_POST['notify_staff']));
                    activity('Assigned '.number(['kind'=>$kind,'id'=>$id]).' to '.$staff,$id);
                    $_SESSION['notice'] = trim(($_SESSION['notice'] ?? '').' '.$assigned);
                }
                go('index.php?page=view&id='.$id);
            }
            if ($action === 'assign_document') {
                if (!assignment_ready()) throw new RuntimeException('Import assignment-schema.sql in phpMyAdmin to enable staff assignment.');
                $doc = one('SELECT * FROM documents WHERE id=?',[(int)($_POST['id'] ?? 0)]);
                if (!$doc) throw new RuntimeException('Document not found.');
                $staff = assignment_email(field('assigned_to'));
                $_SESSION['notice'] = assignment_apply($doc,$staff,!empty($_POST['notify_staff']));
                activity($staff===null ? 'Removed the staff assignment from '.number($doc) : 'Assigned '.number($doc).' to '.$staff,(int)$doc['id']);
                go('index.php?page=view&id='.$doc['id'].'#assignment');
            }
            if ($action === 'convert') {
                $due = date_input(field('due_date'));
                if ($due < date('Y-m-d')) throw new RuntimeException('The Tax Invoice due date cannot be before today.');
                $db->beginTransaction();
                $doc = one('SELECT * FROM documents WHERE id=? AND kind=? FOR UPDATE',[(int)($_POST['id'] ?? 0),'quotation']);
                if (!$doc) throw new RuntimeException('Proforma not found.');
                $existing = one('SELECT id FROM documents WHERE source_id=?',[$doc['id']]);
                if ($existing) { $db->commit(); go('index.php?page=view&id='.$existing['id']); }
                query("INSERT INTO documents (kind,customer_id,customer_snapshot,company_snapshot,source_id,document_date,due_date,notes,items_json,subtotal,discount,tax_bps,tax,total,created_by) VALUES ('invoice',?,?,?,?,?,?,?,?,?,?,?,?,?,?)",[$doc['customer_id'],$doc['customer_snapshot'],$doc['company_snapshot'],$doc['id'],date('Y-m-d'),$due,$doc['notes'],$doc['items_json'],$doc['subtotal'],$doc['discount'],$doc['tax_bps'],$doc['tax'],$doc['total'],$_SESSION['user_id']]);
                $id = (int)$db->lastInsertId();
                doc_no_assign($id,'invoice');
                activity('Converted '.number($doc).' to Tax Invoice '.number(['kind'=>'invoice','id'=>$id]),(int)$doc['id']);
                activity('Created Tax Invoice '.number(['kind'=>'invoice','id'=>$id]).' from Proforma '.number($doc),(int)$id,(int)$doc['customer_id']);
                // The invoice keeps the quotation's staff assignment.
                if (array_key_exists('assigned_to',$doc)) query('UPDATE documents SET assigned_to=? WHERE id=?',[$doc['assigned_to'],$id]);
                $db->commit(); doc_marks_copy((int)$doc['id'],(int)$id); go('index.php?page=view&id='.$id);
            }
            if ($action === 'payment') {
                $amount = units(field('amount')); $date = date_input(field('payment_date')); $reference = field('reference',190,true);
                if ($date > date('Y-m-d')) throw new RuntimeException('Payment dates cannot be in the future.');
                $db->beginTransaction();
                $doc = one('SELECT * FROM documents WHERE id=? AND kind=? FOR UPDATE',[(int)($_POST['id'] ?? 0),'invoice']);
                if (!$doc) throw new RuntimeException('Tax Invoice not found.');
                $paid = (int)one('SELECT COALESCE(SUM(amount),0) AS paid FROM payments WHERE document_id=?',[$doc['id']])['paid'];
                if ($amount <= 0 || $amount > (int)$doc['total']-$paid) throw new RuntimeException('Payment must be greater than zero and no more than the outstanding balance.');
                query('INSERT INTO payments (document_id,amount,payment_date,reference,created_by) VALUES (?,?,?,?,?)',[$doc['id'],$amount,$date,$reference,$_SESSION['user_id']]);
                activity('Recorded a payment of '.$config['currency'].' '.money($amount).' on '.number($doc).' (ref: '.$reference.')',(int)$doc['id']);
                $db->commit(); go('index.php?page=view&id='.$doc['id'].'#payments');
            }
        }
    }
} catch (Throwable $ex) {
    if ($db->inTransaction()) $db->rollBack();
    $error = $ex instanceof RuntimeException && !($ex instanceof PDOException) ? $ex->getMessage() : 'The change could not be saved. Please retry or contact your administrator.';
    if ($ex instanceof PDOException) error_log('Billing Portal query failed: '.$ex->getCode());
}
// Customer list download. Runs before any page output.
if ($page === 'customers' && ($_GET['export'] ?? '') === 'csv' && !empty($_SESSION['user_id'])) customers_csv((string)($_GET['q'] ?? ''), isset(CUSTOMER_SORTS[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'name');
$titles = ['dashboard'=>'Overview','customers'=>'Customers','customer'=>'Customer','import'=>'Import customers','new'=>'Create document','documents'=>'Proformas & Tax Invoices','recurring'=>'Recurring services','reports'=>'Monthly report','marks'=>'Stamp & signature','view'=>'Document','account'=>'Your account','users'=>'Users & activity','company'=>'Company details','login'=>'Welcome back','setup'=>'Set up your portal'];
if ($page === 'new' && isset($_GET['edit'])) $titles['new'] = 'Edit document';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?=e($titles[$page])?> | <?=e(brand('short_name'))?> Portal</title><link rel="icon" href="images/logo-icon.png" type="image/png"><link rel="stylesheet" href="style.css?v=27"><script src="app.js?v=13" defer></script></head><body>
<aside class="sidebar"><a class="brand" href="index.php"><img class="brand-logo" src="images/logo-light.png" alt="<?=e(brand('brand'))?>"><small>STAFF PORTAL</small></a>
<?php if (!empty($_SESSION['user_id'])): ?><nav><?php foreach (['dashboard'=>'Overview','customers'=>'Customers','documents'=>'Proformas & Tax Invoices','recurring'=>'Recurring','reports'=>'Reports','marks'=>'Stamp & signature','new'=>'Create document']+(is_admin()?['company'=>'Company details','users'=>'Users & activity']:[])+['account'=>'Your account'] as $key=>$label): ?><a class="<?=($page===$key || ($key==='customers' && $page==='customer'))?'active':''?>" href="index.php?page=<?=$key?>"><?=e($label)?></a><?php endforeach ?></nav><form method="post"><?=csrf()?><input type="hidden" name="action" value="logout"><button class="quiet">Sign out</button></form><?php endif ?><div class="sidebar-foot"><?=e(brand('company'))?><br><?=e(brand('location'))?></div></aside>
<main><header class="top"><div><div class="eyebrow"><?=e(strtoupper(brand('short_name')))?> WORKSPACE</div><h1><?=e($titles[$page])?></h1></div><span class="today"><?=date('d M Y')?></span></header>
<?php if ($error): ?><div role="alert" class="alert"><?=e($error)?></div><?php endif ?>
<?php if (isset($_SESSION['notice'])): ?><div role="status" class="card"><?=e($_SESSION['notice'])?></div><?php unset($_SESSION['notice']); endif ?>
<?php if (isset($_SESSION['toast'])): $toast=$_SESSION['toast']; unset($_SESSION['toast']); ?><div class="toast toast-<?=e($toast['tone'])?>" role="<?=$toast['tone']==='error'?'alert':'status'?>" id="toast"><span class="toast-icon" aria-hidden="true"><?=$toast['tone']==='error'?'!':'✓'?></span><div><strong><?=e($toast['title'])?></strong><p><?=e($toast['text'])?></p></div><button type="button" class="toast-close" aria-label="Close message">×</button></div><?php endif ?>
<?php if ($page==='login' || $page==='setup'): ?>
<section class="card auth"><h2><?=$page==='setup'?'Create the administrator account':'Sign in to your workspace'?></h2><p class="muted">Manage your customers and billing in one place.</p><form method="post"><?=csrf()?><input type="hidden" name="action" value="<?=$page?>">
<?php if ($page==='setup'): ?><label>Private setup key<input type="password" name="setup_key" required autocomplete="off"></label><?php endif ?>
<label>Email<input type="email" name="email" required maxlength="190" autocomplete="username"></label><label>Password<input type="password" name="password" required maxlength="72" minlength="<?=$page==='setup'?PASSWORD_MIN:1?>" autocomplete="<?=$page==='setup'?'new-password':'current-password'?>"></label><button><?=$page==='setup'?'Create administrator':'Sign in'?></button></form><?php if ($page==='login'): ?><p><a href="reset.php">Forgot password?</a></p><?php endif ?></section>
<?php elseif ($page==='dashboard'): 
$stats = one("SELECT COUNT(*) AS count, COALESCE(SUM(total),0) AS total FROM documents WHERE kind='invoice'");
$paid = (int)one('SELECT COALESCE(SUM(amount),0) AS total FROM payments')['total']; ?>
<div class="metrics"><section class="card"><span>Customers</span><strong><?=e(one('SELECT COUNT(*) AS count FROM customers')['count'])?></strong></section><section class="card"><span>Tax Invoices issued</span><strong><?=e($stats['count'])?></strong></section><section class="card"><span>Outstanding · <?=e($config['currency'])?></span><strong><?=money((int)$stats['total']-$paid)?></strong></section></div>
<section class="card welcome"><div><h2>A clear view of your business.</h2><p>Keep customer details up to date, prepare a proforma, or record a payment.</p></div><a class="button" href="index.php?page=new">Create a document</a></section>
<?php if (recurring_ready()): $upcoming=query("SELECT q.*,d.customer_snapshot FROM recurring_quotations q JOIN documents d ON d.id=q.template_id WHERE q.status='active' AND (q.next_date IS NULL OR q.next_date<=?) ORDER BY q.next_date IS NOT NULL,q.next_date LIMIT 8",[date('Y-m-d',strtotime('+14 days'))])->fetchAll(); $failed=query("SELECT r.document_id,d.kind FROM recurring_runs r JOIN documents d ON d.id=r.document_id WHERE r.mail_status='failed' AND r.created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) ORDER BY r.id DESC LIMIT 5")->fetchAll(); if ($upcoming || $failed): ?>
<section class="card"><div class="section-head"><h2>Recurring services · next 14 days</h2><a href="index.php?page=recurring">All schedules</a></div>
<?php foreach ($failed as $f): ?><p class="alert">Automatic email failed for <a href="index.php?page=view&id=<?=$f['document_id']?>"><?=e(number(['kind'=>$f['kind'],'id'=>$f['document_id']]))?></a>. Open it and send it manually.</p><?php endforeach ?>
<?php foreach ($upcoming as $u): ?><p><strong><?=e($u['next_date']??'Needs next date')?></strong> · <?=e(json_decode($u['customer_snapshot'],true)['name'])?> · <a href="index.php?page=view&id=<?=$u['template_id']?>#recurring"><?=e(recurring_label($u))?></a></p><?php endforeach ?></section>
<?php endif; endif ?>
<section class="card"><h2>Recent documents</h2><?php $docs=query('SELECT d.*,COALESCE((SELECT SUM(amount) FROM payments WHERE document_id=d.id),0) AS paid FROM documents d ORDER BY id DESC LIMIT 10')->fetchAll(); require __DIR__.'/table.php'; ?></section>
<?php elseif ($page==='import'): require __DIR__.'/import.php'; ?>
<?php elseif ($page==='customers'): require __DIR__.'/customers-page.php'; ?>
<?php elseif ($page==='customer'): require __DIR__.'/customer-page.php'; ?>
<?php elseif ($page==='new'): $customers=query('SELECT id,name,email,phone FROM customers ORDER BY name')->fetchAll();
// Editing reuses this form. Documents with payments recorded cannot be edited.
$editDoc=isset($_GET['edit']) ? one('SELECT * FROM documents WHERE id=?',[(int)$_GET['edit']]) : []; $editBlocked=false;
if ($editDoc && one('SELECT id FROM payments WHERE document_id=? LIMIT 1',[$editDoc['id']])) { $editBlocked=true; $editDoc=[]; }
if ($editDoc && ($_POST['action']??'')!=='document_update') {
    $items=json_decode($editDoc['items_json'],true); $plain=fn(int $v)=>rtrim(rtrim(number_format($v/100,2,'.',''),'0'),'.');
    $_POST=['kind'=>$editDoc['kind'],'customer_id'=>$editDoc['customer_id'],'document_date'=>$editDoc['document_date'],'due_date'=>$editDoc['due_date'],'description'=>array_column($items,'description'),'quantity'=>array_map(fn($i)=>$plain((int)$i['quantity']),$items),'price'=>array_map(fn($i)=>number_format((int)$i['price']/100,2,'.',''),$items),'discount'=>number_format((int)$editDoc['discount']/100,2,'.',''),'tax_rate'=>$plain((int)$editDoc['tax_bps']),'notes'=>$editDoc['notes']];
} ?>
<section class="card"><?php if ($editDoc): ?><h2>Edit <?=e(kind_name($editDoc['kind']).' '.number($editDoc))?></h2><?php endif ?><p class="muted"><?=$editDoc?'Changes replace this document\'s details. PDFs and links already sent are not updated, so send it again after saving.':'Documents are saved as a fixed record.'?> Review the details before saving. Amounts are in <?=e($config['currency'])?>.</p><?php if ($editBlocked): ?><p>This document has payments recorded, so it can no longer be edited. <a href="index.php?page=documents">Back to documents</a></p><?php elseif (!$customers): ?><p><a href="index.php?page=customers">Add a customer</a> before creating a document.</p><?php else: ?><form method="post"><?=csrf()?><input type="hidden" name="action" value="<?=$editDoc?'document_update':'document'?>"><?php if ($editDoc): ?><input type="hidden" name="id" value="<?=$editDoc['id']?>"><?php endif ?><div class="form-grid"><label>Document type<select name="kind" <?=$editDoc?'disabled':''?>><?php foreach (['quotation'=>'Proforma','invoice'=>'Tax Invoice'] as $k=>$v): ?><option value="<?=$k?>" <?=($_POST['kind']??'quotation')===$k?'selected':''?>><?=$v?></option><?php endforeach ?></select></label><div class="customer-picker"><label for="customer-select">Customer</label><input type="search" id="customer-search" placeholder="Search by name, email or phone…" autocomplete="off" aria-label="Search customers" hidden><select name="customer_id" id="customer-select" required><option value="">Choose a customer</option><?php foreach ($customers as $c): ?><option value="<?=$c['id']?>" <?=((int)($_POST['customer_id']??$_GET['customer']??0)===$c['id'])?'selected':''?> data-info="<?=e(trim($c['email'].' · '.$c['phone'],' ·'))?>"><?=e($c['name'])?></option><?php endforeach ?></select><p class="muted customer-info" id="customer-info" role="status"></p></div><label>Document date<input type="date" name="document_date" value="<?=e($_POST['document_date']??date('Y-m-d'))?>" required></label><label>Payment due / proforma valid until<input type="date" name="due_date" value="<?=e($_POST['due_date']??date('Y-m-d',strtotime('+30 days')))?>" required></label></div>
<h2>Services</h2><div id="items"><?php $rows=(array)($_POST['description']??['']); foreach (array_slice($rows,0,30) as $i=>$desc): ?><div class="item"><label>Service description<input name="description[]" maxlength="500" value="<?=e($desc)?>" required></label><label>Quantity<input type="number" name="quantity[]" min="0.01" max="10000" step="0.01" value="<?=e($_POST['quantity'][$i]??'1')?>" required></label><label>Unit price<input type="number" name="price[]" min="0" max="10000000" step="0.01" value="<?=e($_POST['price'][$i]??'')?>" required></label><button type="button" class="quiet remove">Remove</button></div><?php endforeach ?></div><button type="button" id="add" class="quiet">+ Add service</button><div class="form-grid"><label>Discount amount<input type="number" name="discount" min="0" step="0.01" value="<?=e($_POST['discount']??'0')?>" required></label><label>Tax rate (%)<input type="number" name="tax_rate" min="0" max="100" step="0.01" value="<?=e($_POST['tax_rate']??'0')?>" required></label></div><label>Notes / terms<textarea name="notes" maxlength="5000"><?=e($_POST['notes']??'')?></textarea></label><p class="muted">Tax is calculated after the discount. Use the rate confirmed for your business.</p>
<?php if (assignment_ready() && !$editDoc): ?><fieldset class="assign-choice"><div class="form-grid"><label>Assign to staff (optional)<input type="email" name="assigned_to" list="staff-emails" maxlength="190" autocomplete="off" value="<?=e($_POST['assigned_to']??'')?>" placeholder="staff@example.com"></label><label class="check"><input type="checkbox" name="notify_staff" value="1" <?=(($_POST['action']??'')!=='document' || !empty($_POST['notify_staff']))?'checked':''?>> Email them the details and PDF</label></div><?=assignment_datalist()?><p class="muted">Emails you have used before appear as suggestions while you type.</p></fieldset><?php endif ?>
<?php if (recurring_ready() && !$editDoc): $repeat=!empty($_POST['recurring']); ?><fieldset class="recurring-choice"><label class="check"><input type="checkbox" name="recurring" value="1" id="recurring-toggle" <?=$repeat?'checked':''?>> This is a recurring service. Create and email it again automatically</label>
<div id="recurring-fields" <?=$repeat?'':'hidden'?>><p class="muted">This document is the first one. On each send date the portal creates a new copy with the same services and prices, dated that day, and emails it.</p><?php recurring_fields(fn(string $key,$fallback)=>(string)($_POST[$key]??$fallback),"Leave blank to use the customer's email"); ?></div></fieldset><?php endif ?>
<button><?=$editDoc?'Save changes':'Save document'?></button><?php if ($editDoc): ?> <a href="index.php?page=view&id=<?=$editDoc['id']?>">Cancel</a><?php endif ?></form><?php endif ?></section>
<?php elseif ($page==='documents'): ?>
<?php $staffList=assignment_suggestions(200); $staffFilter=(string)($_GET['staff']??''); if (!assignment_ready() || ($staffFilter!=='none' && !in_array($staffFilter,$staffList,true))) $staffFilter=''; $staffQuery=$staffFilter===''?'':'&staff='.rawurlencode($staffFilter); ?>
<section class="card"><div class="section-head"><h2>Document register</h2><div class="actions"><?php if (assignment_ready()): ?><form method="get" class="inline staff-filter"><input type="hidden" name="page" value="documents"><label>Staff<select name="staff"><option value="">Everyone</option><option value="none" <?=$staffFilter==='none'?'selected':''?>>Not assigned</option><?php foreach ($staffList as $s): ?><option value="<?=e($s)?>" <?=$staffFilter===$s?'selected':''?>><?=e($s)?></option><?php endforeach ?></select></label><button class="quiet">Show</button></form><?php endif ?><a class="button" href="index.php?page=new">Create document</a></div></div><?php $offset=max(0,(int)($_GET['offset']??0)); [$staffWhere,$staffParams]=$staffFilter==='' ? ['1=1',[]] : ($staffFilter==='none' ? ['d.assigned_to IS NULL',[]] : ['d.assigned_to=?',[$staffFilter]]); $docs=query('SELECT d.*,COALESCE((SELECT SUM(amount) FROM payments WHERE document_id=d.id),0) AS paid FROM documents d WHERE '.$staffWhere.' ORDER BY id DESC LIMIT 50 OFFSET '.min($offset,1000000),$staffParams)->fetchAll();
// Only the administrator can delete. Tax Invoices with payments need a second, explicit warning.
$pick=is_admin() && $docs;
if ($pick): $allCount=(int)one('SELECT COUNT(*) AS n FROM documents d WHERE '.$staffWhere,$staffParams)['n']; $allRisk=documents_risk($staffWhere,$staffParams); ?><form method="post" class="bulk-form" data-plural="documents"><?=csrf()?><input type="hidden" name="action" value="documents_delete"><input type="hidden" name="staff" value="<?=e($staffFilter)?>"><input type="hidden" name="confirmed" value=""><div class="bulk-bar" hidden><strong class="bulk-count">0 selected</strong><button type="button" class="quiet bulk-mark">Mark all on this page</button><button type="submit" class="danger">Delete selected</button><button type="submit" name="all" value="1" class="quiet danger-text" data-what="all <?=$allCount?> document<?=$allCount===1?'':'s'?><?=$staffFilter!==''?' shown for this staff filter':''?> with their customer links and attached files" data-risk="<?=e($allRisk)?>">Delete all <?=$allCount?></button><button type="button" class="link-button bulk-clear">Clear</button></div><?php endif; require __DIR__.'/table.php'; if ($pick): ?></form><?php endif ?><div class="section-head"><?php if ($offset): ?><a href="index.php?page=documents&offset=<?=max(0,$offset-50).e($staffQuery)?>">Previous</a><?php endif ?><?php if (count($docs)===50): ?><a href="index.php?page=documents&offset=<?=($offset+50).e($staffQuery)?>">Next</a><?php endif ?></div></section>
<?php elseif ($page==='recurring'): require __DIR__.'/recurring-page.php'; ?>
<?php elseif ($page==='reports'): require __DIR__.'/report-page.php'; ?>
<?php elseif ($page==='marks'): require __DIR__.'/marks-page.php'; ?>
<?php elseif ($page==='users'): require __DIR__.'/users-page.php'; ?>
<?php elseif ($page==='company'): require __DIR__.'/company-page.php'; ?>
<?php elseif ($page==='view'): $doc=one('SELECT * FROM documents WHERE id=?',[(int)($_GET['id']??0)]); if (!$doc): ?><section class="card">Document not found.</section><?php else: $c=json_decode($doc['customer_snapshot'],true); $company=json_decode($doc['company_snapshot'],true); $payments=query('SELECT * FROM payments WHERE document_id=? ORDER BY payment_date,id',[$doc['id']])->fetchAll(); $paid=array_sum(array_column($payments,'amount')); $balance=(int)$doc['total']-$paid; ?>
<div class="toolbar"><a href="index.php?page=documents">← Documents</a><div class="actions"><?php if (!$payments): ?><a class="button quiet" href="index.php?page=new&edit=<?=$doc['id']?>">Edit</a><?php endif ?><?php if (is_admin()): ?><form method="post" class="bulk-form" data-plural="documents"><?=csrf()?><input type="hidden" name="action" value="documents_delete"><input type="hidden" name="confirmed" value=""><button type="submit" name="only" value="<?=$doc['id']?>" class="quiet danger-text" data-label="<?=e(number($doc))?>" data-risk="<?=$paid?e(number($doc).' with '.$company['currency'].' '.money($paid).' in payments'):''?>">Delete</button></form><?php endif ?><button type="button" id="print">Print / save PDF</button></div></div><div class="doc-layout"><?php require __DIR__.'/document-paper.php'; ?>
<aside class="doc-side no-print" aria-label="Document actions">
<?php require __DIR__.'/document-share-panel.php'; ?>
<?php if ($doc['kind']==='quotation'): $converted=one('SELECT id FROM documents WHERE source_id=?',[$doc['id']]); panel_start('convert','Convert to Tax Invoice',$converted?'✓ Converted':'Not yet',$converted?'ok':''); ?><?php if ($converted): ?><p>This Proforma has been converted. <a href="index.php?page=view&id=<?=$converted['id']?>">View the Tax Invoice</a></p><?php else: ?><p class="muted">Customer accepted? Create the Tax Invoice with the same services and prices.</p><form method="post"><?=csrf()?><input type="hidden" name="action" value="convert"><input type="hidden" name="id" value="<?=$doc['id']?>"><label>Tax Invoice payment due<input type="date" name="due_date" min="<?=date('Y-m-d')?>" value="<?=date('Y-m-d',strtotime('+30 days'))?>" required></label><button>Convert to Tax Invoice</button></form><?php endif ?><?php panel_end(); ?>
<?php else: panel_start('payments','Payments',$balance<=0?'✓ Paid in full':($paid>0?'Balance '.money($balance):'Unpaid · '.money($balance)),$balance<=0?'ok':($paid>0?'warn':'bad')); ?><?php if (!$payments): ?><p class="muted">No payments recorded yet.</p><?php else: ?><ul class="activity"><?php foreach ($payments as $p): ?><li><strong><?=e($company['currency'])?> <?=money((int)$p['amount'])?></strong><span><?=e($p['payment_date'])?> · <?=e($p['reference'])?> · by <?=e(user_name((int)$p['created_by']))?></span></li><?php endforeach ?></ul><?php endif ?><?php if ($balance>0): ?><form method="post"><?=csrf()?><input type="hidden" name="action" value="payment"><input type="hidden" name="id" value="<?=$doc['id']?>"><label>Amount received<input name="amount" type="number" min="0.01" step="0.01" max="<?=e(number_format($balance/100,2,'.',''))?>" required></label><div class="pair"><label>Payment date<input name="payment_date" type="date" value="<?=date('Y-m-d')?>" max="<?=date('Y-m-d')?>" required></label><label>Reference / method<input name="reference" maxlength="190" required placeholder="e.g. M-Pesa ref"></label></div><button>Record payment</button></form><?php endif ?><?php panel_end(); ?>
<?php endif ?>
<?php require __DIR__.'/document-files-panel.php'; ?>
<?php if (assignment_ready()): panel_start('assignment','Assigned staff',$doc['assigned_to']??'Not assigned',$doc['assigned_to']!==null?'ok':''); ?><p><?=$doc['assigned_to']!==null?'Assigned to <strong>'.e($doc['assigned_to']).'</strong>.':'<span class="muted">Not assigned to anyone yet.</span>'?></p><form method="post" class="assign-form"><?=csrf()?><input type="hidden" name="action" value="assign_document"><input type="hidden" name="id" value="<?=$doc['id']?>"><label>Staff email<input type="email" name="assigned_to" list="staff-emails" maxlength="190" autocomplete="off" value="<?=e($doc['assigned_to']??'')?>" placeholder="staff@example.com"></label><label class="check"><input type="checkbox" name="notify_staff" value="1" checked> Email them the details and PDF</label><button><?=$doc['assigned_to']!==null?'Save assignment':'Assign'?></button></form><?=assignment_datalist()?><p class="muted">Clear the email and save to remove the assignment.</p><?php panel_end(); endif ?>
<?php require __DIR__.'/recurring-panel.php'; ?>
<?php require __DIR__.'/document-history-panel.php'; ?>
</aside></div><?php endif ?>
<?php elseif ($page==='account'): ?><?php if (users_ready()): ?><section class="card auth"><h2>Your name</h2><p class="muted">Shown on documents you create and in the activity history. You sign in as <?=e(current_user()['email'])?>.</p><form method="post"><?=csrf()?><input type="hidden" name="action" value="profile"><label>Full name<input name="name" maxlength="120" required value="<?=e(current_user()['name'])?>"></label><button>Save name</button></form></section><?php endif ?><section class="card auth"><h2>Change your password</h2><form method="post"><?=csrf()?><input type="hidden" name="action" value="password"><label>Current password<input type="password" name="current_password" autocomplete="current-password" required maxlength="72"></label><label>New password (at least <?=PASSWORD_MIN?> characters)<input type="password" name="password" autocomplete="new-password" minlength="<?=PASSWORD_MIN?>" maxlength="72" required></label><button>Change password & sign out</button></form></section><?php endif ?>
<footer class="no-print"><?=e(brand('brand'))?> · Staff workspace</footer></main></body></html>
