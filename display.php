<?php
$ja_title = "Entry"; $ja_active = "entries";
session_start();
require 'connection.php';
require 'ml_client.php';
require 'ja-media.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$em = $_SESSION['eml'];
$id = (int)($_GET['id'] ?? 0);

$stmt = mysqli_prepare($conn,
  "SELECT * FROM journals WHERE entry_id = ? AND eml = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'is', $id, $em);
mysqli_stmt_execute($stmt);
$e = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$e) { header('Location: view-list.php'); exit; }

$breakdown = [
  'Food' => (float)$e['food_total'], 'Transport' => (float)$e['transport_total'],
  'Accommodation' => (float)$e['accommodation_total'], 'Shopping' => (float)$e['shopping_total'],
  'Fees & misc' => (float)$e['fees_misc_total'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">
<section class="ja-section" style="padding-top:56px">
  <div class="ja-container">
    <a href="view-list.php" style="color:var(--ja-teal);font-weight:600">← All entries</a>
    <h1 class="reveal" style="font-size:clamp(2rem,5vw,3.4rem);margin-top:12px"><?= htmlspecialchars($e['Title']) ?></h1>
    <p class="sub reveal"><?= htmlspecialchars(trim(($e['City']??'').', '.($e['Country']??''), ', ')) ?>
      · <?= (int)$e['duration_days'] ?> days · <?= htmlspecialchars($e['tv'] ?: 'travel') ?></p>

    <div style="display:grid;gap:24px;grid-template-columns:1.4fr 1fr;align-items:start">
      <div class="ja-card reveal">
        <h3>The journey</h3>
        <p style="color:var(--text-dim);white-space:pre-wrap"><?= htmlspecialchars($e['Description'] ?: '—') ?></p>
        <?php if (!empty($e['ptv'])): ?>
          <h3 style="margin-top:22px">Places visited</h3>
          <p style="color:var(--text-dim)"><?= htmlspecialchars($e['ptv']) ?></p>
        <?php endif; ?>
        <?php if (!empty($e['hn'])): ?>
          <div style="margin-top:18px;color:var(--text-mut)"><b style="color:var(--text-dim)">Stay:</b> <?= htmlspecialchars($e['hn']) ?></div>
        <?php endif; ?>
        <?php $media = ja_entry_media($conn, $id); if ($media): ?>
          <h3 style="margin-top:22px">Photos &amp; videos</h3>
          <div class="ja-media-gallery">
            <?php foreach ($media as $md): ?>
              <div>
                <?php if ($md['kind']==='video'): ?>
                  <video src="<?= htmlspecialchars($md['filepath']) ?>" controls playsinline></video>
                <?php else: ?>
                  <img src="<?= htmlspecialchars($md['filepath']) ?>" alt="" loading="lazy">
                <?php endif; ?>
                <?php if (!empty($md['caption'])): ?>
                  <div class="ja-media-cap"><?= preg_replace('/#([A-Za-z0-9_]+)/','<a href="feed.php?tag=$1">#$1</a>', htmlspecialchars($md['caption'])) ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="ja-card reveal">
        <h3>Cost breakdown</h3>
        <div class="ja-cost" style="margin:6px 0 2px">₹<?= number_format((float)$e['true_total']) ?></div>
        <div style="color:var(--text-mut);font-size:.82rem;margin-bottom:14px">True total across all categories</div>
        <canvas id="bd" height="180"></canvas>
        <?php if ((float)$e['legacy_grand'] != (float)$e['true_total']): ?>
          <div style="margin-top:12px;font-size:.8rem;color:var(--text-mut)">
            (Legacy stored total: ₹<?= number_format((float)$e['legacy_grand']) ?> — excludes accommodation; JourneyAI shows the true total.)
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="reveal" style="margin-top:26px;display:flex;gap:10px">
      <a href="update.php?id=<?= $id ?>" class="ja-btn ja-btn-ghost">Edit entry</a>
      <a href="itinerary.php?dest=<?= urlencode(trim(($e['City']??'').', '.($e['Country']??''),', ')) ?>&days=<?= (int)$e['duration_days'] ?>" class="ja-btn ja-btn-primary">Build an itinerary →</a>
    </div>
  </div>
</section>
<footer class="ja-footer"><div class="ja-container">JourneyAI</div></footer>
<script>
document.addEventListener('DOMContentLoaded',function(){
  if(typeof Chart==='undefined')return;
  new Chart(document.getElementById('bd'),{type:'doughnut',
    data:{labels:<?= json_encode(array_keys($breakdown)) ?>,
      datasets:[{data:<?= json_encode(array_values($breakdown)) ?>,
        backgroundColor:['#31C6D4','#0E7C86','#0B3D4F','#FF7A59','#FF9E7A'],borderWidth:0}]},
    options:{plugins:{legend:{position:'bottom'},tooltip:{callbacks:{label:c=>c.label+': ₹'+c.raw.toLocaleString('en-IN')}}},cutout:'58%'}});
});
</script>
</body>
</html>
