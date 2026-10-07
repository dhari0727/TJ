<?php
$ja_title = "Discover Storybooks"; $ja_active = "feed";
session_start();
require 'connection.php';

$themeFilter = trim($_GET['theme'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

$where = "s.visibility = 'public'";
$types = "";
$params = [];

if ($themeFilter !== '') {
    $where .= " AND s.theme = ?";
    $types .= "s";
    $params[] = $themeFilter;
}

$countSql = "SELECT COUNT(*) AS total FROM storybooks s WHERE $where";
$countStmt = mysqli_prepare($conn, $countSql);
if ($params) {
    $refs = [];
    foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
    array_unshift($refs, $types);
    call_user_func_array('mysqli_stmt_bind_param', array_merge([$countStmt], $refs));
}
mysqli_stmt_execute($countStmt);
$total = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['total'];
mysqli_stmt_close($countStmt);
$totalPages = max(1, (int)ceil($total / $perPage));

$sql = "SELECT s.book_id, s.eml, s.title, s.subtitle, s.theme, s.cover_img,
               s.visibility, s.share_token, s.page_order, s.created_at, s.updated_at,
               u.fname, u.lname, COUNT(sp.page_id) AS page_count
        FROM storybooks s
        JOIN signup u ON u.eml = s.eml
        LEFT JOIN storybook_pages sp ON sp.book_id = s.book_id
        WHERE $where
        GROUP BY s.book_id, s.eml, s.title, s.subtitle, s.theme, s.cover_img,
                 s.visibility, s.share_token, s.page_order, s.created_at, s.updated_at,
                 u.fname, u.lname
        ORDER BY s.updated_at DESC
        LIMIT ? OFFSET ?";

$stmt = mysqli_prepare($conn, $sql);
$bindTypes = $types . "ii";
$bindVals = $params;
$bindVals[] = $perPage;
$bindVals[] = $offset;
$refs = [];
foreach ($bindVals as $k => $v) { $refs[$k] = &$bindVals[$k]; }
array_unshift($refs, $bindTypes);
call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt], $refs));
mysqli_stmt_execute($stmt);
$books = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$themesRes = mysqli_query($conn, "SELECT DISTINCT theme FROM storybooks WHERE visibility = 'public' AND theme IS NOT NULL AND theme != '' ORDER BY theme");
$themes = [];
if ($themesRes) {
    while ($row = mysqli_fetch_assoc($themesRes)) {
        $themes[] = $row['theme'];
    }
}

function sb_initials($fname, $lname) {
    $i = strtoupper(substr($fname, 0, 1));
    if ($lname) $i .= strtoupper(substr($lname, 0, 1));
    return $i;
}

function sb_theme_color($theme) {
    $map = [
        'floral' => '#D4758C',
        'dark-academia' => '#C9A96E',
        'retro-travel' => '#D35400',
        'minimal' => '#1A1A1A',
        'y2k' => '#FF2D9B',
        'moody-film' => '#C4956A',
        'cottagecore' => '#5E8B5A',
    ];
    return $map[$theme] ?? '#8C7A80';
}

function sb_cover_url($book) {
    if (!empty($book['cover_img'])) return $book['cover_img'];
    global $conn;
    $res = @mysqli_query($conn,
        "SELECT photo_1 FROM storybook_pages WHERE book_id = " . (int)$book['book_id'] . " AND photo_1 IS NOT NULL AND photo_1 != '' ORDER BY page_order ASC LIMIT 1");
    if ($res && $row = mysqli_fetch_assoc($res)) return $row['photo_1'];
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include 'ja-head.php'; ?>
<link rel="stylesheet" href="css/storybook.css">
</head>
<body class="ja">

<div class="ja-pagehead">
  <div class="ja-container">
    <div class="ja-eyebrow">Browse</div>
    <h1>Discover Storybooks</h1>
    <p class="sub">Travel journals from other adventurers</p>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container">

    <?php if ($themes): ?>
    <div class="ja-chips" style="overflow-x:auto;white-space:nowrap;padding:4px 0 16px;-webkit-overflow-scrolling:touch">
      <a href="storybook-discover.php" class="ja-chip <?= $themeFilter === '' ? 'on' : '' ?>">All</a>
      <?php foreach ($themes as $t): ?>
        <a href="storybook-discover.php?theme=<?= urlencode($t) ?>" class="ja-chip <?= $themeFilter === $t ? 'on' : '' ?>"><?= htmlspecialchars(ucwords(str_replace('-', ' ', $t))) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!$books): ?>
      <div class="sb-my-empty">
        <div class="sb-my-empty-icon">📖</div>
        <div class="sb-my-empty-text">No storybooks found<?= $themeFilter ? ' for this theme' : '' ?> yet.</div>
      </div>
    <?php else: ?>
      <div class="sb-discover-grid">
        <?php foreach ($books as $i => $b):
          $cover = sb_cover_url($b);
          $ownerLabel = htmlspecialchars(trim($b['fname'] . ' ' . $b['lname']));
          $tColor = sb_theme_color($b['theme']);
        ?>
        <a href="storybook-view.php?id=<?= (int)$b['book_id'] ?>" class="sb-discover-card" style="text-decoration:none;color:inherit;transition-delay:<?= min($i, 11) * 0.04 ?>s">
          <?php if ($cover): ?>
            <img class="sb-discover-cover" src="<?= htmlspecialchars($cover) ?>" alt="<?= htmlspecialchars($b['title']) ?>" loading="lazy">
          <?php else: ?>
            <div class="sb-discover-cover" style="display:flex;align-items:center;justify-content:center;background:<?= $tColor ?>22;color:<?= $tColor ?>;font-size:2.4rem;font-family:var(--sb-font-display)">📖</div>
          <?php endif; ?>
          <div class="sb-discover-info">
            <div class="sb-discover-title"><?= htmlspecialchars($b['title']) ?></div>
            <div class="sb-discover-meta">
              <span class="sb-theme-badge" style="border-color:<?= $tColor ?>44;background:<?= $tColor ?>11;color:<?= $tColor ?>"><?= htmlspecialchars(ucwords(str_replace('-', ' ', $b['theme'] ?? ''))) ?></span>
              <span><?= $b['page_count'] ?> page<?= $b['page_count'] != 1 ? 's' : '' ?></span>
              <span>·</span>
              <span><?= htmlspecialchars($ownerLabel) ?></span>
            </div>
            <div class="sb-discover-meta" style="margin-top:4px">
              <span>Updated <?= htmlspecialchars(date('M j, Y', strtotime($b['updated_at']))) ?></span>
            </div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>

      <?php if ($totalPages > 1): ?>
      <div style="display:flex;align-items:center;justify-content:center;gap:12px;padding:24px 0 40px">
        <?php if ($page > 1): ?>
          <a class="ja-btn ja-btn-ghost" href="storybook-discover.php?<?= http_build_query(array_filter(['theme' => $themeFilter, 'page' => $page - 1])) ?>">← Previous</a>
        <?php endif; ?>
        <span style="font-size:.85rem;color:var(--text-dim)">Page <?= $page ?> of <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
          <a class="ja-btn ja-btn-primary" href="storybook-discover.php?<?= http_build_query(array_filter(['theme' => $themeFilter, 'page' => $page + 1])) ?>">Next →</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>

  </div>
</main>

<?php include 'ja-footer.php'; ?>
</body>
</html>
