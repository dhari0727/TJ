<?php
$ja_title = "Analytics"; $ja_active = "analytics";
session_start();
require 'ml_client.php';

$data = ml_analytics();
$err = $data['__error'] ?? null;
$totals = $data['totals'] ?? [];
$costByDest = $data['cost_by_destination'] ?? [];
$sentiment = $data['sentiment_distribution'] ?? [];
$bestValue = $data['best_value'] ?? [];
$cheapMonths = $data['cheapest_months'] ?? [];
$costRanges = $data['cost_ranges'] ?? [];
$metrics = $data['cost_metrics'] ?? [];

// personal (if logged in)
$personal = null;
if (!empty($_SESSION['eml'])) {
    $personal = ml_request('GET', '/analytics/personal?eml=' . urlencode($_SESSION['eml']), null, 8);
    if (!empty($personal['__error']) || ($personal['status'] ?? '') === 'error') $personal = null;
}

usort($costByDest, fn($a,$b)=> ($b['n']??0) <=> ($a['n']??0));
$topCost = array_slice($costByDest, 0, 12);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">

<div class="ja-pagehead">
  <div class="ja-container">
    <div class="ja-eyebrow"><?= ja_icon('chart',14) ?> Travel insights</div>
    <h1>Analytics</h1>
    <p class="sub">Real patterns from <?= number_format((int)($totals['journals']??0)) ?> travel journals across <?= (int)($totals['destinations']??0) ?> destinations — to help you plan smarter.</p>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container">
    <?php if ($err): ?>
      <?= ml_offline_banner($data) ?>
    <?php else: ?>

    <?php if ($personal): $ps = $personal['stats']; $bc = $personal['by_category']; ?>
      <!-- ============ YOUR TRAVEL ============ -->
      <h2 style="margin-bottom:16px">Your travel</h2>
      <?php if ((int)($ps['trips']??0) > 0): ?>
        <div style="display:grid;gap:24px;grid-template-columns:2fr 1fr;align-items:start;margin-bottom:20px">
          <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
            <div class="ja-card ja-stat"><div class="n" style="font-size:2rem"><?= (int)$ps['trips'] ?></div><div class="l">Trips logged</div></div>
            <div class="ja-card ja-stat"><div class="n" style="font-size:2rem">₹<?= number_format((float)$ps['total_spent']/1000,0) ?>k</div><div class="l">Total spent</div></div>
            <div class="ja-card ja-stat"><div class="n" style="font-size:2rem">₹<?= number_format((float)$ps['avg_trip']) ?></div><div class="l">Avg trip
              <?php $ca=(float)($personal['community_avg_trip']??0); if($ca): $diff=round(((float)$ps['avg_trip']-$ca)/$ca*100); ?>
                <span style="color:<?= $diff<=0?'var(--ja-teal)':'var(--ja-coral)' ?>"><?= $diff<=0?'':'+' ?><?= $diff ?>% vs avg</span>
              <?php endif; ?></div></div>
            <div class="ja-card ja-stat"><div class="n" style="font-size:2rem"><?= (float)$ps['avg_days'] ?></div><div class="l">Avg days</div></div>
          </div>
          <div class="ja-card">
            <h3 style="font-size:1.1rem">Your spending</h3>
            <canvas id="myCat" height="170"></canvas>
          </div>
        </div>
      <?php else: ?>
        <div class="ja-empty" style="margin-bottom:20px">You haven't logged any trips yet. <a href="new-entry.php" style="color:var(--ja-teal);font-weight:600">Add your first →</a> to see your personal insights.</div>
      <?php endif; ?>
      <hr class="ja-rule" style="margin:20px 0 40px">
    <?php endif; ?>

    <!-- ============ PRACTICAL PLANNING INSIGHTS ============ -->
    <h2 style="margin-bottom:4px">Plan smarter</h2>
    <p class="sub" style="margin-bottom:24px">Data-backed tips from real trips.</p>

    <div style="display:grid;gap:24px;grid-template-columns:repeat(auto-fit,minmax(320px,1fr))">
      <div class="ja-card">
        <h3><?= ja_icon('star',18) ?> Best value destinations</h3>
        <p style="color:var(--text-mut);font-size:.85rem;margin:2px 0 12px">Most travel joy per rupee spent.</p>
        <?php foreach (array_slice($bestValue,0,6) as $b): ?>
          <div style="display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--brd)">
            <span style="font-weight:500"><?= htmlspecialchars(explode(',',$b['destination'])[0]) ?></span>
            <span style="color:var(--text-mut);font-size:.9rem">₹<?= number_format($b['avg_cost']) ?> avg</span>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="ja-card">
        <h3><?= ja_icon('calendar',18) ?> Cheapest months to travel</h3>
        <p style="color:var(--text-mut);font-size:.85rem;margin:2px 0 12px">Average daily spend by month.</p>
        <canvas id="monthsChart" height="200"></canvas>
      </div>

      <div class="ja-card">
        <h3><?= ja_icon('wallet',18) ?> Budget vs premium (per day)</h3>
        <p style="color:var(--text-mut);font-size:.85rem;margin:2px 0 12px">Daily cost range for popular places.</p>
        <?php foreach (array_slice($costRanges,0,6) as $r):
          $lo=(int)$r['min_daily']; $hi=(int)$r['max_daily']; ?>
          <div style="margin-bottom:11px">
            <div style="display:flex;justify-content:space-between;font-size:.9rem;margin-bottom:4px">
              <span><?= htmlspecialchars(explode(',',$r['destination'])[0]) ?></span>
              <span style="color:var(--text-mut)">₹<?= number_format($lo) ?>–<?= number_format($hi) ?></span>
            </div>
            <div class="ja-score-bar"><div style="height:100%;background:var(--grad-aqua);border-radius:100px;width:100%"></div></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <hr class="ja-rule" style="margin:40px 0">

    <!-- ============ CORPUS CHARTS ============ -->
    <h2 style="margin-bottom:24px">Across all journals</h2>
    <div style="display:grid;gap:24px;grid-template-columns:repeat(auto-fit,minmax(340px,1fr))">
      <div class="ja-card"><h3>Average trip cost by destination</h3><canvas id="costChart" height="230"></canvas></div>
      <div class="ja-card"><h3>Traveller sentiment</h3><canvas id="sentChart" height="230"></canvas></div>
    </div>

    <div class="ja-card" style="margin-top:24px">
      <h3>Cost prediction model</h3>
      <p style="color:var(--text-dim);margin:0">
        Trained on real trip totals · MAE <strong>₹<?= number_format($metrics['mae']??0) ?></strong> ·
        R² <strong><?= number_format($metrics['r2']??0,2) ?></strong>
        <br><span style="color:var(--text-mut);font-size:.9rem">GradientBoostingRegressor — powers every cost estimate you see.</span>
      </p>
    </div>

    <script>
    window.__ja = {
      cost: <?= json_encode(array_map(fn($r)=>['d'=>explode(',',$r['destination'])[0],'c'=>(int)$r['avg_cost'],'tier'=>$r['tier']??'mainstream'], $topCost)) ?>,
      sentiment: <?= json_encode($sentiment) ?>,
      months: <?= json_encode($cheapMonths) ?>,
      myCat: <?= $personal ? json_encode($personal['by_category']) : 'null' ?>
    };
    </script>
    <?php endif; ?>
  </div>
