<?php
/**
 * JourneyAI — recommendation card renderer (shared by plan-trip, recommendations, dashboard).
 * ja_render_cards(array $recs): echoes the animated card grid.
 *
 * Images are bifurcated: a representative stock photo by default, but if a REAL
 * (non-seed) user journal exists for that destination, the card is tagged "User Entry".
 */
require_once __DIR__ . '/ja-icons.php';
require_once __DIR__ . '/ja-images.php';

/** Representative image for a destination (local curated photo, graceful fallback). */
function ja_dest_image($destination) {
    return ja_image_for($destination);
}

/** Does a REAL (non-seed) user journal exist for this destination? (for "User Entry" tag) */
function ja_has_user_entry($destination) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        if (isset($GLOBALS['conn'])) {
            $res = mysqli_query($GLOBALS['conn'],
                "SELECT DISTINCT canonical_dest FROM journal_features jf
                 JOIN signup s ON s.eml = jf.eml
                 WHERE jf.eml NOT LIKE '%@seed.journeyai' AND jf.canonical_dest IS NOT NULL");
            if ($res) while ($r = mysqli_fetch_assoc($res)) $cache[$r['canonical_dest']] = true;
        }
    }
    return isset($cache[$destination]);
}

/** Aggregate rating (avg + count) per destination, one query for the whole grid. */
function ja_dest_rating($destination) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        if (isset($GLOBALS['conn'])) {
            $res = mysqli_query($GLOBALS['conn'],
                "SELECT destination, AVG(rating) avg_r, COUNT(*) n FROM interactions
                 WHERE interaction_type='rating' AND rating IS NOT NULL
                 GROUP BY destination");
            if ($res) while ($r = mysqli_fetch_assoc($res)) {
                $cache[$r['destination']] = ['avg' => (float)$r['avg_r'], 'n' => (int)$r['n']];
            }
        }
    }
    return $cache[$destination] ?? null;
}

