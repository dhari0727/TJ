<?php
$ja_title = "Insights"; $ja_active = "analytics";
$ja_css_extra = ['pages-account.css'];
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
$styles = $data['style_distribution'] ?? [];
$metrics = $data['cost_metrics'] ?? [];

// personal (if logged in)
$personal = null;
if (!empty($_SESSION['eml'])) {
    $personal = ml_request('GET', '/analytics/personal?eml=' . urlencode($_SESSION['eml']), null, 8);
    if (!empty($personal['__error']) || ($personal['status'] ?? '') === 'error') $personal = null;
}

usort($costByDest, fn($a,$b)=> ($b['n']??0) <=> ($a['n']??0));
$topCost = array_slice($costByDest, 0, 12);

/* ---------- small helpers: every figure on this page comes from the data above ---------- */
function ins_inr($n) { return '₹' . number_format((float)$n); }
function ins_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function ins_place($d) { return trim(explode(',', (string)$d)[0]); }

/** Horizontal bars. $rows = [['label'=>, 'value'=>number, 'text'=>shown value, 'note'=>optional, 'alt'=>bool (second colour)]] */
function ins_bars($rows, $id, $title, $cols = ['Item', 'Value'], $legend = '') {
    if (!$rows) return '';
    $max = max(array_map(fn($r) => (float)$r['value'], $rows)) ?: 1;
    $h = '<ol class="ins-bars" aria-label="' . ins_h($title) . '">';
    foreach ($rows as $r) {
        $w = max(1.5, round((float)$r['value'] / $max * 100, 1));
        $h .= '<li><span class="l">' . ins_h($r['label']) . (!empty($r['note']) ? ' <small>' . ins_h($r['note']) . '</small>' : '') . '</span>'
            . '<svg class="b" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true" focusable="false"><rect class="trk" x="0" y="0" width="100" height="10" rx="2"/><rect class="' . (!empty($r['alt']) ? 'alt' : 'fill') . '" x="0" y="0" width="' . $w . '" height="10" rx="2"/></svg>'
            . '<span class="v">' . ins_h($r['text']) . '</span></li>';
    }
    $h .= '</ol>' . ($legend ? '<p class="ins-legend">' . $legend . '</p>' : '');
    return $h . ins_table($rows, $id, $title, $cols);
}

/** The same numbers as a table, for screen readers and anyone who wants the exact values. */
function ins_table($rows, $id, $title, $cols) {
    $h = '<details class="ins-alt"><summary>View as a table</summary><div class="ins-tablewrap"><table><caption class="ja-sr">' . ins_h($title) . '</caption><thead><tr>';
    foreach ($cols as $c) $h .= '<th scope="col">' . ins_h($c) . '</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr><th scope="row">' . ins_h($r['label']) . '</th>';
        foreach ((array)($r['cells'] ?? [$r['text']]) as $c) $h .= '<td>' . ins_h($c) . '</td>';
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div></details>';
}

$monthNames = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$monthShort = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$byMonth = [];
foreach ($cheapMonths as $m) $byMonth[(int)$m['month']] = ['avg' => (float)$m['avg_daily'], 'n' => (int)$m['n']];
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja ja-acct-page">

