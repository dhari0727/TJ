<?php
$ja_title = "Itinerary"; $ja_active = "plan";
require 'ml_client.php';
require 'ja-images.php';

$STYLES = ['budget','mid-range','luxury','adventure','family','solo','backpacker'];
$dest  = $_GET['dest'] ?? '';
$days  = max(1, min(21, (int)($_GET['days'] ?? 5)));
$style = in_array($_GET['style'] ?? '', $STYLES, true) ? $_GET['style'] : 'mid-range';
$month = (int)($_GET['month'] ?? 0); $month = ($month>=1 && $month<=12) ? $month : null;
$party = max(1, min(20, (int)($_GET['party'] ?? 1)));

$it = $dest ? ml_itinerary([
    'destination'=>$dest,'days'=>$days,'travel_style'=>$style,'month'=>$month,'party_size'=>$party
]) : ['__error'=>'No destination specified.'];

$hero = $dest ? ja_image_for($dest) : '';
$hasError = !empty($it['__error']) || ($it['status'] ?? '') === 'error';
$ja_saved_flag = !empty($_GET['saved']);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?><link rel="stylesheet" media="print" href="css/journeyai-print.css"></head>
<body class="ja">

<?php if ($hasError): ?>
  <div class="ja-pagehead"><div class="ja-container"><h1>Itinerary</h1></div></div>
  <main class="ja-main"><div class="ja-container">
    <?= ml_offline_banner($it) ?>
    <?php if (empty($it['__error'])): ?><div class="ja-empty">Couldn't build an itinerary for that destination. <a href="plan-trip.php" style="color:var(--ja-teal)">Plan a trip →</a></div><?php endif; ?>
  </div></main>
<?php else: ?>

<!-- itinerary hero -->
<header class="ja-itin-hero" style="background-image:linear-gradient(90deg,rgba(4,24,32,.9),rgba(6,32,42,.45) 70%,rgba(6,32,42,.2)),url('<?= htmlspecialchars($hero) ?>')">
  <div class="ja-container">
    <div class="ja-hc-eyebrow"><?= ja_icon('map-pin',14) ?> <?= htmlspecialchars($it['region']) ?></div>
    <h1><?= htmlspecialchars($it['city']) ?></h1>
    <p><?= (int)$it['days'] ?>-day <?= htmlspecialchars($it['travel_style']) ?> itinerary
      <?php if (!empty($it['best_season'])): ?>· best in <?= htmlspecialchars($it['best_season']) ?><?php endif; ?></p>
    <div class="ja-itin-meta">
      <div><span class="n">₹<?= number_format($it['total_cost']) ?></span><span class="l">Est. total (<?= (int)$it['party_size'] ?> <?= $it['party_size']==1?'traveller':'travellers' ?>)</span></div>
      <div><span class="n">₹<?= number_format($it['per_day_cost']) ?></span><span class="l">Per day</span></div>
      <div><span class="n"><?= count($it['highlights']) ?></span><span class="l">Highlights</span></div>
    </div>
  </div>
</header>

