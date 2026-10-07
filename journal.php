<?php
/**
 * JourneyAI — read one shared journal.
 *   journal.php?id=12                 public journals (anyone) or your own
 *   journal.php?id=12&token=XXXX      link-shared journals
 */
$ja_title = "Journal"; $ja_active = "feed";
session_start();
require 'connection.php';
require_once 'ja-media.php';
require_once 'ja-icons.php';
require_once 'ja-lib.php';
$me = $_SESSION['eml'] ?? null;
$id = (int)($_GET['id'] ?? 0);
$token = (string)($_GET['token'] ?? '');

$q = mysqli_prepare($conn, "SELECT j.*, s.fname, s.lname, d.visibility, d.share_token
        FROM journals j JOIN db d ON d.entry_id = j.entry_id LEFT JOIN signup s ON s.eml = j.eml WHERE j.entry_id = ?");
mysqli_stmt_bind_param($q, 'i', $id); mysqli_stmt_execute($q);
$e = mysqli_fetch_assoc(mysqli_stmt_get_result($q)); mysqli_stmt_close($q);

$owner = $e && $me && strcasecmp($e['eml'], $me) === 0;
$allowed = $e && ($owner || $e['visibility'] === 'public' || ($e['visibility'] === 'link' && $token !== '' && hash_equals((string)$e['share_token'], $token)));
if (!$allowed) { http_response_code(404); }
$media = $allowed ? ja_entry_media($conn, $id) : [];
if ($allowed && !$owner) $media = array_values(array_filter($media, function ($m) use ($e) { return (int)$m['is_public'] === 1 || $e['visibility'] === 'link'; }));
$place = $allowed ? trim(($e['City'] ?? '') . ', ' . ($e['Country'] ?? ''), ', ') : '';
$authorHandle = $allowed ? ja_ensure_handle($e['eml']) : null;
$likes = 0; $iLiked = false;
if ($allowed) {
    $likes = (int)mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM journal_likes WHERE entry_id = ' . $id))[0];
    if ($me) { $lq = mysqli_prepare($conn, 'SELECT 1 FROM journal_likes WHERE entry_id = ? AND eml = ?'); mysqli_stmt_bind_param($lq, 'is', $id, $me); mysqli_stmt_execute($lq); $iLiked = (bool)mysqli_fetch_row(mysqli_stmt_get_result($lq)); mysqli_stmt_close($lq); }
}
$author = $allowed ? trim(($e['fname'] ?? 'A traveller') . ' ' . mb_substr((string)($e['lname'] ?? ''), 0, 1) . '.') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">
<section class="ja-section" style="padding-top:48px"><div class="ja-container" style="max-width:900px">
<?php if (!$allowed): ?>
  <div class="ja-empty" style="padding:60px 30px"><h2>This journal isn't available</h2>
    <p class="ja-muted">It may be private, or the link is wrong.</p>
    <a href="read-journals.php" class="ja-btn ja-btn-primary" style="margin-top:14px">Read other journals</a></div>
<?php else: ?>
  <a href="read-journals.php" class="ja-muted" style="font-size:.85rem">&larr; All journals</a>
  <?php if ($owner && $e['visibility'] === 'private'): ?><div class="ja-ok" style="margin-top:10px">Only you can see this. <a href="my-entries.php" style="color:var(--ja-teal);font-weight:600">Share it from My entries</a>.</div><?php endif; ?>
  <h1 style="font-size:clamp(2rem,5vw,3.2rem);margin:10px 0 6px"><?= htmlspecialchars($e['Title']) ?></h1>
  <p class="sub"><?= htmlspecialchars($place) ?> &middot; <?= (int)$e['duration_days'] ?> days &middot; by <?php if ($authorHandle): ?><a href="traveller.php?h=<?= urlencode($authorHandle) ?>" style="color:inherit;text-decoration:underline"><?= htmlspecialchars($author) ?></a><?php else: ?><?= htmlspecialchars($author) ?><?php endif; ?><?= $e['dv'] ? ' &middot; ' . htmlspecialchars(date('M Y', strtotime($e['dv']))) : '' ?></p>

  <?php if ($media): ?>
  <div class="ja-journal-gallery">
    <?php foreach ($media as $m): ?>
      <figure>
        <?php if ($m['kind'] === 'video'): ?><video src="<?= htmlspecialchars($m['filepath']) ?>" controls preload="metadata"></video>
        <?php else: ?><img src="<?= htmlspecialchars($m['filepath']) ?>" alt="" loading="lazy"><?php endif; ?>
        <?php if ($m['caption']): ?><figcaption><?= htmlspecialchars($m['caption']) ?></figcaption><?php endif; ?>
      </figure>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="ja-card" style="margin-top:22px"><h3>The story</h3>
    <p style="white-space:pre-wrap;color:var(--text-dim)"><?= htmlspecialchars($e['Description'] ?: 'No story written yet.') ?></p>
    <?php if (!empty($e['ptv'])): ?><p><strong>Places visited:</strong> <?= htmlspecialchars($e['ptv']) ?></p><?php endif; ?>
    <?php if (!empty($e['hn'])): ?><p><strong>Stayed at:</strong> <?= htmlspecialchars($e['hn']) ?></p><?php endif; ?>
  </div>

  <?php $total = (float)$e['true_total']; if ($total > 0): ?>
  <div class="ja-card" style="margin-top:18px"><h3>What this trip cost</h3>
    <div class="ja-cost" style="font-size:1.8rem">&#8377;<?= number_format($total) ?> <span class="ja-muted" style="font-size:.9rem">total &middot; about &#8377;<?= number_format($total / max(1, (int)$e['duration_days'])) ?>/day</span></div>
    <div class="ja-cost-bars">
    <?php foreach (['Food' => 'food_total', 'Transport' => 'transport_total', 'Stay' => 'accommodation_total', 'Shopping' => 'shopping_total', 'Fees & misc' => 'fees_misc_total'] as $lbl => $k):
        $v = (float)$e[$k]; if ($v <= 0) continue; $pct = round($v / $total * 100); ?>
      <div class="ja-cb"><span><?= $lbl ?></span><div><i style="width:<?= $pct ?>%"></i></div><span>&#8377;<?= number_format($v) ?></span></div>
    <?php endforeach; ?></div>
  </div>
  <?php endif; ?>

  <div class="ja-card" style="margin-top:18px">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <button type="button" class="ja-feed-likebtn <?= $iLiked ? 'liked' : '' ?>" id="jLike" data-login="<?= $me ? '0' : '1' ?>">
        <?= ja_icon('heart',18) ?> <span id="jLikeN"><?= (int)$likes ?></span>
      </button>
      <span class="ja-muted" style="font-size:.9rem">Comments</span>
    </div>
    <div class="ja-cmts" style="border-top:none;padding-top:10px">
      <div class="ja-cmts-list" id="jCmtList" style="max-height:none"></div>
      <?php if ($me): ?><form class="ja-cmts-form" id="jCmtForm"><input id="jCmtInput" maxlength="600" placeholder="Write a comment" autocomplete="off"><button type="submit">Post</button></form>
      <?php else: ?><a href="login.php" class="ja-muted" style="font-size:.85rem">Log in to like and comment</a><?php endif; ?>
    </div>
  </div>
  <script>
  (function(){
    var ID = <?= (int)$id ?>, TOKEN = <?= json_encode($token) ?>, CSRF = <?= json_encode(ja_csrf()) ?>, ME = <?= $me ? 'true' : 'false' ?>;
    function esc(t){ var d=document.createElement('div'); d.textContent=t==null?'':String(t); return d.innerHTML; }
    function post(data){ var fd=new FormData(); Object.keys(data).forEach(function(k){fd.append(k,data[k]);}); fd.append('csrf',CSRF); fd.append('kind','journal'); fd.append('id',ID); if(TOKEN) fd.append('token',TOKEN); return fetch('engage.php',{method:'POST',body:fd}).then(function(r){return r.json();}); }
    function load(){ fetch('engage.php?action=comments&kind=journal&id='+ID+(TOKEN?'&token='+encodeURIComponent(TOKEN):'')).then(function(r){return r.json();}).then(function(d){
      var box=document.getElementById('jCmtList');
      box.innerHTML=(d.comments&&d.comments.length)?d.comments.map(function(c){ var who=c.handle?'<a href="traveller.php?h='+encodeURIComponent(c.handle)+'">'+esc(c.name)+'</a>':esc(c.name); return '<div class="ja-cmt" data-cid="'+c.id+'"><b>'+who+'</b> '+esc(c.body)+(c.can_delete?' <button type="button" class="ja-cmt-del" title="Delete">&times;</button>':'')+'</div>'; }).join(''):'<span class="ja-muted">No comments yet.</span>'; }); }
    load();
    var like=document.getElementById('jLike');
    if(!ME) like.addEventListener('click',function(){ location.href='login.php'; });
    if(ME) like.addEventListener('click',function(){ post({action:'like'}).then(function(d){ if(d.error){alert(d.error);return;} like.classList.toggle('liked',d.liked); document.getElementById('jLikeN').textContent=d.likes; }); });
    var f=document.getElementById('jCmtForm');
    if(f) f.addEventListener('submit',function(e){ e.preventDefault(); var i=document.getElementById('jCmtInput'); post({action:'comment_add',body:i.value}).then(function(d){ if(d.error){alert(d.error);return;} i.value=''; load(); }); });
    document.getElementById('jCmtList').addEventListener('click',function(e){ if(!e.target.classList.contains('ja-cmt-del'))return; post({action:'comment_delete',comment_id:e.target.closest('.ja-cmt').dataset.cid}).then(function(d){ if(d.ok) load(); }); });
  })();
  </script>

  <div style="margin-top:22px;display:flex;gap:10px;flex-wrap:wrap">
    <a class="ja-btn ja-btn-primary" href="trip.php?dest=<?= urlencode($place) ?>&days=<?= max(1, (int)$e['duration_days']) ?>"><?= ja_icon('compass',16) ?> Plan a similar trip to <?= htmlspecialchars(explode(',', $place)[0]) ?></a>
  </div>
<?php endif; ?>
</div></section>
<?php include 'ja-footer.php'; ?>
