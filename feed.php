<?php
/**
 * JourneyAI — Discover Feed (public browse feed of user photos/videos).
 * Viewable logged-out; like/comment actions require login (session eml).
 *
 * Query params:
 *   ?tag=xxx   filter by hashtag
 *   ?dest=xxx  filter by destination
 *   ?page=N    pagination (24 per page)
 */
$ja_title = "Community"; $ja_active = "feed";
session_start();
require 'connection.php';
require_once 'ja-media.php';
require_once 'ja-icons.php';
require_once 'ja-lib.php';
require_once 'ja-community-ui.php';

$eml = $_SESSION['eml'] ?? null;

$tagFilter  = trim($_GET['tag'] ?? '');
$destFilter = trim($_GET['dest'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 24;
$offset     = ($page - 1) * $perPage;

// ---- build the feed query (prepared) ----
$where  = "m.is_public = 1 AND m.moderation_status = 'approved'";
$types  = "";
$params = [];

if ($tagFilter !== '') {
    $where .= " AND m.media_id IN (SELECT mh.media_id FROM media_hashtags mh
                 JOIN hashtags h ON h.tag_id = mh.tag_id WHERE h.tag = ?)";
    $types .= "s";
    $params[] = strtolower($tagFilter);
}
if ($destFilter !== '') {
    $where .= " AND m.destination LIKE ?";
    $types .= "s";
    $params[] = '%' . $destFilter . '%';
}

// current-user like lookup needs eml bound too (LEFT JOIN ... AND ml.eml = ?)
$sql = "SELECT m.*, " . ($eml ? "ml.eml IS NOT NULL AS liked" : "0 AS liked") . "
        FROM media m
        " . ($eml ? "LEFT JOIN media_likes ml ON ml.media_id = m.media_id AND ml.eml = ?" : "") . "
        WHERE $where
        ORDER BY m.created_at DESC
        LIMIT ? OFFSET ?";

$bindTypes = ($eml ? "s" : "") . $types . "ii";
$bindVals  = [];
if ($eml) $bindVals[] = $eml;
foreach ($params as $p) $bindVals[] = $p;
$bindVals[] = $perPage;
$bindVals[] = $offset;

$stmt = mysqli_prepare($conn, $sql);
$refs = [];
foreach ($bindVals as $k => $v) { $refs[$k] = &$bindVals[$k]; }
array_unshift($refs, $bindTypes);
call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt], $refs));
mysqli_stmt_execute($stmt);
$posts = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// hashtags + owner name for each post
$mediaIds = array_column($posts, 'media_id');
$tagsByMedia = [];
if ($mediaIds) {
    $in = implode(',', array_fill(0, count($mediaIds), '?'));
    $types2 = str_repeat('i', count($mediaIds));
    $s2 = mysqli_prepare($conn, "SELECT mh.media_id, h.tag FROM media_hashtags mh
        JOIN hashtags h ON h.tag_id = mh.tag_id WHERE mh.media_id IN ($in)");
    $refs2 = [];
    foreach ($mediaIds as $k => $v) { $refs2[$k] = &$mediaIds[$k]; }
    array_unshift($refs2, $types2);
    call_user_func_array('mysqli_stmt_bind_param', array_merge([$s2], $refs2));
    mysqli_stmt_execute($s2);
    $tres = mysqli_stmt_get_result($s2);
    while ($row = mysqli_fetch_assoc($tres)) {
        $tagsByMedia[$row['media_id']][] = $row['tag'];
    }
    mysqli_stmt_close($s2);
}

// owner display name (first name via signup, fallback to email prefix)
$ownerEmails = array_values(array_unique(array_column($posts, 'eml')));
$ownerNames = []; $ownerHandles = [];
if ($ownerEmails) {
    $in = implode(',', array_fill(0, count($ownerEmails), '?'));
    $types3 = str_repeat('s', count($ownerEmails));
    $s3 = mysqli_prepare($conn, "SELECT eml, fname, handle FROM signup WHERE eml IN ($in)");
    $refs3 = [];
    foreach ($ownerEmails as $k => $v) { $refs3[$k] = &$ownerEmails[$k]; }
    array_unshift($refs3, $types3);
    call_user_func_array('mysqli_stmt_bind_param', array_merge([$s3], $refs3));
    mysqli_stmt_execute($s3);
    $ores = mysqli_stmt_get_result($s3);
    while ($row = mysqli_fetch_assoc($ores)) {
        $ownerNames[$row['eml']] = $row['fname'];
        $ownerHandles[$row['eml']] = $row['handle'] ?: ja_ensure_handle($row['eml']);
    }
    mysqli_stmt_close($s3);
}

// comment counts per post
$commentCounts = [];
if ($mediaIds) {
    $ids = implode(',', array_map('intval', $mediaIds));
    $cr = mysqli_query($conn, "SELECT media_id, COUNT(*) n FROM media_comments WHERE media_id IN ($ids) GROUP BY media_id");
    while ($cr && ($row = mysqli_fetch_assoc($cr))) $commentCounts[(int)$row['media_id']] = (int)$row['n'];
}

function ja_feed_owner_label($eml, $ownerNames) {
    if (!empty($ownerNames[$eml])) return $ownerNames[$eml];
    return 'Traveller';   // never show (part of) an email address
}
function ja_feed_initials($label) {
    $label = trim($label);
    if ($label === '') return '?';
    $parts = preg_split('/\s+/', $label);
    $ini = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) $ini .= strtoupper(substr($parts[count($parts) - 1], 0, 1));
    else $ini = strtoupper(substr($label, 0, 2));
    return $ini;
}


