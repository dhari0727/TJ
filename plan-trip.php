<?php
$ja_title = "Plan a trip"; $ja_active = "plan";
require 'ml_client.php';
require_once __DIR__ . '/ja-lib.php';
require_once __DIR__ . '/ja-dest.php';
$prefs = ja_user_prefs($_SESSION['eml'] ?? null);   // saved defaults (home city, budget, style, interests)

// allowed interest vocabulary (mirrors the ML service)
$ALL_INTERESTS = ['beach','trekking','food','nightlife','history','temples','museums',
  'shopping','wildlife','adventure','relaxation','photography','nature','culture',
  'mountains','desert','snow','diving','architecture'];
$STYLES = ['budget','mid-range','luxury','adventure','family','solo','backpacker'];
$MODES  = ['day'=>'Day trip','weekend'=>'Weekend','short'=>'3 to 5 days','long'=>'A week or more'];
$MODE_DAYS = ['day'=>1,'weekend'=>2,'short'=>4,'long'=>8];

$result = null; $nearby = null; $submitted = false; $mode='short';
$q = ['budget'=>$prefs['default_budget'], 'party'=>$prefs['party_size'], 'style'=>$prefs['travel_style'],
      'interests'=>$prefs['interests'], 'origin'=>$prefs['home_city'], 'mode'=>'short', 'days'=>4, 'month'=>null];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = true;
    $budget   = max(1000, min(2000000, (int)($_POST['budget'] ?? 30000)));
    $party    = max(1, min(20, (int)($_POST['party'] ?? 1)));
    $month    = (int)($_POST['month'] ?? 0); $month = ($month >= 1 && $month <= 12) ? $month : null;
    $style    = in_array($_POST['style'] ?? '', $STYLES, true) ? $_POST['style'] : 'mid-range';
    $mode     = array_key_exists($_POST['mode'] ?? '', $MODES) ? $_POST['mode'] : 'short';
    $origin   = trim($_POST['origin'] ?? '');   // free-text "where are you starting from?"
    $interests = array_values(array_intersect((array)($_POST['interests'] ?? []), $ALL_INTERESTS));
    $days     = (int)($_POST['days'] ?? 0);
    $duration = ($days >= 1 && $days <= 30) ? $days : $MODE_DAYS[$mode];
    $days     = $duration;

    $q = compact('budget','party','month','style','interests','origin','mode','days');

    // Day/Weekend + a specific origin => REAL nearby places (OSM). Otherwise => ML destinations.
    if ($origin !== '' && in_array($mode, ['day','weekend'], true)) {
        $nearby = ml_request('POST','/nearby', [
            'place'=>$origin, 'mode'=>$mode, 'interests'=>$interests, 'limit'=>12
        ], 40);
    } else {
        $result = ml_recommend([
            'eml' => $_SESSION['eml'] ?? null,
            'budget' => $budget, 'duration_days' => $duration, 'party_size' => $party,
            'month' => $month, 'travel_style' => $style, 'interests' => $interests, 'top_n' => 6,
            'origin' => $origin, 'trip_mode' => $mode,   // rank by distance from where you start
        ]);
    }
} else {
    $q['days'] = $MODE_DAYS['short'];
}

// destination names for the "price one place" box
$nameList = ml_request('POST', '/destination', ['list' => true], 6);
$cities = [];
foreach (($nameList['destinations'] ?? []) as $d) $cities[$d['city']] = true;
$cities = array_keys($cities); sort($cities);
$mn = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?><link rel="stylesheet" href="css/pages-discover.css?v=<?= (int)@filemtime(__DIR__ . '/css/pages-discover.css') ?>"></head>
<body class="ja dz-plan">

<header class="dz-pagehead">
  <h1>Where to next<?= !empty($_SESSION['fname']) ? ', ' . htmlspecialchars($_SESSION['fname']) : '' ?>?</h1>
  <p class="dz-lede">Describe the trip in your own words. You get places, a route and a cost estimate on this page.</p>

  <form id="smartForm" class="dz-ask" role="search">
    <label class="dz-sr" for="smartInput">Describe your trip</label>
    <?= ja_icon('search',20) ?>
    <input type="text" id="smartInput" autocomplete="off"
      placeholder="Weekend near Surat, food"
      value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
    <button type="submit" class="ja-btn ja-btn-primary">Plan it</button>
  </form>

  <p class="dz-try">Try
    <button type="button" class="dz-textbtn" data-ex="1 day near me, temples">1 day near me, temples</button>,
    <button type="button" class="dz-textbtn" data-ex="Weekend from Ahmedabad, food & gardens">a weekend of food and gardens</button>,
    <button type="button" class="dz-textbtn" data-ex="3 days beaches under 20000">3 days of beaches under ₹20,000</button> or
    <button type="button" class="dz-textbtn" data-ex="Udaipur to Jaipur route">the Udaipur to Jaipur route</button>.</p>
  <div id="geoNote" class="dz-note" aria-live="polite"></div>
