<?php
if (!isset($db) || empty($_SESSION['user_id']) || !is_admin()) { http_response_code(404); exit; }
if (!users_ready()): ?>
<section class="card"><h2>Users are not enabled yet</h2><p>Import <strong>users-schema.sql</strong> once in phpMyAdmin. Your account becomes the administrator, and you can then add up to <?=users_max()?> users here.</p></section>
<?php return; endif;
$users=query("SELECT * FROM users WHERE status<>'removed' ORDER BY role='admin' DESC,id")->fetchAll();
$everyone=query('SELECT * FROM users ORDER BY id')->fetchAll();
$count=staff_count(); $max=users_max();
$invite=$_SESSION['invite_link']??null; unset($_SESSION['invite_link']);
$filter=(int)($_GET['user']??0); if (!in_array($filter,array_map('intval',array_column($everyone,'id')),true)) $filter=0;
$offset=max(0,(int)($_GET['offset']??0)); $perPage=50;
$entries=activity_list($filter?:null,null,$perPage+1,$offset);
?>
<?php if ($invite): ?><section class="card alert"><h2>Send the invitation link yourself</h2><p>The email to <strong><?=e($invite['email'])?></strong> could not be sent. Copy this link and send it to them directly (for example on WhatsApp). It works once and expires in 3 days. Do not open it yourself.</p><div class="inline"><input id="customer-link" value="<?=e($invite['link'])?>" readonly aria-label="Invitation link"><button type="button" id="copy-customer-link">Copy link</button></div><p id="copy-link-status" role="status"></p></section><?php endif ?>
<section class="card"><div class="section-head"><h2>Users</h2><span class="chip<?=$count>=$max?' chip-warn':''?>"><?=$count?> of <?=$max?> users</span></div>
<div class="table-wrap"><table><thead><tr><th>User</th><th>Role</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead><tbody>
<?php foreach ($users as $u): [$state,$tone]=user_state($u); $self=(int)$u['id']===(int)$_SESSION['user_id']; ?>
<tr><td><strong><?=e(user_label($u))?></strong><?php if (trim($u['name'])!==''): ?><small><?=e($u['email'])?></small><?php endif ?></td>
<td><?=$u['role']==='admin'?'Administrator':'Staff'?></td>
<td><span class="chip<?=$tone!==''?' chip-'.$tone:''?>"><?=e($state)?></span></td>
<td class="nowrap"><?=$u['last_login_at']?e(local_time($u['last_login_at'])):'<span class="muted">Never</span>'?></td>
<td><?php if ($self): ?><span class="muted">You</span><?php elseif ($u['role']==='staff'): ?><div class="user-actions">
<?php if ($u['password_hash']==='' && $u['status']==='active'): ?><form method="post"><?=csrf()?><input type="hidden" name="action" value="user_resend"><input type="hidden" name="id" value="<?=$u['id']?>"><button class="quiet">Resend invitation</button></form><?php endif ?>
<form method="post"><?=csrf()?><input type="hidden" name="action" value="<?=$u['status']==='paused'?'user_resume':'user_pause'?>"><input type="hidden" name="id" value="<?=$u['id']?>"><button class="quiet"><?=$u['status']==='paused'?'Resume':'Pause'?></button></form>
<form method="post" data-confirm="Remove <?=e(user_label($u))?>? They will no longer be able to sign in. Their past work keeps their name."><?=csrf()?><input type="hidden" name="action" value="user_remove"><input type="hidden" name="id" value="<?=$u['id']?>"><button class="danger">Remove</button></form>
</div><?php endif ?></td></tr>
<?php endforeach ?></tbody></table></div>
<p class="muted">Paused users are signed out straight away and cannot sign in or reset their password until you resume them. Removing a user frees their place; their documents and history still show their name.</p></section>

<section class="card"><h2>Add a user</h2>
<?php if ($count>=$max): ?><p class="muted">You have the maximum of <?=$max?> users. Remove a user to add someone else.</p>
<?php else: ?><p class="muted">They receive an email with a link to create their own password. The link works once and expires in 3 days.</p>
<form method="post" class="form-grid"><?=csrf()?><input type="hidden" name="action" value="user_add"><label>Full name<input name="name" maxlength="120" required autocomplete="off" value="<?=e(($_POST['action']??'')==='user_add'?($_POST['name']??''):'')?>"></label><label>Email<input type="email" name="email" maxlength="190" required autocomplete="off" value="<?=e(($_POST['action']??'')==='user_add'?($_POST['email']??''):'')?>"></label><div><button>Add user &amp; send invitation</button></div></form><?php endif ?></section>

<section class="card" id="activity"><div class="section-head"><h2>Activity</h2><form method="get" class="inline staff-filter" action="index.php#activity"><input type="hidden" name="page" value="users"><label>User<select name="user"><option value="">Everyone</option><?php foreach ($everyone as $u): ?><option value="<?=$u['id']?>" <?=$filter===(int)$u['id']?'selected':''?>><?=e(user_label($u))?><?=$u['status']==='removed'?' (removed)':''?></option><?php endforeach ?></select></label><button class="quiet">Show</button></form><?php if ($entries): ?><form method="post" data-confirm="Clear the whole activity history? Every recorded action is removed and cannot be recovered."><?=csrf()?><input type="hidden" name="action" value="activity_clear"><button class="quiet danger-text">Clear history</button></form><?php endif ?></div>
<?php if (!$entries): ?><p class="muted">No activity recorded yet.</p><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead><tbody>
<?php foreach (array_slice($entries,0,$perPage) as $a): ?><tr><td class="nowrap"><?=e(local_time($a['created_at']))?></td><td><?=e(user_name($a['user_id']===null?null:(int)$a['user_id']))?></td><td><?=e($a['summary'])?><?php if ($a['document_id']): ?> · <a href="index.php?page=view&id=<?=(int)$a['document_id']?>">Open</a><?php elseif ($a['customer_id']): ?> · <a href="index.php?page=customer&id=<?=(int)$a['customer_id']?>">Open</a><?php endif ?></td></tr><?php endforeach ?>
</tbody></table></div>
<div class="actions"><?php if ($offset>0): ?><a href="index.php?page=users&user=<?=$filter?:''?>&offset=<?=max(0,$offset-$perPage)?>#activity">← Newer</a><?php endif ?><?php if (count($entries)>$perPage): ?><a href="index.php?page=users&user=<?=$filter?:''?>&offset=<?=$offset+$perPage?>#activity">Older →</a><?php endif ?></div>
<?php endif ?></section>
