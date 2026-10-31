<?php
if (!isset($db) || empty($_SESSION['user_id'])) { http_response_code(404); exit; }
$person=signatory(); ?>
<section class="card"><h2>Company stamp and signature</h2>
<p>Optional. Upload them once here, then choose on each Proforma or Tax Invoice (under <strong>Send to customer</strong>) whether its PDF shows the stamp, the signature or both. Nothing is added to a document unless you tick it there.</p>
<p class="muted">Use a PNG with a transparent background, cropped close to the ink, ideally scanned at 300 dpi. Up to 1 MB. Anyone signed in to the portal can add these to documents, so keep staff accounts limited to people you trust.</p></section>
<div class="marks-grid">
<?php foreach (MARK_TYPES as $type=>$label): $path=mark_path($type); ?>
<section class="card mark-card" id="mark-<?=$type?>"><h2><?=e($label)?></h2>
<div class="mark-preview"><?php if ($path): ?><img src="mark.php?type=<?=$type?>&amp;v=<?=filemtime($path)?>" alt="<?=e($label)?>"><?php else: ?><span class="muted">Not uploaded</span><?php endif ?></div>
<form method="post" enctype="multipart/form-data"><?=csrf()?><input type="hidden" name="action" value="mark_upload"><input type="hidden" name="type" value="<?=$type?>"><label><?=$path?'Replace with a new PNG':'PNG image'?><input type="file" name="image" accept="image/png,.png" required></label><button><?=$path?'Replace':'Upload'?></button></form>
<?php if ($path): ?><form method="post"><?=csrf()?><input type="hidden" name="action" value="mark_remove"><input type="hidden" name="type" value="<?=$type?>"><button class="quiet">Remove</button></form><?php endif ?>
</section>
<?php endforeach ?>
</div>
<section class="card"><h2>Signatory</h2><p class="muted">Printed under the signature. Leave blank to show "Authorised signature".</p>
<form method="post"><?=csrf()?><input type="hidden" name="action" value="signatory_save"><div class="form-grid"><label>Name<input name="signatory_name" maxlength="80" value="<?=e($person['name'])?>" placeholder="e.g. Jane Mushi"></label><label>Title<input name="signatory_title" maxlength="80" value="<?=e($person['title'])?>" placeholder="e.g. Managing Director"></label></div><button>Save signatory</button></form></section>
