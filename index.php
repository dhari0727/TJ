<?php
$ja_title = "AI Travel Planning"; $ja_active = "home";
$ja_nav_overlay = true;   // transparent nav floating over the full-screen hero
require 'ja-images.php';
$slides = ja_hero_slides();
$imgs = ja_local_images();
// pick a few images for editorial sections
$ed1 = $imgs['kerala'] ?? reset($imgs);
$ed2 = $imgs['mountains'] ?? $ed1;
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja" data-cj-landing>
<svg width="0" height="0" style="position:absolute" aria-hidden="true"><filter id="cj-torn" x="-5%" y="-30%" width="110%" height="160%"><feTurbulence type="fractalNoise" baseFrequency=".035 .12" numOctaves="3" seed="7"/><feDisplacementMap in="SourceGraphic" scale="22"/></filter></svg>

<!-- ============ CINEMATIC HERO CAROUSEL ============ -->
<section class="ja-hero-carousel" id="hero" data-cj-sec="Home" aria-label="Featured destinations">
  <div class="ja-hc-progress"><div class="fill" id="hcFill"></div></div>

  <?php foreach ($slides as $idx => $s):
    // side cards = the OTHER slides
    $others = array_values(array_filter($slides, fn($x)=>$x['name']!==$s['name'])); ?>
    <div class="ja-hc-slide <?= $idx===0?'on':'' ?>" data-slide="<?= $idx ?>">
      <img class="bg" src="<?= htmlspecialchars($s['src']) ?>" alt="<?= htmlspecialchars($s['name']) ?>">
      <div class="ja-hc-inner">
        <div class="ja-hc-copy">
          <div class="ja-hc-eyebrow"><?= ja_icon('map-pin',14) ?> <?= htmlspecialchars($s['region']) ?></div>
          <<?= $idx===0 ? 'h1' : 'h2' ?> class="ja-hc-title"><?= htmlspecialchars($s['name']) ?></<?= $idx===0 ? 'h1' : 'h2' ?>>
          <p class="ja-hc-sub"><?= htmlspecialchars($s['sub']) ?></p>
          <div class="ja-hc-cta">
            <a href="plan-trip.php" class="ja-btn ja-btn-primary ja-btn-lg" data-magnetic>Plan this trip <?= ja_icon('arrow',18) ?></a>
          </div>
        </div>
        <div class="ja-hc-cards">
          <?php foreach (array_slice($others,0,3) as $o): ?>
            <div class="ja-hc-card">
              <img src="<?= htmlspecialchars($o['src']) ?>" alt="<?= htmlspecialchars($o['name']) ?>">
              <div class="bk"><?= ja_icon('heart',14) ?></div>
              <div class="lbl"><?= htmlspecialchars($o['name']) ?><small><?= ja_icon('map-pin',10) ?> <?= htmlspecialchars($o['region']) ?></small></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="ja-hc-controls">
    <button class="ja-hc-arrow" id="hcPrev" aria-label="Previous"><?= ja_icon('arrow',18,'style="transform:rotate(180deg)"') ?></button>
    <div class="ja-hc-dots" id="hcDots">
      <?php foreach ($slides as $i=>$s): ?><span class="ja-hc-dot <?= $i===0?'on':'' ?>" data-dot="<?= $i ?>"></span><?php endforeach; ?>
    </div>
    <button class="ja-hc-arrow" id="hcNext" aria-label="Next"><?= ja_icon('arrow',18) ?></button>
  </div>
  <div class="ja-hc-count"><span id="hcNum">01</span> / <?= str_pad(count($slides),2,'0',STR_PAD_LEFT) ?></div>
</section>

<!-- ============ TICKER ============ -->
<div class="wm-ticker" aria-hidden="true"><div class="wm-ticker-track">
  <?php $wm = ['Dwarka','Somnath','Junagadh','Sasan Gir','Diu','White Rann','Udaipur','Jaipur','Varanasi','Rishikesh','Munnar','Hampi','Leh','Goa','Kolkata','Darjeeling','Pushkar','Mysuru'];
  for ($k = 0; $k < 2; $k++) foreach ($wm as $w) echo '<span>' . htmlspecialchars($w) . '</span>'; ?>
