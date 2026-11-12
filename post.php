<?php
/**
 * JourneyAI - one public community photo (the landing page for shared links). Only public + approved posts exist here.
 */
$ja_title = "Photo"; $ja_active = "feed";
session_start();
require 'connection.php';
require_once 'ja-lib.php';
require_once 'ja-social.php';
$me = $_SESSION['eml'] ?? null;
$id = (int)($_GET['id'] ?? 0);
$st = mysqli_prepare($conn, "SELECT m.media_id, m.eml, m.kind, m.filepath, m.caption, m.destination, m.created_at, s.fname, s.lname
    FROM media m LEFT JOIN signup s ON s.eml = m.eml WHERE m.media_id = ? AND m.is_public = 1 AND m.moderation_status = 'approved'");
mysqli_stmt_bind_param($st, 'i', $id); mysqli_stmt_execute($st);
$m = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
if (!$m) http_response_code(404);
$cap = $m ? trim((string)$m['caption']) : '';
$who = $m ? trim(($m['fname'] ?: 'A traveller') . ' ' . mb_substr((string)$m['lname'], 0, 1) . '.') : '';
$handle = $m ? ja_ensure_handle($m['eml']) : null;
$ja_css_extra = ['pages-community.css'];
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?>
<?php if ($m): ja_social_og(['title' => $cap !== '' ? ja_social_excerpt($cap, 70) : ($m['destination'] ? 'A photo from ' . explode(',', $m['destination'])[0] : 'A travel photo'),
        'description' => $cap !== '' ? $cap : 'A photo shared by a traveller on JourneyAI.', 'image' => $m['kind'] === 'photo' ? $m['filepath'] : '', 'url' => 'post.php?id=' . $id, 'type' => 'website']); endif; ?>
<style>.po{max-width:760px;margin:0 auto;padding:24px 16px 56px}.po figure{margin:0 0 16px;border-radius:var(--radius-lg);overflow:hidden;background:var(--surface);border:1px solid var(--hair)}.po img,.po video{display:block;width:100%;height:auto;max-height:78vh;object-fit:contain}.po h1{font-family:var(--font-display);font-size:clamp(1.4rem,3.4vw,2rem);margin:0 0 6px}.po .by{color:var(--text-dim);margin:0 0 16px}.po .row{display:flex;gap:10px;flex-wrap:wrap}</style>
</head>
<body class="ja">
<main class="ja-main po" id="main">
<?php if (!$m): ?>
  <h1>This photo isn't available</h1><p>It may have been removed or made private.</p><p><a class="ja-btn ja-btn-primary" href="feed.php">Back to the community</a></p>
<?php else: ?>
  <figure><?php if ($m['kind'] === 'video'): ?><video src="<?= htmlspecialchars($m['filepath']) ?>" controls playsinline preload="metadata"></video>
    <?php else: ?><img src="<?= htmlspecialchars($m['filepath']) ?>" alt="<?= htmlspecialchars($cap !== '' ? ja_social_excerpt($cap, 120) : 'Photo shared by ' . $who) ?>"><?php endif; ?></figure>
  <?php if ($cap !== ''): ?><h1><?= htmlspecialchars($cap) ?></h1><?php endif; ?>
  <p class="by">By <?= $handle ? '<a href="traveller.php?h=' . urlencode($handle) . '">' . htmlspecialchars($who) . '</a>' : htmlspecialchars($who) ?>
    <?= $m['destination'] ? ' in <a href="feed.php?dest=' . urlencode($m['destination']) . '">' . htmlspecialchars($m['destination']) . '</a>' : '' ?>, <?= htmlspecialchars(date('j M Y', strtotime($m['created_at']))) ?></p>
  <div class="row"><a class="ja-btn ja-btn-primary" href="feed.php">See more from travellers</a><?= ja_social_button('post', $id) ?></div>
<?php endif; ?>
</main>
<?php include 'ja-footer.php'; ?>
</body>
</html>
