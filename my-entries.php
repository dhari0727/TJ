<?php
$ja_title = "My Journal"; $ja_active = "entries"; $ja_jtab = "entries";
session_start();
require 'connection.php';
require_once 'ja-lib.php';
require_once 'ja-community-ui.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$em = $_SESSION['eml'];

$stmt = mysqli_prepare($conn,
  "SELECT j.entry_id, j.Title, j.City, j.Country, j.cd, j.duration_days, j.true_total, d.visibility, d.share_token, d.is_pinned, d.moderation_status,
          (SELECT COUNT(*) FROM media m WHERE m.entry_id = j.entry_id) AS n_media,
          (SELECT m2.filepath FROM media m2 WHERE m2.entry_id = j.entry_id AND m2.kind = 'photo' ORDER BY m2.media_id LIMIT 1) AS cover
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
<head><?php include 'ja-head.php'; ?>
<link rel="stylesheet" href="css/pages-community.css?v=<?= (int)@filemtime(__DIR__ . '/css/pages-community.css') ?>"></head>
<body class="ja">

<div class="ja-pagehead pc-head">
  <div class="ja-container">
    <h1>My journal</h1>
    <p class="sub"><?= count($rows) ?> trip<?= count($rows)===1?'':'s' ?> recorded. Share any of them, or turn the photos into posts.</p>
    <?php include 'ja-journal-tabs.php'; ?>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container">
    <?php if ($pins): ?>
    <section class="pc-mapcard" aria-label="Map of your photos">
      <h2>Where your photos were taken</h2>
      <p><?= count($pins) ?> photo<?= count($pins)===1?'':'s' ?> with a known location.</p>
      <div id="entriesMap" style="height:340px;border-radius:12px;overflow:hidden"></div>
    </section>
    <?php endif; ?>
    <?php if (!$rows): ?>
      <?= pc_empty('You have not written a journal yet', 'Record a trip with its story, photos and what it cost. It stays private until you choose to share it.', ['Write your first journal', 'new-entry.php'], 'pen') ?>
    <?php else: ?>
      <div class="pc-jgrid">
        <?php foreach ($rows as $r):
          $eid = (int)$r['entry_id'];
          $pl = trim(($r['City'] ?? '') . ', ' . ($r['Country'] ?? ''), ', ');
          $pend = $r['visibility'] === 'public' && $r['moderation_status'] !== 'approved';
        ?>
          <article class="pc-j" data-pin-card>
            <?php if ($r['cover']): ?><div class="pc-j-cover" style="background-image:url('<?= htmlspecialchars($r['cover'], ENT_QUOTES) ?>')" role="img" aria-label="Cover photo of <?= htmlspecialchars($r['Title']) ?>"></div>
            <?php else: ?><div class="pc-j-cover"><?= pc_icon('image', 30) ?></div><?php endif; ?>
            <div class="pc-j-body">
              <div>
                <h3><a href="display.php?id=<?= $eid ?>"><?= htmlspecialchars($r['Title']) ?></a></h3>
                <?php if ($pl): ?><p class="pc-j-place"><?= htmlspecialchars($pl) ?></p><?php endif; ?>
              </div>
              <div class="pc-facts">
                <?php if ((int)$r['duration_days'] > 0): ?><span><?= ja_icon('calendar', 15) ?> <?= (int)$r['duration_days'] ?> day<?= (int)$r['duration_days'] === 1 ? '' : 's' ?></span><?php endif; ?>
                <?php if ((float)$r['true_total'] > 0): ?><span><?= ja_icon('wallet', 15) ?> &#8377;<?= number_format((float)$r['true_total']) ?></span><?php endif; ?>
                <span><?= pc_icon('image', 15) ?> <?= (int)$r['n_media'] ?></span>
                <span class="ja-vis <?= $pend ? htmlspecialchars($r['moderation_status']) : htmlspecialchars($r['visibility']) ?>" id="vis-<?= $eid ?>"><?= $pend ? ['pending'=>'Under review','hidden'=>'Hidden','rejected'=>'Not approved'][$r['moderation_status']] : (['private'=>'Private','link'=>'Link-shared','public'=>'Public'][$r['visibility']] ?? 'Private') ?></span>
              </div>
              <div class="pc-j-row">
                <a href="display.php?id=<?= $eid ?>" class="ja-btn ja-btn-ghost pc-btn-sm">View</a>
                <a href="update.php?id=<?= $eid ?>" class="ja-btn ja-btn-ghost pc-btn-sm">Edit</a>
                <button type="button" class="ja-btn ja-btn-primary pc-btn-sm" data-share="<?= $eid ?>" data-vis="<?= htmlspecialchars($r['visibility']) ?>"><?= pc_icon('link', 15) ?> Share</button>
                <details class="pc-menu"><summary class="ja-btn ja-btn-ghost pc-btn-sm" aria-label="More actions for <?= htmlspecialchars($r['Title']) ?>"><?= pc_icon('dots', 16) ?></summary>
                  <div class="pc-menu-list">
                    <button type="button" data-pin-kind="journal" data-pin-id="<?= $eid ?>" data-pinned="<?= (int)$r['is_pinned'] ?>" title="Pin to your public profile">Pin to profile</button>
                    <a href="share.php?entry=<?= $eid ?>">Post its photos to the feed</a>
                    <form method="post" action="delete.php" onsubmit="return confirm('Delete this entry, its photos and its comments? This cannot be undone.')">
                      <?= ja_csrf_field() ?><input type="hidden" name="id" value="<?= $eid ?>">
                      <button class="danger">Delete journal</button>
                    </form>
                  </div>
                </details>
              </div>
              <div class="ja-share-pop" id="pop-<?= $eid ?>" style="display:none"></div>
            </div>
          </article>
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
            var chip = document.getElementById('vis-'+id); var pend = res.status && res.status !== 'approved' && res.visibility === 'public'; chip.className = 'ja-vis ' + (pend ? 'pending' : res.visibility); chip.textContent = pend ? 'Under review' : LABEL[res.visibility]; if (res.message) { var note = document.getElementById('lnk'+id); if (note) note.insertAdjacentHTML('beforebegin', '<div class="ja-muted" style="margin-top:6px">'+res.message.replace(/</g,'&lt;')+'</div>'); }
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