</header>

<div id="smartResults" class="ja-smart-results dz-wrap" aria-live="polite"></div>

<main class="dz-wrap">
  <details class="dz-adv" id="adv" <?= $submitted ? 'open' : '' ?>>
    <summary>Set budget, month, style and interests yourself</summary>
    <div class="dz-adv-grid">
      <form method="post" id="planForm" class="dz-form">
        <div class="dz-field">
          <label for="budget">Budget <strong id="budgetLabel">₹<?= number_format($q['budget'] ?? 30000) ?></strong></label>
          <input class="ja-range" type="range" name="budget" id="budget" min="5000" max="200000" step="1000" value="<?= (int)($q['budget'] ?? 30000) ?>">
        </div>

        <fieldset class="dz-field dz-fs">
          <legend>How long is the trip</legend>
          <div class="dz-choices" id="modeChips">
            <?php foreach ($MODES as $mk=>$ml): ?>
              <button type="button" class="ja-chip <?= (($q['mode'] ?? 'short')===$mk)?'on':'' ?>" data-mode="<?= $mk ?>" data-days="<?= $MODE_DAYS[$mk] ?>" aria-pressed="<?= (($q['mode'] ?? 'short')===$mk)?'true':'false' ?>"><?= $ml ?></button>
            <?php endforeach; ?>
          </div>
          <input type="hidden" name="mode" id="modeInput" value="<?= htmlspecialchars($q['mode'] ?? 'short') ?>">
        </fieldset>

        <div class="dz-row">
          <div class="dz-field">
            <label for="days">Days</label>
            <input class="ja-input" type="number" name="days" id="days" min="1" max="30" value="<?= (int)($q['days'] ?? 4) ?>">
          </div>
          <div class="dz-field">
            <label for="party">Travellers</label>
            <input class="ja-input" type="number" name="party" id="party" min="1" max="20" value="<?= (int)($q['party'] ?? 1) ?>">
          </div>
          <div class="dz-field">
            <label for="style">Travel style</label>
            <select class="ja-select" name="style" id="style">
              <?php foreach ($STYLES as $s): ?>
                <option value="<?= $s ?>" <?= (($q['style'] ?? 'mid-range')===$s)?'selected':'' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="dz-field">
            <label for="month">Month</label>
            <select class="ja-select" name="month" id="month">
              <option value="0">Any month</option>
              <?php for($m=1;$m<=12;$m++): ?>
                <option value="<?= $m ?>" <?= (($q['month'] ?? null)==$m)?'selected':'' ?>><?= $mn[$m] ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <div class="dz-field">
          <label for="origin">Where are you starting from
            <button type="button" id="nearMeBtn" class="dz-textbtn dz-nearme"><?= ja_icon('map-pin',14) ?> <span>Use my location</span></button>
          </label>
          <input class="ja-input" type="text" name="origin" id="origin" autocomplete="off"
                 placeholder="Anand, Ahmedabad, Mumbai" value="<?= htmlspecialchars($q['origin'] ?? '') ?>">
          <p class="dz-hint" id="geoStatus">With a day trip or weekend we list real places close to you. For longer trips we rank destinations by distance from here.</p>
        </div>

        <fieldset class="dz-field dz-fs">
          <legend>What you enjoy <span class="dz-opt">pick as many as you like</span></legend>
          <div class="dz-choices" id="chips">
            <?php $sel = $q['interests'] ?? []; foreach ($ALL_INTERESTS as $it): ?>
              <button type="button" class="ja-chip <?= in_array($it,$sel,true)?'on':'' ?>" data-val="<?= $it ?>" aria-pressed="<?= in_array($it,$sel,true)?'true':'false' ?>"><?= $it ?></button>
            <?php endforeach; ?>
          </div>
          <div id="interestInputs"></div>
        </fieldset>

        <button class="ja-btn ja-btn-primary dz-submit" type="submit" id="planSubmit">Find destinations</button>
      </form>

      <aside class="dz-estimate" id="estimate" aria-labelledby="estH">
        <h2 id="estH">What this trip could cost</h2>
        <div class="dz-field">
          <label for="estPlace">Price one place <span class="dz-opt">optional</span></label>
          <input class="ja-input" type="text" id="estPlace" list="cityList" autocomplete="off" placeholder="Any place, or leave blank">
          <datalist id="cityList"><?php foreach ($cities as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?php endforeach; ?></datalist>
        </div>
        <div class="dz-est-out" id="estOut" aria-live="polite">
          <p class="dz-hint">Change days, travellers, travel style or budget and the estimate updates here.</p>
        </div>
        <p class="dz-hint dz-est-foot">Built from about 2,100 real prices that travellers listed for stays, meals and entry fees. Travel style picks the price tier, so budget, mid-range and luxury give different totals; group size changes how many rooms and meals are counted. The month does not change it because we have no real seasonal prices. It leaves out getting there and shopping. Treat it as a guide, not a quote.</p>
      </aside>
    </div>
  </details>

  <div id="planResults">
    <?php if ($submitted && $nearby !== null): ?>
      <?php // ---- REAL nearby places (OSM) for day/weekend trips ---- ?>
      <?php if (!empty($nearby['__error']) || ($nearby['status'] ?? '')==='error'): ?>
        <?= ja_service_error($nearby, 'find places near you') ?>
        <?php if (!empty($nearby['error'])): ?><p class="dz-hint"><?= htmlspecialchars($nearby['error']) ?> Check the spelling of your starting point, or try the nearest big city.</p><?php endif; ?>
      <?php else: ?>
        <div class="dz-results-head">
          <div>
            <h2>Places near <?= htmlspecialchars(explode(',',$nearby['origin']['display'])[0]) ?></h2>
            <p class="dz-lede"><?= (int)$nearby['count'] ?> real places within <?= (int)$nearby['radius_km'] ?> km, suited to a <?= htmlspecialchars(strtolower($nearby['mode_label'])) ?>.</p>
          </div>
          <a class="ja-btn ja-btn-primary"
             href="trip.php?tab=route&place=<?= urlencode($q['origin']) ?>&mode=<?= urlencode($q['mode']) ?>&interests=<?= urlencode(implode(',',$q['interests'])) ?>">Build a route</a>
        </div>
        <?php if (empty($nearby['places'])): ?>
          <div class="dz-empty"><h3>No places found in range</h3><p>Try a bigger town as your starting point, or switch to a longer trip so we can suggest destinations further away.</p></div>
        <?php else: ?>
        <ul class="dz-places">
          <?php foreach ($nearby['places'] as $p): ?>
            <li>
              <div>
                <strong><?= htmlspecialchars($p['name']) ?></strong>
                <span><?= htmlspecialchars(ucfirst($p['category'])) ?>, <?= $p['dist_km'] ?> km away</span>
              </div>
              <a target="_blank" rel="noopener" aria-label="Open <?= htmlspecialchars($p['name']) ?> in Google Maps"
                 href="https://www.google.com/maps/search/?api=1&query=<?= $p['lat'] ?>,<?= $p['lon'] ?>"><?= ja_icon('map-pin',16) ?> Maps</a>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      <?php endif; ?>

    <?php elseif ($submitted && $result !== null): ?>
      <?php if (!empty($result['__error'])): ?>
        <?= ja_service_error($result) ?>
      <?php else: ?>
        <div class="dz-results-head">
          <div>
            <h2>Your matches</h2>
            <p class="dz-lede"><?= (int)($result['count'] ?? 0) ?> destinations for <?= (int)$q['days'] ?> day<?= $q['days']==1?'':'s' ?> and <?= ja_inr($q['budget']) ?>, best fit first.</p>
          </div>
        </div>
        <?php require 'ja-cards.php'; ja_render_cards($result['recommendations'] ?? []); ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <template id="skelTpl"><div class="dz-grid" aria-hidden="true"><?php for ($i=0;$i<3;$i++): ?><div class="dz-skel"><i class="a"></i><i class="b"></i><i class="c"></i><i class="d"></i></div><?php endfor; ?></div></template>
</main>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="js/plan-trip.js?v=<?= (int)@filemtime(__DIR__ . '/js/plan-trip.js') ?>" defer></script>
<script src="js/explore.js?v=<?= (int)@filemtime(__DIR__ . '/js/explore.js') ?>" defer></script>
<?php ja_discover_assets(); include 'ja-footer.php'; ?>
