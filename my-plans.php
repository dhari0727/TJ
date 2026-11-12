<?php
$ja_title = "My Plans"; $ja_active = "plans";
session_start();
require 'connection.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$eml = $_SESSION['eml'];
require_once 'ja-icons.php';
require_once 'ja-images.php';
require_once 'ja-trip-parts.php';

$MONTHS_FULL = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];

// view a single saved plan?
$viewId = (int)($_GET['view'] ?? 0);
$viewPlan = null;
if ($viewId) {
    $s = mysqli_prepare($conn, "SELECT * FROM saved_plans WHERE plan_id=? AND eml=?");
    mysqli_stmt_bind_param($s, 'is', $viewId, $eml);
    mysqli_stmt_execute($s);
    $viewPlan = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
}

$s = mysqli_prepare($conn, "SELECT plan_id, destination, days, travel_style, month, party_size, share_token, created_at, itinerary FROM saved_plans WHERE eml=? ORDER BY plan_id DESC");
mysqli_stmt_bind_param($s, 's', $eml);
mysqli_stmt_execute($s);
$plans = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
mysqli_stmt_close($s);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?><link rel="stylesheet" media="print" href="css/journeyai-print.css">
<link rel="stylesheet" href="css/pages-trips.css?v=<?= (int)@filemtime(__DIR__ . '/css/pages-trips.css') ?>">
<link rel="manifest" href="manifest.webmanifest"><meta name="theme-color" content="#123524"><link rel="apple-touch-icon" href="images/icon-192.png">
<script src="js/trips-ui.js?v=<?= (int)@filemtime(__DIR__ . '/js/trips-ui.js') ?>" defer></script>
<script src="js/pwa.js?v=<?= (int)@filemtime(__DIR__ . '/js/pwa.js') ?>" defer></script></head>
<body class="ja tp-page">

