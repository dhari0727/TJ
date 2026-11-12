<?php
/**
 * JourneyAI — public, read-only view of a saved plan via its share token.
 * No login/session check required. Looks up saved_plans by share_token.
 */
$ja_title = "Shared Plan"; $ja_active = "";
require_once 'ja-icons.php';
require 'connection.php';
require_once 'ja-trip-parts.php';
require_once 'ja-images.php';

$MONTHS = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];

$token = trim($_GET['t'] ?? '');
$plan = null;
$packing = [];
if ($token) {
    $s = mysqli_prepare($conn, "SELECT * FROM saved_plans WHERE share_token=?");
    mysqli_stmt_bind_param($s, 's', $token);
    mysqli_stmt_execute($s);
    $plan = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);

    if ($plan) {
        $s = mysqli_prepare($conn, "SELECT i.label, i.category, i.is_checked, i.qty FROM packing_list_items i JOIN packing_lists l ON l.list_id = i.list_id WHERE l.plan_id=? ORDER BY i.sort_order ASC, i.item_id ASC");
        mysqli_stmt_bind_param($s, 'i', $plan['plan_id']);
        mysqli_stmt_execute($s);
        $packing = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
        mysqli_stmt_close($s);
    }
}
$it = $plan ? json_decode($plan['itinerary'], true) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?><link rel="stylesheet" media="print" href="css/journeyai-print.css">
<link rel="stylesheet" href="css/pages-trips.css?v=<?= (int)@filemtime(__DIR__ . '/css/pages-trips.css') ?>">
<meta name="robots" content="noindex">
<script src="js/trips-ui.js?v=<?= (int)@filemtime(__DIR__ . '/js/trips-ui.js') ?>" defer></script></head>
<body class="ja tp-page">

<?php if (!$plan || !$it || empty($it['plan'])): ?>
<main class="ja-main"><div class="ja-container tp-wrap">
  <div class="tp-state" role="alert">
    <h2>This link does not open a plan</h2>
    <p>It may have been mistyped, or the owner removed the plan. Ask them to send a fresh link.</p>
    <div class="tp-state-actions"><a href="index.php" class="ja-btn ja-btn-primary">Go to JourneyAI</a><a href="plan-trip.php" class="ja-btn ja-btn-ghost">Plan your own trip</a></div>
  </div>
</div></main>
<?php else: $hero = ja_image_for($plan['destination']); ?>
<header class="tp-head">
  <div class="ja-container tp-head-in">
    <div class="tp-head-text">
      <p class="tp-region"><?= tp_icon('share', 15) ?> A plan shared with you</p>
      <h1 style="font-size:clamp(2rem,5vw,3.1rem);margin:0 0 8px;line-height:1.05"><?= htmlspecialchars($plan['destination']) ?></h1>
      <p class="tp-lede"><?= (int)$plan['days'] ?> days, <?= htmlspecialchars($plan['travel_style']) ?><?php if ($plan['month']): ?>, <?= $MONTHS[(int)$plan['month']] ?><?php endif; ?>, <?= (int)$plan['party_size'] ?> traveller<?= $plan['party_size']==1?'':'s' ?>.</p>
      <?php if (!empty($it['total_cost'])): ?>
      <dl class="tp-stats">
        <div><dt>Estimated total</dt><dd>₹<?= number_format($it['total_cost']) ?></dd></div>
        <?php if (!empty($it['per_day_cost'])): ?><div><dt>Per day</dt><dd>₹<?= number_format($it['per_day_cost']) ?></dd></div><?php endif; ?>
      </dl>
      <?php endif; ?>
      <div class="tp-actions">
        <button type="button" class="ja-btn ja-btn-primary" data-tp-print><?= tp_icon('print') ?><span>Print itinerary</span></button>
        <a class="ja-btn ja-btn-ghost" href="plan-trip.php"><span>Plan your own trip</span></a>
      </div>
    </div>
    <?php if ($hero): ?><figure class="tp-photo"><img src="<?= htmlspecialchars($hero) ?>" alt="<?= htmlspecialchars(explode(',', $plan['destination'])[0]) ?>" width="560" height="380"></figure><?php endif; ?>
  </div>
</header>

<main class="ja-main">
  <div class="ja-container tp-wrap">
    <div class="tp-layout">
      <div>
        <div class="tp-sectionbar"><h2>Day by day</h2><button type="button" class="tp-linkbtn" id="tpToggleDays" data-state="open">Collapse all days</button></div>
        <?= tp_days($it) ?>
      </div>
      <aside class="tp-side">
        <?php if (!empty($it['cost_breakdown'])): ?><section class="tp-panel"><h2>Where the money goes</h2><?= tp_cost_chart($it) ?></section><?php endif; ?>
        <?php if (!empty($it['highlights'])): ?><section class="tp-panel"><h2>Highlights</h2><?= tp_highlights($it) ?></section><?php endif; ?>
        <?php if ($packing): ?>
        <section class="tp-panel">
          <h2>Packing checklist</h2>
          <ul class="tp-hl">
            <?php foreach ($packing as $p): ?>
              <li><input type="checkbox" disabled <?= $p['is_checked'] ? 'checked' : '' ?> aria-label="<?= $p['is_checked'] ? 'Packed' : 'Not packed' ?>" style="width:17px;height:17px;flex:none;margin-top:3px">
                <span style="<?= $p['is_checked'] ? 'text-decoration:line-through;color:var(--text-mut)' : '' ?>"><?= htmlspecialchars($p['label']) ?><?= (int)$p['qty'] > 1 ? ' x' . (int)$p['qty'] : '' ?></span></li>
            <?php endforeach; ?>
          </ul>
        </section>
        <?php endif; ?>
      </aside>
    </div>
  </div>
</main>
<?php endif; ?>

<?php include 'ja-footer.php'; ?>
</body>
</html>
