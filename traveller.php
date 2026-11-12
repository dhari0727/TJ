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
require_once 'ja-community-ui.php';
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
    $posts = rows($conn, "SELECT media_id, kind, filepath, caption, destination, likes, is_pinned FROM media WHERE eml = ? AND is_public = 1 AND moderation_status = 'approved' ORDER BY is_pinned DESC, media_id DESC LIMIT 48", $u['eml']);
    $journals = rows($conn, "SELECT d.entry_id, d.Title, d.City, d.Country, d.is_pinned,
        (SELECT m.filepath FROM media m WHERE m.entry_id = d.entry_id AND m.kind = 'photo' AND m.is_public = 1 AND m.moderation_status = 'approved' ORDER BY m.media_id LIMIT 1) AS cover
        FROM db d WHERE d.eml = ? AND d.visibility = 'public' AND d.moderation_status = 'approved' ORDER BY d.is_pinned DESC, d.published_at DESC LIMIT 30", $u['eml']);
    $books = rows($conn, "SELECT book_id, title, subtitle, cover_img, is_pinned FROM storybooks WHERE eml = ? AND visibility = 'public' AND moderation_status = 'approved' ORDER BY is_pinned DESC, updated_at DESC LIMIT 30", $u['eml']);
}
$name = $u ? trim($u['fname'] . ' ' . mb_substr((string)$u['lname'], 0, 1) . '.') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?>
<link rel="stylesheet" href="css/pages-community.css?v=<?= (int)@filemtime(__DIR__ . '/css/pages-community.css') ?>"></head>
<body class="ja">
<main class="ja-main" style="padding-top:44px"><div class="ja-container" style="max-width:1040px">
<?php if (!$u): ?>
  <?= pc_empty('Traveller not found', 'That profile does not exist or is no longer active. The Community page lists people who are sharing right now.', ['Back to Community', 'feed.php'], 'user') ?>