<div class="tp-top tp-noprint">
  <div class="ja-container">
    <h1>My trips</h1>
    <?php $ja_tab='plans'; include 'ja-trips-tabs.php'; ?>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container tp-wrap">

    <?php if ($viewId && !$viewPlan): ?>
      <div class="tp-empty">
        <h2>We could not find that plan</h2>
        <p>It may have been deleted, or it belongs to another account.</p>
        <a href="my-plans.php" class="ja-btn ja-btn-primary">Back to saved plans</a>
      </div>

    <?php elseif ($viewPlan): $it = json_decode($viewPlan['itinerary'], true); $vname = explode(',', $viewPlan['destination'])[0]; ?>
      <a href="my-plans.php" class="tp-back tp-noprint">&larr; All saved plans</a>

      <?php if (!empty($_GET['saved'])): ?>
        <p class="tp-saved" role="status"><?= tp_icon('check', 16) ?> Saved. A packing list is ready when you want it.</p>
      <?php endif; ?>

      <div class="tp-viewhead">
        <div>
          <h2><?= htmlspecialchars($viewPlan['destination']) ?></h2>
          <p class="sub"><?= (int)$viewPlan['days'] ?> days, <?= htmlspecialchars($viewPlan['travel_style']) ?><?= $viewPlan['month'] ? ', ' . $MONTHS_FULL[(int)$viewPlan['month']] : '' ?>,
            <?= (int)$viewPlan['party_size'] ?> traveller<?= $viewPlan['party_size']==1?'':'s' ?>. Saved <?= htmlspecialchars(date('j M Y', strtotime($viewPlan['created_at']))) ?>.</p>
        </div>
        <div class="tp-actions tp-noprint" style="display:flex">
          <button class="ja-btn ja-btn-ghost" id="jaShareBtn" data-plan-id="<?= (int)$viewPlan['plan_id'] ?>"><?= tp_icon('share') ?><span>Share link</span></button>
          <?php require_once __DIR__ . '/ja-social.php'; echo ja_social_button('plan', (int)$viewPlan['plan_id'], ['label' => 'Share on social']); ?>
          <button class="ja-btn ja-btn-ghost" data-tp-print><?= tp_icon('print') ?><span>Print itinerary</span></button>
          <button class="ja-btn ja-btn-ghost" data-del data-url="save-plan-delete.php" data-body='<?= htmlspecialchars(json_encode(['plan_id' => (int)$viewPlan['plan_id']]), ENT_QUOTES) ?>' data-name="<?= htmlspecialchars($vname, ENT_QUOTES) ?>" data-redirect="my-plans.php"><?= tp_icon('trash') ?><span>Delete</span></button>
        </div>
      </div>

      <div id="jaShareBox" hidden class="tp-panel tp-sharebox tp-noprint">
        <div class="row">
          <input type="text" class="ja-input" id="jaShareUrl" readonly aria-label="Share link">
          <button class="ja-btn ja-btn-primary" type="button" id="jaShareCopy">Copy link</button>
        </div>
        <p style="margin:8px 0 0">Anyone with this link can view this plan without logging in.</p>
      </div>

      <?php if (!$it || (empty($it['plan']))): ?>
        <div class="tp-state"><h2>This plan has no saved itinerary</h2><p>The snapshot is missing. Build it again from the planner.</p>
          <div class="tp-state-actions"><a class="ja-btn ja-btn-primary" href="trip.php?<?= htmlspecialchars(http_build_query(['dest' => $viewPlan['destination'], 'days' => $viewPlan['days'], 'style' => $viewPlan['travel_style']])) ?>">Rebuild this plan</a></div></div>
      <?php else: ?>
      <div class="tp-layout">
        <div>
          <div class="tp-sectionbar"><h2>Day by day</h2><button type="button" class="tp-linkbtn tp-noprint" id="tpToggleDays" data-state="open">Collapse all days</button></div>
          <?= tp_days($it) ?>
        </div>
        <aside class="tp-side">
          <?php if (!empty($it['cost_breakdown'])): ?><section class="tp-panel"><h2>Where the money goes</h2><?= tp_cost_chart($it) ?></section><?php endif; ?>
          <?php if (!empty($it['highlights'])): ?><section class="tp-panel"><h2>Highlights</h2><?= tp_highlights($it) ?></section><?php endif; ?>
          <section class="tp-panel tp-noprint">
            <h2>Packing list</h2>
            <p>A checklist sized to this trip's days, season and style, with a reason for each item.</p>
            <a href="packing.php?plan=<?= (int)$viewPlan['plan_id'] ?>" class="ja-btn ja-btn-primary"><?= tp_icon('bag') ?><span>Open packing list</span></a>
          </section>
          <section class="tp-panel tp-noprint" id="tpOffline" hidden>
            <h2>Use it without a connection</h2>
            <p>Keep this plan on this device so you can open it on the road.</p>
            <button type="button" class="ja-btn ja-btn-ghost" id="tpOfflineBtn" data-title="<?= htmlspecialchars($vname . ', ' . (int)$viewPlan['days'] . ' days', ENT_QUOTES) ?>" data-cost="<?= (int)($it['total_cost'] ?? 0) ?>" data-days="<?= (int)$viewPlan['days'] ?>"><?= tp_icon('download') ?><span>Save for offline</span></button>
            <p class="tp-offstate" id="tpOfflineState" role="status" hidden><?= tp_icon('wifi-off', 15) ?> Available offline</p>
          </section>
        </aside>
      </div>
      <script type="application/json" id="tripData"><?= json_encode($it, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
      <?php endif; ?>

    <?php elseif (!$plans): ?>
      <div class="tp-empty">
        <h2>No saved plans yet</h2>
        <p>Plan a trip, then choose Save trip on the plan to keep it here with its cost and a packing list.</p>
        <a href="plan-trip.php" class="ja-btn ja-btn-primary">Plan a trip</a>
      </div>
    <?php else: ?>
      <ul class="tp-plans has-lead">
        <?php foreach ($plans as $pl):
          $pit = json_decode($pl['itinerary'], true) ?: [];
          $pname = explode(',', $pl['destination'])[0];
          $pic = ja_image_for($pl['destination']);
          $cost = (int)($pit['total_cost'] ?? 0);
          $href = 'my-plans.php?view=' . (int)$pl['plan_id'];
        ?>
        <li class="tp-plan" data-offline-url="<?= $href ?>">
          <a class="tp-plan-media" href="<?= $href ?>" tabindex="-1" aria-hidden="true"><?php if ($pic): ?><img src="<?= htmlspecialchars($pic) ?>" alt="" loading="lazy"><?php else: ?><span class="tp-plan-km"><?= (int)$pl['days'] ?> days</span><?php endif; ?></a>
          <div class="tp-plan-body">
            <h3><a href="<?= $href ?>"><?= htmlspecialchars($pl['destination']) ?></a></h3>
            <p class="tp-plan-meta"><?= (int)$pl['days'] ?> days, <?= htmlspecialchars($pl['travel_style']) ?><?= $pl['month'] ? ', ' . $MONTHS_FULL[(int)$pl['month']] : '' ?>. Saved <?= htmlspecialchars(date('j M Y', strtotime($pl['created_at']))) ?>.</p>
            <?php if ($cost): ?><p class="tp-plan-cost">About ₹<?= number_format($cost) ?> <small>for <?= (int)$pl['party_size'] ?> traveller<?= $pl['party_size']==1?'':'s' ?></small></p><?php endif; ?>
            <div class="tp-plan-flags">
              <?php if ($pl['share_token']): ?><span><?= tp_icon('share', 14) ?> Shared</span><?php endif; ?>
              <span data-offline-flag><?= tp_icon('wifi-off', 14) ?> Available offline</span>
            </div>
            <div class="tp-plan-actions">
              <a href="<?= $href ?>" class="ja-btn ja-btn-ghost">Open plan</a>
              <button type="button" class="tp-del" data-del data-url="save-plan-delete.php" data-body='<?= htmlspecialchars(json_encode(['plan_id' => (int)$pl['plan_id']]), ENT_QUOTES) ?>' data-name="<?= htmlspecialchars($pname, ENT_QUOTES) ?>"><?= tp_icon('trash', 15) ?> Delete</button>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</main>

<?php include 'ja-footer.php'; ?>
</body>
</html>
