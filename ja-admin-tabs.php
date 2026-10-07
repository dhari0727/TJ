<?php /* Sub-navigation shared by all admin pages. Set $ja_tab before including. */ ?>
<div class="ja-eyebrow" style="color:var(--ja-teal)"><?= ja_icon('gem',14) ?> Admin</div>
<div class="ja-tabs" style="margin-top:6px">
  <a href="admin.php"          class="<?= ($ja_tab ?? '') === 'overview' ? 'on' : '' ?>">Overview</a>
  <a href="admin-users.php"    class="<?= ($ja_tab ?? '') === 'users' ? 'on' : '' ?>">Users</a>
  <a href="admin-settings.php?tab=email"   class="<?= ($ja_tab ?? '') === 'email' ? 'on' : '' ?>">Email (SMTP)</a>
  <a href="admin-settings.php?tab=site"    class="<?= ($ja_tab ?? '') === 'site' ? 'on' : '' ?>">Site &amp; services</a>
</div>
