<?php
$ja_title = "Route"; $ja_active = "plan";
require 'ml_client.php';

$place = trim($_GET['place'] ?? '');
$mode  = $_GET['mode'] ?? 'weekend';
$stops = max(2, min(8, (int)($_GET['stops'] ?? 4)));
$interests = array_filter(array_map('trim', explode(',', $_GET['interests'] ?? '')));

$r = $place ? ml_request('POST','/route', [
    'place'=>$place,'mode'=>$mode,'interests'=>array_values($interests),'stops'=>$stops
], 45) : ['__error'=>'No starting point given.'];

$err = !empty($r['__error']) || ($r['status'] ?? '')==='error';
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
      <p class="sub"><?= (int)$r['stops'] ?> stops · <?= (int)$r['total_km'] ?> km round trip · <?= htmlspecialchars($r['mode_label']) ?></p>
    <?php endif; ?>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container">
    <?php if ($err): ?>
      <?= ml_offline_banner($r) ?>
      <?php if (!empty($r['error'])): ?><div class="ja-empty"><?= htmlspecialchars($r['error']) ?> <a href="plan-trip.php" style="color:var(--ja-teal)">Plan a trip →</a></div><?php endif; ?>
    <?php else: ?>
      <div class="ja-route-layout">
        <!-- route timeline -->
        <div>
          <div class="ja-route-line">
            <!-- origin -->
            <div class="ja-route-stop origin reveal">
              <div class="ja-route-dot"><?= ja_icon('home',16) ?></div>
              <div class="ja-route-body">
                <div class="ja-route-name">Start · <?= htmlspecialchars(explode(',',$r['origin']['display'])[0]) ?></div>
                <div class="ja-route-sub">Your starting point</div>
              </div>
            </div>
            <?php foreach ($r['route'] as $i=>$s):
              $catIcon = ['religious'=>'star','heritage'=>'home','nature'=>'sun','museum'=>'book','beach'=>'sun','town'=>'map-pin'][$s['category']] ?? 'map-pin'; ?>
              <div class="ja-route-leg"><?= ja_icon('arrow',13,'style="transform:rotate(90deg)"') ?> <?= $s['leg_km'] ?> km</div>
              <div class="ja-route-stop reveal">
                <div class="ja-route-dot"><?= $i+1 ?></div>
                <div class="ja-route-body">
                  <div class="ja-route-name"><?= ja_icon($catIcon,15) ?> <?= htmlspecialchars($s['name']) ?></div>
                  <div class="ja-route-sub"><span style="text-transform:capitalize"><?= htmlspecialchars($s['category']) ?></span> ·
                    <a target="_blank" rel="noopener" style="color:var(--ja-teal)"
                       href="https://www.google.com/maps/search/?api=1&query=<?= $s['lat'] ?>,<?= $s['lon'] ?>">open in Google Maps</a></div>
                </div>
              </div>
            <?php endforeach; ?>
            <div class="ja-route-leg"><?= ja_icon('arrow',13,'style="transform:rotate(90deg)"') ?> <?= (int)$r['return_km'] ?> km back</div>
            <div class="ja-route-stop origin reveal">
              <div class="ja-route-dot"><?= ja_icon('home',16) ?></div>
              <div class="ja-route-body"><div class="ja-route-name">Return home</div></div>
            </div>
          </div>
        </div>

        <!-- map + summary -->
        <aside class="ja-itin-side">
          <div class="ja-card">
            <h3>Route map</h3>
            <div id="map" style="height:320px;border-radius:14px;overflow:hidden;margin-top:8px"></div>
          </div>
          <div class="ja-card" style="margin-top:20px">
            <h3>Summary</h3>
            <div style="display:flex;gap:24px;margin-top:6px">
              <div class="ja-stat" style="text-align:left"><div class="n" style="font-size:1.8rem"><?= (int)$r['total_km'] ?></div><div class="l">Total km</div></div>
              <div class="ja-stat" style="text-align:left"><div class="n" style="font-size:1.8rem"><?= (int)$r['stops'] ?></div><div class="l">Stops</div></div>
            </div>
            <a href="plan-trip.php" class="ja-btn ja-btn-ghost" style="width:100%;margin-top:16px;padding:10px">Plan another</a>
          </div>
        </aside>
      </div>

      <script>
      window.__route={origin:<?= json_encode($r['origin']) ?>,stops:<?= json_encode($r['route']) ?>};
      </script>
      <!-- Leaflet (OSM map) -->
      <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
      <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
      <script>
      (function(){
        function draw(){
          if(typeof L==='undefined'||!window.__route){setTimeout(draw,300);return;}
          var o=window.__route.origin,st=window.__route.stops;
          var map=L.map('map',{scrollWheelZoom:false}).setView([o.lat,o.lon],9);
          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap',maxZoom:17}).addTo(map);
          var pts=[[o.lat,o.lon]];
          L.marker([o.lat,o.lon]).addTo(map).bindPopup('Start');
          st.forEach(function(s,i){pts.push([s.lat,s.lon]);L.marker([s.lat,s.lon]).addTo(map).bindPopup((i+1)+'. '+s.name);});
          pts.push([o.lat,o.lon]);
          L.polyline(pts,{color:'#0E7C86',weight:3,opacity:.8,dashArray:'6 6'}).addTo(map);
          map.fitBounds(L.latLngBounds(pts).pad(0.15));
        }
        if(document.readyState!=='loading')draw();else document.addEventListener('DOMContentLoaded',draw);
      })();
      </script>
    <?php endif; ?>
  </div>
</main>

<?php include 'ja-footer.php'; ?>
