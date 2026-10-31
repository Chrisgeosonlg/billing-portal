<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/password-reset-lib.php';
header('Referrer-Policy: no-referrer');
$error=''; $notice=''; $available=true;
try { query('SELECT user_id FROM password_resets LIMIT 1'); }
catch (PDOException $ex) { $available=false; $error='Password recovery is not enabled yet. Please contact the portal administrator.'; error_log('Billing Portal: import password-reset-schema.sql to enable password recovery.'); }
// Remove the secret from the address bar before showing a form or loading assets.
// Links are opened from an email (another site), where the browser holds back the SameSite=Strict session cookie,
// including across a server redirect. Continuing from this page makes the next request part of the portal, so the cookie is sent.
if ($available && isset($_GET['token'])) {
    $_SESSION['reset_token'] = preg_match('/^[a-f0-9]{64}$/D',(string)$_GET['token']) ? (string)$_GET['token'] : '';
    exit('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><meta http-equiv="refresh" content="0;url=reset.php?mode=reset"><title>Opening…</title></head><body><p><a href="reset.php?mode=reset">Continue</a></p></body></html>');
}
$mode = ($_GET['mode'] ?? '') === 'reset' ? 'reset' : 'request';
if ($available && $_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if ($mode==='request') {
            $email=field('email',190,true);
            if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
            request_password_reset($email);
            $_SESSION['reset_notice']='If that email belongs to an account, a reset link will be emailed to it. Check your inbox and spam folder. Please wait five minutes before requesting another link.';
            go('reset.php');
        } else {
            $invited=($record=reset_record((string)($_SESSION['reset_token']??''))) && $record['password_hash']==='';
            $userId=complete_password_reset((string)($_SESSION['reset_token']??''),(string)($_POST['password']??''),(string)($_POST['confirmation']??''));
            activity($invited?'Created their password':'Reset their password',null,null,$userId);
            $_SESSION=[]; session_regenerate_id(true);
            $_SESSION['notice']=$invited?'Your password has been created. Sign in with your email and new password.':'Your password has been reset. Sign in with your new password.';
            go('index.php');
        }
    } catch (PDOException $ex) { $error='Password recovery is temporarily unavailable. Please try again later.'; error_log('Billing Portal password recovery database error.'); }
    catch (RuntimeException $ex) { $error=$ex->getMessage(); }
    catch (Throwable $ex) { $error='Password recovery is temporarily unavailable. Please contact the administrator.'; error_log('Billing Portal password recovery failed.'); }
}
$notice=$_SESSION['reset_notice']??''; unset($_SESSION['reset_notice']);
$record = $available && $mode==='reset' ? reset_record((string)($_SESSION['reset_token']??'')) : [];
$valid = (bool)$record;
// Users added by the administrator have no password yet: the same link lets them create one.
$invited = $valid && $record['password_hash']==='';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?=$invited?'Create your password':'Password recovery'?> | <?=e(brand('short_name'))?> Portal</title><link rel="icon" href="images/logo-icon.png" type="image/png"><link rel="stylesheet" href="style.css?v=27"><script src="app.js?v=13" defer></script></head><body>
<aside class="sidebar"><a class="brand" href="index.php"><img class="brand-logo" src="images/logo-light.png" alt="<?=e(brand('brand'))?>"><small>BILLING PORTAL</small></a></aside>
<main><section class="card auth"><h1><?=$invited?'Create your password':($mode==='reset'?'Choose a new password':'Forgot password?')?></h1>
<?php if ($error): ?><p class="alert" role="alert"><?=e($error)?></p><?php endif ?>
<?php if ($notice): ?><p role="status"><?=e($notice)?></p><?php endif ?>
<?php if ($available && $mode==='request'): ?><p class="muted">Enter the email address you use to sign in. We will send a link to reset your password.</p><form method="post" action="reset.php"><?=csrf()?><label>Email address<input type="email" name="email" maxlength="190" autocomplete="username" required></label><button>Send reset link</button></form>
<?php elseif ($available && $valid): ?><?php if ($invited): ?><p class="muted">Welcome<?=trim($record['name'])!==''?', '.e($record['name']):''?>. Choose a password for <strong><?=e($record['email'])?></strong>. You will use it with this email to sign in.</p><?php endif ?><form method="post" action="reset.php?mode=reset"><?=csrf()?><label>New password (at least <?=PASSWORD_MIN?> characters)<input type="password" name="password" minlength="<?=PASSWORD_MIN?>" maxlength="72" autocomplete="new-password" required></label><label>Confirm new password<input type="password" name="confirmation" minlength="<?=PASSWORD_MIN?>" maxlength="72" autocomplete="new-password" required></label><button><?=$invited?'Create password':'Reset password'?></button></form>
<?php elseif ($available): ?><p>This link is invalid or has expired.</p><p><a href="reset.php">Request a new link</a>, or ask your administrator to send a new invitation.</p><?php endif ?>
<p><a href="index.php">Back to sign in</a></p></section></main></body></html>
