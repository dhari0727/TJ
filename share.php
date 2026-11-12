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
require_once 'ja-trust.php';
require_once 'ja-community-ui.php';
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
        $cq = mysqli_prepare($conn, "SELECT is_public FROM media WHERE media_id = ? AND eml = ?");
        mysqli_stmt_bind_param($cq, 'is', $mid, $em); mysqli_stmt_execute($cq);
        $cur = mysqli_fetch_row(mysqli_stmt_get_result($cq)); mysqli_stmt_close($cq);
        if ($cur && (int)$cur[0] === 1) { $new = 0; $st = 'approved'; $why = null; }
        elseif ($cur) {
            $m = ja_moderation_for($em);
            if ($m['status'] === null) { $new = 0; $err = $m['reason']; $st = 'approved'; $why = null; }
            else { $new = 1; $st = $m['status']; $why = $m['reason']; }
        }
        if ($cur && !$err) {
            $u = mysqli_prepare($conn, "UPDATE media SET is_public = ?, moderation_status = ?, mod_reason = ? WHERE media_id = ? AND eml = ?");
            mysqli_stmt_bind_param($u, 'issis', $new, $st, $why, $mid, $em); mysqli_stmt_execute($u); mysqli_stmt_close($u);
            $msg = $new ? ($st === 'pending' ? 'Submitted for review. It appears in the Feed once a moderator approves it.' : 'Now public.') : 'Now private.';
        }
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
        if (!empty($_POST['picked']) && is_array($_POST['picked'])) {
            foreach (array_slice($_POST['picked'], 0, 20) as $pp) {
                if (!is_string($pp)) continue;
                [$mid, $e] = ja_attach_picked($conn, $pp, $em, $entryId ?: null, $caption, $place ?: null, $public, $lat, $lon);
                if ($mid) $made++; elseif ($e) $errors[] = $e;
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
<head><?php include 'ja-head.php'; ?>
<link rel="stylesheet" href="css/pages-community.css?v=<?= (int)@filemtime(__DIR__ . '/css/pages-community.css') ?>">
<link rel="stylesheet" href="css/pages-camera-picker.css?v=<?= (int)@filemtime(__DIR__ . '/css/pages-camera-picker.css') ?>"></head>
<body class="ja">
<div class="ja-pagehead pc-head"><div class="ja-container">
  <h1>Community</h1>
  <p class="sub">Share a photo or short video from the road. Add hashtags and a place so others can find it.</p>
  <?php include 'ja-community-tabs.php'; ?>
</div></div>

<main class="ja-main"><div class="ja-container" style="max-width:900px">
  <?php if ($msg): ?><div class="ja-ok" role="status"><?= htmlspecialchars($msg) ?> <a class="pc-link" href="feed.php">See the feed</a></div><?php endif; ?>
  <?php if ($err): ?><div class="ja-err" role="alert"><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="ps-form" id="shareForm">
    <?= ja_csrf_field() ?>
    <?php if ($entry): ?><input type="hidden" name="entry_id" value="<?= (int)$entry['entry_id'] ?>">
      <p class="pc-muted" style="margin-top:0">Posting from your journal: <strong><?= htmlspecialchars($entry['Title']) ?></strong></p><?php endif; ?>
    <div id="mediaDrop" class="pw-drop" aria-label="Add photos or videos">
      <?= pc_icon('upload', 30) ?>
      <strong>Drag photos and videos here, or choose files</strong>
      <span>JPG, PNG, WEBP, MP4 or WEBM, up to 40 MB each</span>
      <input type="file" name="media[]" id="mediaInput" accept="image/*,video/*" multiple hidden>
    </div>
    <div class="pw-camera-row"><button type="button" class="ja-btn ja-btn-ghost" id="cameraPick"><?= ja_icon('camera', 18) ?> From my camera roll</button>
      <span class="pc-muted">Your originals stay private.</span></div>
    <ul id="mediaList" class="pw-files"></ul>
    <div class="ja-field" style="margin-top:16px"><label for="sh-cap">Caption <span class="pc-muted">(add #hashtags like #goa #sunset)</span></label>
      <textarea class="ja-input" id="sh-cap" name="caption" rows="3" maxlength="480" placeholder="What is the story behind this shot?"></textarea></div>
    <div class="ja-fieldrow">
      <div class="ja-field"><label for="sh-place">Place</label><input class="ja-input" id="sh-place" name="place" value="<?= htmlspecialchars($entry ? trim(($entry['City'] ?? '') . ', ' . ($entry['Country'] ?? ''), ', ') : '') ?>" placeholder="Udaipur, India"></div>
      <div class="ja-field"><label for="sh-aud">Who can see this?</label>
        <select class="ja-select" id="sh-aud" name="audience"><option value="public">Everyone, in the feed</option><option value="private">Only me</option></select></div>
    </div>
    <input type="hidden" name="lat" id="lat"><input type="hidden" name="lon" id="lon">
    <button class="ja-btn ja-btn-primary" type="submit">Post</button>
  </form>

  <div class="pc-section-h"><h2>Your posts</h2><span class="pc-muted"><?= count($mine) ?></span></div>
  <?php if (!$mine): ?><?= pc_empty('You have not posted anything yet', 'Your posts will be listed here, where you can pin, hide or delete them. Add your first photo above.', null, 'image') ?><?php endif; ?>
  <div class="ps-grid">
    <?php foreach ($mine as $p): ?>
      <div class="ps-post" data-pin-card>
        <div class="m">
          <?php if ($p['kind'] === 'video'): ?><video src="<?= htmlspecialchars($p['filepath']) ?>" muted preload="metadata"></video>
          <?php else: ?><img src="<?= htmlspecialchars($p['filepath']) ?>" alt="<?= htmlspecialchars($p['caption'] ?: 'Your post') ?>" loading="lazy"><?php endif; ?>
          <span class="flag"><?= $p['is_pinned'] ? 'Pinned, ' : '' ?><?= $p['is_public'] ? 'Public' : 'Private' ?></span>
        </div>
        <div class="b">
          <p class="c"><?= htmlspecialchars($p['caption'] ?: 'No caption') ?></p>
          <div class="meta"><?= (int)$p['likes'] ?> likes, <?= (int)$p['n_comments'] ?> comments<?= $p['destination'] ? ', ' . htmlspecialchars($p['destination']) : '' ?></div>
          <form method="post" onsubmit="return this.do.value!=='delete_post'||confirm('Delete this post?')">
            <?= ja_csrf_field() ?><input type="hidden" name="media_id" value="<?= (int)$p['media_id'] ?>">
            <button type="button" data-pin-kind="media" data-pin-id="<?= (int)$p['media_id'] ?>" data-pinned="<?= (int)$p['is_pinned'] ?>" class="ja-btn ja-btn-ghost pc-btn-sm">Pin</button>
            <button name="do" value="toggle_post" class="ja-btn ja-btn-ghost pc-btn-sm"><?= $p['is_public'] ? 'Make private' : 'Make public' ?></button>
            <button name="do" value="delete_post" class="ja-btn ja-btn-ghost pc-btn-sm" style="color:var(--bad-ink)">Delete</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div></main>

<script src="js/camera-picker.js?v=<?= (int)@filemtime(__DIR__ . '/js/camera-picker.js') ?>"></script>
<script src="js/media-drop.js?v=<?= (int)@filemtime(__DIR__ . '/js/media-drop.js') ?>"></script>
<script>
(function(){
  PCDrop.init({drop:document.getElementById('mediaDrop'),input:document.getElementById('mediaInput'),list:document.getElementById('mediaList'),captions:false,camera:document.getElementById('cameraPick')});
  if(navigator.geolocation)navigator.geolocation.getCurrentPosition(function(p){document.getElementById('lat').value=p.coords.latitude;document.getElementById('lon').value=p.coords.longitude;},function(){},{maximumAge:300000,timeout:6000});
})();
</script>
<?php include 'ja-footer.php'; ?>
