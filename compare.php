<?php
/** JourneyAI — compare 2 or 3 destinations side by side (same real profile data as the guide pages). */
session_start();
require_once __DIR__ . '/ja-lib.php';
require_once __DIR__ . '/ja-dest.php';

$ja_title = 'Compare destinations'; $ja_active = 'plan';
$raw = $_GET['d'] ?? [];
$raw = is_array($raw) ? $raw : [$raw];
$names = [];
foreach ($raw as $n) { $n = mb_substr(trim((string)$n), 0, 120); if ($n !== '' && !in_array(strtolower($n), array_map('strtolower', $names), true)) $names[] = $n; }
$names = array_slice($names, 0, 3);
$fetch = count($names) ? ja_dest_fetch($names) : ['items' => [], 'error' => null, 'offline' => false];
$cols = []; $missing = [];
foreach ($names as $i => $n) { if (!empty($fetch['items'][$i])) $cols[] = $fetch['items'][$i]; else $missing[] = $n; }
if (!$cols && count($names)) http_response_code($fetch['offline'] ? 503 : 404);
$list = ml_request('POST', '/destination', ['list' => true], 6);
$cities = array_values(array_unique(array_column($list['destinations'] ?? [], 'city'))); sort($cities);

$cheapest = null;
foreach ($cols as $i => $c) { $pd = $c['costs']['1']['per_day'] ?? null; if ($pd !== null && ($cheapest === null || $pd < $cols[$cheapest]['costs']['1']['per_day'])) $cheapest = $i; }
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?><link rel="stylesheet" href="css/pages-discover.css?v=<?= (int)@filemtime(__DIR__ . '/css/pages-discover.css') ?>"></head>
<body class="ja dz-plan dz-cmp">
<header class="dz-pagehead">
  <h1>Compare destinations</h1>
  <?php if (count($cols) >= 2): ?>
    <p class="dz-lede">Cost, season, activities and region for <?= htmlspecialchars(implode(', ', array_map(function ($c) { return $c['city']; }, $cols))) ?>, from the same data as each guide.</p>
  <?php else: ?>
    <p class="dz-lede">Choose two or three places to see them side by side. You can also tap Compare on any destination card while you browse.</p>
  <?php endif; ?>
</header>

