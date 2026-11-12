<?php
/**
 * JourneyAI — ONE trip page. Replaces the separate itinerary and route pages.
 *
 *   trip.php?dest=Goa,+India&days=5            -> opens on "Day by day"
 *   trip.php?place=Anand&mode=day&interests=.. -> opens on "Route & map"
 *   &tab=itinerary | route   forces a tab (the other tab loads on click)
 * Old itinerary.php / route.php links redirect here.
 */
$ja_title = "Your trip"; $ja_active = "plan";
session_start();
require 'ml_client.php';
require 'ja-images.php';

$dest        = trim($_GET['dest'] ?? '');
$place       = trim($_GET['place'] ?? '');
$destination = trim($_GET['destination'] ?? '');     // "A to B" routes
if ($dest === '' && $place !== '') $dest = $place;     // a typed place works for the itinerary too
if ($place === '' && $dest !== '') $place = trim(explode(',', $dest)[0]);
if ($dest === '' && $place === '') { header('Location: plan-trip.php'); exit; }

$tab = $_GET['tab'] ?? '';
if (!in_array($tab, ['itinerary', 'route'], true)) $tab = (isset($_GET['place']) && !isset($_GET['dest'])) ? 'route' : 'itinerary';

/* ---- itinerary inputs ---- */
$STYLES = ['budget','mid-range','luxury','adventure','family','solo','backpacker'];
$days  = max(1, min(21, (int)($_GET['days'] ?? 5)));
$style = in_array($_GET['style'] ?? '', $STYLES, true) ? $_GET['style'] : 'mid-range';
$month = (int)($_GET['month'] ?? 0); $month = ($month >= 1 && $month <= 12) ? $month : null;
$party = max(1, min(20, (int)($_GET['party'] ?? 1)));
$ja_saved_flag = !empty($_GET['saved']);

/* ---- route inputs ---- */
$mode  = $_GET['mode'] ?? ($days <= 1 ? 'day' : ($days <= 2 ? 'weekend' : 'short'));
$stops = max(2, min(8, (int)($_GET['stops'] ?? 4)));
$interests = array_filter(array_map('trim', explode(',', $_GET['interests'] ?? '')));

$title = $destination !== '' ? explode(',', $place)[0] . ' to ' . explode(',', $destination)[0] : explode(',', $dest)[0];

function trip_url($tab, $extra = []) {
    $q = array_merge($_GET, ['tab' => $tab], $extra);
    return 'trip.php?' . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?><link rel="stylesheet" media="print" href="css/journeyai-print.css">
<link rel="stylesheet" href="css/pages-trips.css?v=<?= (int)@filemtime(__DIR__ . '/css/pages-trips.css') ?>">
<link rel="manifest" href="manifest.webmanifest"><meta name="theme-color" content="#14213D"><link rel="apple-touch-icon" href="images/icon-192.png">
<script src="js/trips-ui.js?v=<?= (int)@filemtime(__DIR__ . '/js/trips-ui.js') ?>" defer></script>
<script src="js/pwa.js?v=<?= (int)@filemtime(__DIR__ . '/js/pwa.js') ?>" defer></script></head>
<body class="ja tp-page">

<div class="tp-top">
  <div class="ja-container">
    <a href="plan-trip.php" class="tp-back">&larr; Plan another trip</a>
    <h1><?= htmlspecialchars($title) ?></h1>
    <nav class="tp-tabs" aria-label="Trip views">
      <a href="<?= htmlspecialchars(trip_url('itinerary')) ?>" <?= $tab === 'itinerary' ? 'aria-current="page"' : '' ?>>Day by day and cost</a>
      <a href="<?= htmlspecialchars(trip_url('route')) ?>" <?= $tab === 'route' ? 'aria-current="page"' : '' ?>>Route and map</a>
      <a href="packing.php?<?= htmlspecialchars(http_build_query(['dest' => $dest, 'days' => $days])) ?>">Packing list</a>
    </nav>
  </div>
</div>

<?php
/* Lookups can take several seconds when cold, so paint a skeleton with progress text first, then hide it once the content is ready. */
function tp_loading($msg, $kind) {
    echo '<div id="tpLoading" class="ja-container tp-wrap tp-loading" role="status" aria-live="polite"><p class="tp-loadtext">' . htmlspecialchars($msg) . '</p>'
       . '<div class="tp-skel ' . $kind . '"><i></i><i></i><i></i><i></i></div></div>';
    echo str_repeat(' ', 1024) . "
";
    while (ob_get_level() > 0) @ob_end_flush();
    flush();
}
function tp_loaded() { echo '<style>#tpLoading{display:none}</style>'; }
?>
<?php
if ($tab === 'itinerary') {
    tp_loading('Building a ' . $days . '-day plan for ' . explode(',', $dest)[0] . '. The first lookup can take a few seconds.', 'itin');
    $it = ml_itinerary(['destination' => $dest, 'days' => $days, 'travel_style' => $style, 'month' => $month, 'party_size' => $party]);
    $hero = ja_image_for($dest);
    $hasError = !empty($it['__error']) || ($it['status'] ?? '') === 'error';
    tp_loaded();
    include 'ja-trip-itinerary.php';
    $sim = ml_similar($dest, 4);
    if (empty($sim['__error']) && !empty($sim['similar'])): ?>
<section class="ja-container tp-wrap tp-similar"><h2>If you like this, try</h2>
  <p class="tp-sub">Matched on what travellers wrote, what you do there, cost and region.</p>
  <ul>
  <?php foreach ($sim['similar'] as $x): $pic = ja_image_for($x['destination']); ?>
    <li><a href="trip.php?<?= htmlspecialchars(http_build_query(['dest' => $x['destination'], 'days' => $days])) ?>">
      <?php if ($pic): ?><img src="<?= htmlspecialchars($pic) ?>" alt="" loading="lazy" width="210" height="110"><?php else: ?><span class="tp-noimg"></span><?php endif; ?>
      <strong><?= htmlspecialchars(explode(',', $x['destination'])[0]) ?></strong>
      <span><?= htmlspecialchars($x['why']) ?></span></a></li>
  <?php endforeach; ?></ul></section>
<?php endif;
} else {
    $reqBody = ['place' => $place, 'mode' => $mode, 'interests' => array_values($interests), 'stops' => $stops];
    if ($destination !== '') $reqBody['destination'] = $destination;
    tp_loading('Finding stops near ' . explode(',', $place)[0] . '. Cold lookups can take up to a minute.', 'route');
    $r = ml_request('POST', '/route', $reqBody, 60);
    $isBetween = ($destination !== '' && empty($r['__error']));
    $err = !empty($r['__error']) || ($r['status'] ?? '') === 'error';
    tp_loaded();
    include 'ja-trip-route.php';
}
?>
<?php include 'ja-footer.php'; ?>
</body>
</html>