</div></div>

<!-- ============ SCROLL ROUTE: a pin travels the road and opens each place ============ -->
<?php $cj_stops = [
  ['Goa','goa','Beaches · 4 days','.1'],
  ['Jaipur','jaipur','Palaces · 3 days','.36'],
  ['Himalayas','mountains','Snow treks · 6 days','.62'],
  ['Kerala','kerala','Backwaters · 5 days','.88'],
]; ?>
<section class="cj-route" id="cjRoute" data-cj-sec="Route">
  <div class="cj-route-head cj-rv"><span class="hand">scroll to travel</span><h2>A route through India</h2><p>Scroll and the pin follows the road, introducing each place along the way.</p></div>
  <div class="cj-map" id="cjMap">
    <svg class="cj-path" viewBox="0 0 1000 2400" aria-hidden="true">
      <path id="cjBase" d="M500 40 C900 220 100 420 500 640 S900 1020 500 1240 S100 1620 500 1840 S900 2200 500 2360" fill="none" stroke="#c9bfae" stroke-width="6" stroke-dasharray="2 18" stroke-linecap="round"/>
      <path id="cjFill" d="M500 40 C900 220 100 420 500 640 S900 1020 500 1240 S100 1620 500 1840 S900 2200 500 2360" fill="none" stroke="#E63457" stroke-width="7" stroke-linecap="round"/>
      <g id="cjDots"></g>
      <g id="cjPin" class="cj-pin"><ellipse cx="0" cy="4" rx="12" ry="4" fill="rgba(0,0,0,.2)"/><g class="bob"><path d="M0 0 C-28 -32 -22 -66 0 -66 C22 -66 28 -32 0 0Z" fill="#E63457"/><circle cx="0" cy="-44" r="9" fill="#fff"/></g></g>
    </svg>
    <?php foreach ($cj_stops as $i => [$nm,$key,$sub,$f]): $img = $imgs[$key] ?? $ed1; ?>
      <article class="cj-stop" data-f="<?= $f ?>">
        <span class="no">0<?= $i+1 ?> / 04</span>
        <div class="scn"><img loading="lazy" src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($nm) ?>"></div>
        <h3><?= htmlspecialchars($nm) ?></h3><small><?= htmlspecialchars($sub) ?></small>
        <div class="row"><a class="ja-btn ja-btn-primary" href="trip.php?dest=<?= urlencode($nm === 'Himalayas' ? 'Manali' : $nm) ?>">Plan this trip <?= ja_icon('arrow',14) ?></a></div>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<!-- ============ EDITORIAL: how it works ============ -->
<section class="ja-section">
  <div class="ja-container">
    <div class="ja-editorial">
      <div>
        <div class="ja-eyebrow reveal" style="color:var(--ja-teal)">The JourneyAI difference</div>
        <h2 class="reveal" style="font-size:clamp(2rem,4vw,3rem)">Recommendations from real journeys — not ads.</h2>
        <p class="reveal" style="color:var(--text-dim);font-size:1.1rem">We read thousands of genuine travel journals and turn them into
          structured intelligence: where people went, what they spent, what they loved. Then we match it to <em>you</em>.</p>
        <ul class="ja-feat-list reveal" style="margin-top:24px">
          <li><span class="ja-feat-num">01</span><div><h3>Budget-aware</h3><p>Realistic trip costs predicted by machine learning, with a full breakdown.</p></div></li>
          <li><span class="ja-feat-num">02</span><div><h3>Explainable</h3><p>Every suggestion tells you exactly why — matched interests, similar travellers, budget fit.</p></div></li>
          <li><span class="ja-feat-num">03</span><div><h3>Hidden gems</h3><p>Authentic lesser-known places surfaced alongside the classics.</p></div></li>
        </ul>
      </div>
      <img class="reveal" src="<?= htmlspecialchars($ed1) ?>" alt="Travel">
    </div>
  </div>
