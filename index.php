<?php
$ja_title = "Plan a trip in India"; $ja_active = "home";
$ja_landing = true;
require 'ja-images.php';
require_once 'ml_client.php';
$imgs = ja_local_images();

// Real numbers from this site's own data, nothing rounded up for effect.
$nPlaces = 0; $nJournals = 0;
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

// Cover photo changes on each visit; the caption always names the place shown.
$covers = [
  ['kerala', 'Kerala backwaters', 'A houseboat on a backwater canal'],
  ['jaipur', 'Jaipur', 'Hawa Mahal, built in 1799'],
  ['goa', 'Goa', 'A beach on the Goa coast'],
  ['mountains', 'Himachal', 'A snow ridge in the Himalayas'],
];
$covers = array_values(array_filter($covers, fn($c) => isset($imgs[$c[0]])));
$pick = $covers ? $covers[array_rand($covers)] : null;
$second = null;
foreach ($covers as $c) if ($pick && $c[0] !== $pick[0]) { $second = $c; break; }

$cj_stops = [
  ['Goa', 'goa', 'Beaches, 4 days', '.1'],
  ['Jaipur', 'jaipur', 'Palaces and bazaars, 3 days', '.36'],
  ['Himalayas', 'mountains', 'Snow treks, 6 days', '.62'],
  ['Kerala', 'kerala', 'Backwaters, 5 days', '.88'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja cj-home" data-cj-landing>

<main id="main">
<section class="cj-hero" aria-labelledby="cjH1">
  <div class="cj-hero-copy">
    <h1 id="cjH1">Where do you want to go, and what can you spend?</h1>
    <p class="cj-lede">Write it the way you'd say it to a friend. JourneyAI reads real travellers' journals from India and answers with a route, a day-by-day plan and what it should cost.</p>
    <form class="cj-ask" action="plan-trip.php" method="get" role="search">
      <label for="cjAsk" class="cj-sr">Describe your trip</label>
      <input id="cjAsk" name="q" type="text" autocomplete="off" placeholder="Weekend from Ahmedabad, temples and good food, under 15,000">
      <input type="hidden" name="go" value="1">
      <button type="submit" class="cj-go">Plan my trip</button>
    </form>
    <p class="cj-try">Or start from one of these:
      <a href="plan-trip.php?q=<?= urlencode('3 days beaches under 20000') ?>&amp;go=1">3 days of beaches under 20,000</a>,
      <a href="plan-trip.php?q=<?= urlencode('Udaipur to Jaipur route') ?>&amp;go=1">Udaipur to Jaipur by road</a>,
      <a href="plan-trip.php?q=<?= urlencode('weekend temples') ?>&amp;go=1">a weekend of temples</a>.</p>
    <?php if ($nPlaces && $nJournals): ?>
    <p class="cj-facts"><?= number_format($nPlaces) ?> places and <?= number_format($nJournals) ?> journals so far. Every suggestion says why it was chosen.</p>
    <?php endif; ?>
  </div>

  <?php if ($pick): ?>
  <figure class="cj-cover">
    <img class="cj-cover-main" src="<?= htmlspecialchars($imgs[$pick[0]]) ?>" alt="<?= htmlspecialchars($pick[2]) ?>" fetchpriority="high">
    <?php if ($second): ?><img class="cj-cover-aside" src="<?= htmlspecialchars($imgs[$second[0]]) ?>" alt="<?= htmlspecialchars($second[2]) ?>" loading="lazy"><?php endif; ?>
    <figcaption><strong><?= htmlspecialchars($pick[1]) ?></strong> <?= htmlspecialchars($pick[2]) ?></figcaption>
  </figure>
  <?php endif; ?>
</section>

<?php if ($plan): $p = $plan['plan']; ?>
<section class="cj-sample" aria-labelledby="cjSampleH">
  <div class="cj-sample-head">
    <h2 id="cjSampleH">What an answer looks like</h2>
    <p>This is a real plan from the planner for <?= (int)$plan['days'] ?> days in <?= htmlspecialchars(explode(',', $plan['destination'])[0]) ?>, two people, mid-range. It costs about <strong>₹<?= number_format($plan['total_cost']) ?></strong> in all, and the best months are <?= htmlspecialchars($plan['best_season']) ?>.</p>
    <a class="cj-link" href="trip.php?dest=<?= urlencode($plan['destination']) ?>&amp;days=<?= (int)$plan['days'] ?>">Open the full plan with the map</a>
  </div>
  <div class="cj-days">
    <?php foreach (array_slice($p, 0, 3) as $d): ?>
    <article class="cj-day">
      <header><h3><?= htmlspecialchars(preg_replace('/^Day \d+\W+/u', '', $d['title'] ?? 'Day')) ?></h3><span>Day <?= (int)$d['day'] ?> &middot; about ₹<?= number_format($d['est_cost']) ?></span></header>
      <ol>
        <?php foreach ($d['timeline'] as $t): ?><li><b><?= htmlspecialchars($t['time']) ?></b><span><?= htmlspecialchars($t['text']) ?></span></li><?php endforeach; ?>
      </ol>
    </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="cj-route" id="cjRoute" aria-labelledby="cjRouteH">
  <div class="cj-route-head">
    <h2 id="cjRouteH">Four ways to see India</h2>
    <p>Keep scrolling and the pin follows the road from stop to stop.</p>
  </div>
  <div class="cj-map" id="cjMap">
    <svg class="cj-path" viewBox="0 0 1000 2400" aria-hidden="true">
      <path id="cjBase" d="M500 40 C900 220 100 420 500 640 S900 1020 500 1240 S100 1620 500 1840 S900 2200 500 2360" fill="none" stroke="currentColor" stroke-opacity=".28" stroke-width="5" stroke-dasharray="2 16" stroke-linecap="round"/>
      <path id="cjFill" d="M500 40 C900 220 100 420 500 640 S900 1020 500 1240 S100 1620 500 1840 S900 2200 500 2360" fill="none" stroke="#F4A300" stroke-width="6" stroke-linecap="round"/>
      <g id="cjDots"></g>
      <g id="cjPin" class="cj-pin"><ellipse cx="0" cy="4" rx="12" ry="4" fill="rgba(0,0,0,.2)"/><g class="bob"><path d="M0 0 C-28 -32 -22 -66 0 -66 C22 -66 28 -32 0 0Z" fill="#14213D"/><circle cx="0" cy="-44" r="9" fill="#F4A300"/></g></g>
    </svg>
    <?php foreach ($cj_stops as [$nm, $key, $sub, $f]): $img = $imgs[$key] ?? null; if (!$img) continue; ?>
      <article class="cj-stop" data-f="<?= $f ?>">
        <img loading="lazy" src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($nm) ?>">
        <div class="cj-stop-text">
          <h3><?= htmlspecialchars($nm) ?></h3><p><?= htmlspecialchars($sub) ?></p>
          <a class="cj-link" href="trip.php?dest=<?= urlencode($nm === 'Himalayas' ? 'Manali' : $nm) ?>">Plan this trip</a>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<section class="cj-trust" aria-labelledby="cjTrustH">
  <div class="cj-trust-in">
    <h2 id="cjTrustH">Anyone can write a journal. Not everything gets in.</h2>
    <p class="cj-trust-lede">Recommendations are only as honest as the journals behind them, so those are checked.</p>
    <dl>
      <div><dt>Verified people</dt><dd>Only accounts with a confirmed email can post publicly.</dd></div>
      <div><dt>Looked at first</dt><dd>New writers are reviewed before their journals appear, and copied text is flagged.</dd></div>
      <div><dt>Private photos stay private</dt><dd>Location data is removed from every photo on upload.</dd></div>
      <div><dt>No gaming the score</dt><dd>One person cannot push a destination up with many accounts.</dd></div>
    </dl>
  </div>
</section>

<section class="cj-end" aria-labelledby="cjEndH">
  <h2 id="cjEndH">Start with a place or a budget.</h2>
  <p>It takes one sentence, and you can change everything afterwards.</p>
  <a class="cj-go" href="plan-trip.php">Plan a trip</a>
  <a class="cj-link" href="register.php">Make a free account to save it</a>
</section>
</main>

<?php include 'ja-footer.php'; ?>
</body>
</html>
