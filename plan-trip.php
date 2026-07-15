<?php
$ja_title = "Plan a Trip"; $ja_active = "plan";
require 'ml_client.php';

// allowed interest vocabulary (mirrors the ML service)
$ALL_INTERESTS = ['beach','trekking','food','nightlife','history','temples','museums',
  'shopping','wildlife','adventure','relaxation','photography','nature','culture',
  'mountains','desert','snow','diving','architecture'];
$STYLES = ['budget','mid-range','luxury','adventure','family','solo','backpacker'];
$MODES  = ['day'=>'Day trip','weekend'=>'Weekend','short'=>'Short trip (3-5 days)','long'=>'Long trip'];

$result = null; $nearby = null; $submitted = false; $mode='short'; $q = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = true;
    $budget   = max(1000, min(2000000, (int)($_POST['budget'] ?? 30000)));
    $party    = max(1, min(20, (int)($_POST['party'] ?? 1)));
    $month    = (int)($_POST['month'] ?? 0); $month = ($month >= 1 && $month <= 12) ? $month : null;
    $style    = in_array($_POST['style'] ?? '', $STYLES, true) ? $_POST['style'] : 'mid-range';
    $mode     = array_key_exists($_POST['mode'] ?? '', $MODES) ? $_POST['mode'] : 'short';
    $origin   = trim($_POST['origin'] ?? '');   // free-text "where are you starting from?"
    $interests = array_values(array_intersect((array)($_POST['interests'] ?? []), $ALL_INTERESTS));
    $modeDays = ['day'=>1,'weekend'=>2,'short'=>4,'long'=>8][$mode];
    $duration = $modeDays;

    $q = compact('budget','party','month','style','interests','origin','mode');

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
        ]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">

