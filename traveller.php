<?php
/**
 * JourneyAI — public traveller profile: traveller.php?h=<handle>
 * Shows only what the person chose to share publicly. Pinned items come first. Never shows the email.
 */
$ja_title = "Traveller"; $ja_active = "feed";
session_start();
require 'connection.php';
require_once 'ja-icons.php';
require_once 'ja-lib.php';
$me = $_SESSION['eml'] ?? null;
$h = trim($_GET['h'] ?? '');

$u = null;
if ($h !== '') {
    $q = mysqli_prepare($conn, "SELECT fname, lname, eml, handle, bio, created_at FROM signup WHERE handle = ? AND is_active = 1");
    mysqli_stmt_bind_param($q, 's', $h); mysqli_stmt_execute($q);
    $u = mysqli_fetch_assoc(mysqli_stmt_get_result($q)); mysqli_stmt_close($q);
}
if (!$u) { http_response_code(404); }
$isMe = $u && $me && strcasecmp($u['eml'], $me) === 0;

function rows($conn, $sql, $eml) {
    $s = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($s, 's', $eml); mysqli_stmt_execute($s);
    $r = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC); mysqli_stmt_close($s);
    return $r;
}
$posts = $journals = $books = [];
if ($u) {
    $posts = rows($conn, "SELECT media_id, kind, filepath, caption, destination, likes, is_pinned FROM media WHERE eml = ? AND is_public = 1 ORDER BY is_pinned DESC, media_id DESC LIMIT 48", $u['eml']);
    $journals = rows($conn, "SELECT d.entry_id, d.Title, d.City, d.Country, d.is_pinned,
        (SELECT m.filepath FROM media m WHERE m.entry_id = d.entry_id AND m.kind = 'photo' AND m.is_public = 1 ORDER BY m.media_id LIMIT 1) AS cover
        FROM db d WHERE d.eml = ? AND d.visibility = 'public' ORDER BY d.is_pinned DESC, d.published_at DESC LIMIT 30", $u['eml']);
    $books = rows($conn, "SELECT book_id, title, subtitle, cover_img, is_pinned FROM storybooks WHERE eml = ? AND visibility = 'public' ORDER BY is_pinned DESC, updated_at DESC LIMIT 30", $u['eml']);
}
$name = $u ? trim($u['fname'] . ' ' . mb_substr((string)$u['lname'], 0, 1) . '.') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">
<section class="ja-section" style="padding-top:48px"><div class="ja-container" style="max-width:1000px">
<?php if (!$u): ?>
  <div class="ja-empty" style="padding:60px 30px"><h2>Traveller not found</h2><a href="feed.php" class="ja-btn ja-btn-primary" style="margin-top:14px">Back to Community</a></div>
<?php else: ?>
  <div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;margin-bottom:22px">
    <span class="ja-feed-avatar" style="width:72px;height:72px;font-size:1.5rem"><?= htmlspecialchars(strtoupper(mb_substr($u['fname'], 0, 1) . mb_substr((string)$u['lname'], 0, 1))) ?></span>
    <div style="flex:1;min-width:220px">
      <h1 style="margin:0;font-size:clamp(1.8rem,4vw,2.6rem)"><?= htmlspecialchars($name) ?></h1>
      <div class="ja-muted">Travelling with JourneyAI since <?= htmlspecialchars(date('M Y', strtotime($u['created_at'] ?: 'now'))) ?> &middot; <?= count($posts) ?> posts &middot; <?= count($journals) ?> journals &middot; <?= count($books) ?> storybooks</div>
      <?php if ($u['bio']): ?><p style="margin:8px 0 0"><?= htmlspecialchars($u['bio']) ?></p><?php endif; ?>
    </div>
    <?php if ($isMe): ?><a href="profile.php" class="ja-btn ja-btn-ghost">Edit profile</a><?php endif; ?>
  </div>

  <?php $pinnedPosts = array_filter($posts, function ($p) { return $p['is_pinned']; }); $pinnedJ = array_filter($journals, function ($j) { return $j['is_pinned']; }); $pinnedB = array_filter($books, function ($b) { return $b['is_pinned']; }); ?>
  <?php if ($pinnedPosts || $pinnedJ || $pinnedB): ?>
    <h2 style="font-size:1.3rem;margin:6px 0 12px"><?= ja_icon('star',16) ?> Pinned</h2>
    <div class="ja-grid cols-3" style="margin-bottom:26px">
      <?php foreach ($pinnedB as $b): ?><a class="ja-card" href="storybook-view.php?id=<?= (int)$b['book_id'] ?>" style="text-decoration:none;color:inherit"><?php if ($b['cover_img']): ?><div class="ja-jcover" style="background-image:url('<?= htmlspecialchars($b['cover_img']) ?>')"></div><?php endif; ?><h3 style="margin:10px 0 0;font-size:1.05rem"><?= htmlspecialchars($b['title']) ?></h3><span class="ja-pinned-badge">Storybook</span></a><?php endforeach; ?>
      <?php foreach ($pinnedJ as $j): ?><a class="ja-card" href="journal.php?id=<?= (int)$j['entry_id'] ?>" style="text-decoration:none;color:inherit"><?php if ($j['cover']): ?><div class="ja-jcover" style="background-image:url('<?= htmlspecialchars($j['cover']) ?>')"></div><?php endif; ?><h3 style="margin:10px 0 0;font-size:1.05rem"><?= htmlspecialchars($j['Title']) ?></h3><span class="ja-pinned-badge">Journal</span></a><?php endforeach; ?>
      <?php foreach ($pinnedPosts as $p): ?><a class="ja-card" href="feed.php?dest=<?= urlencode((string)$p['destination']) ?>" style="text-decoration:none;color:inherit;padding:0;overflow:hidden"><div class="ja-post-media"><?php if ($p['kind'] === 'video'): ?><video src="<?= htmlspecialchars($p['filepath']) ?>" muted preload="metadata"></video><?php else: ?><img src="<?= htmlspecialchars($p['filepath']) ?>" alt="" loading="lazy"><?php endif; ?></div><div style="padding:10px 14px"><span class="ja-pinned-badge">Post</span> <?= htmlspecialchars(mb_strimwidth((string)$p['caption'], 0, 60, '…')) ?></div></a><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($journals): ?>
    <h2 style="font-size:1.3rem;margin:6px 0 12px">Journals</h2>
    <div class="ja-grid cols-3" style="margin-bottom:26px">
      <?php foreach ($journals as $j): ?><a class="ja-card" href="journal.php?id=<?= (int)$j['entry_id'] ?>" style="text-decoration:none;color:inherit"><?php if ($j['cover']): ?><div class="ja-jcover" style="background-image:url('<?= htmlspecialchars($j['cover']) ?>')"></div><?php endif; ?><h3 style="margin:10px 0 2px;font-size:1.05rem"><?= htmlspecialchars($j['Title']) ?></h3><div class="ja-muted" style="font-size:.85rem"><?= htmlspecialchars(trim(($j['City'] ?? '') . ', ' . ($j['Country'] ?? ''), ', ')) ?></div></a><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($books): ?>
    <h2 style="font-size:1.3rem;margin:6px 0 12px">Storybooks</h2>
    <div class="ja-grid cols-3" style="margin-bottom:26px">
      <?php foreach ($books as $b): ?><a class="ja-card" href="storybook-view.php?id=<?= (int)$b['book_id'] ?>" style="text-decoration:none;color:inherit"><?php if ($b['cover_img']): ?><div class="ja-jcover" style="background-image:url('<?= htmlspecialchars($b['cover_img']) ?>')"></div><?php endif; ?><h3 style="margin:10px 0 0;font-size:1.05rem"><?= htmlspecialchars($b['title']) ?></h3></a><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($posts): ?>
    <h2 style="font-size:1.3rem;margin:6px 0 12px">Posts</h2>
    <div class="ja-post-grid">
      <?php foreach ($posts as $p): ?><a class="ja-post" href="feed.php?dest=<?= urlencode((string)$p['destination']) ?>" style="text-decoration:none;color:inherit"><div class="ja-post-media"><?php if ($p['kind'] === 'video'): ?><video src="<?= htmlspecialchars($p['filepath']) ?>" muted preload="metadata"></video><?php else: ?><img src="<?= htmlspecialchars($p['filepath']) ?>" alt="" loading="lazy"><?php endif; ?></div><div class="ja-post-body"><div class="ja-post-cap"><?= htmlspecialchars($p['caption'] ?: '') ?></div><div class="ja-muted" style="font-size:.78rem"><?= (int)$p['likes'] ?> likes</div></div></a><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if (!$posts && !$journals && !$books): ?><div class="ja-empty" style="padding:40px"><p style="margin:0">Nothing shared publicly yet.</p></div><?php endif; ?>
<?php endif; ?>
</div></section>
<?php include 'ja-footer.php'; ?>
