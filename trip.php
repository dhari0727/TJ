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
<head><?php include 'ja-head.php'; ?><link rel="stylesheet" media="print" href="css/journeyai-print.css"></head>
<body class="ja">

<div class="ja-pagehead" style="padding-bottom:0">
  <div class="ja-container">
    <a href="plan-trip.php" class="ja-muted" style="font-size:.85rem">&larr; Plan another trip</a>
    <h1 style="margin:6px 0 14px"><?= htmlspecialchars($title) ?></h1>
    <div class="ja-tabs" style="margin-bottom:0">
      <a href="<?= htmlspecialchars(trip_url('itinerary')) ?>" class="<?= $tab === 'itinerary' ? 'on' : '' ?>">Day by day &amp; cost</a>
      <a href="<?= htmlspecialchars(trip_url('route')) ?>" class="<?= $tab === 'route' ? 'on' : '' ?>">Route &amp; map</a>
      <a href="packing.php?<?= htmlspecialchars(http_build_query(['dest' => $dest, 'days' => $days])) ?>">Packing list &rarr;</a>
    </div>
  </div>
</div>

<?php
if ($tab === 'itinerary') {
    $it = ml_itinerary(['destination' => $dest, 'days' => $days, 'travel_style' => $style, 'month' => $month, 'party_size' => $party]);
    $hero = ja_image_for($dest);
    $hasError = !empty($it['__error']) || ($it['status'] ?? '') === 'error';
    include 'ja-trip-itinerary.php';
} else {
    $reqBody = ['place' => $place, 'mode' => $mode, 'interests' => array_values($interests), 'stops' => $stops];
    if ($destination !== '') $reqBody['destination'] = $destination;
    $r = ml_request('POST', '/route', $reqBody, 60);
    $isBetween = ($destination !== '' && empty($r['__error']));
    $err = !empty($r['__error']) || ($r['status'] ?? '') === 'error';
    include 'ja-trip-route.php';
}
?>
<?php include 'ja-footer.php'; ?>
</body>
</html>
