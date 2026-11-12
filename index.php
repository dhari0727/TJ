<?php
$ja_title = "Plan a trip in India"; $ja_active = "home";
$ja_landing = true;
require 'ja-images.php';
require_once 'ml_client.php';
require_once 'ja-dest.php';
require_once 'ja-lib.php';
$imgs = ja_local_images();

// Real numbers from this site's own data, nothing rounded up for effect.
$nPlaces = 0; $nJournals = 0;
$conn = function_exists('ja_db') ? @ja_db() : ($conn ?? null);
if (!empty($conn)) {
    $r = @mysqli_query($conn, "SELECT (SELECT COUNT(*) FROM destinations) d, (SELECT COUNT(*) FROM db WHERE moderation_status='approved') j");
    if ($r && ($row = mysqli_fetch_assoc($r))) { $nPlaces = (int)$row['d']; $nJournals = (int)$row['j']; }
}

// One real itinerary from the planner, kept for a day so the page stays quick.
function cj_sample_plan() {
    $f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ja_landing_plan.json';
    if (is_file($f) && time() - filemtime($f) < 86400) { $d = json_decode(@file_get_contents($f), true); if (is_array($d)) return $d; }
    $d = ml_itinerary(['destination' => 'Jaipur, India', 'days' => 3, 'travel_style' => 'mid-range', 'party_size' => 2]);
    if (!empty($d['__error']) || empty($d['plan'])) return null;
    @file_put_contents($f, json_encode($d));
    return $d;
}
$plan = cj_sample_plan();

/* Real destination facts for the journey and the photo reel, from the destination profile service
   (Wikivoyage, Wikipedia and real journals). Kept for a day; if the service is down the page still works, just without the numbers. */
function cj_facts($cities, $reelNames) {
    $f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ja_landing_stops.json';
    if (is_file($f) && time() - filemtime($f) < 86400) { $d = json_decode(@file_get_contents($f), true); if (is_array($d) && !empty($d['p'])) return $d; }
    $out = ['p' => [], 'perday' => [], 'count' => 0];
    foreach (array_chunk($cities, 3) as $chunk) {
        $r = ml_request('POST', '/destination', ['destinations' => $chunk], 12);
        if (!empty($r['__error'])) break;
        foreach (($r['destinations'] ?? $r['results'] ?? []) as $p) {
            if (!is_array($p)) continue;
            $c = $p['city'] ?? ''; if ($c === '') continue;
            $out['p'][$c] = [
                'destination' => $p['destination'] ?? $c, 'region' => $p['region'] ?? '',
                'season_peak' => $p['season_peak'] ?? [], 'attractions' => array_slice($p['attractions'] ?? [], 0, 3),
                'per_day' => $p['costs']['3']['per_day'] ?? ($p['base_daily_cost'] ?? null),
                'n_journals' => (int)($p['n_journals'] ?? 0),
            ];
        }
    }
    $l = ml_request('POST', '/destination', ['list' => true], 12);
    if (empty($l['__error']) && !empty($l['destinations'])) {
        $out['count'] = count($l['destinations']);
        foreach ($l['destinations'] as $row) if (in_array($row['city'], $reelNames, true)) $out['perday'][$row['city']] = ['destination' => $row['destination'], 'per_day' => $row['per_day']];
    }
    if ($out['p']) @file_put_contents($f, json_encode($out));
    return $out;
}

/* Hero photos: three real photographs of named places, in a different order on each visit. */
$covers = [
  ['jaipur', 'Jaipur', 'Hawa Mahal, built in 1799'],
  ['kerala', 'Kerala backwaters', 'A houseboat on a quiet canal'],
  ['mountains', 'Himachal', 'A snow ridge in the Himalayas'],
  ['goa', 'Goa', 'A beach on the Goa coast'],
];
$covers = array_values(array_filter($covers, fn($c) => isset($imgs[$c[0]])));
shuffle($covers); $covers = array_slice($covers, 0, 3);

