<?php /* Tabs for the Community area. Set $ja_ctab = 'feed' | 'share' | 'read' before including. */ ?>
<div class="ja-tabs">
  <a href="feed.php"          class="<?= ($ja_ctab ?? '') === 'feed'  ? 'on' : '' ?>">Feed</a>
  <a href="share.php"         class="<?= ($ja_ctab ?? '') === 'share' ? 'on' : '' ?>">Share a post</a>
  <a href="read-journals.php" class="<?= ($ja_ctab ?? '') === 'read'  ? 'on' : '' ?>">Read journals</a>
</div>
