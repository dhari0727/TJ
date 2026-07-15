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
error_reporting(0);
$ja_title = "Discover Feed"; $ja_active = "feed";
session_start();
require 'connection.php';
require_once 'ja-media.php';
require_once 'ja-icons.php';

$eml = $_SESSION['eml'] ?? null;

$tagFilter  = trim($_GET['tag'] ?? '');
$destFilter = trim($_GET['dest'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 24;
$offset     = ($page - 1) * $perPage;

// ---- build the feed query (prepared) ----
$where  = "m.is_public = 1";
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
$ownerNames = [];
if ($ownerEmails) {
    $in = implode(',', array_fill(0, count($ownerEmails), '?'));
    $types3 = str_repeat('s', count($ownerEmails));
    $s3 = mysqli_prepare($conn, "SELECT eml, fname FROM signup WHERE eml IN ($in)");
    $refs3 = [];
    foreach ($ownerEmails as $k => $v) { $refs3[$k] = &$ownerEmails[$k]; }
    array_unshift($refs3, $types3);
    call_user_func_array('mysqli_stmt_bind_param', array_merge([$s3], $refs3));
    mysqli_stmt_execute($s3);
    $ores = mysqli_stmt_get_result($s3);
    while ($row = mysqli_fetch_assoc($ores)) {
        $ownerNames[$row['eml']] = $row['fname'];
    }
    mysqli_stmt_close($s3);
}

function ja_feed_owner_label($eml, $ownerNames) {
    if (!empty($ownerNames[$eml])) return $ownerNames[$eml];
    $prefix = explode('@', $eml)[0];
    return $prefix ?: 'traveler';
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

// trending hashtags
$trend = mysqli_query($conn, "SELECT tag, uses FROM hashtags ORDER BY uses DESC LIMIT 10");
$trending = $trend ? mysqli_fetch_all($trend, MYSQLI_ASSOC) : [];

$hasMore = count($posts) === $perPage;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include 'ja-head.php'; ?>
<link rel="stylesheet" href="css/journeyai-feed.css">
</head>
<body class="ja">

<div class="ja-pagehead">
  <div class="ja-container">
    <div class="ja-eyebrow"><?= ja_icon('sparkle',14) ?> From real travelers</div>
    <h1>Discover Feed</h1>
    <p class="sub">Photos and moments shared by the JourneyAI community<?= $tagFilter ? ' · tagged #' . htmlspecialchars($tagFilter) : '' ?><?= $destFilter ? ' · in ' . htmlspecialchars($destFilter) : '' ?>.</p>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container">

    <form method="get" action="feed.php" class="ja-feed-searchbar">
      <?= ja_icon('search',18) ?>
      <input type="text" name="tag" placeholder="Search by hashtag, e.g. beach" value="<?= htmlspecialchars($tagFilter) ?>">
      <button type="submit" class="ja-btn ja-btn-primary">Search</button>
      <?php if ($tagFilter || $destFilter): ?>
        <a href="feed.php" class="ja-btn ja-btn-ghost">Clear</a>
      <?php endif; ?>
    </form>

    <?php if ($trending): ?>
    <div class="ja-feed-trending">
      <span class="ja-feed-trending-label"><?= ja_icon('chart',14) ?> Trending:</span>
      <?php foreach ($trending as $t): ?>
        <a href="feed.php?tag=<?= urlencode($t['tag']) ?>"
           class="ja-chip ja-feed-tagchip <?= ($tagFilter === $t['tag']) ? 'on' : '' ?>">#<?= htmlspecialchars($t['tag']) ?> <small><?= (int)$t['uses'] ?></small></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!$posts): ?>
      <div class="ja-empty" style="padding:60px 30px">
        <div style="color:var(--ja-aqua);margin-bottom:12px"><?= ja_icon('sparkle',40) ?></div>
        <p style="font-size:1.1rem;color:var(--text-dim)">No posts yet<?= ($tagFilter||$destFilter) ? ' for this filter' : '' ?>.</p>
        <?php if ($eml): ?><a href="new-entry.php" class="ja-btn ja-btn-primary" style="margin-top:14px">Share a photo</a><?php endif; ?>
      </div>
    <?php else: ?>
      <div class="ja-feed-grid" id="jaFeedGrid">
        <?php foreach ($posts as $i => $m):
            $label = ja_feed_owner_label($m['eml'], $ownerNames);
            $tags  = $tagsByMedia[$m['media_id']] ?? [];
            $liked = !empty($m['liked']);
            $capJson = htmlspecialchars(json_encode([
                'id' => (int)$m['media_id'],
                'src' => $m['filepath'],
                'kind' => $m['kind'],
                'caption' => $m['caption'],
                'destination' => $m['destination'],
                'owner' => $label,
                'likes' => (int)$m['likes'],
                'liked' => $liked,
                'tags' => $tags,
                'created' => $m['created_at'],
            ]), ENT_QUOTES);
        ?>
        <article class="ja-feed-card reveal" data-tilt style="transition-delay:<?= min($i,12)*0.05 ?>s"
                 data-post="<?= $capJson ?>" data-idx="<?= $i ?>">
          <div class="ja-feed-media">
            <?php if ($m['kind'] === 'video'): ?>
              <video src="<?= htmlspecialchars($m['filepath']) ?>" muted loop playsinline preload="metadata"
                     class="ja-feed-video" onmouseenter="this.play().catch(()=>{})" onmouseleave="this.pause();this.currentTime=0;"></video>
              <div class="ja-feed-playicon"><?= ja_icon('arrow',18) ?></div>
              <span class="ja-feed-kindbadge">Video</span>
            <?php else: ?>
              <img src="<?= htmlspecialchars($m['filepath']) ?>" alt="<?= htmlspecialchars($m['caption'] ?? '') ?>" loading="lazy">
            <?php endif; ?>
            <?php if ($m['destination']): ?>
              <a class="ja-feed-desttag" href="feed.php?dest=<?= urlencode($m['destination']) ?>"><?= ja_icon('map-pin',12) ?> <?= htmlspecialchars(explode(',', $m['destination'])[0]) ?></a>
            <?php endif; ?>
          </div>
          <div class="ja-feed-body">
            <div class="ja-feed-owner">
              <span class="ja-feed-avatar"><?= htmlspecialchars(ja_feed_initials($label)) ?></span>
              <span class="ja-feed-ownername"><?= htmlspecialchars($label) ?></span>
              <span class="ja-feed-date"><?= htmlspecialchars(date('M j', strtotime($m['created_at']))) ?></span>
            </div>
            <?php if ($m['caption']): ?><p class="ja-feed-caption"><?= htmlspecialchars($m['caption']) ?></p><?php endif; ?>
            <?php if ($tags): ?>
            <div class="ja-feed-tags">
              <?php foreach ($tags as $t): ?><a href="feed.php?tag=<?= urlencode($t) ?>" class="ja-feed-tagchip-sm">#<?= htmlspecialchars($t) ?></a><?php endforeach; ?>
            </div>
            <?php endif; ?>
            <button type="button" class="ja-feed-likebtn <?= $liked ? 'liked' : '' ?>" data-media-id="<?= (int)$m['media_id'] ?>" onclick="jaFeedToggleLike(event,this)">
              <?= ja_icon('heart',17) ?> <span class="ja-feed-likecount"><?= (int)$m['likes'] ?></span>
            </button>
          </div>
        </article>
        <?php endforeach; ?>
      </div>

      <div class="ja-feed-pagination">
        <?php if ($page > 1): ?>
          <a class="ja-btn ja-btn-ghost" href="feed.php?<?= http_build_query(array_filter(['tag'=>$tagFilter,'dest'=>$destFilter,'page'=>$page-1])) ?>">← Newer</a>
        <?php endif; ?>
        <?php if ($hasMore): ?>
          <a class="ja-btn ja-btn-primary" href="feed.php?<?= http_build_query(array_filter(['tag'=>$tagFilter,'dest'=>$destFilter,'page'=>$page+1])) ?>">Load more</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </div>
</main>

<!-- reel-style lightbox viewer -->
<div class="ja-feed-lightbox" id="jaFeedLightbox" aria-hidden="true">
  <div class="ja-feed-lightbox-inner">
    <button type="button" class="ja-feed-lb-close" onclick="jaFeedCloseLightbox()">&times;</button>
    <button type="button" class="ja-feed-lb-nav prev" onclick="jaFeedNav(-1)">&#8249;</button>
    <div class="ja-feed-lb-media" id="jaFeedLbMedia"></div>
    <button type="button" class="ja-feed-lb-nav next" onclick="jaFeedNav(1)">&#8250;</button>
    <div class="ja-feed-lb-info">
      <div class="ja-feed-owner">
        <span class="ja-feed-avatar" id="jaFeedLbAvatar"></span>
        <span class="ja-feed-ownername" id="jaFeedLbOwner"></span>
        <span class="ja-feed-date" id="jaFeedLbDate"></span>
      </div>
      <p class="ja-feed-caption" id="jaFeedLbCaption"></p>
      <div class="ja-feed-tags" id="jaFeedLbTags"></div>
      <button type="button" class="ja-feed-likebtn" id="jaFeedLbLike" onclick="jaFeedToggleLike(event,this)">
        <?= ja_icon('heart',18) ?> <span class="ja-feed-likecount" id="jaFeedLbLikeCount">0</span>
      </button>
    </div>
  </div>
</div>

<script>
var JA_FEED_LOGGED_IN = <?= $eml ? 'true' : 'false' ?>;
var jaFeedPosts = [];
var jaFeedIdx = 0;

document.addEventListener('DOMContentLoaded', function () {
  var cards = document.querySelectorAll('.ja-feed-card[data-post]');
  cards.forEach(function (card, i) {
    try { jaFeedPosts[i] = JSON.parse(card.getAttribute('data-post')); } catch (e) {}
    card.querySelector('.ja-feed-media').addEventListener('click', function () {
      jaFeedOpenLightbox(i);
    });
  });
});

function jaFeedInitials(label) {
  label = (label || '').trim();
  if (!label) return '?';
  var parts = label.split(/\s+/);
  if (parts.length > 1) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  return label.substring(0, 2).toUpperCase();
}

function jaFeedRenderLightbox() {
  var p = jaFeedPosts[jaFeedIdx];
  if (!p) return;
  var mediaEl = document.getElementById('jaFeedLbMedia');
  if (p.kind === 'video') {
    mediaEl.innerHTML = '<video src="' + p.src + '" controls autoplay loop playsinline></video>';
  } else {
    mediaEl.innerHTML = '<img src="' + p.src + '" alt="">';
  }
  document.getElementById('jaFeedLbAvatar').textContent = jaFeedInitials(p.owner);
  document.getElementById('jaFeedLbOwner').textContent = p.owner;
  document.getElementById('jaFeedLbDate').textContent = p.created ? new Date(p.created.replace(' ', 'T')).toLocaleDateString('en-US', {month:'short', day:'numeric'}) : '';
  document.getElementById('jaFeedLbCaption').textContent = p.caption || '';
  var tagsEl = document.getElementById('jaFeedLbTags');
  tagsEl.innerHTML = '';
  (p.tags || []).forEach(function (t) {
    var a = document.createElement('a');
    a.href = 'feed.php?tag=' + encodeURIComponent(t);
    a.className = 'ja-feed-tagchip-sm';
    a.textContent = '#' + t;
    tagsEl.appendChild(a);
  });
  var likeBtn = document.getElementById('jaFeedLbLike');
  likeBtn.dataset.mediaId = p.id;
  likeBtn.classList.toggle('liked', !!p.liked);
  document.getElementById('jaFeedLbLikeCount').textContent = p.likes;
}

function jaFeedOpenLightbox(i) {
  jaFeedIdx = i;
  jaFeedRenderLightbox();
  var lb = document.getElementById('jaFeedLightbox');
  lb.classList.add('open');
  lb.setAttribute('aria-hidden', 'false');
  document.body.style.overflow = 'hidden';
}
function jaFeedCloseLightbox() {
  var lb = document.getElementById('jaFeedLightbox');
  lb.classList.remove('open');
  lb.setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
  var v = lb.querySelector('video');
  if (v) v.pause();
}
function jaFeedNav(dir) {
  jaFeedIdx = (jaFeedIdx + dir + jaFeedPosts.length) % jaFeedPosts.length;
  jaFeedRenderLightbox();
}
document.addEventListener('keydown', function (e) {
  var lb = document.getElementById('jaFeedLightbox');
  if (!lb.classList.contains('open')) return;
  if (e.key === 'Escape') jaFeedCloseLightbox();
  if (e.key === 'ArrowRight') jaFeedNav(1);
  if (e.key === 'ArrowLeft') jaFeedNav(-1);
});
document.getElementById('jaFeedLightbox').addEventListener('click', function (e) {
  if (e.target === this) jaFeedCloseLightbox();
});

function jaFeedToggleLike(evt, btn) {
  evt.stopPropagation();
  if (!JA_FEED_LOGGED_IN) {
    window.location.href = 'login.php';
    return;
  }
  var mediaId = btn.dataset.mediaId;
  fetch('media-like.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'media_id=' + encodeURIComponent(mediaId)
  }).then(function (r) { return r.json(); }).then(function (data) {
    if (data.error) { if (data.error.toLowerCase().indexOf('log in') !== -1) window.location.href = 'login.php'; return; }
    // update every button/card referencing this media id (grid + lightbox)
    document.querySelectorAll('[data-media-id="' + mediaId + '"]').forEach(function (el) {
      el.classList.toggle('liked', !!data.liked);
      var c = el.querySelector('.ja-feed-likecount');
      if (c) c.textContent = data.likes;
    });
    jaFeedPosts.forEach(function (p) {
      if (String(p.id) === String(mediaId)) { p.liked = data.liked; p.likes = data.likes; }
    });
  }).catch(function () {});
}
</script>

<?php include 'ja-footer.php'; ?>
</body>
</html>