/* The scroll journey: five real places in the order a road trip would take them. */
$coords = json_decode((string)@file_get_contents(__DIR__ . '/ml/data/dest_coords.json'), true) ?: [];
$cj_stops = [
  // city in the guide, name shown, local photo, alt text, label side, coordinate key
  ['Manali', 'Manali', 'mountains', 'A snow ridge in the Himalayas', 'r', 'Manali, India'],
  ['Jaipur', 'Jaipur', 'jaipur', 'Hawa Mahal in Jaipur', 'l', 'Jaipur, India'],
  ['Panaji', 'Goa', 'goa', 'A beach on the Goa coast', 'l', 'Panaji, India'],
  ['Kochi', 'Kerala', 'kerala', 'A houseboat on a Kerala backwater canal', 'l', 'Kochi, India'],
  ['Pondicherry', 'Pondicherry', '', 'Rock Beach in Pondicherry', 'r', 'Pondicherry, India'],
];
$cj_stops = array_map(function ($s) use ($imgs) { $s[6] = $imgs[$s[2]] ?? ja_image_for($s[5]); return $s; }, $cj_stops);
$cj_stops = array_values(array_filter($cj_stops, fn($s) => $s[6] && isset($coords[$s[5]])));
$cj_ref = [['Delhi','Delhi, India','r'],['Mumbai','Mumbai, India','l'],['Kolkata','Kolkata, India','r'],['Varanasi','Varanasi, India','r']];

$cj_reelPrefs = ['Udaipur','Jaisalmer','Jodhpur','Darjeeling','Rishikesh','Munnar','Varanasi','Kolkata','Mumbai','Gokarna','Gangtok','Amritsar','Shimla','Mysore'];
$facts = cj_facts(array_column($cj_stops, 0), $cj_reelPrefs);
if (!$nPlaces && !empty($facts['count'])) $nPlaces = (int)$facts['count'];
$P = $facts['p'] ?? [];

// India outline (lon, lat), simplified, projected to the same x/y as the city dots.
$cj_outline = [[74.0,34.9],[75.5,36.5],[77.8,35.5],[79.0,34.4],[78.7,32.6],[79.9,31.0],[81.0,30.2],[80.1,28.8],[81.5,28.4],[84.1,27.4],[86.0,26.6],[88.1,26.4],[88.0,27.2],[88.8,27.5],[89.8,26.8],[92.0,26.9],[92.1,27.9],[93.8,28.6],[95.3,29.2],[96.2,28.3],[97.3,28.2],[96.0,27.2],[95.2,26.0],[94.6,25.2],[94.3,23.9],[93.4,22.9],[93.0,22.0],[92.3,22.7],[92.2,23.7],[91.6,24.2],[92.0,25.1],[90.3,25.1],[89.8,25.4],[89.8,26.0],[89.0,26.3],[88.4,25.5],[88.1,24.7],[88.7,24.2],[88.9,23.2],[89.0,22.1],[88.8,21.6],[87.5,21.5],[86.9,21.0],[86.5,20.2],[85.9,19.8],[85.0,19.3],[84.2,18.5],[83.3,17.7],[82.3,16.6],[81.3,16.3],[80.4,15.8],[80.1,15.0],[80.2,13.5],[80.3,13.0],[79.9,11.9],[79.8,10.9],[79.3,10.3],[78.9,9.3],[78.1,8.9],[77.5,8.1],[77.2,8.1],[76.6,8.7],[76.3,9.7],[75.9,11.0],[75.3,12.2],[74.8,13.2],[74.4,14.4],[74.1,15.2],[73.8,15.5],[73.4,16.5],[73.0,17.9],[72.8,19.2],[72.7,20.5],[72.9,21.2],[72.6,21.6],[72.2,21.1],[71.0,20.8],[70.2,21.2],[69.2,21.7],[69.0,22.3],[70.2,22.9],[68.7,23.3],[68.2,23.7],[68.9,24.3],[70.0,24.2],[71.1,24.4],[70.5,25.7],[69.6,26.7],[70.3,28.0],[71.8,27.9],[72.9,29.0],[74.0,29.8],[74.6,31.0],[74.5,31.7],[74.6,32.5],[75.4,32.9],[74.3,33.9]];
const CJ_W = 870; const CJ_H = 910;
function cj_xy($lat, $lon) { return [round(($lon - 67) * 27.8 + 20, 1), round((37.2 - $lat) * 30 + 20, 1)]; }
$outlineD = '';
foreach ($cj_outline as $i => $pt) { [$x, $y] = cj_xy($pt[1], $pt[0]); $outlineD .= ($i ? 'L' : 'M') . $x . ' ' . $y; }
$outlineD .= 'Z';
$xy = [];
foreach ($cj_stops as $i => $s) $xy[$i] = cj_xy($coords[$s[5]][0], $coords[$s[5]][1]);
// a gentle arc between each pair of stops, bowed to alternating sides like a hand-drawn route
$segD = [];
for ($i = 0; $i < count($xy) - 1; $i++) {
    [$x0, $y0] = $xy[$i]; [$x1, $y1] = $xy[$i + 1];
    $mx = ($x0 + $x1) / 2; $my = ($y0 + $y1) / 2; $dx = $x1 - $x0; $dy = $y1 - $y0; $len = max(1, sqrt($dx * $dx + $dy * $dy));
    $bow = 0.22 * $len * ($i % 2 ? -1 : 1);
    $cx = round($mx - $dy / $len * $bow, 1); $cy = round($my + $dx / $len * $bow, 1);
    $segD[] = "M$x0 $y0 Q$cx $cy $x1 $y1";
}
$nStops = count($cj_stops);