<main class="acct-page ins-page">
  <header class="acct-head">
    <h1>Travel insights</h1>
    <p>What <?= number_format((int)($totals['journals']??0)) ?> travel journals across <?= number_format((int)($totals['destinations']??0)) ?> destinations say about timing and how trips went. The cost charts use only journals written by travellers on this site, never text imported from guides, so they appear once enough real trips exist.</p>
  </header>

  <?php if ($err): ?>
    <?= ml_offline_banner($data) ?>
  <?php else: ?>

  <?php if ($personal): $ps = $personal['stats']; $bc = $personal['by_category'] ?? []; ?>
  <section class="ins-sec" aria-labelledby="i-mine">
    <div class="ins-h"><h2 id="i-mine">Your travel</h2><p>From the trips in your own journal.</p></div>
    <div class="ins-b">
      <?php if ((int)($ps['trips']??0) > 0): ?>
        <dl class="acct-figs ins-figs">
          <div><dt>Trips logged</dt><dd><?= (int)$ps['trips'] ?></dd></div>
          <div><dt>Total spent</dt><dd><?= ins_inr($ps['total_spent']) ?></dd></div>
          <div><dt>Average trip</dt><dd><?= ins_inr($ps['avg_trip']) ?></dd>
            <?php $ca = (float)($personal['community_avg_trip'] ?? 0); if ($ca): $diff = round(((float)$ps['avg_trip'] - $ca) / $ca * 100); ?>
              <dd class="ins-sub"><?= $diff === 0 ? 'Same as' : (abs($diff) . '% ' . ($diff < 0 ? 'below' : 'above')) ?> the community average of <?= ins_inr($ca) ?></dd>
            <?php endif; ?></div>
          <div><dt>Average length</dt><dd><?= (float)$ps['avg_days'] ?> days</dd></div>
        </dl>
        <?php
          $LBL = ['food' => 'Food', 'transport' => 'Transport', 'accommodation' => 'Stay', 'shopping' => 'Shopping', 'fees_misc' => 'Fees and other'];
          $sum = array_sum(array_map('floatval', $bc)); $rows = [];
          foreach ($bc as $k => $v) { if ((float)$v > 0) $rows[] = ['label' => $LBL[$k] ?? $k, 'value' => (float)$v, 'text' => ins_inr($v) . ' (' . round($v / max($sum, 1) * 100) . '%)', 'cells' => [ins_inr($v), round($v / max($sum, 1) * 100) . '%']]; }
          usort($rows, fn($a, $b) => $b['value'] <=> $a['value']);
          if ($rows): ?>
          <h3 class="ins-h3">Where your money went</h3>
          <?= ins_bars($rows, 'mycat', 'Your spending by category', ['Category', 'Amount', 'Share']) ?>
        <?php endif; ?>
      <?php else: ?>
        <p class="ins-empty">You have not logged a trip yet. <a href="new-entry.php">Add your first journal</a> and your own numbers show up here.</p>
      <?php endif; ?>
    </div>
  </section>
  <?php elseif (empty($_SESSION['eml'])): ?>
    <p class="ins-signin"><a href="login.php">Log in</a> to see how your own trips compare with these numbers.</p>
  <?php endif; ?>

  <?php if ($topCost):
    $rows = array_map(fn($r) => ['label' => ins_place($r['destination']), 'value' => (float)$r['avg_cost'], 'alt' => ($r['tier'] ?? '') === 'lesser_known',
        'note' => ($r['tier'] ?? '') === 'lesser_known' ? 'lesser known' : '', 'text' => ins_inr($r['avg_cost']) . ' from ' . (int)$r['n'] . ' journals',
        'cells' => [ins_inr($r['avg_cost']), (int)$r['n'], ($r['tier'] ?? '') === 'lesser_known' ? 'Lesser known' : 'Popular']], $topCost); ?>
  <section class="ins-sec" aria-labelledby="i-cost">
    <div class="ins-h"><h2 id="i-cost">What a trip costs</h2><p>Average total cost per trip, for the <?= count($rows) ?> places with the most journals.</p></div>
    <div class="ins-b"><?= ins_bars($rows, 'cost', 'Average trip cost by destination', ['Destination', 'Average trip cost', 'Journals', 'Kind of place'],
        '<span class="sw fill"></span> Popular place <span class="sw alt"></span> Lesser-known place') ?></div>
  </section>
  <?php endif; ?>

  <?php if ($byMonth):
    $flat = []; foreach ($byMonth as $m => $v) $flat[$m] = $v['avg'];
    $lo = min($flat); $hi = max($flat); $loM = array_search($lo, $flat); $hiM = array_search($hi, $flat);
    $W = 360; $base = 190; $top = 28; $bw = 20; $gap = 8; $x0 = 16;
    $desc = 'Lowest in ' . $monthNames[$loM] . ' at ' . ins_inr($lo) . ' a day, highest in ' . $monthNames[$hiM] . ' at ' . ins_inr($hi) . ' a day.';
    $rows = []; for ($m = 1; $m <= 12; $m++) if (isset($byMonth[$m])) $rows[] = ['label' => $monthNames[$m], 'text' => ins_inr($byMonth[$m]['avg']) . ' a day', 'cells' => [ins_inr($byMonth[$m]['avg']), $byMonth[$m]['n']]]; ?>
  <section class="ins-sec" aria-labelledby="i-months">
    <div class="ins-h"><h2 id="i-months">When it costs least</h2><p>Average spend per day by month of travel. <?= ins_h($monthNames[$loM]) ?> is lowest in these journals. Months with few journals are less reliable, so check the table for counts.</p></div>
    <div class="ins-b">
      <svg class="ins-cols" viewBox="0 0 <?= $W ?> 226" role="img" aria-labelledby="mt-t mt-d">
        <title id="mt-t">Average daily spend by month</title><desc id="mt-d"><?= ins_h($desc) ?></desc>
        <line class="axis" x1="8" y1="<?= $base ?>" x2="<?= $W - 8 ?>" y2="<?= $base ?>"/>
        <?php for ($m = 1; $m <= 12; $m++): $x = $x0 + ($m - 1) * ($bw + $gap);
          if (isset($byMonth[$m])): $h = max(3, round($byMonth[$m]['avg'] / $hi * ($base - $top - 14))); $y = $base - $h; ?>
          <rect class="<?= $m === $loM ? 'fill best' : 'fill' ?>" x="<?= $x ?>" y="<?= $y ?>" width="<?= $bw ?>" height="<?= $h ?>" rx="2"/>
          <text class="val" x="<?= $x + $bw / 2 ?>" y="<?= $y - 5 ?>" text-anchor="middle"><?= number_format($byMonth[$m]['avg'] / 1000, 1) ?>k</text>
        <?php endif; ?>
          <text class="mon" x="<?= $x + $bw / 2 ?>" y="<?= $base + 17 ?>" text-anchor="middle"><?= $monthShort[$m] ?></text>
        <?php endfor; ?>
      </svg>
      <p class="ins-legend"><span class="sw best"></span> Lowest month. Values are rupees per day, in thousands.</p>
      <?= ins_table($rows, 'months', 'Average daily spend by month', ['Month', 'Average per day', 'Journals']) ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($costRanges):
    $cr = array_slice($costRanges, 0, 6); $rmax = max(array_map(fn($r) => (int)$r['max_daily'], $cr)) ?: 1; ?>
  <section class="ins-sec" aria-labelledby="i-range">
    <div class="ins-h"><h2 id="i-range">Cheap days and dear days</h2><p>The lowest and highest daily spend in the journals for six places, drawn on one shared scale.</p></div>
    <div class="ins-b">
      <ol class="ins-bars ins-range" aria-label="Daily cost range by destination">
      <?php foreach ($cr as $r): $a = (int)$r['min_daily']; $z = (int)$r['max_daily']; $x = round($a / $rmax * 100, 1); $w = max(1.5, round(($z - $a) / $rmax * 100, 1)); ?>
        <li><span class="l"><?= ins_h(ins_place($r['destination'])) ?></span>
          <svg class="b" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true" focusable="false"><rect class="trk" x="0" y="0" width="100" height="10" rx="2"/><rect class="fill" x="<?= $x ?>" y="0" width="<?= $w ?>" height="10" rx="2"/></svg>
          <span class="v"><?= ins_inr($a) ?> to <?= ins_inr($z) ?></span></li>
      <?php endforeach; ?>
      </ol>
      <?= ins_table(array_map(fn($r) => ['label' => ins_place($r['destination']), 'cells' => [ins_inr($r['min_daily']), ins_inr($r['max_daily']), (int)$r['n']]], $cr), 'range', 'Daily spend range by destination', ['Destination', 'Lowest per day', 'Highest per day', 'Journals']) ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($bestValue): $bv = array_slice($bestValue, 0, 6); ?>
  <section class="ins-sec" aria-labelledby="i-value">
    <div class="ins-h"><h2 id="i-value">Most enjoyed for the money</h2><p>Places where travellers wrote warmly about the trip and spent little. Ranked by sentiment against cost.</p></div>
    <div class="ins-b">
      <table class="ins-list">
        <thead><tr><th scope="col">Destination</th><th scope="col">Average trip</th><th scope="col">Journals</th></tr></thead>
        <tbody><?php foreach ($bv as $b): ?><tr><th scope="row"><?= ins_h(ins_place($b['destination'])) ?></th><td><?= ins_inr($b['avg_cost']) ?></td><td><?= (int)$b['n'] ?></td></tr><?php endforeach; ?></tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($sentiment):
    $order = ['positive' => 0, 'neutral' => 1, 'negative' => 2]; usort($sentiment, fn($a, $b) => ($order[$a['label']] ?? 9) <=> ($order[$b['label']] ?? 9));
    $tot = array_sum(array_map(fn($s) => (int)$s['n'], $sentiment)) ?: 1; $x = 0; $segs = []; ?>
  <section class="ins-sec" aria-labelledby="i-sent">
    <div class="ins-h"><h2 id="i-sent">How trips went</h2><p>Each journal is read for tone and counted as positive, neutral or negative.</p></div>
    <div class="ins-b">
      <svg class="ins-stack" viewBox="0 0 100 12" preserveAspectRatio="none" role="img" aria-labelledby="st-t st-d">
        <title id="st-t">Tone of <?= number_format($tot) ?> journals</title>
        <desc id="st-d"><?= ins_h(implode(', ', array_map(fn($s) => $s['label'] . ' ' . round($s['n'] / $tot * 100) . '%', $sentiment))) ?>.</desc>
        <?php foreach ($sentiment as $s): $w = $s['n'] / $tot * 100; ?><rect class="seg-<?= ins_h($s['label']) ?>" x="<?= round($x, 2) ?>" y="0" width="<?= round($w, 2) ?>" height="12"/><?php $x += $w; endforeach; ?>
      </svg>
      <ul class="ins-keys">
        <?php foreach ($sentiment as $s): ?><li><span class="sw seg-<?= ins_h($s['label']) ?>"></span><strong><?= ucfirst(ins_h($s['label'])) ?></strong> <?= number_format((int)$s['n']) ?> journals, <?= round($s['n'] / $tot * 100) ?>%</li><?php endforeach; ?>
      </ul>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($styles): $rows = array_map(fn($s) => ['label' => ucfirst($s['style']), 'value' => (int)$s['n'], 'text' => number_format((int)$s['n']) . ' journals', 'cells' => [(int)$s['n']]], $styles); ?>
  <section class="ins-sec" aria-labelledby="i-style">
    <div class="ins-h"><h2 id="i-style">How people travel</h2><p>The travel style each journal was written under.</p></div>
    <div class="ins-b"><?= ins_bars($rows, 'styles', 'Journals by travel style', ['Travel style', 'Journals']) ?></div>
  </section>
  <?php endif; ?>

  <?php if ($metrics):
    $hd = $metrics['headline'] ?? []; $tv = $metrics['trip_validation'] ?? [];
    $kf = $metrics['listing_validation_5fold_known_destination']['sleep'] ?? [];
    $lo = $metrics['listing_validation_leave_one_destination_out']['sleep'] ?? [];
    $nTrips = (int)($hd['trip_reports_n'] ?? 0); $mape = $hd['trip_mape_pct'] ?? null; $ci = $hd['trip_mape_ci95'] ?? null; ?>
  <section class="ins-sec" aria-labelledby="i-model">
    <div class="ins-h"><h2 id="i-model">The cost estimator</h2><p>Built from <?= number_format((int)($hd['price_reports'] ?? 0)) ?> real prices that travellers listed for stays, meals, local fares and entry fees, covering <?= number_format((int)($hd['destinations_with_data'] ?? 0)) ?> places. It is not trained on journals.</p></div>
    <div class="ins-b">
      <dl class="acct-figs">
        <?php if ($mape !== null): ?><div><dt>Error on <?= $nTrips ?> real trip reports</dt><dd><?= number_format((float)$mape, 0) ?>%</dd></div><?php endif; ?>
        <?php if ($kf): ?><div><dt>Hotel price error, known place</dt><dd><?= number_format((float)($kf['model']['MAPE_pct'] ?? 0), 0) ?>%</dd></div><?php endif; ?>
        <?php if ($lo): ?><div><dt>Hotel price error, unseen place</dt><dd><?= number_format((float)($lo['model']['MAPE_pct'] ?? 0), 0) ?>%</dd></div><?php endif; ?>
      </dl>
      <p class="ins-note">Only <?= $nTrips ?> independent trip reports with a stated total exist in our sources, so that first figure is a rough guide<?= $ci ? ' (it could plausibly be anywhere from ' . number_format((float)$ci[0], 0) . '% to ' . number_format((float)$ci[1], 0) . '%)' : '' ?>. Each report was predicted with the one adjustment factor fitted on the other reports. The hotel figures are measured on real listings held back from fitting: a place whose prices we know, and a place we have never seen. Individual hotels vary a lot, so a real trip can land outside the range shown. Seasonal price changes, getting there and shopping are not modelled.</p>
    </div>
  </section>
  <?php endif; ?>

  <?php endif; /* ml up */ ?>
</main>

<?php include 'ja-footer.php'; ?>
</body>
</html>
