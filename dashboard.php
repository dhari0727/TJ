<?php
$ja_title = "Dashboard"; $ja_active = "dashboard";
session_start();
require 'connection.php';
require 'ml_client.php';
require 'ja-notify.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$em = $_SESSION['eml'];
$fname = $_SESSION['fname'] ?? 'Traveller';

// dashboard notifications (rule-based, generated on the fly — no cron/worker)
$notifications = ja_generate_notifications($conn, $em);
$unreadCount = count($notifications);

// recent journal entries
$stmt = mysqli_prepare($conn, "SELECT entry_id, Title, City, Country, cd FROM db WHERE eml=? ORDER BY entry_id DESC LIMIT 4");
mysqli_stmt_bind_param($stmt,'s',$em); mysqli_stmt_execute($stmt);
$entries = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// saved plans (interactions type=plan/save)
$stmt = mysqli_prepare($conn, "SELECT destination, MAX(created_at) t FROM interactions WHERE eml=? AND interaction_type IN ('plan','save') GROUP BY destination ORDER BY t DESC LIMIT 6");
mysqli_stmt_bind_param($stmt,'s',$em); mysqli_stmt_execute($stmt);
$plans = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// personalised picks — derive user context from their journal data
$user_budget = null;
$user_interests = [];
$user_duration = 5;