// stars for the night section: fixed positions so the page is stable between visits
$stars = []; mt_srand(7);
for ($i = 0; $i < 26; $i++) $stars[] = [mt_rand(2, 98), mt_rand(3, 96), mt_rand(0, 40) / 10, mt_rand(1, 3)];
mt_srand();

$icon = [
  'check' => '<path d="M12 3l7 3v5c0 5-3 8-7 10-4-2-7-5-7-10V6z"/><path d="M8.8 12l2.2 2.2L15.4 9.8"/>',
  'eye'   => '<path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.8"/>',
  'pin'   => '<path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0113 0c0 5.4-6.5 11-6.5 11z"/><path d="M4 4l16 16"/>',
  'scale' => '<path d="M12 4v16M6 20h12M5 7h14"/><path d="M5 7l-3 7a3.2 3.2 0 006 0zM19 7l-3 7a3.2 3.2 0 006 0z"/>',
];
function cj_words($s) { $o = []; foreach (explode(' ', $s) as $i => $w) $o[] = '<span class="lp-w"><span style="--i:' . $i . '">' . htmlspecialchars($w) . '</span></span>'; return implode(' ', $o); }
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja lp">

<main id="main">
<!-- 1. Hero: a full-bleed photograph, one line, and the planner bar docked at the bottom -->
<section class="lp-hero" aria-labelledby="lpH1" id="lpHero">
  <?php if ($covers): ?>
  <div class="lp-media" aria-hidden="true">
    <?php foreach ($covers as $i => $c): ?>
    <div class="lp-slide<?= $i === 0 ? ' on' : '' ?>"><img src="<?= htmlspecialchars($imgs[$c[0]]) ?>" alt=""<?= $i === 0 ? ' fetchpriority="high"' : '' ?> decoding="async"></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="lp-scrim" aria-hidden="true"></div>

  <?php if ($covers): ?>
  <figure class="lp-cap" aria-label="Photo caption">
    <?php foreach ($covers as $i => $c): ?>
    <figcaption class="<?= $i === 0 ? 'on' : '' ?>"<?= $i === 0 ? '' : ' aria-hidden="true"' ?>><strong><?= htmlspecialchars($c[1]) ?></strong><span><?= htmlspecialchars($c[2]) ?></span></figcaption>
    <?php endforeach; ?>
    <div class="lp-ticks" aria-hidden="true"><?php foreach ($covers as $i => $c): ?><i class="<?= $i === 0 ? 'on' : '' ?>"></i><?php endforeach; ?></div>
  </figure>
  <?php endif; ?>

  <div class="lp-hero-in">
    <h1 id="lpH1"><?= cj_words('Where to next, and what will it cost?') ?></h1>
    <p class="lp-lede">JourneyAI reads real travellers' journals from India and answers with a route, a day-by-day plan and what it should cost.</p>
  </div>

  <div class="lp-dockwrap">
    <form class="lp-dock" id="lpDock" action="plan-trip.php" method="get" role="search" aria-label="Plan a trip">
      <div class="lp-f lp-f-where">
        <label for="lpWhere">Where</label>
        <input id="lpWhere" name="q" type="text" autocomplete="off" list="lpPlaces" placeholder="Goa, or Udaipur to Jaipur" enterkeyhint="search">
        <datalist id="lpPlaces"></datalist>
      </div>
      <div class="lp-f lp-f-days">
        <label for="lpDays">Days</label>
        <div class="lp-step-in">
          <button type="button" class="lp-sb" data-step="-1" aria-label="One day fewer"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 12h12"/></svg></button>
          <input id="lpDays" name="days" type="number" inputmode="numeric" min="1" max="14" placeholder="Any">
          <button type="button" class="lp-sb" data-step="1" aria-label="One day more"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 12h12M12 6v12"/></svg></button>
        </div>
      </div>
      <div class="lp-f lp-f-bud">
        <label for="lpBud">Budget</label>
        <div class="lp-rs"><span aria-hidden="true">₹</span><input id="lpBud" name="budget" type="text" inputmode="numeric" autocomplete="off" placeholder="Total, optional"></div>
      </div>
      <input type="hidden" name="go" value="1">
      <button type="submit" class="lp-btn lp-btn-mel lp-dock-go">Plan my trip</button>
    </form>
    <p class="lp-try">Or start from
      <a href="plan-trip.php?q=<?= urlencode('3 days beaches under 20000') ?>&amp;go=1">3 days of beaches under 20,000</a>,
      <a href="plan-trip.php?q=<?= urlencode('Udaipur to Jaipur route') ?>&amp;go=1">Udaipur to Jaipur by road</a>,
      <a href="plan-trip.php?q=<?= urlencode('weekend temples') ?>&amp;go=1">a weekend of temples</a>.
      <?php if ($nPlaces && $nJournals): ?><span class="lp-facts"><?= number_format($nPlaces) ?> places and <?= number_format($nJournals) ?> journals so far.</span><?php endif; ?></p>
  </div>