function pc_feed_card($m, $i, $ownerNames, $ownerHandles, $tagsByMedia, $commentCounts) {
    $label = ja_feed_owner_label($m['eml'], $ownerNames);
    $tags  = $tagsByMedia[$m['media_id']] ?? [];
    $liked = !empty($m['liked']);
    $mid   = (int)$m['media_id'];
    $cc    = (int)($commentCounts[$mid] ?? 0);
    $handle = $ownerHandles[$m['eml']] ?? null;
    $isVideo = $m['kind'] === 'video';
    $ar = $isVideo ? '4 / 5' : pc_ratio($m['filepath']);
    $post = htmlspecialchars(json_encode([
        'id' => $mid, 'src' => $m['filepath'], 'kind' => $m['kind'], 'caption' => $m['caption'],
        'destination' => $m['destination'], 'owner' => $label, 'likes' => (int)$m['likes'], 'liked' => $liked,
        'tags' => $tags, 'created' => $m['created_at'], 'handle' => $handle, 'comments' => $cc,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG), ENT_QUOTES, 'UTF-8');
    $alt = trim((string)$m['caption']) !== '' ? mb_strimwidth($m['caption'], 0, 120, '…') : 'Photo shared by ' . $label;
    ob_start(); ?>
    <article class="pc-card" data-post="<?= $post ?>">
      <div style="position:relative">
        <button type="button" class="pc-media" style="--ar:<?= htmlspecialchars($ar) ?>" data-open aria-label="Open <?= $isVideo ? 'video' : 'photo' ?>: <?= htmlspecialchars($alt) ?>">
          <?php if ($isVideo): ?>
            <video src="<?= htmlspecialchars($m['filepath']) ?>" muted loop playsinline preload="metadata"></video>
            <span class="pc-play"><?= pc_icon('play', 14) ?></span>
          <?php else: ?>
            <img src="<?= htmlspecialchars($m['filepath']) ?>" alt="<?= htmlspecialchars($alt) ?>" loading="lazy" decoding="async">
          <?php endif; ?>
        </button>
        <?php if ($m['destination']): ?>
          <a class="pc-dest" href="feed.php?dest=<?= urlencode($m['destination']) ?>"><?= ja_icon('map-pin', 12) ?> <?= htmlspecialchars(explode(',', $m['destination'])[0]) ?></a>
        <?php endif; ?>
      </div>
      <div class="pc-body">
        <div class="pc-owner">
          <span class="pc-av" aria-hidden="true"><?= htmlspecialchars(ja_feed_initials($label)) ?></span>
          <?php if ($handle): ?><a href="traveller.php?h=<?= urlencode($handle) ?>"><?= htmlspecialchars($label) ?></a>
          <?php else: ?><span class="nm"><?= htmlspecialchars($label) ?></span><?php endif; ?>
          <span class="pc-date"><?= htmlspecialchars(date('M j', strtotime($m['created_at']))) ?></span>
        </div>
        <?php if (trim((string)$m['caption']) !== ''): ?><p class="pc-cap"><?= htmlspecialchars($m['caption']) ?></p><?php endif; ?>
        <?php if ($tags): ?><div class="pc-tags"><?php foreach ($tags as $t): ?><a href="feed.php?tag=<?= urlencode($t) ?>">#<?= htmlspecialchars($t) ?></a><?php endforeach; ?></div><?php endif; ?>
        <div class="pc-actions">
          <button type="button" class="pc-act" data-like data-media-id="<?= $mid ?>" aria-pressed="<?= $liked ? 'true' : 'false' ?>" aria-label="Like this post">
            <?= ja_icon('heart', 17) ?><span class="n"><?= (int)$m['likes'] ?></span>
          </button>
          <button type="button" class="pc-act" data-comment aria-label="Comments">
            <?= pc_icon('chat', 17) ?><span class="n" data-cmt-for="<?= $mid ?>"><?= $cc ?></span>
          </button>
        </div>
      </div>
    </article>
    <?php
    return ob_get_clean();
}

// partial response for "Load more" and chip filtering
if (($_GET['partial'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $html = '';
    foreach ($posts as $i => $m) $html .= pc_feed_card($m, $i, $ownerNames, $ownerHandles, $tagsByMedia, $commentCounts);
    echo json_encode(['html' => $html, 'more' => count($posts) === $perPage, 'page' => $page, 'count' => count($posts)]);
    exit;
}

// trending hashtags
$trend = mysqli_query($conn, "SELECT tag, uses FROM hashtags ORDER BY uses DESC LIMIT 10");
$trending = $trend ? mysqli_fetch_all($trend, MYSQLI_ASSOC) : [];

$hasMore = count($posts) === $perPage;
$qs = function ($extra = []) use ($tagFilter, $destFilter) {
    $q = array_filter(['tag' => $tagFilter, 'dest' => $destFilter], function ($v) { return $v !== ''; });
    foreach ($extra as $k => $v) { if ($v === null) unset($q[$k]); else $q[$k] = $v; }
    return 'feed.php' . ($q ? '?' . http_build_query($q) : '');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include 'ja-head.php'; ?>
<link rel="stylesheet" href="css/pages-community.css?v=<?= (int)@filemtime(__DIR__ . '/css/pages-community.css') ?>">
</head>
<body class="ja">

<div class="ja-pagehead pc-head">
  <div class="ja-container">
    <h1>Community</h1>
    <p class="sub">Photos and moments from people who have just been there.</p>
    <?php $ja_ctab = 'feed'; include 'ja-community-tabs.php'; ?>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container">

    <div class="pc-filters">
      <form method="get" action="feed.php" class="pc-search" role="search">
        <?= ja_icon('search', 18) ?>
        <label class="pc-sr" for="pcTagInput">Search by hashtag</label>
        <input id="pcTagInput" type="text" name="tag" placeholder="Search a hashtag, like beach" value="<?= htmlspecialchars($tagFilter) ?>" autocomplete="off">
        <?php if ($destFilter !== ''): ?><input type="hidden" name="dest" value="<?= htmlspecialchars($destFilter) ?>"><?php endif; ?>
        <button type="submit" class="ja-btn ja-btn-primary pc-btn-sm">Search</button>
      </form>
      <nav class="pc-chips" id="pcChips" aria-label="Filter by hashtag">
        <a class="pc-chip" href="feed.php" <?= ($tagFilter === '' && $destFilter === '') ? 'aria-current="true"' : '' ?>>All posts</a>
        <?php if ($destFilter !== ''): ?>
          <a class="pc-chip" aria-current="true" href="<?= htmlspecialchars($qs(['dest' => null])) ?>" title="Remove this place filter"><?= ja_icon('map-pin', 14) ?> <?= htmlspecialchars(explode(',', $destFilter)[0]) ?> <span class="x"><?= pc_icon('close', 14) ?></span></a>
        <?php endif; ?>
        <?php
          $shown = [];
          foreach ($trending as $t) {
              $shown[] = strtolower($t['tag']);
              $on = ($tagFilter !== '' && strtolower($tagFilter) === strtolower($t['tag']));
              $href = $on ? $qs(['tag' => null]) : $qs(['tag' => $t['tag']]);
        ?>
          <a class="pc-chip" href="<?= htmlspecialchars($href) ?>" <?= $on ? 'aria-current="true" title="Remove this filter"' : '' ?>>#<?= htmlspecialchars($t['tag']) ?> <small><?= (int)$t['uses'] ?></small><?php if ($on): ?><span class="x"><?= pc_icon('close', 14) ?></span><?php endif; ?></a>
        <?php } ?>
        <?php if ($tagFilter !== '' && !in_array(strtolower($tagFilter), $shown, true)): ?>
          <a class="pc-chip" aria-current="true" href="<?= htmlspecialchars($qs(['tag' => null])) ?>" title="Remove this filter">#<?= htmlspecialchars($tagFilter) ?> <span class="x"><?= pc_icon('close', 14) ?></span></a>
        <?php endif; ?>
      </nav>
    </div>

    <div id="pcFeedRoot" data-tag="<?= htmlspecialchars($tagFilter) ?>" data-dest="<?= htmlspecialchars($destFilter) ?>" data-page="<?= (int)$page ?>" data-more="<?= $hasMore ? '1' : '0' ?>">
      <div id="pcEmpty" <?= $posts ? 'hidden' : '' ?>>
        <?php
          $filtered = ($tagFilter !== '' || $destFilter !== '');
          if ($filtered) echo pc_empty('Nothing here yet', 'No public posts match this filter. Clear it to see everything, or be the first to post with it.', $eml ? ['Share a photo', 'share.php'] : ['Log in to share a photo', 'login.php']);
          else echo pc_empty('No posts yet', 'Photos and short videos that travellers share will show up here. Add the first one from your trip.', $eml ? ['Share a photo', 'share.php'] : ['Log in to share a photo', 'login.php']);
        ?>
      </div>
      <div class="pc-masonry" id="pcGrid" aria-live="polite">
        <?php foreach ($posts as $i => $m) echo pc_feed_card($m, $i, $ownerNames, $ownerHandles, $tagsByMedia, $commentCounts); ?>
      </div>
      <div class="pc-skels" id="pcSkels" hidden aria-hidden="true">
        <?php for ($k = 0; $k < 6; $k++): ?><div class="pc-skel"><i class="m<?= $k % 2 ? ' w' : '' ?>"></i><i class="l"></i><i class="l2"></i></div><?php endfor; ?>
      </div>
      <div class="pc-more" id="pcMore" <?= $hasMore ? '' : 'hidden' ?>>
        <a class="ja-btn ja-btn-ghost" id="pcMoreBtn" href="<?= htmlspecialchars($qs(['page' => $page + 1])) ?>">Load more</a>
        <p id="pcMoreMsg" role="status"></p>
      </div>
      <p class="pc-end" id="pcEnd" <?= (!$hasMore && $posts) ? '' : 'hidden' ?>>You have reached the end.</p>
    </div>

  </div>
</main>

<!-- post viewer -->
<div class="pc-lb pc-lb-post" id="pcLb" role="dialog" aria-modal="true" aria-label="Post viewer">
  <button type="button" class="pc-lb-close" data-close aria-label="Close viewer"><?= pc_icon('close', 20) ?></button>
  <button type="button" class="pc-lb-nav prev" data-nav="-1" aria-label="Previous post"><?= pc_icon('left', 22) ?></button>
  <figure class="pc-lb-stage" id="pcLbStage"></figure>
  <button type="button" class="pc-lb-nav next" data-nav="1" aria-label="Next post"><?= pc_icon('right', 22) ?></button>
  <aside class="pc-lb-panel">
    <div class="pc-owner">
      <span class="pc-av" id="pcLbAv" aria-hidden="true"></span>
      <span class="nm" id="pcLbOwner"></span>
      <span class="pc-date" id="pcLbDate"></span>
    </div>
    <p class="pc-cap" id="pcLbCap"></p>
    <div class="pc-tags" id="pcLbTags"></div>
    <div class="pc-actions">
      <button type="button" class="pc-act" id="pcLbLike" data-like aria-pressed="false" aria-label="Like this post"><?= ja_icon('heart', 17) ?><span class="n">0</span></button>
      <?php require_once __DIR__ . '/ja-social.php'; echo ja_social_assets(); ?>
      <button type="button" class="pc-act" id="pcLbShare" data-soc-open data-kind="post" data-id="0" aria-haspopup="dialog" aria-label="Share this post"><?= ja_social_icon('share', 17) ?><span class="n">Share</span></button>
    </div>
    <?php if ($eml): ?>
    <div class="pc-report"><label class="pc-sr" for="pcLbReport">Report this post</label><select id="pcLbReport" class="ja-report-select"><option value="">Report this post</option><option value="fake">Fake or misleading</option><option value="spam">Spam</option><option value="unsafe">Unsafe or offensive</option><option value="copyright">Not their photo</option></select><span id="pcLbReportMsg" class="pc-muted" role="status"></span></div>
    <?php endif; ?>
    <div class="pc-cmts">
      <div class="pc-cmts-list" id="pcCmtList"></div>
      <?php if ($eml): ?>
      <form class="pc-cmts-form" id="pcCmtForm"><label class="pc-sr" for="pcCmtInput">Add a comment</label><input type="text" id="pcCmtInput" maxlength="600" placeholder="Add a comment" autocomplete="off"><button type="submit">Post</button></form>
      <?php else: ?><a href="login.php" class="pc-link">Log in to like and comment</a><?php endif; ?>
    </div>
  </aside>
  <div class="pc-lb-count" id="pcLbCount" aria-live="polite"></div>
</div>

<script>window.PC_FEED = { loggedIn: <?= $eml ? 'true' : 'false' ?>, csrf: <?= json_encode(ja_csrf()) ?> };</script>
<script src="js/feed.js?v=<?= (int)@filemtime(__DIR__ . '/js/feed.js') ?>" defer></script>

<?php include 'ja-footer.php'; ?>
</body>
</html>
