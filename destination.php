<?php
/** JourneyAI — destination guide: one place, real data only (Wikivoyage/Wikipedia profile, cost model, journals). */
session_start();
require_once __DIR__ . '/ja-lib.php';
require_once __DIR__ . '/ja-dest.php';
require_once __DIR__ . '/ja-cards.php';

$ja_active = 'plan';
$q = trim((string)($_GET['d'] ?? ''));
$q = mb_substr($q, 0, 120);
$fetch = $q !== '' ? ja_dest_fetch([$q]) : ['items' => [null], 'error' => null, 'offline' => false];
$p = $fetch['items'][0] ?? null;
$signedIn = !empty($_SESSION['eml']);

if ($p === null) {
    // unknown (404) or service down (503); either way give the person a way forward
    http_response_code($fetch['offline'] ? 503 : 404);
    $ja_title = $fetch['offline'] ? 'Guide unavailable' : 'Destination not found';
    $list = ml_request('POST', '/destination', ['list' => true], 6);
    $all = $list['destinations'] ?? [];
    $cities = array_values(array_unique(array_column($all, 'city'))); sort($cities);
    $sug = [];
    foreach ($all as $row) { if (count($sug) >= 8) break; if (ja_image_for($row['destination'])) $sug[] = $row['city']; }
    ?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?><link rel="stylesheet" href="css/pages-discover.css?v=<?= (int)@filemtime(__DIR__ . '/css/pages-discover.css') ?>"></head>
<body class="ja dz-plan">
<header class="dz-pagehead">
  <?php if ($fetch['offline']): ?>
    <h1>The guide is not available right now</h1>
    <p class="dz-lede">The destination service is not responding. Reload in a minute, or plan a trip while you wait.</p>
    <p><a class="ja-btn ja-btn-primary" href="plan-trip.php">Plan a trip</a></p>
  <?php else: ?>
    <h1><?= $q !== '' ? 'We have no guide for "' . htmlspecialchars($q) . '"' : 'Pick a destination' ?></h1>
    <p class="dz-lede">We only publish places we hold real data for. Check the spelling, or search for another place or a nearby bigger town.</p>
    <form class="dz-ask dz-ask-sm" method="get" action="destination.php" role="search">
      <label class="dz-sr" for="dq">Destination</label>
      <input type="text" id="dq" name="d" list="dl" autocomplete="off" placeholder="Jaipur, Manali, Gokarna" value="<?= htmlspecialchars($q) ?>">
      <datalist id="dl"><?php foreach ($cities as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?php endforeach; ?></datalist>
      <button class="ja-btn ja-btn-primary" type="submit">Find guide</button>
    </form>
    <?php if ($sug): ?><p class="dz-try">Popular places with photos:
      <?php foreach ($sug as $i => $c): ?><a href="<?= htmlspecialchars(ja_dest_url($c)) ?>"><?= htmlspecialchars($c) ?></a><?= $i < count($sug) - 1 ? ', ' : '.' ?><?php endforeach; ?></p><?php endif; ?>
  <?php endif; ?>
</header>
<?php ja_discover_assets(); include 'ja-footer.php'; ?>
<?php exit; }

$name = $p['destination']; $city = $p['city'] ?: explode(',', $name)[0];
$ja_title = $city . ' travel guide';
$img = ja_image_for($name); $credit = ja_image_credit($name);
$peak = $p['season_peak'] ?? [];
$span = ja_month_span($peak);
$rating = ja_dest_rating($name);
$sim = ml_similar($name, 4);
$simList = (empty($sim['__error']) && !empty($sim['similar'])) ? $sim['similar'] : [];
$journals = ja_dest_journals($city, $name, 3);
$c5 = $p['costs']['5'] ?? null; $c1 = $p['costs']['1'] ?? null;
$cs = $p['cost_styles'] ?? null;                       // per-person daily cost for each style, from real price reports
$csN = (int)($cs['n_observations'] ?? 0); $csReg = (($cs['confidence'] ?? '') === 'regional estimate');
$acts = $p['activities'] ?? [];
$maxShare = $acts ? max(array_column($acts, 'share')) : 1;
$land = $p['landmarks'] ?? [];
$tierText = ['mainstream' => 'A well-known destination', 'lesser_known' => 'A lesser-known destination', 'emerging' => 'An emerging destination'][$p['popularity_tier'] ?? ''] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?>
<meta name="description" content="<?= htmlspecialchars(mb_substr(($p['description'] ?: $city . ' travel guide with best months, typical daily cost and what to do.'), 0, 155)) ?>">
<?php require_once __DIR__ . '/ja-social.php'; ja_social_og(['title' => $city . ' travel guide', 'description' => ($p['description'] ?: $city . ' travel guide with best months, typical daily cost and what to do.'), 'image' => $img ?: '', 'image_alt' => $img ? $city : '', 'url' => 'destination.php?d=' . urlencode($name), 'type' => 'website']); ?>
<link rel="stylesheet" href="css/pages-discover.css?v=<?= (int)@filemtime(__DIR__ . '/css/pages-discover.css') ?>"></head>
<body class="ja dz-plan dz-guide">

<main class="dz-wrap">
  <nav class="dz-crumb" aria-label="Breadcrumb"><a href="plan-trip.php">Plan a trip</a> <span aria-hidden="true">/</span> <span><?= htmlspecialchars($city) ?></span></nav>

  <header class="dz-ghead<?= $img ? '' : ' nophoto' ?>">
    <?php if ($img): ?>
    <figure class="dz-gphoto">
      <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($city) ?>" referrerpolicy="no-referrer" onerror="this.closest('.dz-ghead').classList.add('nophoto');this.closest('figure').remove()">
      <?php if ($credit): ?><figcaption>Photo from <a href="<?= htmlspecialchars($credit) ?>" target="_blank" rel="noopener">Wikipedia</a>, used under its free licence</figcaption><?php endif; ?>
    </figure>
    <?php endif; ?>
    <div class="dz-gtitle">
      <h1><?= htmlspecialchars($city) ?></h1>
      <p class="dz-gmeta"><?= htmlspecialchars(implode('. ', array_filter([$p['region'], $tierText]))) ?><?= $p['region'] || $tierText ? '.' : '' ?></p>
      <?php if ($p['description']): ?>
        <p class="dz-gdesc"><?= htmlspecialchars($p['description']) ?></p>
        <?php if (!empty($p['source_url'])): ?><p class="dz-src">Summary from <a href="<?= htmlspecialchars($p['source_url']) ?>" target="_blank" rel="noopener">Wikivoyage</a> (CC BY-SA 4.0).</p><?php endif; ?>
      <?php else: ?>
        <p class="dz-gdesc dz-muted">We do not hold a written summary for <?= htmlspecialchars($city) ?> yet. The facts below come from traveller journals and trip budgets.</p>
      <?php endif; ?>
      <div class="dz-gact">
        <a class="ja-btn ja-btn-primary" href="trip.php?dest=<?= urlencode($name) ?>&days=4">Plan a trip here</a>
        <?= ja_compare_btn($name) ?>
      </div>
    </div>
  </header>

  <section class="dz-facts" aria-label="At a glance">
    <dl>
      <?php if ($c1): ?><div><dt>Typical cost per day <span class="dz-dtnote">(mid-range, one traveller)</span></dt><dd><?= ja_inr(ja_inr_round($c1['per_day'])) ?></dd></div><?php endif; ?>
      <div><dt>Best months</dt><dd><?= $span ? htmlspecialchars($span) : 'Not recorded' ?></dd></div>
      <?php if ($p['region']): ?><div><dt>Region</dt><dd><?= htmlspecialchars($p['region']) ?></dd></div><?php endif; ?>
      <div><dt>Traveller rating</dt><dd><?= $rating ? number_format($rating['avg'], 1) . ' of 5 <small>from ' . $rating['n'] . '</small>' : 'None yet' ?></dd></div>
    </dl>
  </section>

  <div class="dz-gcols">
    <section class="dz-gsec" aria-labelledby="hMonths">
      <h2 id="hMonths">When to go</h2>
      <?php if ($peak): ?>
        <?= ja_month_chart($peak, 'lg', 'mc1') ?>
        <p class="dz-cap"><span class="dz-key peak"></span>Peak season: <?= htmlspecialchars($span) ?>. <span class="dz-key"></span>Other months can still work, with more heat, rain or cold.</p>
      <?php else: ?>
        <p class="dz-muted">Best months are not recorded for <?= htmlspecialchars($city) ?>. Read the journals below to see when travellers went.</p>
      <?php endif; ?>
    </section>

    <section class="dz-gsec" aria-labelledby="hCost">
      <h2 id="hCost">What it costs</h2>
      <?php if ($c5): ?>
        <p class="dz-big"><?= ucfirst(ja_range_text($c1['low'], $c1['high'])) ?> <span>per day, one traveller</span></p>
        <table class="dz-table">
          <caption class="dz-sr">Estimated total cost by trip length for one traveller</caption>
          <thead><tr><th scope="col">Trip length</th><th scope="col">Likely total</th><th scope="col">Usual range</th></tr></thead>
          <tbody>
          <?php foreach (['3' => '3 days', '5' => '5 days', '7' => '7 days'] as $k => $lab): if (empty($p['costs'][$k])): continue; endif; $c = $p['costs'][$k]; ?>
            <tr><th scope="row"><?= $lab ?></th><td><?= ja_inr(ja_inr_round($c['total'])) ?></td><td><?php $rt = ja_range_text($c['low'], $c['high']); echo $rt[0] === 'a' ? 'Narrow, close to the total' : $rt; ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (!empty($cs['styles'])): $sty = ['budget' => 'Budget', 'mid-range' => 'Mid-range', 'luxury' => 'Luxury']; ?>
        <h3 class="dz-sub">By travel style, per traveller per day</h3>
        <table class="dz-table">
          <caption class="dz-sr">Estimated daily cost for one traveller by travel style</caption>
          <thead><tr><th scope="col">Style</th><th scope="col">Likely per day</th><th scope="col">Usual range</th></tr></thead>
          <tbody>
          <?php foreach ($sty as $k => $lab): $r = $cs['styles'][$k] ?? null; if (!$r) continue; ?>
            <tr><th scope="row"><?= $lab ?></th><td><?= ja_inr(ja_inr_round($r['per_person_per_day'])) ?></td><td><?= ja_inr(ja_inr_round($r['low'])) ?> to <?= ja_inr(ja_inr_round($r['high'])) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p class="dz-cap">
          <?php if ($csReg): ?><span class="dz-badge-reg">Regional estimate</span> No price reports for <?= htmlspecialchars($city) ?> itself, so these use prices from nearby places.
          <?php else: ?>Based on <?= $csN ?> price report<?= $csN === 1 ? '' : 's' ?> from travellers for <?= htmlspecialchars($city) ?> (<?= htmlspecialchars($cs['confidence']) ?> confidence).<?php endif; ?>
          Each style reads a different real price tier for stay, meals and fares. Totals above are mid-range for one traveller; a larger group shares rooms, so the per-person cost falls. Not included: getting there, shopping, and seasonal price changes (we have no real seasonal prices).
          Change the group size in <a href="plan-trip.php#adv">the cost helper</a>.</p>
        <?php else: ?>
        <p class="dz-cap">Estimated from real traveller-reported prices for stay, meals, local fares and entry fees. It leaves out getting there and shopping. Change the group size in <a href="plan-trip.php#adv">the cost helper</a>.</p>
        <?php endif; ?>
      <?php else: ?>
        <p class="dz-muted">There is no cost estimate for this place yet.</p>
      <?php endif; ?>
    </section>
  </div>

  <?php if ($acts): ?>
  <section class="dz-gsec" aria-labelledby="hActs">
    <h2 id="hActs">What travellers do here</h2>
    <ul class="dz-actbars">
      <?php foreach ($acts as $a): $w = max(6, round($a['share'] / $maxShare * 100)); ?>
        <li><span class="dz-actname"><?= htmlspecialchars(ucfirst($a['name'])) ?></span>
          <span class="dz-actbar" aria-hidden="true"><i style="width:<?= $w ?>%"></i></span>
          <span class="dz-actpct"><?= round($a['share'] * 100) ?>%</span></li>
      <?php endforeach; ?>
    </ul>
    <p class="dz-cap">Share of the activities mentioned in journals and guides about <?= htmlspecialchars($city) ?>.</p>
  </section>
  <?php endif; ?>

  <?php if ($land || $p['attractions']): ?>
  <section class="dz-gsec" aria-labelledby="hSights">
    <h2 id="hSights">Places worth seeing</h2>
    <?php if ($land): ?>
      <ul class="dz-sights">
        <?php foreach ($land as $l): ?>
          <li><strong><?= htmlspecialchars($l['name']) ?></strong><?php if (!empty($l['category'])): ?> <em><?= htmlspecialchars($l['category']) ?></em><?php endif; ?>
            <?php if (!empty($l['note'])): ?><p><?= htmlspecialchars($l['note']) ?></p><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <ul class="dz-chiplist"><?php foreach ($p['attractions'] as $a): ?><li><?= htmlspecialchars($a) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($simList): ?>
  <section class="dz-gsec" aria-labelledby="hSim">
    <h2 id="hSim">Places like <?= htmlspecialchars($city) ?></h2>
    <div class="dz-strip">
      <?php foreach ($simList as $s): $sc = explode(',', $s['destination'])[0]; $si = ja_image_for($s['destination']); ?>
        <article class="dz-mini">
          <a class="dz-mini-photo" href="<?= htmlspecialchars(ja_dest_url($s['destination'])) ?>" tabindex="-1" aria-hidden="true">
            <?php if ($si): ?><img src="<?= htmlspecialchars($si) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()"><?php else: ?><span><?= htmlspecialchars($sc) ?></span><?php endif; ?></a>
          <h3><a href="<?= htmlspecialchars(ja_dest_url($s['destination'])) ?>"><?= htmlspecialchars($sc) ?></a></h3>
          <p><?= htmlspecialchars($s['why'] ?? '') ?>.<?= !empty($s['daily_cost']) ? ' About ' . ja_inr(ja_inr_round($s['daily_cost'])) . ' a day.' : '' ?></p>
          <?= ja_compare_btn($s['destination']) ?>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="dz-gsec" aria-labelledby="hJ">
    <h2 id="hJ">Journals about <?= htmlspecialchars($city) ?></h2>
    <?php if ($journals): ?>
      <ul class="dz-journals">
        <?php foreach ($journals as $j): ?>
          <li><a href="journal.php?id=<?= (int)$j['entry_id'] ?>">
            <?php if ($j['cover']): ?><img src="<?= htmlspecialchars($j['cover']) ?>" alt="" loading="lazy"><?php endif; ?>
            <span><strong><?= htmlspecialchars($j['Title']) ?></strong>
            <em>by <?= htmlspecialchars($j['fname'] ?: 'a traveller') ?><?= $j['days'] ? ', ' . (int)$j['days'] . ' days' : '' ?></em>
            <?= htmlspecialchars(mb_strimwidth(trim(preg_replace('/\s+/', ' ', strip_tags($j['Description'] ?? ''))), 0, 140, '...')) ?></span></a></li>
        <?php endforeach; ?>
      </ul>
      <p><a class="dz-more-link" href="read-journals.php?q=<?= urlencode($city) ?>">Read all journals mentioning <?= htmlspecialchars($city) ?></a></p>
    <?php else: ?>
      <div class="dz-empty dz-empty-sm"><h3>No one has shared a journal about <?= htmlspecialchars($city) ?> yet</h3>
        <p>Been here? Your notes and costs would help the next traveller, and make these estimates better.</p>
        <a class="ja-btn ja-btn-ghost" href="<?= $signedIn ? 'new-entry.php' : 'register.php' ?>"><?= $signedIn ? 'Write a journal' : 'Create an account to write one' ?></a></div>
    <?php endif; ?>
  </section>
</main>
<?php ja_discover_assets(); include 'ja-footer.php'; ?>
