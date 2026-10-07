<?php /* Tabs shared by My Plans / My Routes so "My Trips" reads as one place. $ja_tab = 'plans'|'routes' */ ?>
<div class="ja-tabs">
  <a href="my-plans.php" class="<?= ($ja_tab ?? '') === 'plans' ? 'on' : '' ?>">Saved plans</a>
  <a href="my-routes.php" class="<?= ($ja_tab ?? '') === 'routes' ? 'on' : '' ?>">Saved routes</a>
  <a href="packing.php"   class="<?= ($ja_tab ?? '') === 'packing' ? 'on' : '' ?>">Packing lists</a>
</div>
