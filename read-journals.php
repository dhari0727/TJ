<?php
/** JourneyAI — Community > Read journals: journals and storybooks people chose to share publicly. */
$ja_title = "Read journals"; $ja_active = "feed"; $ja_ctab = "read";
session_start();
require 'connection.php';
require_once 'ja-icons.php';
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1)); $per = 12; $off = ($page - 1) * $per;

$where = "d.visibility = 'public'"; $types = ''; $params = [];
if ($q !== '') { $where .= " AND (d.Title LIKE ? OR d.City LIKE ? OR d.Country LIKE ? OR d.Description LIKE ?)"; $like = "%$q%"; $types = 'ssss'; $params = [$like, $like, $like, $like]; }
$cs = mysqli_prepare($conn, "SELECT COUNT(*) FROM db d WHERE $where");
if ($params) mysqli_stmt_bind_param($cs, $types, ...$params);
mysqli_stmt_execute($cs); mysqli_stmt_bind_result($cs, $total); mysqli_stmt_fetch($cs); mysqli_stmt_close($cs);

$ls = mysqli_prepare($conn, "SELECT d.entry_id, d.Title, d.City, d.Country, d.Description, d.published_at, s.fname, s.lname,
        (SELECT m.filepath FROM media m WHERE m.entry_id = d.entry_id AND m.kind = 'photo' ORDER BY m.media_id LIMIT 1) AS cover,
        (SELECT j.true_total FROM journals j WHERE j.entry_id = d.entry_id) AS total,
        (SELECT j.duration_days FROM journals j WHERE j.entry_id = d.entry_id) AS days
        FROM db d LEFT JOIN signup s ON s.eml = d.eml WHERE $where ORDER BY d.published_at DESC, d.entry_id DESC LIMIT $per OFFSET $off");
if ($params) mysqli_stmt_bind_param($ls, $types, ...$params);
mysqli_stmt_execute($ls);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($ls), MYSQLI_ASSOC); mysqli_stmt_close($ls);
$pages = max(1, (int)ceil($total / $per));

$books = [];
$bq = @mysqli_query($conn, "SELECT b.book_id, b.title, b.subtitle, b.theme, b.cover_img, s.fname FROM storybooks b LEFT JOIN signup s ON s.eml = b.eml
        WHERE b.visibility = 'public' ORDER BY b.updated_at DESC LIMIT 6");
while ($bq && ($r = mysqli_fetch_assoc($bq))) $books[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">
<div class="ja-pagehead"><div class="ja-container">
  <div class="ja-eyebrow"><?= ja_icon('book',14) ?> Stories from travellers</div>
  <h1>Community</h1>
  <?php include 'ja-community-tabs.php'; ?>
</div></div>

<main class="ja-main"><div class="ja-container">
  <form method="get" class="ja-filterbar"><input class="ja-input" name="q" placeholder="Search by place, title or story" value="<?= htmlspecialchars($q) ?>"><button class="ja-btn ja-btn-primary">Search</button></form>

  <?php if ($books && !$q): ?>
    <h2 style="font-size:1.4rem;margin:6px 0 12px">Storybooks <a class="ja-more" href="storybook-discover.php">Browse all &rarr;</a></h2>
    <div class="ja-grid cols-3" style="margin-bottom:30px">
      <?php foreach ($books as $b): ?>
        <a class="ja-card" href="storybook-view.php?id=<?= (int)$b['book_id'] ?>" style="text-decoration:none;color:inherit">
          <?php if ($b['cover_img']): ?><div class="ja-jcover" style="background-image:url('<?= htmlspecialchars($b['cover_img']) ?>')"></div><?php endif; ?>
          <h3 style="margin:10px 0 2px;font-size:1.1rem"><?= htmlspecialchars($b['title']) ?></h3>
          <div class="ja-muted" style="font-size:.85rem">by <?= htmlspecialchars($b['fname'] ?: 'a traveller') ?><?= $b['subtitle'] ? ' &middot; ' . htmlspecialchars($b['subtitle']) : '' ?></div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2 style="font-size:1.4rem;margin:6px 0 12px">Journals <span class="ja-muted" style="font-size:1rem">(<?= (int)$total ?>)</span></h2>
  <?php if (!$rows): ?>
    <div class="ja-empty" style="padding:50px 26px"><p style="margin:0 0 12px">No shared journals<?= $q ? ' match that search' : ' yet' ?>.</p>
      <?php if (!empty($_SESSION['eml'])): ?><a href="my-entries.php" class="ja-btn ja-btn-primary">Share one of yours</a><?php endif; ?></div>
  <?php endif; ?>
  <div class="ja-grid cols-3">
    <?php foreach ($rows as $r): ?>
      <a class="ja-card ja-jcard" href="journal.php?id=<?= (int)$r['entry_id'] ?>" style="text-decoration:none;color:inherit">
        <?php if ($r['cover']): ?><div class="ja-jcover" style="background-image:url('<?= htmlspecialchars($r['cover']) ?>')"></div><?php endif; ?>
        <h3 style="margin:10px 0 2px;font-size:1.15rem"><?= htmlspecialchars($r['Title']) ?></h3>
        <div class="ja-muted" style="font-size:.86rem"><?= htmlspecialchars(trim(($r['City'] ?? '') . ', ' . ($r['Country'] ?? ''), ', ')) ?> &middot; by <?= htmlspecialchars($r['fname'] ?: 'a traveller') ?></div>
        <p style="color:var(--text-dim);font-size:.9rem;margin:8px 0"><?= htmlspecialchars(mb_strimwidth((string)$r['Description'], 0, 140, '…')) ?></p>
        <?php if ((float)$r['total'] > 0): ?><span class="ja-pill near">&#8377;<?= number_format((float)$r['total']) ?> &middot; <?= (int)$r['days'] ?> days</span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php if ($pages > 1): ?><div class="ja-tabs" style="margin-top:20px"><?php for ($i = 1; $i <= $pages; $i++): ?><a class="<?= $i === $page ? 'on' : '' ?>" href="?<?= htmlspecialchars(http_build_query(['q' => $q, 'p' => $i])) ?>"><?= $i ?></a><?php endfor; ?></div><?php endif; ?>
</div></main>
<?php include 'ja-footer.php'; ?>