</section>

<!-- 2. The scroll journey: a pin travels the route between five real places -->
<?php if ($nStops >= 2): ?>
<section class="lp-journey" id="lpJourney" aria-labelledby="lpJH" style="--n:<?= $nStops ?>">
  <div class="lp-j-track">
  <div class="lp-j-stick">
    <div class="lp-j-grid">
      <div class="lp-j-mapwrap">
        <svg class="lp-map" id="lpMap" viewBox="0 0 <?= CJ_W ?> <?= CJ_H ?>" role="img" aria-label="A map of India with a route through <?= htmlspecialchars(implode(', ', array_column($cj_stops, 1))) ?>" preserveAspectRatio="xMidYMid meet" data-w="<?= CJ_W ?>" data-h="<?= CJ_H ?>">
          <defs>
            <pattern id="lpDots" width="14" height="14" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.5" class="lp-pdot"/></pattern>
          </defs>
          <path class="lp-land" d="<?= $outlineD ?>"/>
          <path class="lp-land-tex" d="<?= $outlineD ?>" fill="url(#lpDots)"/>
          <?php foreach ($cj_ref as [$nm, $key, $side]): if (!isset($coords[$key])) continue; [$x, $y] = cj_xy($coords[$key][0], $coords[$key][1]); ?>
          <g class="lp-ref"><circle cx="<?= $x ?>" cy="<?= $y ?>" r="4.5"/><text x="<?= $x + ($side === 'r' ? 11 : -11) ?>" y="<?= $y + 4 ?>" text-anchor="<?= $side === 'r' ? 'start' : 'end' ?>"><?= htmlspecialchars($nm) ?></text></g>
          <?php endforeach; ?>
          <?php foreach ($segD as $i => $d): ?><path class="lp-seg-base" d="<?= $d ?>"/><?php endforeach; ?>
          <?php foreach ($segD as $i => $d): ?><path class="lp-seg" data-i="<?= $i ?>" d="<?= $d ?>"/><?php endforeach; ?>
          <g id="lpDust"></g>
          <?php foreach ($cj_stops as $i => $s): [$x, $y] = $xy[$i]; ?>
          <g class="lp-dot" data-i="<?= $i ?>"><circle class="lp-halo" cx="<?= $x ?>" cy="<?= $y ?>" r="22"/><circle class="lp-core" cx="<?= $x ?>" cy="<?= $y ?>" r="9"/>
            <text x="<?= $x + ($s[4] === 'r' ? 20 : -20) ?>" y="<?= $y + 5 ?>" text-anchor="<?= $s[4] === 'r' ? 'start' : 'end' ?>"><?= htmlspecialchars($s[1]) ?></text></g>
          <?php endforeach; ?>
          <g id="lpPin" class="lp-pin" transform="translate(<?= $xy[0][0] ?>,<?= $xy[0][1] ?>)"><g class="lp-pin-s"><ellipse class="lp-pin-shadow" cx="0" cy="2" rx="14" ry="5"/><g class="lp-bob"><path d="M0 0C-30-34-24-70 0-70S30-34 0 0z" class="lp-pin-body"/><circle cx="0" cy="-47" r="10" class="lp-pin-eye"/></g></g></g>
        </svg>
        <p class="lp-note" aria-hidden="true">start in the hills</p>
      </div>

      <div class="lp-j-side">
        <header class="lp-j-head">
          <h2 id="lpJH"><?= ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six'][$nStops] ?? $nStops ?> places, one long road</h2>
          <p>Scroll and the pin follows the route. Cost and best months come from the same guides you get when you search.</p>
          <p class="lp-step" id="lpStep" aria-live="polite">Stop 1 of <?= $nStops ?></p>
        </header>
        <div class="lp-j-cards">
        <?php foreach ($cj_stops as $i => $s):
            $pf = $P[$s[0]] ?? null;
            $perDay = $pf && !empty($pf['per_day']) ? ja_inr_round($pf['per_day']) : null;
            $months = $pf && !empty($pf['season_peak']) ? ja_month_span($pf['season_peak']) : '';
            $known = $pf ? implode(', ', array_slice($pf['attractions'] ?? [], 0, 3)) : '';
        ?>
          <article class="lp-stop<?= $i === 0 ? ' is-on' : ' is-next' ?>" data-i="<?= $i ?>">
            <div class="lp-stop-img"><img loading="lazy" decoding="async" src="<?= htmlspecialchars($s[6]) ?>" alt="<?= htmlspecialchars($s[3]) ?>" referrerpolicy="no-referrer"></div>
            <div class="lp-stop-body">
              <h3><?= htmlspecialchars($s[1]) ?><?php if ($s[1] !== $s[0]): ?> <small><?= htmlspecialchars($s[0]) ?></small><?php endif; ?></h3>
              <?php if ($perDay || $months || $known): ?>
              <dl class="lp-facts-l">
                <?php if ($perDay): ?><div><dt>A typical day</dt><dd>about <?= ja_inr($perDay) ?> each</dd></div><?php endif; ?>
                <?php if ($months): ?><div><dt>Best months</dt><dd><?= htmlspecialchars($months) ?></dd></div><?php endif; ?>
                <?php if ($known): ?><div><dt>Known for</dt><dd><?= htmlspecialchars($known) ?></dd></div><?php endif; ?>
              </dl>
              <?php endif; ?>
              <p class="lp-stop-links"><a class="lp-link" href="<?= htmlspecialchars(ja_dest_url($s[0])) ?>">Read the guide</a> <a class="lp-link" href="trip.php?dest=<?= urlencode($s[0]) ?>">Plan this trip</a></p>
            </div>
          </article>
        <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  </div>