<main class="ja-main">
  <div class="ja-container">
    <div class="ja-itin-layout">
      <!-- timeline -->
      <div class="ja-itin-days">
        <?php foreach ($it['plan'] as $d): ?>
          <section class="ja-itin-day reveal">
            <div class="ja-itin-daynum"><?= (int)$d['day'] ?></div>
            <div class="ja-itin-daybody">
              <div class="ja-itin-dayhead">
                <h2><?= htmlspecialchars($d['title']) ?></h2>
                <span class="ja-pill season"><?= ja_icon('wallet',12) ?> ~₹<?= number_format($d['est_cost']) ?></span>
              </div>
              <div class="ja-timeline">
                <?php foreach ($d['timeline'] as $t): ?>
                  <div class="ja-tl-item">
                    <div class="ja-tl-dot"><?= ja_icon($t['icon'] ?? 'clock',14) ?></div>
                    <div class="ja-tl-content">
                      <div class="ja-tl-time"><?= htmlspecialchars($t['time']) ?></div>
                      <div class="ja-tl-text"><?= htmlspecialchars($t['text']) ?></div>
                      <?php if (!empty($t['meal'])): ?><div class="ja-tl-meal"><?= ja_icon('sparkle',11) ?> <?= htmlspecialchars($t['meal']) ?></div><?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="ja-itin-stay"><?= ja_icon('home',15) ?> Suggested stay: <strong><?= htmlspecialchars($d['stay']) ?></strong></div>
            </div>
          </section>
        <?php endforeach; ?>
      </div>

      <!-- sidebar -->
      <aside class="ja-itin-side">
        <div class="ja-card">
          <h3>Cost breakdown</h3>
          <canvas id="itinDonut" height="180"></canvas>
          <div style="margin-top:14px;font-size:.9rem;color:var(--text-dim)">
            Range ₹<?= number_format($it['cost_low']) ?> – ₹<?= number_format($it['cost_high']) ?>
          </div>
        </div>
        <div class="ja-card" style="margin-top:20px">
          <h3>Highlights</h3>
          <ul style="list-style:none;padding:0;margin:6px 0 0">
            <?php foreach ($it['highlights'] as $h): ?>
              <li style="padding:8px 0;border-bottom:1px solid var(--brd);display:flex;gap:9px;align-items:center;font-size:.92rem">
                <span style="color:var(--ja-teal)"><?= ja_icon('map-pin',14) ?></span><?= htmlspecialchars($h) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <div class="ja-card" style="margin-top:20px;background:var(--grad-hero);border:none;color:#fff">
          <h3 style="color:#fff">Make it yours</h3>
          <p style="color:#d7f6fb;font-size:.9rem;margin:8px 0 16px">Adjust the length or style to reshape this plan.</p>
          <form method="get" style="display:flex;gap:8px;flex-wrap:wrap">
            <input type="hidden" name="dest" value="<?= htmlspecialchars($dest) ?>">
            <select name="days" class="ja-select" style="flex:1"><?php for($i=2;$i<=12;$i++):?><option value="<?=$i?>" <?=$i==$days?'selected':''?>><?=$i?> days</option><?php endfor;?></select>
            <select name="style" class="ja-select" style="flex:1"><?php foreach($STYLES as $s):?><option value="<?=$s?>" <?=$s==$style?'selected':''?>><?=ucfirst($s)?></option><?php endforeach;?></select>
            <button class="ja-btn ja-btn-primary" style="width:100%">Rebuild</button>
          </form>
        </div>

        <div class="ja-card" style="margin-top:20px">
          <h3><?= ja_icon('heart',16) ?> Save this plan</h3>
          <?php if ($ja_saved_flag): ?>
            <p style="color:var(--ja-teal);font-size:.9rem;margin:8px 0 4px;font-weight:600"><?= ja_icon('check',14) ?> Saved to My Plans.</p>
          <?php else: ?>
            <p style="color:var(--text-dim);font-size:.9rem;margin:8px 0 16px">Keep this itinerary and get an auto-generated packing checklist.</p>
          <?php endif; ?>
          <form method="post" action="save-itinerary.php">
            <input type="hidden" name="dest" value="<?= htmlspecialchars($dest) ?>">
            <input type="hidden" name="days" value="<?= (int)$days ?>">
            <input type="hidden" name="style" value="<?= htmlspecialchars($style) ?>">
            <input type="hidden" name="month" value="<?= (int)($month ?? 0) ?>">
            <input type="hidden" name="party" value="<?= (int)$party ?>">
            <button class="ja-btn ja-btn-primary" style="width:100%"><?= ja_icon('heart',15) ?> Save this plan</button>
          </form>
          <button class="ja-btn ja-btn-ghost" style="width:100%;margin-top:10px" onclick="window.print()"><?= ja_icon('book',15) ?> Print / Save as PDF</button>
        </div>
      </aside>
    </div>
  </div>
</main>

<script>
document.addEventListener('DOMContentLoaded',function(){
  if(typeof Chart==='undefined')return;
  var bd=<?= json_encode($it['cost_breakdown']) ?>;
  var LBL={food:'Food',transport:'Transport',accommodation:'Stay',shopping:'Shopping',fees_misc:'Fees & misc'};
  new Chart(document.getElementById('itinDonut'),{type:'doughnut',
    data:{labels:Object.keys(bd).map(function(k){return LBL[k]||k}),
      datasets:[{data:Object.values(bd),backgroundColor:['#31C6D4','#0E7C86','#0B3D4F','#FF7A59','#FF9E7A'],borderWidth:0}]},
    options:{plugins:{legend:{position:'bottom'},tooltip:{callbacks:{label:function(c){return c.label+': ₹'+c.raw.toLocaleString('en-IN')}}}},cutout:'58%'}});
});
</script>

<?php endif; ?>
<?php include 'ja-footer.php'; ?>