</section>

<hr class="ja-rule">

<!-- ============ HOW IT WORKS (guide) ============ -->
<section class="ja-section" id="how" data-cj-sec="How it works">
  <div class="ja-container">
    <div style="text-align:center;max-width:640px;margin:0 auto 44px">
      <div class="ja-eyebrow reveal" style="color:var(--ja-teal)">How it works</div>
      <h2 class="reveal" style="font-size:clamp(2rem,4vw,3rem)">Plan a whole trip in three steps.</h2>
      <p class="reveal" style="color:var(--text-dim);font-size:1.1rem">No forms to wrestle with — just say what you want.</p>
    </div>
    <div class="ja-grid cols-3 stagger">
      <div class="ja-card ja-feature">
        <div class="ic"><?= ja_icon('user',24) ?></div>
        <h3>1 · Create an account</h3>
        <p>Sign up free in seconds. Your trips, saved routes and journal live in one place.</p>
      </div>
      <div class="ja-card ja-feature">
        <div class="ic"><?= ja_icon('search',24) ?></div>
        <h3>2 · Say it in one line</h3>
        <p>“Weekend from Ahmedabad, temples &amp; food.” JourneyAI understands and plans instantly.</p>
      </div>
      <div class="ja-card ja-feature">
        <div class="ic"><?= ja_icon('compass',24) ?></div>
        <h3>3 · Get a complete trip</h3>
        <p>Real places, a mapped route, where to eat, day-by-day itinerary and realistic costs — ready to save.</p>
      </div>
    </div>
    <div class="reveal" style="text-align:center;margin-top:34px">
      <a href="register.php" class="ja-btn ja-btn-primary ja-btn-lg" data-magnetic>Create free account <?= ja_icon('arrow',18) ?></a>
      <a href="login.php" class="ja-btn ja-btn-ghost ja-btn-lg" style="margin-left:10px">Log in</a>
    </div>
  </div>
</section>

<hr class="ja-rule">

<!-- ============ WHAT YOU CAN DO (features) ============ -->
<section class="ja-section">
  <div class="ja-container">
    <div style="text-align:center;max-width:640px;margin:0 auto 44px">
      <div class="ja-eyebrow reveal" style="color:var(--ja-teal)">Everything in one place</div>
      <h2 class="reveal" style="font-size:clamp(2rem,4vw,3rem)">Your complete travel companion.</h2>
    </div>
    <div class="ja-grid cols-3 stagger">
      <div class="ja-card ja-feature"><div class="ic"><?= ja_icon('compass',24) ?></div><h3>Instant trip planner</h3><p>One sentence → places, route, cost. Day trips to week-long journeys.</p></div>
      <div class="ja-card ja-feature"><div class="ic"><?= ja_icon('map-pin',24) ?></div><h3>Real nearby places</h3><p>Genuine spots around any town — temples, food, gardens, hidden gems — with live maps.</p></div>
      <div class="ja-card ja-feature"><div class="ic"><?= ja_icon('star',24) ?></div><h3>Smart recommendations</h3><p>Budget-aware, explainable destination picks from real travel journals.</p></div>
      <div class="ja-card ja-feature"><div class="ic"><?= ja_icon('calendar',24) ?></div><h3>Day-by-day itineraries</h3><p>Morning-to-evening plans with attractions, meals, stays and per-day cost.</p></div>
      <div class="ja-card ja-feature"><div class="ic"><?= ja_icon('book',24) ?></div><h3>Travel journal</h3><p>Record your trips with photos — and improve everyone's recommendations.</p></div>
      <div class="ja-card ja-feature"><div class="ic"><?= ja_icon('sparkle',24) ?></div><h3>AI assistant</h3><p>Chat: “ice cream near me”, “3 days on a budget” — it just answers.</p></div>
    </div>
  </div>
</section>

<hr class="ja-rule">