</section>
<?php endif; ?>

<!-- 3. What an answer looks like: a flat deep-green block, tickets over a photo of the place -->
<?php if ($plan): $p = $plan['plan']; $jp = $imgs['jaipur'] ?? null; ?>
<section class="lp-sample" aria-labelledby="lpSampleH" id="lpSample">
  <div class="lp-wrap lp-sample-grid">
    <div class="lp-sample-head">
      <h2 id="lpSampleH">What an answer looks like</h2>
      <p>This is a real plan from the planner for <?= (int)$plan['days'] ?> days in <?= htmlspecialchars(explode(',', $plan['destination'])[0]) ?>, two people, mid-range. It costs about <strong>₹<?= number_format($plan['total_cost']) ?></strong> in all, and the best months are <?= htmlspecialchars($plan['best_season']) ?>.</p>
      <a class="lp-link lp-link-l" href="trip.php?dest=<?= urlencode($plan['destination']) ?>&amp;days=<?= (int)$plan['days'] ?>">Open the full plan with the map</a>
      <?php if ($jp): ?>
      <figure class="lp-collage lp-collage-1">
        <img src="<?= htmlspecialchars($jp) ?>" alt="Hawa Mahal in Jaipur" loading="lazy" decoding="async">
        <figcaption>Jaipur</figcaption>
      </figure>
      <?php endif; ?>
    </div>
    <div class="lp-tickets">
      <?php foreach (array_slice($p, 0, 3) as $k => $d): ?>
      <article class="lp-ticket" style="--k:<?= $k ?>">
        <div class="lp-stub"><span class="lp-stub-d">Day <?= (int)$d['day'] ?></span><span class="lp-stub-c">about ₹<?= number_format($d['est_cost']) ?></span></div>
        <div class="lp-tbody">
          <?php $tt = trim(preg_replace('/^Day \d+\W*/u', '', $d['title'] ?? '')); if ($tt !== ''): ?><h3><?= htmlspecialchars($tt) ?></h3><?php endif; ?>
          <ol>
            <?php foreach ($d['timeline'] as $j => $t): ?><li style="--j:<?= $j ?>"><b><?= htmlspecialchars($t['time']) ?></b><span><?= htmlspecialchars($t['text']) ?></span></li><?php endforeach; ?>
          </ol>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- 4. Photo reel -->
