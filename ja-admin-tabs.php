<?php /* Sub-navigation shared by all admin pages. Set $ja_tab before including. */ ?>
<nav class="adm-tabs" aria-label="Admin sections">
  <a href="admin.php"          class="<?= ($ja_tab ?? '') === 'overview' ? 'on' : '' ?>"<?= ($ja_tab ?? '') === 'overview' ? ' aria-current="page"' : '' ?>>Overview</a>
  <a href="admin-moderation.php" class="<?= ($ja_tab ?? '') === 'moderation' ? 'on' : '' ?>"<?= ($ja_tab ?? '') === 'moderation' ? ' aria-current="page"' : '' ?>>Moderation</a>
  <a href="admin-users.php"    class="<?= ($ja_tab ?? '') === 'users' ? 'on' : '' ?>"<?= ($ja_tab ?? '') === 'users' ? ' aria-current="page"' : '' ?>>Users</a>
  <a href="admin-settings.php?tab=email"   class="<?= ($ja_tab ?? '') === 'email' ? 'on' : '' ?>"<?= ($ja_tab ?? '') === 'email' ? ' aria-current="page"' : '' ?>>Email (SMTP)</a>
  <a href="admin-settings.php?tab=social"  class="<?= ($ja_tab ?? '') === 'social' ? 'on' : '' ?>"<?= ($ja_tab ?? '') === 'social' ? ' aria-current="page"' : '' ?>>Social apps</a>
  <a href="admin-settings.php?tab=site"    class="<?= ($ja_tab ?? '') === 'site' ? 'on' : '' ?>"<?= ($ja_tab ?? '') === 'site' ? ' aria-current="page"' : '' ?>>Site &amp; services</a>
</nav>