<!-- ============ EDITORIAL: near me / seasons ============ -->
<section class="ja-section">
  <div class="ja-container">
    <div class="ja-editorial rev">
      <div>
        <div class="ja-eyebrow reveal" style="color:var(--ja-teal)">Personal & practical</div>
        <h2 class="reveal" style="font-size:clamp(2rem,4vw,3rem)">Close to home, or the trip of a lifetime.</h2>
        <p class="reveal" style="color:var(--text-dim);font-size:1.1rem">Tell us where you're travelling from and JourneyAI prioritises
          destinations near you — with the best season to visit built into every recommendation across 500+ destinations in India.</p>
        <a href="plan-trip.php" class="ja-btn ja-btn-primary ja-btn-lg reveal" data-magnetic style="margin-top:20px">Start planning <?= ja_icon('arrow',18) ?></a>
      </div>
      <img class="reveal" src="<?= htmlspecialchars($ed2) ?>" alt="Mountains">
    </div>
  </div>
</section>

<!-- ============ TRUST: why the recommendations can be believed ============ -->
<svg class="cj-torn" viewBox="0 0 1440 80" preserveAspectRatio="none" aria-hidden="true" style="background:var(--bg)"><path d="M0 50 Q240 90 480 50 T960 50 T1440 40 V80 H0Z" fill="#0e2a1d"/></svg>
<section class="cj-night" data-cj-sec="Trust">
  <div class="cj-moon" aria-hidden="true"></div>
  <div class="ja-container">
    <h2 class="cj-rv">Real travellers. Real journals.</h2>
    <p class="s cj-rv">Anyone can write one, but not everything gets in. That is how the recommendations stay honest.</p>
    <div class="cj-cards">
      <div class="cj-fc cj-rv"><?= ja_icon('user',30) ?><h3>Verified people</h3><p>Email-verified accounts only for public posts.</p></div>
      <div class="cj-fc cj-rv" style="transition-delay:.1s"><?= ja_icon('book',30) ?><h3>Reviewed first</h3><p>New writers are checked before they appear.</p></div>
      <div class="cj-fc cj-rv" style="transition-delay:.2s"><?= ja_icon('map-pin',30) ?><h3>Private by default</h3><p>Photo locations are stripped on upload.</p></div>
      <div class="cj-fc cj-rv" style="transition-delay:.3s"><?= ja_icon('star',30) ?><h3>No gaming the score</h3><p>One voice cannot dominate a destination.</p></div>
    </div>
  </div>
</section>

<?php include 'ja-footer.php'; ?>

<script>
/* cinematic hero carousel: autoplay + dots + arrows + progress + counter */
(function(){
  var slides=[].slice.call(document.querySelectorAll('.ja-hc-slide'));
  if(!slides.length)return;
  var dots=[].slice.call(document.querySelectorAll('.ja-hc-dot'));
  var num=document.getElementById('hcNum'),fill=document.getElementById('hcFill');
  var cur=0,timer=null,DUR=6000,t0=0,raf=null,paused=false;
  function go(n){
    cur=(n+slides.length)%slides.length;
    slides.forEach(function(s,i){s.classList.toggle('on',i===cur)});
    dots.forEach(function(d,i){d.classList.toggle('on',i===cur)});
    if(num)num.textContent=String(cur+1).padStart(2,'0');
    restart();
  }
  function restart(){t0=performance.now();}
  function loop(t){
    if(!paused){var p=Math.min((t-t0)/DUR,1);if(fill)fill.style.height=(p*100)+'%';
      if(p>=1)go(cur+1);}
    raf=requestAnimationFrame(loop);
  }
  document.getElementById('hcNext').addEventListener('click',function(){go(cur+1)});
  document.getElementById('hcPrev').addEventListener('click',function(){go(cur-1)});
  dots.forEach(function(d){d.addEventListener('click',function(){go(+d.dataset.dot)})});
  var hero=document.getElementById('hero');
  hero.addEventListener('mouseenter',function(){paused=true});
  hero.addEventListener('mouseleave',function(){paused=false;restart()});
  restart();raf=requestAnimationFrame(loop);
})();
</script>
</body>
</html>