<?php
$reel = [];
foreach ($cj_reelPrefs as $c) {
    if (count($reel) >= 12) break;
    $row = $facts['perday'][$c] ?? null; if (!$row) continue;
    $im = ja_image_for($row['destination']); if (!$im) continue;
    $reel[] = [$c, $im, $row['per_day']];
}
if ($reel): ?>
<section class="lp-reel" aria-labelledby="lpReelH">
  <div class="lp-wrap lp-reel-head">
    <div><h2 id="lpReelH">More places to look at</h2><p>Drag, swipe or use the arrows. Each one opens its guide.</p></div>
    <div class="lp-reel-btns"><button type="button" class="lp-rb" data-dir="-1" aria-label="Previous places"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></button><button type="button" class="lp-rb" data-dir="1" aria-label="Next places"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg></button></div>
  </div>
  <ul class="lp-track" id="lpReel">
    <?php foreach ($reel as [$c, $im, $pd]): ?>
    <li class="lp-card"><a href="<?= htmlspecialchars(ja_dest_url($c)) ?>" draggable="false">
      <img src="<?= htmlspecialchars($im) ?>" alt="" loading="lazy" decoding="async" draggable="false" referrerpolicy="no-referrer" onerror="var l=this.closest('li');l&&l.remove()">
      <span class="lp-card-t"><strong><?= htmlspecialchars($c) ?></strong><?php if ($pd): ?><span>about <?= ja_inr(ja_inr_round($pd)) ?> a day</span><?php endif; ?></span>
    </a></li>
    <?php endforeach; ?>
  </ul>
  <p class="lp-wrap lp-credit">Photos are each place's lead image on Wikipedia, used under their free licences.</p>
