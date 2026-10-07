<?php /* Tabs for My Journal. Set $ja_jtab = 'entries' | 'books' | 'new' before including. */ ?>
<div class="ja-tabs">
  <a href="my-entries.php"     class="<?= ($ja_jtab ?? '') === 'entries' ? 'on' : '' ?>">My entries</a>
  <a href="my-storybooks.php"  class="<?= ($ja_jtab ?? '') === 'books'   ? 'on' : '' ?>">Storybooks</a>
  <a href="new-entry.php"      class="<?= ($ja_jtab ?? '') === 'new'     ? 'on' : '' ?>">+ New entry</a>
</div>
