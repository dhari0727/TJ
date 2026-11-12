<?php
/** JourneyAI — Community > Read journals: journals and storybooks people chose to share publicly. */
$ja_title = "Read journals"; $ja_active = "feed"; $ja_ctab = "read";
session_start();
require 'connection.php';
require_once 'ja-icons.php';
require_once __DIR__ . '/ja-lib.php';
require_once __DIR__ . '/ja-community-ui.php';
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1)); $per = 12; $off = ($page - 1) * $per;

$where = "d.visibility = 'public' AND d.moderation_status = 'approved'"; $types = ''; $params = [];
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
        WHERE b.visibility = 'public' AND b.moderation_status = 'approved' ORDER BY b.updated_at DESC LIMIT 6");
while ($bq && ($r = mysqli_fetch_assoc($bq))) $books[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?>
<link rel="stylesheet" href="css/pages-community.css?v=<?= (int)@filemtime(__DIR__ . '/css/pages-community.css') ?>"></head>
<body class="ja">
<div class="ja-pagehead pc-head"><div class="ja-container">
  <h1>Community</h1>
  <p class="sub">Journals and storybooks that travellers chose to share, with real costs and real days.</p>
  <?php include 'ja-community-tabs.php'; ?>
</div></div>

<main class="ja-main"><div class="ja-container">
  <form method="get" class="pc-filterbar" role="search">
    <label class="pc-sr" for="rjq">Search journals</label>
    <input class="ja-input" id="rjq" name="q" placeholder="Search by place, title or story" value="<?= htmlspecialchars($q) ?>">
    <button class="ja-btn ja-btn-primary">Search</button>
  </form>

  <?php if ($books && !$q): ?>
    <div class="pc-section-h"><h2>Storybooks</h2><a class="ja-more pc-link" href="storybook-discover.php">Browse all storybooks</a></div>
    <div class="pc-cards" style="margin-bottom:38px">
      <?php foreach ($books as $b): ?>
        <a class="pc-tile" href="storybook-view.php?id=<?= (int)$b['book_id'] ?>">
          <?php if ($b['cover_img']): ?><div class="cv" style="background-image:url('<?= htmlspecialchars($b['cover_img'], ENT_QUOTES) ?>')" role="img" aria-label="Cover of <?= htmlspecialchars($b['title']) ?>"></div>
          <?php else: ?><div class="cv"><?= pc_icon('book', 34) ?></div><?php endif; ?>
          <div class="tx"><h3><?= htmlspecialchars($b['title']) ?></h3>
            <div class="by">By <?= htmlspecialchars($b['fname'] ?: 'a traveller') ?><?= $b['subtitle'] ? ', ' . htmlspecialchars($b['subtitle']) : '' ?></div></div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="pc-section-h"><h2>Journals</h2><span class="pc-muted"><?= (int)$total ?> shared</span></div>
  <?php if (!$rows): ?>
    <?= pc_empty($q ? 'No journals match that search' : 'No shared journals yet', $q ? 'Try a place name, like Goa or Jaipur, or clear the search.' : 'When travellers share a journal publicly it shows up here. Yours could be the first.',
        !empty($_SESSION['eml']) ? ($q ? ['Clear search', 'read-journals.php'] : ['Write a journal', 'new-entry.php']) : ['Log in to write one', 'login.php'], 'book') ?>
  <?php endif; ?>
  <div class="pc-cards">
    <?php foreach ($rows as $r): $pl = trim(($r['City'] ?? '') . ', ' . ($r['Country'] ?? ''), ', '); ?>
      <a class="pc-tile" href="journal.php?id=<?= (int)$r['entry_id'] ?>">
        <?php if ($r['cover']): ?><div class="cv" style="background-image:url('<?= htmlspecialchars($r['cover'], ENT_QUOTES) ?>')" role="img" aria-label="Photo from <?= htmlspecialchars(ja_title($r['Title'])) ?>"></div>
        <?php else: ?><div class="cv"><?= pc_icon('image', 34) ?></div><?php endif; ?>
        <div class="tx">
          <h3><?= htmlspecialchars(ja_title($r['Title'])) ?></h3>
          <div class="by"><?= htmlspecialchars($pl) ?><?= $pl ? ', by ' : 'By ' ?><?= htmlspecialchars($r['fname'] ?: 'a traveller') ?></div>
          <?php if (trim((string)$r['Description']) !== ''): ?><p><?= htmlspecialchars(mb_strimwidth(preg_replace('/^#{1,3}\s+/m', '', (string)$r['Description']), 0, 160, '…')) ?></p><?php endif; ?>
          <?php if ((float)$r['total'] > 0): ?><span class="tot">&#8377;<?= number_format((float)$r['total']) ?> over <?= (int)$r['days'] ?> days</span><?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php if ($pages > 1): ?><nav class="pc-pager" aria-label="Pages"><?php for ($i = 1; $i <= $pages; $i++): ?><a class="<?= $i === $page ? 'on' : '' ?>" <?= $i === $page ? 'aria-current="page"' : '' ?> href="?<?= htmlspecialchars(http_build_query(['q' => $q, 'p' => $i])) ?>"><?= $i ?></a><?php endfor; ?></nav><?php endif; ?>
</div></main>
<?php include 'ja-footer.php'; ?>