</section>
<?php endif; ?>

<!-- 5. Trust, at night -->
<section class="lp-trust" aria-labelledby="lpTrustH">
  <div class="lp-stars" aria-hidden="true"><?php foreach ($stars as [$x, $y, $d, $s]): ?><i style="left:<?= $x ?>%;top:<?= $y ?>%;--d:<?= $d ?>s;--s:<?= $s ?>px"></i><?php endforeach; ?></div>
  <div class="lp-wrap lp-trust-in">
    <h2 id="lpTrustH">Anyone can write a journal. Not everything gets in.</h2>
    <p class="lp-trust-lede">Recommendations are only as honest as the journals behind them, so those are checked.</p>
    <?php $nums = array_values(array_filter([[ $nPlaces, 'places with a guide' ], [ $nJournals, 'approved journals' ]], fn($n) => $n[0] > 0)); if ($nums): ?>
    <ul class="lp-nums"><?php foreach ($nums as [$v, $l]): ?><li><b data-count="<?= (int)$v ?>"><?= number_format($v) ?></b><span><?= htmlspecialchars($l) ?></span></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <dl class="lp-pledges">
      <div><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icon['check'] ?></svg><dt>Verified people</dt><dd>Only accounts with a confirmed email can post publicly.</dd></div>
      <div><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icon['eye'] ?></svg><dt>Looked at first</dt><dd>New writers are reviewed before their journals appear, and copied text is flagged.</dd></div>
      <div><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icon['pin'] ?></svg><dt>Private photos stay private</dt><dd>Location data is removed from every photo on upload.</dd></div>
      <div><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icon['scale'] ?></svg><dt>No gaming the score</dt><dd>One person cannot push a destination up with many accounts.</dd></div>
    </dl>
  </div>
</section>

<!-- 6. Closing: a flat watermelon block with an offset collage of real photos -->
<section class="lp-end" aria-labelledby="lpEndH">
  <div class="lp-wrap lp-end-grid">
    <div class="lp-end-in">
      <h2 id="lpEndH">Start with a place or a budget.</h2>
      <p class="lp-end-note">It takes one sentence, and you can change everything afterwards.</p>
      <p class="lp-end-act"><a class="lp-btn lp-btn-cream lp-mag" href="plan-trip.php">Plan a trip</a> <a class="lp-link lp-link-l" href="register.php">Make a free account to save it</a></p>
    </div>
    <div class="lp-end-collage" aria-hidden="true">
      <?php foreach ([['goa', 'Goa'], ['taj-mahal', 'Agra'], ['kerala', 'Kerala']] as $i => [$k, $nm]): if (!isset($imgs[$k])) continue; ?>
      <figure class="lp-ec lp-ec-<?= $i + 1 ?>"><img src="<?= htmlspecialchars($imgs[$k]) ?>" alt="" loading="lazy" decoding="async"><figcaption><?= $nm ?></figcaption></figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
</main>

<?php include 'ja-footer.php'; ?>
<script src="js/landing.js?v=<?= (int)@filemtime(__DIR__ . '/js/landing.js') ?>" defer></script>
</body>
</html>
