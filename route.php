<?php
$ja_title = "Route"; $ja_active = "plan";
session_start();
require 'ml_client.php';

$place = trim($_GET['place'] ?? '');
$mode  = $_GET['mode'] ?? 'day';
$stops = max(2, min(8, (int)($_GET['stops'] ?? 4)));
$interests = array_filter(array_map('trim', explode(',', $_GET['interests'] ?? '')));

$r = $place ? ml_request('POST','/route', [
    'place'=>$place,'mode'=>$mode,'interests'=>array_values($interests),'stops'=>$stops
], 60) : ['__error'=>'No starting point given.'];

$err = !empty($r['__error']) || ($r['status'] ?? '')==='error';

function stop_icon($cat){
  return ['religious'=>'star','heritage'=>'home','garden'=>'sun','nature'=>'sun',
    'museum'=>'book','funpark'=>'compass','hotel'=>'home','food'=>'heart',
    'shopping'=>'wallet','beach'=>'sun','attraction'=>'map-pin'][$cat] ?? 'map-pin';
}
function render_stops($route, $origin){
  foreach ($route as $i=>$s):
    $img = $s['image'] ?? ''; $eat = $s['eat'] ?? null; ?>
    <div class="ja-route-stop reveal" data-stop="<?= $i ?>">
      <div class="ja-route-dot"><?= $i+1 ?></div>
      <div class="ja-route-card">
        <?php if ($img): ?><div class="ja-route-img" style="background-image:url('<?= htmlspecialchars($img) ?>')"></div><?php else: ?>
          <div class="ja-route-img noimg"><?= ja_icon(stop_icon($s['category']),34) ?></div><?php endif; ?>
        <div class="ja-route-info">
          <div class="ja-route-head">
            <div>
              <div class="ja-route-name"><?= ja_icon(stop_icon($s['category']),15) ?> <?= htmlspecialchars($s['name']) ?></div>
              <div class="ja-route-sub"><span style="text-transform:capitalize"><?= htmlspecialchars($s['category']) ?></span> · <?= $s['leg_km'] ?> km away</div>
            </div>
            <button type="button" class="ja-route-remove" data-remove="<?= $i ?>" title="Remove stop"><?= ja_icon('trash',15) ?></button>
          </div>
          <div class="ja-route-do"><?= ja_icon('compass',14) ?> <?= htmlspecialchars($s['do'] ?? 'Visit this spot') ?></div>
          <?php if ($eat): ?>
            <div class="ja-route-eat"><?= ja_icon('heart',14) ?> Eat nearby: <strong><?= htmlspecialchars($eat['name']) ?></strong>
              <?= $eat['cuisine']? '· '.htmlspecialchars(is_array($eat['cuisine'])?implode(', ',$eat['cuisine']):$eat['cuisine']):'' ?>
              <span style="color:var(--text-mut)">(<?= $eat['dist_km'] ?> km)</span></div>
          <?php endif; ?>
          <a class="ja-route-map" target="_blank" rel="noopener"
             href="https://www.google.com/maps/search/?api=1&query=<?= $s['lat'] ?>,<?= $s['lon'] ?>"><?= ja_icon('map-pin',13) ?> Google Maps</a>
        </div>
      </div>
    </div>
  <?php endforeach;
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">

<div class="ja-pagehead">
  <div class="ja-container">
    <div class="ja-eyebrow"><?= ja_icon('compass',14) ?> Multi-stop route</div>
    <h1>Your Route<?= $place ? ' from '.htmlspecialchars(explode(',',$place)[0]) : '' ?></h1>
    <?php if (!$err): ?>
      <p class="sub"><span id="rStops"><?= (int)$r['stops'] ?></span> stops · <span id="rKm"><?= (int)$r['total_km'] ?></span> km round trip · <?= htmlspecialchars($r['mode_label']) ?></p>
    <?php endif; ?>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container">
    <?php if ($err): ?>
      <?= ml_offline_banner($r) ?>
      <?php if (!empty($r['error'])): ?><div class="ja-empty"><?= htmlspecialchars($r['error']) ?> <a href="plan-trip.php" style="color:var(--ja-teal)">Plan a trip →</a></div><?php endif; ?>
    <?php else: ?>

      <!-- route toggle: main vs alternative -->
      <div style="display:flex;gap:10px;margin-bottom:22px;flex-wrap:wrap;align-items:center">
        <div class="ja-chips" id="routeToggle">
          <span class="ja-chip on" data-route="main">Recommended route</span>
          <?php if (!empty($r['alternative'])): ?><span class="ja-chip" data-route="alt">Alternative route</span><?php endif; ?>
        </div>
        <div style="flex:1"></div>
        <?php if (!empty($_SESSION['eml'])): ?>
          <button class="ja-btn ja-btn-primary" id="saveRouteBtn" data-magnetic><?= ja_icon('heart',16) ?> Save route</button>
        <?php else: ?>
          <a class="ja-btn ja-btn-ghost" href="login.php"><?= ja_icon('heart',16) ?> Log in to save</a>
        <?php endif; ?>
      </div>

      <div class="ja-route-layout">
        <div>
          <div class="ja-route-line" id="routeLine">
            <div class="ja-route-stop origin">
              <div class="ja-route-dot"><?= ja_icon('home',16) ?></div>
              <div class="ja-route-card"><div class="ja-route-info"><div class="ja-route-name">Start · <?= htmlspecialchars(explode(',',$r['origin']['display'])[0]) ?></div></div></div>
            </div>
            <div id="stopsHost"><?php render_stops($r['route'], $r['origin']); ?></div>
            <div class="ja-route-stop origin">
              <div class="ja-route-dot"><?= ja_icon('home',16) ?></div>
              <div class="ja-route-card"><div class="ja-route-info"><div class="ja-route-name">Return home · <span id="backKm"><?= (int)$r['return_km'] ?></span> km</span></div></div></div>
            </div>
          </div>
        </div>

        <aside class="ja-itin-side">
          <div class="ja-card">
            <h3>Route map</h3>
            <div id="map" style="height:300px;border-radius:14px;overflow:hidden;margin-top:8px"></div>
          </div>
          <?php if (!empty($r['food_options'])): ?>
          <div class="ja-card" style="margin-top:20px">
            <h3><?= ja_icon('heart',18) ?> Where to eat</h3>
            <p style="color:var(--text-mut);font-size:.85rem;margin:2px 0 10px">Popular food nearby.</p>
            <?php foreach ($r['food_options'] as $f): ?>
              <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--brd)">
                <span style="font-weight:500"><?= htmlspecialchars($f['name']) ?></span>
                <span style="color:var(--text-mut);font-size:.85rem"><?= $f['dist_km'] ?> km</span>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <div class="ja-card" style="margin-top:20px">
            <a href="plan-trip.php" class="ja-btn ja-btn-ghost" style="width:100%">Plan another trip</a>
          </div>
        </aside>
      </div>

      <script>
      window.__routeData = <?= json_encode($r) ?>;
      </script>
      <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
      <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
      <script src="js/route.js" defer></script>
    <?php endif; ?>
  </div>
</main>

<?php include 'ja-footer.php'; ?>
</body>
</html>
