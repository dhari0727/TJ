<?php
$ja_title = "Explore"; $ja_active = "explore";
session_start();
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
require 'ml_client.php';
include 'ja-shell.php';
?>
<div class="ja-explore-hero">
  <div class="ja-eyebrow" style="color:var(--ja-teal)"><?= ja_icon('sparkle',14) ?> One line. A whole trip.</div>
  <h1>Where to next, <?= htmlspecialchars($_SESSION['fname'] ?? '') ?>?</h1>
  <p class="sub">Just tell me your trip in plain words — I'll build the places, route, and cost instantly.</p>

  <form id="smartForm" class="ja-smart-box">
    <?= ja_icon('search',22) ?>
    <input type="text" id="smartInput" autocomplete="off"
      placeholder="e.g. Weekend from Ahmedabad, temples &amp; food"
      value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
    <button type="submit" class="ja-btn ja-btn-primary">Plan it <?= ja_icon('arrow',18) ?></button>
  </form>

  <div class="ja-smart-examples">
    <span>Try:</span>
    <button type="button" class="ja-chip" data-ex="1 day near me, temples">1 day near me, temples</button>
    <button type="button" class="ja-chip" data-ex="Weekend from Ahmedabad, food & gardens">Weekend food & gardens</button>
    <button type="button" class="ja-chip" data-ex="3 days beaches under 20000">3 days beaches under ₹20k</button>
    <button type="button" class="ja-chip" data-ex="ice cream and parks near Anand">Ice cream & parks near Anand</button>
  </div>
  <div id="geoNote" style="font-size:.82rem;color:var(--text-mut);margin-top:10px;min-height:1em"></div>
</div>

<div id="smartResults" class="ja-smart-results"></div>

<?php include 'ja-shell-end.php'; ?>
<script src="js/explore.js" defer></script>