<div class="ja-pagehead">
  <div class="ja-container">
    <div class="ja-eyebrow">✦ Personalised for you</div>
    <h1>Plan a Trip</h1>
    <p class="sub">Tell us your budget, time and what you love — JourneyAI blends real journals into
      budget-aware, explainable recommendations.</p>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container">
    <div class="ja-card" style="margin-bottom:40px">
      <form method="post" id="planForm">
        <div class="ja-field">
          <label>Budget: <strong id="budgetLabel">₹<?= number_format($q['budget'] ?? 30000) ?></strong></label>
          <input class="ja-range" type="range" name="budget" id="budget" min="5000" max="200000" step="1000"
                 value="<?= $q['budget'] ?? 30000 ?>">
        </div>
        <!-- trip mode -->
        <div class="ja-field" style="margin-top:6px">
          <label>How long is your trip?</label>
          <div class="ja-chips" id="modeChips">
            <?php foreach ($MODES as $mk=>$ml): ?>
              <span class="ja-chip <?= (($q['mode'] ?? 'short')===$mk)?'on':'' ?>" data-mode="<?= $mk ?>"><?= $ml ?></span>
            <?php endforeach; ?>
          </div>
          <input type="hidden" name="mode" id="modeInput" value="<?= $q['mode'] ?? 'short' ?>">
        </div>

        <div style="display:grid;gap:20px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
          <div class="ja-field">
            <label for="origin">Where are you starting from?
              <button type="button" id="nearMeBtn" class="ja-nearme"><?= ja_icon('map-pin',13) ?> <span>Near me</span></button>
            </label>
            <input class="ja-input" type="text" name="origin" id="origin" autocomplete="off"
                   placeholder="e.g. Anand, Ahmedabad, Mumbai…" value="<?= htmlspecialchars($q['origin'] ?? '') ?>">
            <div id="geoStatus" style="font-size:.78rem;color:var(--text-mut);margin-top:6px;min-height:1em">For day trips & weekends we'll find real places near you.</div>
          </div>
          <div class="ja-field">
            <label for="party">Travellers</label>
            <input class="ja-input" type="number" name="party" id="party" min="1" max="20" value="<?= $q['party'] ?? 1 ?>">
          </div>
          <div class="ja-field">
            <label for="style">Travel style</label>
            <select class="ja-select" name="style" id="style">
              <?php foreach ($STYLES as $s): ?>
                <option value="<?= $s ?>" <?= (($q['style'] ?? 'mid-range')===$s)?'selected':'' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ja-field">
            <label for="month">Month of travel</label>
            <select class="ja-select" name="month" id="month">
              <option value="0">Any</option>
              <?php $mn=['','January','February','March','April','May','June','July','August','September','October','November','December'];
              for($m=1;$m<=12;$m++): ?>
                <option value="<?= $m ?>" <?= (($q['month'] ?? null)==$m)?'selected':'' ?>><?= $mn[$m] ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <div class="ja-field" style="margin-top:8px">
          <label>What do you love? <span style="color:var(--text-mut);font-weight:400">(pick a few)</span></label>
          <div class="ja-chips" id="chips">
            <?php $sel = $q['interests'] ?? []; foreach ($ALL_INTERESTS as $it): ?>
              <span class="ja-chip <?= in_array($it,$sel,true)?'on':'' ?>" data-val="<?= $it ?>"><?= $it ?></span>
            <?php endforeach; ?>
          </div>
          <div id="interestInputs"></div>
        </div>

        <button class="ja-btn ja-btn-primary ja-btn-lg" type="submit" data-magnetic style="margin-top:10px" id="planSubmit">
          <?= ja_icon('search',18) ?> Find my destinations
        </button>
      </form>
    </div>

    <?php if ($submitted && $nearby !== null): ?>
      <?php // ---- REAL nearby places (OSM) for day/weekend trips ---- ?>
      <?php if (!empty($nearby['__error']) || ($nearby['status'] ?? '')==='error'): ?>
        <?= ml_offline_banner($nearby) ?>
        <?php if (!empty($nearby['error'])): ?><div class="ja-empty"><?= htmlspecialchars($nearby['error']) ?></div><?php endif; ?>
      <?php else: ?>
        <div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:12px;margin-bottom:16px">
          <div>
            <h2 style="margin-bottom:4px">Places near <?= htmlspecialchars(explode(',',$nearby['origin']['display'])[0]) ?></h2>
            <p class="sub"><?= (int)$nearby['count'] ?> real spots within <?= (int)$nearby['radius_km'] ?> km · <?= htmlspecialchars($nearby['mode_label']) ?></p>
          </div>
          <a class="ja-btn ja-btn-primary" data-magnetic
             href="route.php?place=<?= urlencode($q['origin']) ?>&mode=<?= urlencode($q['mode']) ?>&interests=<?= urlencode(implode(',',$q['interests'])) ?>">
            <?= ja_icon('compass',18) ?> Build a route</a>
        </div>
        <div class="ja-nearby-grid">
          <?php foreach ($nearby['places'] as $p):
            $catIcon = ['religious'=>'star','heritage'=>'home','nature'=>'sun','museum'=>'book','beach'=>'sun','town'=>'map-pin'][$p['category']] ?? 'map-pin'; ?>
            <div class="ja-nearby-card reveal">
              <div class="ja-nb-icon"><?= ja_icon($catIcon,20) ?></div>
              <div class="ja-nb-body">
                <div class="ja-nb-name"><?= htmlspecialchars($p['name']) ?></div>
                <div class="ja-nb-meta"><span class="ja-pill near"><?= ja_icon('map-pin',11) ?> <?= $p['dist_km'] ?> km</span>
                  <span style="text-transform:capitalize;color:var(--text-mut);font-size:.82rem"><?= htmlspecialchars($p['category']) ?></span></div>
              </div>
              <a class="ja-nb-map" target="_blank" rel="noopener"
                 href="https://www.google.com/maps/search/?api=1&query=<?= $p['lat'] ?>,<?= $p['lon'] ?>"
                 title="Open in Google Maps"><?= ja_icon('map-pin',16) ?></a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    <?php elseif ($submitted && $result !== null): ?>
      <?php if (!empty($result['__error'])): ?>
        <?= ml_offline_banner($result) ?>
      <?php else: ?>
        <div style="margin-bottom:14px">
          <h2 style="margin-bottom:4px">Your matches</h2>
          <p class="sub"><?= (int)($result['count'] ?? 0) ?> destinations, ranked and explained.</p>
        </div>
        <?php require 'ja-cards.php'; ja_render_cards($result['recommendations'] ?? []); ?>
      <?php endif; ?>

    <?php else: ?>
      <div class="ja-empty" style="padding:56px 30px">
        <div style="color:var(--ja-aqua);margin-bottom:14px"><?= ja_icon('compass',44) ?></div>
        <p style="font-size:1.15rem;color:var(--text-dim);margin:0 0 6px">Ready when you are.</p>
        <p style="color:var(--text-mut);margin:0">For a <strong>day trip</strong> or <strong>weekend</strong>, type where you're starting from and we'll find real places nearby. For longer trips, we'll recommend destinations.</p>
      </div>
    <?php endif; ?>
  </div>