<main class="dz-wrap">
  <?php if ($fetch['offline']): ?>
    <?= ja_service_error(['__error' => $fetch['error']], 'load these destinations') ?>
  <?php endif; ?>

  <form class="dz-cmp-pick" method="get" action="compare.php">
    <?php for ($i = 0; $i < 3; $i++): ?>
      <div class="dz-field"><label for="c<?= $i ?>">Destination <?= $i + 1 ?><?= $i == 2 ? ' <span class="dz-opt">optional</span>' : '' ?></label>
        <input class="ja-input" id="c<?= $i ?>" name="d[]" list="cl" autocomplete="off" value="<?= htmlspecialchars($names[$i] ?? '') ?>" placeholder="<?= ['Goa', 'Manali', 'Jaipur'][$i] ?>"></div>
    <?php endfor; ?>
    <datalist id="cl"><?php foreach ($cities as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?php endforeach; ?></datalist>
    <button class="ja-btn ja-btn-primary" type="submit">Compare</button>
  </form>

  <?php if ($missing): ?>
    <div class="dz-error" role="alert"><h3>No data for <?= htmlspecialchars(implode(', ', $missing)) ?></h3>
      <p>We only compare places we hold real data for. Check the spelling, or pick from the suggestions in the boxes above.</p></div>
  <?php endif; ?>

  <?php if (count($cols) === 1): ?>
    <div class="dz-empty"><h3>Add one more place</h3><p>A comparison needs at least two destinations. <a href="<?= htmlspecialchars(ja_dest_url($cols[0]['destination'])) ?>">Read the <?= htmlspecialchars($cols[0]['city']) ?> guide</a> in the meantime.</p></div>
  <?php elseif (count($cols) >= 2): $n = count($cols); ?>
  <p class="dz-cmp-hint">Swipe sideways to see every place.</p>
  <div class="dz-cmp-scroll">
  <table class="dz-cmp-table" style="--cols:<?= $n ?>">
    <caption class="dz-sr">Destinations compared side by side</caption>
    <thead><tr><td></td>
      <?php foreach ($cols as $c): $im = ja_image_for($c['destination']); ?>
        <th scope="col">
          <?php if ($im): ?><img class="dz-cmp-photo" src="<?= htmlspecialchars($im) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()"><?php else: ?><span class="dz-cmp-photo dz-cmp-none">No photo</span><?php endif; ?>
          <a href="<?= htmlspecialchars(ja_dest_url($c['destination'])) ?>"><?= htmlspecialchars($c['city']) ?></a>
        </th>
      <?php endforeach; ?></tr></thead>
    <tbody>
      <tr><th scope="row">Cost per day</th>
        <?php foreach ($cols as $i => $c): $c1 = $c['costs']['1'] ?? null; ?>
          <td><?php if ($c1): ?><strong><?= ja_inr(ja_inr_round($c1['per_day'])) ?></strong><?php if (ja_range_text($c1['low'], $c1['high'])[0] !== 'a'): ?><small><?= ja_range_text($c1['low'], $c1['high']) ?></small><?php endif; ?><?= $i === $cheapest ? '<em class="dz-tag">Lowest</em>' : '' ?><?php else: ?>Not available<?php endif; ?></td>
        <?php endforeach; ?></tr>
      <tr><th scope="row">5 days, one traveller</th>
        <?php foreach ($cols as $c): $c5 = $c['costs']['5'] ?? null; ?><td><?= $c5 ? ja_inr(ja_inr_round($c5['total'])) : 'Not available' ?></td><?php endforeach; ?></tr>
      <tr><th scope="row">Best months</th>
        <?php foreach ($cols as $k => $c): ?><td><?= $c['season_peak'] ? ja_month_chart($c['season_peak'], 'sm', 'mc' . $k) . '<small>' . htmlspecialchars(ja_month_span($c['season_peak'])) . '</small>' : 'Not recorded' ?></td><?php endforeach; ?></tr>
      <tr><th scope="row">Region</th>
        <?php foreach ($cols as $c): ?><td><?= htmlspecialchars($c['region'] ?: 'Not recorded') ?></td><?php endforeach; ?></tr>
      <tr><th scope="row">Top activities</th>
        <?php foreach ($cols as $c): ?><td><?= $c['activities'] ? htmlspecialchars(implode(', ', array_map(function ($a) { return ucfirst($a['name']); }, array_slice($c['activities'], 0, 4)))) : 'Not recorded' ?></td><?php endforeach; ?></tr>
      <tr><th scope="row">Seen in guides</th>
        <?php foreach ($cols as $c): ?><td><?= $c['attractions'] ? htmlspecialchars(implode(', ', array_slice($c['attractions'], 0, 3))) : 'Not recorded' ?></td><?php endforeach; ?></tr>
      <tr><th scope="row"><span class="dz-sr">Next step</span></th>
        <?php foreach ($cols as $c): ?><td><a class="ja-btn ja-btn-primary" href="trip.php?dest=<?= urlencode($c['destination']) ?>&days=4">Plan a trip</a></td><?php endforeach; ?></tr>
    </tbody>
  </table>
  </div>
  <p class="dz-cap">Costs are model estimates for one traveller in a mid-range style, excluding flights. Peak months are the months travellers and guides recommend most.</p>
  <?php elseif (!$names): ?>
    <div class="dz-empty"><h3>Nothing to compare yet</h3><p>Type two or three places above, or browse <a href="recommendations.php">your picks</a> and tap Compare on the ones you like.</p></div>
  <?php endif; ?>
</main>
<?php ja_discover_assets(); include 'ja-footer.php'; ?>
