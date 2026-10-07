<?php
$ja_title = "My Journal"; $ja_active = "entries"; $ja_jtab = "entries";
session_start();
require 'connection.php';
require_once 'ja-lib.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$em = $_SESSION['eml'];

$stmt = mysqli_prepare($conn,
  "SELECT j.entry_id, j.Title, j.City, j.Country, j.cd, j.duration_days, j.true_total, d.visibility, d.share_token, d.is_pinned,
          (SELECT COUNT(*) FROM media m WHERE m.entry_id = j.entry_id) AS n_media
   FROM journals j JOIN db d ON d.entry_id = j.entry_id WHERE j.eml = ? ORDER BY d.is_pinned DESC, j.entry_id DESC");
mysqli_stmt_bind_param($stmt, 's', $em);
mysqli_stmt_execute($stmt);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// Located photos (best-effort geolocation captured at upload time) -> map pins.
// Most existing media predates this feature and has NULL lat/lon, so this can be empty.
$stmt = mysqli_prepare($conn,
  "SELECT m.media_id, m.entry_id, m.filepath, m.lat, m.lon, j.Title, j.City, j.Country
   FROM media m JOIN journals j ON j.entry_id = m.entry_id
   WHERE m.eml = ? AND m.lat IS NOT NULL AND m.lon IS NOT NULL
   ORDER BY m.media_id DESC");
mysqli_stmt_bind_param($stmt, 's', $em);
mysqli_stmt_execute($stmt);
$pins = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">

<div class="ja-pagehead">
  <div class="ja-container">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px">
      <div>
        <div class="ja-eyebrow">✦ Your travel journal</div>
        <h1>My Journal</h1>
        <p class="sub"><?= count($rows) ?> trip<?= count($rows)===1?'':'s' ?> recorded. Share any of them, or turn them into posts.</p>
      </div>
    </div>
    <?php include 'ja-journal-tabs.php'; ?>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container">
    <div class="ja-card" style="margin-bottom:28px">
      <h3><?= ja_icon('map-pin',18) ?> Map view</h3>
      <?php if ($pins): ?>
        <p style="color:var(--text-mut);font-size:.85rem;margin:2px 0 10px">
          <?= count($pins) ?> photo<?= count($pins)===1?'':'s' ?> with a known location.</p>
        <div id="entriesMap" style="height:340px;border-radius:14px;overflow:hidden"></div>
      <?php else: ?>
        <div class="ja-empty" style="padding:34px 20px">
          <p style="margin:0">No located photos yet. Allow location access next time you add photos in <a href="new-entry.php" style="color:var(--ja-teal)">New Entry</a> to see them pinned here.</p>
        </div>
      <?php endif; ?>
    </div>
    <?php if (!$rows): ?>
      <div class="ja-empty" style="padding:60px 30px">
        <div style="margin-bottom:12px;color:var(--ja-aqua)"><?= ja_icon('book',40) ?></div>
        <p style="font-size:1.1rem;color:var(--text-dim)">You haven't recorded any trips yet.</p>
        <a href="new-entry.php" class="ja-btn ja-btn-primary" style="margin-top:16px">Create your first entry</a>
      </div>
    <?php else: ?>
      <div class="ja-grid cols-3">
        <?php foreach ($rows as $r): $city = htmlspecialchars($r['City'] ?: $r['Title']); ?>
          <div class="ja-card" data-tilt data-pin-card>
            <div style="display:flex;justify-content:space-between;align-items:start;gap:10px">
              <div>
                <h3 style="margin-bottom:2px;font-size:1.2rem"><?= htmlspecialchars($r['Title']) ?></h3>
                <div style="color:var(--text-mut);font-size:.88rem"><?= $city ?><?= $r['Country']?', '.htmlspecialchars($r['Country']):'' ?></div>
              </div>
              <span class="ja-pill near"><?= (int)$r['duration_days'] ?>d</span>
            </div>
            <div class="ja-cost" style="font-size:1.5rem;margin:16px 0 2px">₹<?= number_format((float)$r['true_total']) ?></div>
            <div style="color:var(--text-mut);font-size:.82rem;margin-bottom:18px">Total trip cost</div>
            <div style="margin-bottom:12px"><span class="ja-vis <?= htmlspecialchars($r['visibility']) ?>" id="vis-<?= (int)$r['entry_id'] ?>"><?= ['private'=>'Private','link'=>'Link-shared','public'=>'Public'][$r['visibility']] ?? 'Private' ?></span>
              <span class="ja-muted" style="font-size:.78rem;margin-left:6px"><?= (int)$r['n_media'] ?> photo/video<?= (int)$r['n_media'] === 1 ? '' : 's' ?></span></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <a href="display.php?id=<?= (int)$r['entry_id'] ?>" class="ja-btn ja-btn-ghost" style="padding:9px 14px;font-size:.88rem">View</a>
              <a href="update.php?id=<?= (int)$r['entry_id'] ?>" class="ja-btn ja-btn-ghost" style="padding:9px 14px;font-size:.88rem">Edit</a>
              <button type="button" data-pin-kind="journal" data-pin-id="<?= (int)$r['entry_id'] ?>" data-pinned="<?= (int)$r['is_pinned'] ?>" class="ja-btn ja-btn-ghost" style="padding:9px 14px;font-size:.88rem" title="Pin to your public profile">Pin</button>
              <button type="button" class="ja-btn ja-btn-ghost" style="padding:9px 14px;font-size:.88rem" data-share="<?= (int)$r['entry_id'] ?>" data-vis="<?= htmlspecialchars($r['visibility']) ?>">Share</button>
              <a href="share.php?entry=<?= (int)$r['entry_id'] ?>" class="ja-btn ja-btn-ghost" style="padding:9px 14px;font-size:.88rem">Post media</a>
              <form method="post" action="delete.php" style="display:inline" onsubmit="return confirm('Delete this entry, its photos and its comments? This cannot be undone.')">
                <?= ja_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['entry_id'] ?>">
                <button class="ja-btn ja-btn-ghost" style="padding:9px 14px;font-size:.88rem;color:var(--ja-coral)">Delete</button>
              </form>
            </div>
            <div class="ja-share-pop" id="pop-<?= (int)$r['entry_id'] ?>" style="display:none"></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</main>

<script>
(function(){
  var CSRF = <?= json_encode(ja_csrf()) ?>;
  var LABEL = {private:'Private', link:'Link-shared', public:'Public'};
  function post(id, vis, cb){
    var fd = new FormData(); fd.append('entry_id', id); fd.append('visibility', vis); fd.append('csrf', CSRF);
    fetch('entry-share.php', {method:'POST', body:fd}).then(function(r){return r.json();}).then(cb);
  }
  document.querySelectorAll('[data-share]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var id = btn.getAttribute('data-share'), pop = document.getElementById('pop-'+id);
      if (pop.style.display !== 'none') { pop.style.display = 'none'; return; }
      var cur = btn.getAttribute('data-vis');
      pop.innerHTML = '<div style="font-weight:600;margin-bottom:6px">Who can read this journal?</div>' +
        ['private','link','public'].map(function(v){
          var t = {private:'Only me', link:'Anyone with the link', public:'Everyone (listed in Read journals)'}[v];
          return '<label style="display:flex;gap:8px;align-items:center;margin:4px 0;cursor:pointer"><input type="radio" name="v'+id+'" value="'+v+'"'+(v===cur?' checked':'')+'> '+t+'</label>';
        }).join('') + '<div id="lnk'+id+'" style="margin-top:8px"></div>';
      pop.style.display = 'block';
      function showLink(u){ var box = document.getElementById('lnk'+id); box.innerHTML = u ? '<input class="ja-input" readonly value="'+u+'" onclick="this.select()"> <button type="button" class="ja-btn ja-btn-ghost" style="padding:6px 12px;font-size:.8rem;margin-top:6px" id="cp'+id+'">Copy link</button>' : ''; var cp = document.getElementById('cp'+id); if (cp) cp.onclick = function(){ navigator.clipboard.writeText(u); cp.textContent = 'Copied'; }; }
      pop.querySelectorAll('input[type=radio]').forEach(function(r){
        r.addEventListener('change', function(){
          post(id, r.value, function(res){
            if (res.error) { alert(res.error); return; }
            btn.setAttribute('data-vis', res.visibility);
            var chip = document.getElementById('vis-'+id); chip.className = 'ja-vis ' + res.visibility; chip.textContent = LABEL[res.visibility];
            showLink(res.url);
          });
        });
      });
    });
  });
})();
</script>
<?php if ($pins): ?>
<script>
window.__entriesMapData = <?= json_encode(array_map(function($p){
  return [
    'lat' => (float)$p['lat'], 'lon' => (float)$p['lon'],
    'title' => $p['Title'], 'city' => trim(($p['City']?:'').(($p['City']&&$p['Country'])?', ':'').($p['Country']?:'')),
    'img' => $p['filepath'], 'entry_id' => (int)$p['entry_id'],
  ];
}, $pins)) ?>;
</script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="js/entries-map.js" defer></script>
<?php endif; ?>

<?php include 'ja-footer.php'; ?>