</main>

<script>
/* budget slider live label + track fill */
(function(){
  var b=document.getElementById('budget'),lbl=document.getElementById('budgetLabel');
  function upd(){
    var pct=(b.value-b.min)/(b.max-b.min)*100;
    b.style.background='linear-gradient(90deg,var(--ja-aqua) 0%,var(--ja-aqua) '+pct+'%,var(--bg-2) '+pct+'%,var(--bg-2) 100%)';
    lbl.textContent='₹'+Number(b.value).toLocaleString('en-IN');
  }
  if(b){b.addEventListener('input',upd);upd();}
  /* interest chips -> hidden inputs */
  var chips=document.getElementById('chips'),box=document.getElementById('interestInputs');
  function sync(){
    box.innerHTML='';
    chips.querySelectorAll('.ja-chip.on').forEach(function(c){
      var i=document.createElement('input');i.type='hidden';i.name='interests[]';i.value=c.dataset.val;box.appendChild(i);
    });
  }
  if(chips){chips.addEventListener('click',function(e){var c=e.target.closest('.ja-chip');if(!c)return;c.classList.toggle('on');sync();});sync();}

  /* trip-mode single-select chips */
  var modeChips=document.getElementById('modeChips'),modeInput=document.getElementById('modeInput');
  if(modeChips)modeChips.addEventListener('click',function(e){
    var c=e.target.closest('.ja-chip');if(!c)return;
    modeChips.querySelectorAll('.ja-chip').forEach(function(x){x.classList.remove('on')});
    c.classList.add('on');modeInput.value=c.dataset.mode;
  });

  /* submit feedback */
  var f=document.getElementById('planForm');
  if(f)f.addEventListener('submit',function(){
    var btn=document.getElementById('planSubmit');if(btn){btn.textContent='Finding places…';btn.disabled=true;}
  });

  /* ---- "Near me": geolocation -> reverse geocode -> fill origin text ---- */
  var geoBtn=document.getElementById('nearMeBtn'),geoStatus=document.getElementById('geoStatus'),origin=document.getElementById('origin');
  function detect(){
    if(!navigator.geolocation){geoStatus.textContent='Geolocation not supported — type your city.';return;}
    geoStatus.textContent='Detecting your location…';geoBtn.classList.add('loading');
    navigator.geolocation.getCurrentPosition(function(pos){
      var la=pos.coords.latitude,lo=pos.coords.longitude;
      fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat='+la+'&lon='+lo+'&zoom=12')
        .then(function(r){return r.json()}).then(function(d){
          geoBtn.classList.remove('loading');
          var a=d.address||{};
          var city=a.city||a.town||a.village||a.suburb||a.county||a.state_district||a.state||'';
          if(city){origin.value=city;geoBtn.classList.add('on');geoStatus.textContent='Detected: '+city+' — we\'ll find real places nearby.';}
          else{geoStatus.textContent='Couldn\'t name your location — type your city.';}
        }).catch(function(){geoBtn.classList.remove('loading');geoStatus.textContent='Lookup failed — type your city.';});
    },function(err){
      geoBtn.classList.remove('loading');
      geoStatus.textContent=(err.code===1?'Permission denied — type your city.':'Location unavailable — type your city.');
    },{timeout:9000,maximumAge:600000});
  }
  if(geoBtn)geoBtn.addEventListener('click',detect);
})();
</script>
<?php include 'ja-footer.php'; ?>