</main>

<?php include 'ja-footer.php'; ?>

<script>
(function(){
  function draw(){
    if(typeof Chart==="undefined"||!window.__ja){setTimeout(draw,250);return;}
    var A=window.__ja,aqua='#31C6D4',teal='#0E7C86',deep='#0B3D4F',coral='#FF7A59',coral2='#FF9E7A';
    Chart.defaults.font.family="Inter,sans-serif";
    Chart.defaults.color=(getComputedStyle(document.body).getPropertyValue('--text-dim')||'#4a6670').trim();
    var mn=['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    if(A.cost&&A.cost.length)new Chart(document.getElementById('costChart'),{type:'bar',
      data:{labels:A.cost.map(x=>x.d),datasets:[{data:A.cost.map(x=>x.c),backgroundColor:A.cost.map(x=>x.tier==='lesser_known'?coral:aqua),borderRadius:8}]},
      options:{plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>'₹'+c.raw.toLocaleString('en-IN')}}},
        scales:{y:{ticks:{callback:v=>'₹'+(v/1000)+'k'},grid:{color:'rgba(125,146,154,.12)'}},x:{grid:{display:false}}},animation:{duration:900}}});

    if(A.sentiment&&A.sentiment.length){var order={positive:0,neutral:1,negative:2},cmap={positive:aqua,neutral:'#9bb5bc',negative:coral};
      var s=A.sentiment.slice().sort((a,b)=>order[a.label]-order[b.label]);
      new Chart(document.getElementById('sentChart'),{type:'doughnut',
        data:{labels:s.map(x=>x.label),datasets:[{data:s.map(x=>x.n),backgroundColor:s.map(x=>cmap[x.label]||teal),borderWidth:0}]},
        options:{plugins:{legend:{position:'bottom'}},cutout:'60%'}});}

    if(A.months&&A.months.length){var bym={};A.months.forEach(function(x){bym[+x.month]=+x.avg_daily});
      var labels=[],vals=[];for(var m=1;m<=12;m++){labels.push(mn[m]);vals.push(bym[m]||0);}
      var min=Math.min.apply(null,vals.filter(v=>v>0));
      new Chart(document.getElementById('monthsChart'),{type:'bar',
        data:{labels:labels,datasets:[{data:vals,backgroundColor:vals.map(v=>v===min?teal:aqua),borderRadius:6}]},
        options:{plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>'₹'+c.raw.toLocaleString('en-IN')+'/day'}}},
          scales:{y:{ticks:{callback:v=>'₹'+(v/1000).toFixed(0)+'k'},grid:{color:'rgba(125,146,154,.12)'}},x:{grid:{display:false}}},animation:{duration:900}}});}

    if(A.myCat){var bd=A.myCat,LBL={food:'Food',transport:'Transport',accommodation:'Stay',shopping:'Shopping',fees_misc:'Fees'};
      new Chart(document.getElementById('myCat'),{type:'doughnut',
        data:{labels:Object.keys(bd).map(k=>LBL[k]||k),datasets:[{data:Object.values(bd).map(Number),backgroundColor:[aqua,teal,deep,coral,coral2],borderWidth:0}]},
        options:{plugins:{legend:{position:'bottom'}},cutout:'58%'}});}
  }
  if(document.readyState!=='loading')draw();else document.addEventListener('DOMContentLoaded',draw);
})();
</script>
</body>
</html>
