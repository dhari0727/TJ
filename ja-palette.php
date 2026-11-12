<?php
/**
 * JourneyAI — command palette markup (Ctrl/Cmd+K, the Search buttons, or "/").
 * Logic lives in js/ja-palette.js. Included once per page from ja-footer.php / ja-shell-end.php / the auth pages.
 * Needs ja-nav.php ($ja_side_nav / $ja_public_nav) — it is loaded by ja-head.php.
 */
if (defined('JA_PALETTE_DONE')) return;
define('JA_PALETTE_DONE', 1);
require_once __DIR__ . '/ja-icons.php';
if (!isset($ja_side_nav)) require __DIR__ . '/ja-nav.php';
$ja_pal_in = !empty($_SESSION['eml']);
$ja_pal_pages = [];
foreach (($ja_pal_in ? $ja_side_nav : $ja_public_nav) as [$k, $href, $ic, $lbl]) $ja_pal_pages[] = ['label' => $lbl, 'href' => $href];
if ($ja_pal_in) {
    $ja_pal_pages[] = ['label' => 'Write a new journal', 'href' => 'new-entry.php'];
    $ja_pal_pages[] = ['label' => 'Take a photo', 'href' => 'camera.php'];
    $ja_pal_pages[] = ['label' => 'Private photos', 'href' => 'camera-roll.php'];
    $ja_pal_pages[] = ['label' => 'Your profile', 'href' => 'profile.php'];
} else {
    $ja_pal_pages[] = ['label' => 'Log in', 'href' => 'login.php'];
    $ja_pal_pages[] = ['label' => 'Create an account', 'href' => 'register.php'];
}
?>
<div class="ja-pal" id="jaPal" hidden data-pages="<?= htmlspecialchars(json_encode($ja_pal_pages), ENT_QUOTES) ?>">
  <div class="ja-pal-scrim" data-ja-pal-close></div>
  <div class="ja-pal-box" role="dialog" aria-modal="true" aria-labelledby="jaPalTitle">
    <h2 class="ja-sr" id="jaPalTitle">Search and shortcuts</h2>
    <div class="ja-pal-top">
      <?= ja_icon('search', 20) ?>
      <input type="text" id="jaPalInput" class="ja-pal-input" role="combobox" aria-expanded="true" aria-controls="jaPalList" aria-autocomplete="list" aria-activedescendant="" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Search places or pages, or type plan: 3 days in Goa" aria-label="Search places and pages">
      <button type="button" class="ja-pal-x" data-ja-pal-close aria-label="Close search"><?= ja_icon('x', 18) ?></button>
    </div>
    <ul class="ja-pal-list" id="jaPalList" role="listbox" aria-label="Results"></ul>
    <p class="ja-pal-foot"><span><kbd class="ja-kbd">&uarr;</kbd> <kbd class="ja-kbd">&darr;</kbd> to move</span><span><kbd class="ja-kbd">Enter</kbd> to open</span><span><kbd class="ja-kbd">Esc</kbd> to close</span></p>
    <div class="ja-sr" id="jaPalStatus" role="status" aria-live="polite"></div>
  </div>
</div>
