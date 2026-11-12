<?php
$ja_title = "Dashboard"; $ja_active = "dashboard";
session_start();
require 'connection.php';
require 'ml_client.php';
require 'ja-notify.php';
require_once 'ja-community-ui.php';
require_once 'ja-lib.php';
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
<head><?php include 'ja-head.php'; ?>
<link rel="stylesheet" href="css/pages-community.css?v=<?= (int)@filemtime(__DIR__ . '/css/pages-community.css') ?>"></head>
<body class="ja">

<div class="ja-container pd-hello">
  <h1>Welcome back, <?= htmlspecialchars($fname) ?></h1>
  <p>Describe a trip in one line and JourneyAI plans it, with real costs.</p>
  <form class="ja-smart-box" onsubmit="location.href='plan-trip.php?q='+encodeURIComponent(this.q.value);return false;">
    <?= ja_icon('search',22) ?>
    <label class="pc-sr" for="dashQ">Describe your trip</label>
    <input type="text" id="dashQ" name="q" autocomplete="off" placeholder="Weekend from Ahmedabad, temples and food">
    <button type="submit" class="ja-btn ja-btn-primary">Plan it</button>
  </form>
</div>

<main class="ja-main"><div class="ja-container">

    <!-- notifications -->
    <div class="pd-bell">
      <button type="button" id="jaNotifBell" class="pd-bellbtn" aria-label="Notifications<?= $unreadCount ? ', ' . (int)$unreadCount . ' unread' : '' ?>" aria-expanded="false" aria-controls="jaNotifPanel">
        <?= pc_icon('bell', 20) ?>
        <?php if ($unreadCount > 0): ?><span id="jaNotifCount" class="pd-badge"><?= (int)$unreadCount ?></span><?php endif; ?>
      </button>
      <div id="jaNotifPanel" class="pd-panel" role="region" aria-label="Notifications">
        <div class="row">
          <h3>Notifications</h3>
          <?php if ($unreadCount > 0): ?><button type="button" id="jaNotifMarkAll" class="lnk">Mark all read</button><?php endif; ?>
        </div>
        <?php if (!$notifications): ?>
          <p class="pc-muted" style="margin:0">You are all caught up.</p>
        <?php else: foreach ($notifications as $n): ?>
          <div class="ja-notif-item pd-item" data-id="<?= (int)$n['notif_id'] ?>">
            <div class="t"><?php if (!empty($n['link'])): ?><a href="<?= htmlspecialchars($n['link']) ?>"><?= htmlspecialchars($n['message']) ?></a><?php else: ?><?= htmlspecialchars($n['message']) ?><?php endif; ?></div>
            <div class="f"><span><?= htmlspecialchars($n['created_at']) ?></span>
              <button type="button" class="ja-notif-read-btn lnk" data-id="<?= (int)$n['notif_id'] ?>" style="background:none;border:0;color:var(--rind);font-weight:600;cursor:pointer;text-decoration:underline">Mark read</button></div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <script>
    (function(){
      var bell = document.getElementById('jaNotifBell');
      var panel = document.getElementById('jaNotifPanel');
      if (!bell || !panel) return;
      bell.addEventListener('click', function(e){
        e.stopPropagation();
        var open = panel.style.display !== 'block'; panel.style.display = open ? 'block' : 'none'; bell.setAttribute('aria-expanded', open ? 'true' : 'false');
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
    <div class="pd-quick">
      <a href="plan-trip.php"><span class="qi"><?= ja_icon('search',24) ?></span>Plan a trip<small>Search, route and cost</small></a>
      <a href="my-plans.php"><span class="qi"><?= ja_icon('wallet',24) ?></span>My trips<small>Saved plans and routes</small></a>
      <a href="my-entries.php"><span class="qi"><?= ja_icon('book',24) ?></span>My journal<small><?= count($entries) ? count($entries) . ' recent' : 'Write your first trip' ?></small></a>
      <a href="feed.php"><span class="qi"><?= ja_icon('heart',24) ?></span>Community<small>Posts and shared journals</small></a>
      <a href="analytics.php"><span class="qi"><?= ja_icon('chart',24) ?></span>Insights<small>What trips cost</small></a>
    </div>

    <div class="ja-hub-grid">
      <!-- left column -->
      <div>
        <div class="pd-h"><h2>Recommended for you</h2><a href="recommendations.php">See all</a></div>
        <?php if (!empty($reco['__error'])): ?>
          <?= ml_offline_banner($reco) ?>
        <?php elseif (!empty($reco['recommendations'])): ?>
          <?php require 'ja-cards.php'; ja_render_cards(array_slice($reco['recommendations'],0,3)); ?>
        <?php else: ?>
          <?= pc_empty('No picks yet', 'Plan a trip or write a journal and JourneyAI will start suggesting places that fit your budget and interests.', ['Plan a trip', 'plan-trip.php'], 'compass') ?>
        <?php endif; ?>

        <div class="pd-h" style="margin-top:40px"><h2>Recent journal entries</h2><a href="my-entries.php">View all</a></div>
        <?php if ($entries): ?>
          <div class="pc-cards">
            <?php foreach ($entries as $e): $pl = trim(($e['City']??'').', '.($e['Country']??''),', '); ?>
              <a href="display.php?id=<?= (int)$e['entry_id'] ?>" class="pc-tile">
                <div class="tx"><h3><?= htmlspecialchars($e['Title']) ?></h3>
                  <?php if ($pl): ?><div class="by"><?= htmlspecialchars($pl) ?></div><?php endif; ?>
                  <div class="pc-muted"><?= htmlspecialchars(date('M j, Y', strtotime($e['cd']))) ?></div></div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <?= pc_empty('No journal entries yet', 'Write about a trip you have taken. It stays private until you share it.', ['Write your first journal', 'new-entry.php'], 'pen') ?>
        <?php endif; ?>
      </div>

      <!-- right column -->
      <aside>
        <div class="pd-panelcard">
          <h3>Saved plans</h3>
          <?php if ($plans): foreach ($plans as $p): ?>
            <div class="pd-plan"><span><?= htmlspecialchars(explode(',',$p['destination'])[0]) ?></span><a href="trip.php?dest=<?= urlencode($p['destination']) ?>">Open</a></div>
          <?php endforeach; else: ?>
            <p class="pc-muted" style="margin:0;line-height:1.6">You have not saved a plan yet. Open a recommendation and press the heart to keep it here.</p>
          <?php endif; ?>
        </div>
        <div class="pd-gem">
          <h3>Find a hidden gem</h3>
          <p>Let JourneyAI surface a lesser-known destination that fits your budget.</p>
          <a href="plan-trip.php" class="ja-btn">Surprise me</a>
        </div>
      </aside>
    </div>
</div></main>

<?php include 'ja-footer.php'; ?>
