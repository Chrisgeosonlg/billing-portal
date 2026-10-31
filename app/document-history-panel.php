<?php
if (!isset($doc,$db) || empty($_SESSION['user_id'])) { http_response_code(404); exit; }
// Who created the document and everything done to it since, newest first.
$history=activity_list(null,(int)$doc['id'],100);
panel_start('history','History','Created by '.user_name((int)$doc['created_by']));
?>
<ul class="activity">
<?php foreach ($history as $h): ?><li><strong><?=e($h['summary'])?></strong><span><?=e(user_name($h['user_id']===null?null:(int)$h['user_id']))?> · <?=e(local_time($h['created_at']))?></span></li><?php endforeach ?>
<?php if (!$history || !str_starts_with(end($history)['summary'],'Created ')): ?><li><strong>Created <?=e(number($doc))?></strong><span><?=e(user_name((int)$doc['created_by']))?> · <?=e(local_time($doc['created_at']))?></span></li><?php endif ?>
</ul>
<?php panel_end(); ?>
