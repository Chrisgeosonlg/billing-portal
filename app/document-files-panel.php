<?php
if (!isset($doc,$db) || empty($_SESSION['user_id'])) { http_response_code(404); exit; }
$availableMarks=marks_available(); $docMarks=doc_marks((int)$doc['id']);
$attachments??=attachments_list((int)$doc['id']);
$marksStatus=!$availableMarks ? 'Not set up' : (!$docMarks ? 'Off' : (count($docMarks)===2 ? 'Stamp + signature' : MARK_TYPES[$docMarks[0]]));
panel_start('marks','Stamp & signature',$marksStatus,$docMarks?'ok':'');
if (!$availableMarks): ?><p class="muted">Optional. Upload your company stamp or signature under <a href="index.php?page=marks">Stamp &amp; signature</a> to add them to documents.</p>
<?php else: ?><form method="post" class="mark-choice"><?=csrf()?><input type="hidden" name="action" value="doc_marks"><input type="hidden" name="id" value="<?=$doc['id']?>"><?php foreach ($availableMarks as $type=>$label): ?><label class="check"><input type="checkbox" name="marks[]" value="<?=$type?>" <?=in_array($type,$docMarks,true)?'checked':''?>> Add <?=e(strtolower($label))?></label><?php endforeach ?><button class="quiet">Save</button></form>
<p class="muted">Shown on this <?=e(kind_name($doc['kind']))?> on screen and in its PDF, emails and customer links. Saves as soon as you tick or untick.</p><?php endif;
panel_end();
panel_start('attachments','Supporting documents',$attachments?count($attachments).' file'.(count($attachments)>1?'s':''):'None',$attachments?'ok':''); ?>
<p class="muted">PDFs that go with this <?=e(kind_name($doc['kind']))?>, such as the TRA EFD receipt. They are emailed with it and offered on the customer link.</p>
<?php if ($attachments): ?><ul class="attachment-list"><?php foreach ($attachments as $file): ?><li><a href="document.php?id=<?=$doc['id']?>&amp;file=<?=$file['id']?>"><?=e($file['name'])?></a> <span class="muted"><?=attachment_size($file['size'])?></span><form method="post"><?=csrf()?><input type="hidden" name="action" value="remove_attachment"><input type="hidden" name="id" value="<?=$doc['id']?>"><input type="hidden" name="file" value="<?=$file['id']?>"><button class="quiet">Remove</button></form></li><?php endforeach ?></ul><?php endif ?>
<?php if (count($attachments)<ATTACHMENT_MAX_FILES): ?><form method="post" enctype="multipart/form-data"><?=csrf()?><input type="hidden" name="action" value="attach_files"><input type="hidden" name="id" value="<?=$doc['id']?>"><label>PDF files (up to 5 MB each)<input type="file" name="attachments[]" accept="application/pdf,.pdf" multiple required></label><button>Attach</button></form><?php endif ?>
<?php panel_end();