// average budget from user's journal expenses
$emEsc = mysqli_real_escape_string($conn, $em);
$br = @mysqli_query($conn, "SELECT AVG(CAST(d1.total AS DECIMAL(10,2))) avg_b
    FROM db d LEFT JOIN db1 d1 ON d1.Title=d.Title AND d1.eml=d.eml
    WHERE d.eml='$emEsc' AND d1.total IS NOT NULL AND d1.total > 0");
if ($br && $row = mysqli_fetch_assoc($br)) {
    if ($row['avg_b'] && $row['avg_b'] > 0) $user_budget = (float)$row['avg_b'];
}

// extract interests from recent journal descriptions + titles
// keys MUST match CANONICAL_ACTIVITIES in ml/nlp/lexicon.py exactly
$dr = @mysqli_query($conn, "SELECT Description, Title FROM db
    WHERE eml='$emEsc' ORDER BY cd DESC LIMIT 8");
if ($dr) {
    $allText = '';
    while ($r = mysqli_fetch_assoc($dr)) $allText .= ' ' . ($r['Description'] ?? '') . ' ' . ($r['Title'] ?? '');
    $interests_gazetteer = [
        'beach'=>['beach','beaches','shore','coast','sand','seaside','surf','snorkel','island'],
        'trekking'=>['trek','trekking','hike','hiking','trail','trails'],
        'food'=>['food','cuisine','restaurant','cafe','dining','culinary','spice','biryani','curry','street food','delicacies'],
        'nightlife'=>['nightlife','bar','club','party','pub'],
        'history'=>['history','historic','ancient','heritage','ruins','fort','forts','palace','castle'],
        'temples'=>['temple','temples','shrine','monastery','spiritual','pilgrimage','ghat','ghats','aarti'],
        'museums'=>['museum','museums','gallery','galleries','exhibition','art'],
        'shopping'=>['shopping','market','bazaar','souvenir','souvenirs','handicraft','boutique'],
        'wildlife'=>['wildlife','safari','jungle','national park','sanctuary','birds','tiger','elephant'],
        'adventure'=>['adventure','rafting','paragliding','zip line','ziplining','bungee','kayak','thrill'],
        'relaxation'=>['relax','relaxation','relaxing','spa','peaceful','serene','unwind','tranquil','calm'],
        'photography'=>['photography','photo','photos','photogenic','instagram','scenic','viewpoint','views'],
        'nature'=>['nature','waterfall','waterfalls','lake','valley','forest','gardens','tea garden','meadow','meadows'],
        'culture'=>['culture','cultural','tradition','traditional','festival','local life','village','tribal'],
        'backwaters'=>['backwater','backwaters','houseboat'],
        'mountains'=>['mountain','mountains','himalaya','himalayas','peak','hills','hill station','summit'],
        'desert'=>['desert','dunes','rann','sand dunes'],
        'snow'=>['snow','snowfall','ski','skiing','snowy','glacier'],
        'diving'=>['diving','scuba','snorkel','snorkeling','reef'],
        'architecture'=>['architecture','architectural','monument','cathedral','mosque','buildings','old town'],
    ];
    foreach ($interests_gazetteer as $canonical => $keywords) {
        foreach ($keywords as $kw) {
            if (stripos($allText, $kw) !== false) { $user_interests[] = $canonical; break; }
        }
    }
}

$reco = ml_recommend([
    'eml'=>$em,
    'budget'=>$user_budget,
    'duration_days'=>$user_duration,
    'interests'=>$user_interests,
    'top_n'=>6
]);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">

<div class="ja-explore-hero" style="text-align:left;max-width:none;padding-top:4px">
  <div class="ja-eyebrow" style="color:var(--ja-teal)"><?= ja_icon('sparkle',14) ?> Welcome back, <?= htmlspecialchars($fname) ?></div>
  <h1 style="font-size:clamp(1.8rem,4vw,2.6rem)">Where to next?</h1>
  <p class="sub" style="margin-bottom:18px">Describe your trip in one line — I'll plan the whole thing.</p>
  <form class="ja-smart-box" onsubmit="location.href='plan-trip.php?q='+encodeURIComponent(this.q.value);return false;">
    <?= ja_icon('search',22) ?>
    <input type="text" name="q" autocomplete="off" placeholder="e.g. Weekend from Ahmedabad, temples &amp; food">
    <button type="submit" class="ja-btn ja-btn-primary">Plan it <?= ja_icon('arrow',18) ?></button>
  </form>
</div>

<main style="margin-top:34px">

    <!-- notifications bell -->
    <div style="display:flex;justify-content:flex-end;margin-bottom:10px;position:relative">
      <button type="button" id="jaNotifBell" aria-label="Notifications"
        style="position:relative;display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:50%;border:1px solid var(--brd);background:var(--surface);cursor:pointer">
        <span aria-hidden="true" style="font-size:1.15rem;line-height:1">🔔</span>
        <?php if ($unreadCount > 0): ?>
          <span id="jaNotifCount" style="position:absolute;top:-4px;right:-4px;min-width:18px;height:18px;padding:0 4px;border-radius:9px;background:var(--ja-coral);color:#fff;font-size:.68rem;font-weight:700;display:flex;align-items:center;justify-content:center"><?= $unreadCount ?></span>
        <?php endif; ?>
      </button>

      <div id="jaNotifPanel" class="ja-card" style="display:none;position:absolute;top:50px;right:0;width:340px;max-width:90vw;max-height:420px;overflow-y:auto;z-index:50;padding:14px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <h3 style="font-size:1rem;margin:0">Notifications</h3>
          <?php if ($unreadCount > 0): ?>
            <button type="button" id="jaNotifMarkAll" style="background:none;border:none;color:var(--ja-teal);font-size:.8rem;font-weight:600;cursor:pointer;padding:0">Mark all read</button>
          <?php endif; ?>
        </div>
        <?php if (!$notifications): ?>
          <p style="color:var(--text-mut);font-size:.88rem;margin:0">You're all caught up.</p>
        <?php else: ?>
          <?php foreach ($notifications as $n): ?>
            <div class="ja-notif-item" data-id="<?= (int)$n['notif_id'] ?>" style="padding:10px 0;border-bottom:1px solid var(--brd)">
              <div style="font-size:.87rem;line-height:1.35">
                <?php if (!empty($n['link'])): ?>
                  <a href="<?= htmlspecialchars($n['link']) ?>" style="color:inherit;text-decoration:none"><?= htmlspecialchars($n['message']) ?></a>
                <?php else: ?>
                  <?= htmlspecialchars($n['message']) ?>
                <?php endif; ?>
              </div>
              <div style="display:flex;justify-content:space-between;align-items:center;margin-top:5px">
                <span style="color:var(--text-mut);font-size:.72rem"><?= htmlspecialchars($n['created_at']) ?></span>
                <button type="button" class="ja-notif-read-btn" data-id="<?= (int)$n['notif_id'] ?>"
                  style="background:none;border:none;color:var(--ja-teal);font-size:.75rem;font-weight:600;cursor:pointer;padding:0">Mark read</button>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <script>
    (function(){
      var bell = document.getElementById('jaNotifBell');
      var panel = document.getElementById('jaNotifPanel');
      if (!bell || !panel) return;
      bell.addEventListener('click', function(e){
        e.stopPropagation();
        panel.style.display = (panel.style.display === 'none' || !panel.style.display) ? 'block' : 'none';
      });
      document.addEventListener('click', function(e){
        if (panel.style.display === 'block' && !panel.contains(e.target) && e.target !== bell) {
          panel.style.display = 'none';
        }
      });
      function updateCount(delta){
        var countEl = document.getElementById('jaNotifCount');
        if (!countEl) return;
        var n = Math.max(0, (parseInt(countEl.textContent, 10) || 0) + delta);
        if (n === 0) { countEl.remove(); } else { countEl.textContent = n; }
      }
      function markRead(id, itemEl){
        var fd = new FormData();
        fd.append('notif_id', id);
        fetch('notif-read.php', {method:'POST', body: fd, credentials:'same-origin',
          headers: {'X-Requested-With':'XMLHttpRequest'}})
          .then(function(r){ return r.json(); })
          .then(function(data){
            if (data && data.ok && itemEl) { itemEl.remove(); updateCount(-1); }
          })
          .catch(function(){});
      }
      panel.addEventListener('click', function(e){
        var btn = e.target.closest('.ja-notif-read-btn');
        if (btn) { markRead(btn.getAttribute('data-id'), btn.closest('.ja-notif-item')); }
      });
      var markAll = document.getElementById('jaNotifMarkAll');
      if (markAll) {
        markAll.addEventListener('click', function(){
          var fd = new FormData();
          fd.append('all', '1');
          fetch('notif-read.php', {method:'POST', body: fd, credentials:'same-origin',
            headers: {'X-Requested-With':'XMLHttpRequest'}})
            .then(function(r){ return r.json(); })
            .then(function(data){
              if (data && data.ok) {
                document.querySelectorAll('.ja-notif-item').forEach(function(el){ el.remove(); });
                var countEl = document.getElementById('jaNotifCount');
                if (countEl) countEl.remove();
              }
            })
            .catch(function(){});
        });
      }
    })();
    </script>

    <!-- quick actions -->
    <div class="ja-quick" style="margin-bottom:36px">
      <a href="plan-trip.php"><span class="qi"><?= ja_icon('search',26) ?></span>Plan a Trip<span class="ja-muted" style="font-weight:400;font-size:.85rem">Search, route &amp; cost</span></a>
      <a href="my-plans.php"><span class="qi"><?= ja_icon('wallet',26) ?></span>My Trips<span class="ja-muted" style="font-weight:400;font-size:.85rem">Saved plans &amp; routes</span></a>
      <a href="my-entries.php"><span class="qi"><?= ja_icon('book',26) ?></span>My Journal<span class="ja-muted" style="font-weight:400;font-size:.85rem"><?= count($entries) ? count($entries).' recent' : 'Start your journal' ?></span></a>
      <a href="feed.php"><span class="qi"><?= ja_icon('heart',26) ?></span>Community<span class="ja-muted" style="font-weight:400;font-size:.85rem">Posts &amp; shared journals</span></a>
      <a href="analytics.php"><span class="qi"><?= ja_icon('chart',26) ?></span>Insights<span class="ja-muted" style="font-weight:400;font-size:.85rem">What trips cost</span></a>
    </div>

    <div class="ja-hub-grid">
      <!-- left column -->
      <div>
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:16px">
          <h2 style="font-size:1.5rem;margin:0">Recommended for you</h2>
          <a href="recommendations.php" style="color:var(--ja-teal);font-weight:600;font-size:.9rem">See all →</a>
        </div>
        <?php if (!empty($reco['__error'])): ?>
          <?= ml_offline_banner($reco) ?>
        <?php elseif (!empty($reco['recommendations'])): ?>
          <?php require 'ja-cards.php'; ja_render_cards(array_slice($reco['recommendations'],0,3)); ?>
        <?php else: ?>
          <div class="ja-empty">Add a few journal entries or plan a trip, and personalised picks will appear here.</div>
        <?php endif; ?>

        <div style="display:flex;justify-content:space-between;align-items:baseline;margin:40px 0 16px">
          <h2 style="font-size:1.5rem;margin:0">Recent journal entries</h2>
          <a href="my-entries.php" style="color:var(--ja-teal);font-weight:600;font-size:.9rem">View all →</a>
        </div>
        <?php if ($entries): ?>
          <div class="ja-grid cols-3">
            <?php foreach ($entries as $e): ?>
              <a href="display.php?id=<?= (int)$e['entry_id'] ?>" class="ja-card" style="display:block">
                <h3 style="font-size:1.15rem;margin-bottom:4px"><?= htmlspecialchars($e['Title']) ?></h3>
                <div style="color:var(--text-mut);font-size:.88rem"><?= htmlspecialchars(trim(($e['City']??'').', '.($e['Country']??''),', ')) ?></div>
                <div style="color:var(--text-mut);font-size:.8rem;margin-top:8px"><?= htmlspecialchars($e['cd']) ?></div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="ja-empty">No journal entries yet. <a href="new-entry.php" style="color:var(--ja-teal);font-weight:600">Write your first →</a></div>
        <?php endif; ?>
      </div>

      <!-- right column -->
      <aside>
        <div class="ja-card">
          <h3 style="font-size:1.25rem">Saved plans</h3>
          <?php if ($plans): ?>
            <?php foreach ($plans as $p): ?>
              <div style="display:flex;justify-content:space-between;align-items:center;padding:11px 0;border-bottom:1px solid var(--brd)">
                <span style="font-weight:500"><?= htmlspecialchars(explode(',',$p['destination'])[0]) ?></span>
                <a href="plan-trip.php" style="color:var(--ja-teal);font-size:.85rem">plan →</a>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <p style="color:var(--text-mut);font-size:.9rem">You haven't saved any plans yet. Explore <a href="plan-trip.php" style="color:var(--ja-teal)">recommendations</a> and hit “Save”.</p>
          <?php endif; ?>
        </div>

        <div class="ja-card" style="margin-top:20px;background:var(--grad-hero);border:none;color:#fff">
          <h3 style="color:#fff;font-size:1.2rem">Discover a hidden gem</h3>
          <p style="color:#d7f6fb;font-size:.9rem;margin:8px 0 16px">Let JourneyAI surface an authentic, lesser-known destination that fits you.</p>
          <a href="plan-trip.php" class="ja-btn ja-btn-primary" style="padding:11px 22px">Surprise me →</a>
        </div>
      </aside>
    </div>
</main>

<?php include 'ja-footer.php'; ?>