<?php else: ?>
  <?php
    function pc_tr_journal_tile($j, $badge = '') {
        $pl = trim(($j['City'] ?? '') . ', ' . ($j['Country'] ?? ''), ', ');
        ob_start(); ?>
        <a class="pc-tile" href="journal.php?id=<?= (int)$j['entry_id'] ?>">
          <?php if ($j['cover']): ?><div class="cv" style="background-image:url('<?= htmlspecialchars($j['cover'], ENT_QUOTES) ?>')" role="img" aria-label="Photo from <?= htmlspecialchars(ja_title($j['Title'])) ?>"></div><?php else: ?><div class="cv"><?= pc_icon('image', 30) ?></div><?php endif; ?>
          <div class="tx"><h3><?= htmlspecialchars(ja_title($j['Title'])) ?></h3><?php if ($pl): ?><div class="by"><?= htmlspecialchars($pl) ?></div><?php endif; ?><?= $badge ?></div>
        </a>
        <?php return ob_get_clean();
    }
    function pc_tr_book_tile($b, $badge = '') {
        ob_start(); ?>
        <a class="pc-tile" href="storybook-view.php?id=<?= (int)$b['book_id'] ?>">
          <?php if ($b['cover_img']): ?><div class="cv" style="background-image:url('<?= htmlspecialchars($b['cover_img'], ENT_QUOTES) ?>')" role="img" aria-label="Cover of <?= htmlspecialchars($b['title']) ?>"></div><?php else: ?><div class="cv"><?= pc_icon('book', 30) ?></div><?php endif; ?>
          <div class="tx"><h3><?= htmlspecialchars($b['title']) ?></h3><?= $badge ?></div>
        </a>
        <?php return ob_get_clean();
    }
    $pin = function ($t) { return '<span class="pc-pin">' . ja_icon('star', 13) . ' Pinned ' . $t . '</span>'; };
  ?>
  <header class="pt-head">
    <span class="pt-av" aria-hidden="true"><?= htmlspecialchars(strtoupper(mb_substr($u['fname'], 0, 1) . mb_substr((string)$u['lname'], 0, 1))) ?></span>
    <div style="flex:1;min-width:220px">
      <h1><?= htmlspecialchars($name) ?></h1>
      <div class="pt-stats">
        <span>Travelling with JourneyAI since <?= htmlspecialchars(date('M Y', strtotime($u['created_at'] ?: 'now'))) ?></span>
        <span><?= count($posts) ?> post<?= count($posts) === 1 ? '' : 's' ?></span>
        <span><?= count($journals) ?> journal<?= count($journals) === 1 ? '' : 's' ?></span>
        <span><?= count($books) ?> storybook<?= count($books) === 1 ? '' : 's' ?></span>
      </div>
      <?php if ($u['bio']): ?><p class="pt-bio"><?= htmlspecialchars($u['bio']) ?></p><?php endif; ?>
    </div>
    <?php if ($isMe): ?><a href="profile.php" class="ja-btn ja-btn-ghost">Edit profile</a><?php endif; ?>
  </header>

  <?php $pinnedPosts = array_filter($posts, function ($p) { return $p['is_pinned']; }); $pinnedJ = array_filter($journals, function ($j) { return $j['is_pinned']; }); $pinnedB = array_filter($books, function ($b) { return $b['is_pinned']; }); ?>
  <?php if ($pinnedPosts || $pinnedJ || $pinnedB): ?>
    <section class="pt-sec"><h2>Pinned</h2>
    <div class="pc-cards">
      <?php foreach ($pinnedB as $b) echo pc_tr_book_tile($b, $pin('storybook')); ?>
      <?php foreach ($pinnedJ as $j) echo pc_tr_journal_tile($j, $pin('journal')); ?>
      <?php foreach ($pinnedPosts as $p): ?><a class="pc-tile" href="feed.php?dest=<?= urlencode((string)$p['destination']) ?>"><div class="cv" style="aspect-ratio:16 / 10;overflow:hidden;display:block"><?php if ($p['kind'] === 'video'): ?><video src="<?= htmlspecialchars($p['filepath']) ?>" muted preload="metadata" style="width:100%;height:100%;object-fit:cover"></video><?php else: ?><img src="<?= htmlspecialchars($p['filepath']) ?>" alt="<?= htmlspecialchars((string)$p['caption']) ?>" loading="lazy" style="width:100%;height:100%;object-fit:cover"><?php endif; ?></div><div class="tx"><?= $pin('post') ?><p><?= htmlspecialchars(mb_strimwidth((string)$p['caption'], 0, 90, '…')) ?></p></div></a><?php endforeach; ?>
    </div></section>
  <?php endif; ?>

  <?php if ($journals): ?>
    <section class="pt-sec"><h2>Journals</h2><div class="pc-cards"><?php foreach ($journals as $j) echo pc_tr_journal_tile($j); ?></div></section>
  <?php endif; ?>
  <?php if ($books): ?>
    <section class="pt-sec"><h2>Storybooks</h2><div class="pc-cards"><?php foreach ($books as $b) echo pc_tr_book_tile($b); ?></div></section>
  <?php endif; ?>
  <?php if ($posts): ?>
    <section class="pt-sec"><h2>Posts</h2>
    <div class="ps-grid">
      <?php foreach ($posts as $p): ?><a class="ps-post" href="feed.php?dest=<?= urlencode((string)$p['destination']) ?>" style="text-decoration:none;color:inherit"><div class="m"><?php if ($p['kind'] === 'video'): ?><video src="<?= htmlspecialchars($p['filepath']) ?>" muted preload="metadata"></video><?php else: ?><img src="<?= htmlspecialchars($p['filepath']) ?>" alt="<?= htmlspecialchars((string)$p['caption']) ?>" loading="lazy"><?php endif; ?></div><div class="b"><p class="c"><?= htmlspecialchars($p['caption'] ?: '') ?></p><div class="meta"><?= (int)$p['likes'] ?> like<?= (int)$p['likes'] === 1 ? '' : 's' ?></div></div></a><?php endforeach; ?>
    </div></section>
  <?php endif; ?>
  <?php if (!$posts && !$journals && !$books): ?>
    <?= $isMe ? pc_empty('You have not shared anything yet', 'Public journals, storybooks and posts appear on your profile. Share one to get started.', ['Write a journal', 'new-entry.php'], 'pen') : pc_empty('Nothing shared yet', $name . ' has not shared anything publicly yet. Check back later.', ['Browse the Community', 'feed.php'], 'user') ?>
  <?php endif; ?>
<?php endif; ?>
</div></main>
<?php include 'ja-footer.php'; ?>
