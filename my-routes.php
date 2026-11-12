<?php
$ja_title = "My Routes"; $ja_active = "plans";
session_start();
require 'connection.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$eml = $_SESSION['eml'];
require_once 'ja-icons.php';
require_once 'ja-images.php';
require_once 'ja-trip-parts.php';

// view a single saved route?
$viewId = (int)($_GET['view'] ?? 0);
$viewRoute = null;
if ($viewId) {
    $s = mysqli_prepare($conn, "SELECT * FROM saved_routes WHERE route_id=? AND eml=?");
    mysqli_stmt_bind_param($s, 'is', $viewId, $eml);
    mysqli_stmt_execute($s);
    $viewRoute = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
}

$s = mysqli_prepare($conn, "SELECT route_id, title, origin, mode, total_km, created_at FROM saved_routes WHERE eml=? ORDER BY route_id DESC");
mysqli_stmt_bind_param($s, 's', $eml);
mysqli_stmt_execute($s);
$routes = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
mysqli_stmt_close($s);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?><link rel="stylesheet" media="print" href="css/journeyai-print.css">
<link rel="stylesheet" href="css/pages-trips.css?v=<?= (int)@filemtime(__DIR__ . '/css/pages-trips.css') ?>">
<script src="js/trips-ui.js?v=<?= (int)@filemtime(__DIR__ . '/js/trips-ui.js') ?>" defer></script></head>
<body class="ja tp-page">

<div class="tp-top tp-noprint">
  <div class="ja-container">
    <h1>My trips</h1>
    <?php $ja_tab='routes'; include 'ja-trips-tabs.php'; ?>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container tp-wrap">

    <?php if ($viewId && !$viewRoute): ?>
      <div class="tp-empty">
        <h2>We could not find that route</h2>
        <p>It may have been deleted, or it belongs to another account.</p>
        <a href="my-routes.php" class="ja-btn ja-btn-primary">Back to saved routes</a>
      </div>

    <?php elseif ($viewRoute): $data = json_decode($viewRoute['data'], true); $rname = $viewRoute['title']; ?>
      <a href="my-routes.php" class="tp-back tp-noprint">&larr; All saved routes</a>
      <div class="tp-viewhead">
        <div>
          <h2><?= htmlspecialchars($viewRoute['title']) ?></h2>
          <p class="sub"><?= (int)$viewRoute['total_km'] ?> km. Saved <?= htmlspecialchars(date('j M Y', strtotime($viewRoute['created_at']))) ?>.</p>
        </div>
        <div class="tp-actions tp-noprint" style="display:flex">
          <button class="ja-btn ja-btn-ghost" data-tp-print><?= tp_icon('print') ?><span>Print route</span></button>
          <button class="ja-btn ja-btn-ghost" data-del data-url="save-route.php" data-body='<?= htmlspecialchars(json_encode(['action' => 'delete', 'route_id' => (int)$viewRoute['route_id']]), ENT_QUOTES) ?>' data-name="<?= htmlspecialchars($rname, ENT_QUOTES) ?>" data-redirect="my-routes.php"><?= tp_icon('trash') ?><span>Delete route</span></button>
        </div>
      </div>
      <div class="ja-route-line">
        <div class="ja-route-stop origin"><div class="ja-route-dot"><?= ja_icon('home',16) ?></div>
          <div class="ja-route-card"><div class="ja-route-info"><div class="ja-route-name">Start at <?= htmlspecialchars(explode(',',$data['origin']['display'] ?? $viewRoute['origin'])[0]) ?></div></div></div></div>
        <?php foreach (($data['route'] ?? []) as $i=>$st): ?>
          <div class="ja-route-stop"><div class="ja-route-dot"><?= $i+1 ?></div>
            <div class="ja-route-card"><div class="ja-route-info">
              <div class="ja-route-name"><?= htmlspecialchars($st['name']) ?></div>
              <div class="ja-route-sub"><?= isset($st['leg_km'])? $st['leg_km'].' km':'' ?><?= !empty($st['do'])? ', ' . htmlspecialchars($st['do']):'' ?></div>
              <?php if (!empty($st['lat'])): ?><a class="ja-route-map" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=<?= htmlspecialchars($st['lat']) ?>,<?= htmlspecialchars($st['lon']) ?>"><?= ja_icon('map-pin',13) ?> Google Maps</a><?php endif; ?>
            </div></div></div>
        <?php endforeach; ?>
      </div>

    <?php elseif (!$routes): ?>
      <div class="tp-empty">
        <h2>No saved routes yet</h2>
        <p>Build a route from a place you know, then choose Save route to keep its stops and distances here.</p>
        <a href="plan-trip.php" class="ja-btn ja-btn-primary">Plan a trip</a>
      </div>
    <?php else: ?>
      <ul class="tp-plans">
        <?php foreach ($routes as $rt):
          $oname = explode(',', $rt['origin'])[0];
          $pic = ja_image_for($rt['origin']);
          $href = 'my-routes.php?view=' . (int)$rt['route_id'];
        ?>
        <li class="tp-plan">
          <a class="tp-plan-media" href="<?= $href ?>" tabindex="-1" aria-hidden="true"><?php if ($pic): ?><img src="<?= htmlspecialchars($pic) ?>" alt="" loading="lazy"><?php else: ?><span class="tp-plan-km"><?= (int)$rt['total_km'] ?> km</span><?php endif; ?></a>
          <div class="tp-plan-body">
            <h3><a href="<?= $href ?>"><?= htmlspecialchars($rt['title']) ?></a></h3>
            <p class="tp-plan-meta">From <?= htmlspecialchars($oname) ?>, <?= htmlspecialchars($rt['mode']) ?>. Saved <?= htmlspecialchars(date('j M Y', strtotime($rt['created_at']))) ?>.</p>
            <p class="tp-plan-cost"><?= (int)$rt['total_km'] ?> km <small>in total</small></p>
            <div class="tp-plan-actions">
              <a href="<?= $href ?>" class="ja-btn ja-btn-ghost">Open route</a>
              <button type="button" class="tp-del" data-del data-url="save-route.php" data-body='<?= htmlspecialchars(json_encode(['action' => 'delete', 'route_id' => (int)$rt['route_id']]), ENT_QUOTES) ?>' data-name="<?= htmlspecialchars($rt['title'], ENT_QUOTES) ?>"><?= tp_icon('trash', 15) ?> Delete</button>
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