function ja_render_cards($recs) {
    if (empty($recs)) {
        echo '<div class="ja-empty">No destinations matched. Try widening your budget or interests.</div>';
        return;
    }
    echo '<div class="ja-reco-grid" id="recoGrid">';
    $i = 0;
    foreach ($recs as $r) {
        $dest   = htmlspecialchars($r['destination']);
        $city   = htmlspecialchars(explode(',', $r['destination'])[0]);
        $cost   = (int)($r['predicted_cost'] ?? 0);
        $low    = (int)($r['cost_low'] ?? $r['low'] ?? 0);
        $high   = (int)($r['cost_high'] ?? $r['high'] ?? 0);
        $score  = max(0, min(1, (float)($r['score'] ?? 0)));
        $fit    = htmlspecialchars($r['budget_fit'] ?? 'within');
        $gem    = !empty($r['lesser_known']);
        $acts   = $r['top_activities'] ?? [];
        $expl   = htmlspecialchars($r['explanation'] ?? '');
        $titles = $r['sample_journal_titles'] ?? [];
        $bd     = $r['cost_breakdown'] ?? [];
        $season = htmlspecialchars($r['best_season'] ?? '');
        $distLbl= htmlspecialchars($r['distance_label'] ?? '');
        $isIntl = ($distLbl === 'International');
        $img    = ja_dest_image($r['destination']);
        $userEntry = ja_has_user_entry($r['destination']);
        $rating = ja_dest_rating($r['destination']);
        $fitCls = ($fit === 'within') ? 'within' : 'stretch';
        $bdJson = htmlspecialchars(json_encode(array_values($bd)), ENT_QUOTES);
        $bdLbls = htmlspecialchars(json_encode(array_map('ucfirst', array_keys($bd))), ENT_QUOTES);
        ?>
        <article class="ja-reco reveal" data-tilt style="transition-delay:<?= $i*0.08 ?>s">
          <?php if ($gem): ?><div class="ja-gem"><?= ja_icon('gem',12) ?> Hidden Gem</div><?php endif; ?>
          <div class="ja-reco-top">
            <img src="<?= $img ?>" alt="<?= $city ?>" loading="lazy" onerror="this.style.display='none'">
            <div class="ja-reco-city"><?= $city ?></div>
            <?php if ($userEntry): ?><div class="ja-usertag"><?= ja_icon('user',11) ?> User Entry</div><?php endif; ?>
          </div>
          <div class="ja-reco-body">
            <div class="ja-reco-row">
              <div class="ja-cost">₹<span data-countup="<?= $cost ?>">0</span><br><small><?= number_format($low) ?>–<?= number_format($high) ?> range</small></div>
              <span class="ja-fit <?= $fitCls ?>"><?= $fit ?> budget</span>
            </div>

            <div class="ja-dest-rating" data-dest="<?= $dest ?>" style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin:2px 0 10px;font-size:.82rem">
              <span class="ja-dest-rating-avg" style="color:var(--text-mut)">
                <?php if ($rating): ?>
                  <span style="color:var(--ja-coral);font-weight:700">★ <?= number_format($rating['avg'], 1) ?></span>
                  (<?= $rating['n'] ?> rating<?= $rating['n']==1?'':'s' ?>)
                <?php else: ?>
                  <span style="color:var(--text-mut)">No ratings yet</span>
                <?php endif; ?>
              </span>
              <span class="ja-star-widget" data-dest="<?= $dest ?>" style="display:flex;gap:2px;cursor:pointer;line-height:1">
                <?php for ($s = 1; $s <= 5; $s++): ?>
                  <span class="ja-star-pick" data-val="<?= $s ?>" style="color:var(--brd);font-size:1.05rem;transition:color .12s">★</span>
                <?php endfor; ?>
              </span>
            </div>

            <div style="display:flex;gap:7px;flex-wrap:wrap;margin:2px 0 12px">
              <?php if ($distLbl): ?>
                <span class="ja-pill <?= $isIntl?'intl':'near' ?>"><?= ja_icon('map-pin',12) ?> <?= $distLbl ?></span>
              <?php endif; ?>
              <?php if ($season): ?>
                <span class="ja-pill season"><?= ja_icon('sun',12) ?> Best: <?= $season ?></span>
              <?php endif; ?>
            </div>

            <div style="font-size:.82rem;color:var(--text-mut);margin-bottom:2px">Match score</div>
            <div class="ja-score-bar"><div class="ja-score-fill" data-score="<?= $score ?>"></div></div>
            <div class="ja-acts">
              <?php foreach ($acts as $a): ?><span><?= htmlspecialchars($a) ?></span><?php endforeach; ?>
            </div>

            <div style="display:flex;gap:16px;align-items:center;margin:12px 0 4px">
              <canvas class="ja-donut" data-breakdown="<?= $bdJson ?>" data-labels="<?= $bdLbls ?>" width="74" height="74"></canvas>
              <div style="font-size:.8rem;color:var(--text-dim)">Cost breakdown<br>
                <span style="color:var(--text-mut)">food · stay · transport · shopping · misc</span></div>
            </div>

            <?php if ($expl): ?>
            <details class="ja-why">
              <summary>Why this destination?</summary>
              <p><?= $expl ?></p>
              <?php if ($titles): ?>
                <div class="ja-evidence"><b>From journals:</b> <?= htmlspecialchars(implode(' · ', $titles)) ?></div>
              <?php endif; ?>
            </details>
            <?php endif; ?>

            <div style="display:flex;gap:8px;margin-top:14px">
              <a class="ja-btn ja-btn-primary" style="flex:1;padding:11px;justify-content:center"
                 href="itinerary.php?dest=<?= urlencode($r['destination']) ?>&days=<?= (int)($r['duration_days'] ?? 5) ?>"><?= ja_icon('compass',16) ?> Itinerary</a>
              <form method="post" action="save-plan.php" style="flex:none">
                <input type="hidden" name="destination" value="<?= $dest ?>">
                <input type="hidden" name="predicted_cost" value="<?= $cost ?>">
                <button class="ja-btn ja-btn-ghost" style="padding:11px 14px" type="submit" title="Save"><?= ja_icon('heart',16) ?></button>
              </form>
            </div>
          </div>
        </article>
        <?php
        $i++;
    }
    echo '</div>';
    ?>
    <script>
    (function(){
      function draw(){
        if(typeof Chart==="undefined")return;
        document.querySelectorAll('canvas.ja-donut[data-breakdown]').forEach(function(cv){
          if(cv.__done)return; cv.__done=true;
          var data=JSON.parse(cv.getAttribute('data-breakdown')||'[]');
          var labels=JSON.parse(cv.getAttribute('data-labels')||'[]');
          new Chart(cv,{type:'doughnut',data:{labels:labels,datasets:[{data:data,
            backgroundColor:['#31C6D4','#0E7C86','#0B3D4F','#FF7A59','#FF9E7A'],borderWidth:0}]},
            options:{plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return c.label+': ₹'+c.raw.toLocaleString('en-IN')}}}},
              cutout:'62%',animation:{animateRotate:true,duration:900}}});
        });
      }
      if(document.readyState!=='loading')draw();else document.addEventListener('DOMContentLoaded',draw);
      setTimeout(draw,600);
      if(window.jaInit)window.jaInit();
    })();
    (function(){
      function paint(widget, hoverVal){
        var stars = widget.querySelectorAll('.ja-star-pick');
        var val = hoverVal || parseInt(widget.getAttribute('data-my-rating') || '0', 10);
        stars.forEach(function(st){
          st.style.color = (parseInt(st.getAttribute('data-val'),10) <= val) ? 'var(--ja-coral)' : 'var(--brd)';
        });
      }
      function wire(widget){
        if (widget.__wired) return; widget.__wired = true;
        var dest = widget.getAttribute('data-dest');
        widget.addEventListener('mouseover', function(e){
          var st = e.target.closest('.ja-star-pick'); if (!st) return;
          paint(widget, parseInt(st.getAttribute('data-val'),10));
        });
        widget.addEventListener('mouseleave', function(){ paint(widget); });
        widget.addEventListener('click', function(e){
          var st = e.target.closest('.ja-star-pick'); if (!st) return;
          var val = parseInt(st.getAttribute('data-val'), 10);
          widget.setAttribute('data-my-rating', val);
          paint(widget);
          var fd = new FormData();
          fd.append('destination', dest);
          fd.append('rating', val);
          fetch('rate-destination.php', {method:'POST', body: fd, credentials:'same-origin'})
            .then(function(r){ return r.json(); })
            .then(function(data){
              if (!data || !data.ok) return;
              var row = widget.closest('.ja-dest-rating');
              if (!row) return;
              var avgEl = row.querySelector('.ja-dest-rating-avg');
              if (avgEl && typeof data.avg === 'number') {
                avgEl.innerHTML = '<span style="color:var(--ja-coral);font-weight:700">★ ' + data.avg.toFixed(1) + '</span> (' + data.n + ' rating' + (data.n==1?'':'s') + ')';
              }
            })
            .catch(function(){ /* silent — optimistic UI already applied */ });
        });
      }
      document.querySelectorAll('.ja-star-widget').forEach(wire);
    })();
    </script>
    <?php
}
