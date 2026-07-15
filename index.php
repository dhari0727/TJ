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
<body class="ja">

<!-- ============ CINEMATIC HERO CAROUSEL ============ -->
<section class="ja-hero-carousel" id="hero" aria-label="Featured destinations">
  <div class="ja-hc-progress"><div class="fill" id="hcFill"></div></div>

  <?php foreach ($slides as $idx => $s):
    // side cards = the OTHER slides
    $others = array_values(array_filter($slides, fn($x)=>$x['name']!==$s['name'])); ?>
    <div class="ja-hc-slide <?= $idx===0?'on':'' ?>" data-slide="<?= $idx ?>">
      <img class="bg" src="<?= htmlspecialchars($s['src']) ?>" alt="<?= htmlspecialchars($s['name']) ?>">
      <div class="ja-hc-inner">
        <div class="ja-hc-copy">
          <div class="ja-hc-eyebrow"><?= ja_icon('map-pin',14) ?> <?= htmlspecialchars($s['region']) ?></div>
          <h1 class="ja-hc-title"><?= htmlspecialchars($s['name']) ?></h1>
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
              <div class="lbl"><?= htmlspecialchars($o['name']) ?><small><?= ja_icon('star',10) ?> <?= $o['rating'] ?> · <?= htmlspecialchars($o['region']) ?></small></div>
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

<!-- ============ EDITORIAL: near me / seasons ============ -->
<section class="ja-section">
  <div class="ja-container">
    <div class="ja-editorial rev">
      <div>
        <div class="ja-eyebrow reveal" style="color:var(--ja-teal)">Personal & practical</div>
        <h2 class="reveal" style="font-size:clamp(2rem,4vw,3rem)">Close to home, or the trip of a lifetime.</h2>
        <p class="reveal" style="color:var(--text-dim);font-size:1.1rem">Tell us where you're travelling from and JourneyAI prioritises
          destinations near you — with the best season to visit built into every recommendation across 90+ destinations.</p>
        <a href="plan-trip.php" class="ja-btn ja-btn-primary ja-btn-lg reveal" data-magnetic style="margin-top:20px">Start planning <?= ja_icon('arrow',18) ?></a>
      </div>
      <img class="reveal" src="<?= htmlspecialchars($ed2) ?>" alt="Mountains">
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
