<?php
/**
 * JourneyAI — Community > Share a post. Instagram-style: pick photos/videos, write a caption with
 * #hashtags, tag a place, choose who sees it. Also lists your own posts so you can manage them.
 *   share.php?entry=ID   pre-attaches the post to one of your journal entries (and its place).
 */
$ja_title = "Share a post"; $ja_active = "feed"; $ja_ctab = "share";
session_start();
require 'connection.php';
require 'ja-media.php';
require_once 'ja-lib.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$em = $_SESSION['eml'];
$msg = ''; $err = '';

// optional: attach to one of MY entries
$entryId = (int)($_GET['entry'] ?? $_POST['entry_id'] ?? 0);
$entry = null;
if ($entryId) {
    $q = mysqli_prepare($conn, "SELECT entry_id, Title, City, Country FROM db WHERE entry_id = ? AND eml = ?");
    mysqli_stmt_bind_param($q, 'is', $entryId, $em); mysqli_stmt_execute($q);
    $entry = mysqli_fetch_assoc(mysqli_stmt_get_result($q)); mysqli_stmt_close($q);
    if (!$entry) $entryId = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ja_csrf_ok()) { $err = 'Session expired. Reload and try again.'; }
    elseif (($_POST['do'] ?? '') === 'delete_post') {
        $mid = (int)($_POST['media_id'] ?? 0);
        $q = mysqli_prepare($conn, "SELECT filepath FROM media WHERE media_id = ? AND eml = ?");
        mysqli_stmt_bind_param($q, 'is', $mid, $em); mysqli_stmt_execute($q);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($q)); mysqli_stmt_close($q);
        if ($row) {
            foreach (['media_likes', 'media_hashtags', 'media_comments'] as $t) @mysqli_query($conn, "DELETE FROM $t WHERE media_id = $mid");
            $d = mysqli_prepare($conn, "DELETE FROM media WHERE media_id = ? AND eml = ?");
            mysqli_stmt_bind_param($d, 'is', $mid, $em); mysqli_stmt_execute($d); mysqli_stmt_close($d);
            $file = __DIR__ . '/' . $row['filepath'];
            if (strpos(realpath($file) ?: '', realpath(JA_UPLOAD_DIR)) === 0) @unlink($file);
            $msg = 'Post deleted.';
        }
    } elseif (($_POST['do'] ?? '') === 'toggle_post') {
        $mid = (int)($_POST['media_id'] ?? 0);
        $u = mysqli_prepare($conn, "UPDATE media SET is_public = 1 - is_public WHERE media_id = ? AND eml = ?");
        mysqli_stmt_bind_param($u, 'is', $mid, $em); mysqli_stmt_execute($u); mysqli_stmt_close($u);
        $msg = 'Visibility updated.';
    } else {
        // new post (one or many files, each becomes a post sharing the caption)
        if (!ja_rate_limit('upload:' . strtolower($em), 40, 3600)) { $err = 'You are posting very fast. Please wait a little.'; }
        else {
        $caption = trim($_POST['caption'] ?? '');
        $place = trim($_POST['place'] ?? '') ?: ($entry ? trim(($entry['City'] ?? '') . ', ' . ($entry['Country'] ?? ''), ', ') : '');
        $public = ($_POST['audience'] ?? 'public') === 'public' ? 1 : 0;
        $lat = trim($_POST['lat'] ?? ''); $lon = trim($_POST['lon'] ?? '');
        $made = 0; $errors = [];
        if (!empty($_FILES['media']) && is_array($_FILES['media']['name'])) {
            for ($i = 0, $n = count($_FILES['media']['name']); $i < $n; $i++) {
                if (($_FILES['media']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $f = ['name' => $_FILES['media']['name'][$i], 'type' => $_FILES['media']['type'][$i], 'tmp_name' => $_FILES['media']['tmp_name'][$i],
                      'error' => $_FILES['media']['error'][$i], 'size' => $_FILES['media']['size'][$i]];
                [$mid, $e] = ja_handle_upload($conn, $f, $em, $entryId ?: null, $caption, $place ?: null, $public, $lat, $lon);
                if ($mid) $made++; elseif ($e) $errors[] = $f['name'] . ': ' . $e;
            }
        }
        if ($made) { $msg = $made . ($made === 1 ? ' post' : ' posts') . ' shared.' . ($public ? ' It is live in the Feed.' : ' It is private to you.'); }
        if ($errors) $err = implode(' ', $errors);
        if (!$made && !$errors) $err = 'Choose at least one photo or video.';
        }
    }
}

// my posts
$mine = [];
$q = mysqli_prepare($conn, "SELECT media_id, kind, filepath, caption, destination, is_public, likes, created_at, is_pinned,
    (SELECT COUNT(*) FROM media_comments c WHERE c.media_id = media.media_id) AS n_comments FROM media WHERE eml = ? ORDER BY is_pinned DESC, media_id DESC LIMIT 60");
mysqli_stmt_bind_param($q, 's', $em); mysqli_stmt_execute($q);
$mine = mysqli_fetch_all(mysqli_stmt_get_result($q), MYSQLI_ASSOC); mysqli_stmt_close($q);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">
<div class="ja-pagehead"><div class="ja-container">
  <div class="ja-eyebrow"><?= ja_icon('sparkle',14) ?> Share a moment</div>
  <h1>Community</h1>
  <?php include 'ja-community-tabs.php'; ?>
</div></div>

<main class="ja-main"><div class="ja-container" style="max-width:900px">
  <?php if ($msg): ?><div class="ja-ok"><?= htmlspecialchars($msg) ?> <a href="feed.php" style="color:var(--ja-teal);font-weight:600">See the feed &rarr;</a></div><?php endif; ?>
  <?php if ($err): ?><div class="ja-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="ja-card" id="shareForm">
    <?= ja_csrf_field() ?>
    <?php if ($entry): ?><input type="hidden" name="entry_id" value="<?= (int)$entry['entry_id'] ?>">
      <p class="ja-muted" style="margin-top:0">Posting from your journal: <strong><?= htmlspecialchars($entry['Title']) ?></strong></p><?php endif; ?>
    <div id="mediaDrop" class="ja-media-drop">
      <?= ja_icon('compass',30) ?>
      <p style="margin:10px 0 4px;font-weight:600">Add photos or short videos</p>
      <p style="margin:0;color:var(--text-mut);font-size:.85rem">JPG, PNG, WEBP, MP4, WEBM &middot; up to 40 MB each</p>
      <input type="file" name="media[]" id="mediaInput" accept="image/*,video/*" multiple hidden>
    </div>
    <div id="mediaPreview" class="ja-media-preview"></div>
    <div class="ja-field" style="margin-top:14px"><label>Caption <span class="ja-muted">(add #hashtags like #goa #sunset)</span></label>
      <textarea class="ja-input" name="caption" rows="3" maxlength="480" placeholder="What's the story behind this shot?"></textarea></div>
    <div class="ja-fieldrow">
      <div class="ja-field"><label>Place</label><input class="ja-input" name="place" value="<?= htmlspecialchars($entry ? trim(($entry['City'] ?? '') . ', ' . ($entry['Country'] ?? ''), ', ') : '') ?>" placeholder="e.g. Udaipur, India"></div>
      <div class="ja-field"><label>Who can see this?</label>
        <select class="ja-select" name="audience"><option value="public">Everyone (in the Feed)</option><option value="private">Only me</option></select></div>
    </div>
    <input type="hidden" name="lat" id="lat"><input type="hidden" name="lon" id="lon">
    <button class="ja-btn ja-btn-primary ja-btn-lg" type="submit">Post</button>
  </form>

  <h2 style="margin:34px 0 14px;font-size:1.4rem">Your posts (<?= count($mine) ?>)</h2>
  <?php if (!$mine): ?><p class="ja-muted">Nothing posted yet.</p><?php endif; ?>
  <div class="ja-post-grid">
    <?php foreach ($mine as $p): ?>
      <div class="ja-post" data-pin-card>
        <div class="ja-post-media">
          <?php if ($p['kind'] === 'video'): ?><video src="<?= htmlspecialchars($p['filepath']) ?>" muted preload="metadata"></video>
          <?php else: ?><img src="<?= htmlspecialchars($p['filepath']) ?>" alt="" loading="lazy"><?php endif; ?>
          <span class="ja-post-flag"><?= $p['is_pinned'] ? 'Pinned &middot; ' : '' ?><?= $p['is_public'] ? 'Public' : 'Private' ?></span>
        </div>
        <div class="ja-post-body">
          <div class="ja-post-cap"><?= htmlspecialchars($p['caption'] ?: 'No caption') ?></div>
          <div class="ja-muted" style="font-size:.78rem"><?= (int)$p['likes'] ?> likes &middot; <?= (int)$p['n_comments'] ?> comments<?= $p['destination'] ? ' &middot; ' . htmlspecialchars($p['destination']) : '' ?></div>
          <form method="post" class="ja-post-actions" onsubmit="return this.do.value!=='delete_post'||confirm('Delete this post?')">
            <?= ja_csrf_field() ?><input type="hidden" name="media_id" value="<?= (int)$p['media_id'] ?>">
            <button type="button" data-pin-kind="media" data-pin-id="<?= (int)$p['media_id'] ?>" data-pinned="<?= (int)$p['is_pinned'] ?>" class="ja-btn ja-btn-ghost" style="padding:6px 12px;font-size:.82rem">Pin</button>
            <button name="do" value="toggle_post" class="ja-btn ja-btn-ghost" style="padding:6px 12px;font-size:.82rem"><?= $p['is_public'] ? 'Make private' : 'Make public' ?></button>
            <button name="do" value="delete_post" class="ja-btn ja-btn-ghost" style="padding:6px 12px;font-size:.82rem;color:var(--ja-coral)">Delete</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div></main>

<script>
(function(){
  var drop=document.getElementById('mediaDrop'),input=document.getElementById('mediaInput'),prev=document.getElementById('mediaPreview');
  drop.addEventListener('click',function(){input.click();});
  drop.addEventListener('dragover',function(e){e.preventDefault();drop.classList.add('drag');});
  drop.addEventListener('dragleave',function(){drop.classList.remove('drag');});
  drop.addEventListener('drop',function(e){e.preventDefault();drop.classList.remove('drag');input.files=e.dataTransfer.files;render();});
  input.addEventListener('change',render);
  function render(){
    prev.innerHTML='';
    Array.prototype.forEach.call(input.files,function(f){
      var url=URL.createObjectURL(f),el;
      if(f.type.indexOf('video')===0){el=document.createElement('video');el.src=url;el.muted=true;}
      else{el=document.createElement('img');el.src=url;}
      el.className='ja-media-thumb'; prev.appendChild(el);
    });
  }
  if(navigator.geolocation)navigator.geolocation.getCurrentPosition(function(p){document.getElementById('lat').value=p.coords.latitude;document.getElementById('lon').value=p.coords.longitude;},function(){},{maximumAge:300000,timeout:6000});
})();
</script>
<?php include 'ja-footer.php'; ?>
