<?php
$ja_title = "My Storybooks"; $ja_active = "storybooks";
session_start();
require 'connection.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$em = $_SESSION['eml'];

$sql = "SELECT s.book_id, s.eml, s.title, s.subtitle, s.theme, s.cover_img,
          s.visibility, s.share_token, s.page_order, s.created_at, s.updated_at,
          COUNT(sp.page_id) AS page_count
   FROM storybooks s
   LEFT JOIN storybook_pages sp ON sp.book_id = s.book_id
   WHERE s.eml = ?
   GROUP BY s.book_id, s.eml, s.title, s.subtitle, s.theme, s.cover_img,
            s.visibility, s.share_token, s.page_order, s.created_at, s.updated_at
   ORDER BY s.updated_at DESC";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 's', $em);
mysqli_stmt_execute($stmt);
$books = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$bookIds = array_map(function($b){ return (int)$b['book_id']; }, $books);
$firstPhotos = [];
if ($bookIds) {
    $placeholders = implode(',', array_fill(0, count($bookIds), '?'));
    $types = str_repeat('i', count($bookIds));
    $fpSql = "SELECT book_id, MIN(photo_1) AS photo_1 FROM storybook_pages
              WHERE book_id IN ($placeholders) AND photo_1 IS NOT NULL AND photo_1 != ''
              GROUP BY book_id";
    $fpStmt = mysqli_prepare($conn, $fpSql);
    mysqli_stmt_bind_param($fpStmt, $types, ...$bookIds);
    mysqli_stmt_execute($fpStmt);
    $fpResult = mysqli_stmt_get_result($fpStmt);
    while ($row = mysqli_fetch_assoc($fpResult)) {
        $firstPhotos[(int)$row['book_id']] = $row['photo_1'];
    }
    mysqli_stmt_close($fpStmt);
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?>
<link rel="stylesheet" href="css/storybook.css">
</head>
<body class="ja">

<div class="ja-pagehead">
  <div class="ja-container">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px">
      <div>
        <div class="ja-eyebrow">✦ Your travel storybooks</div>
        <h1>My Storybooks</h1>
        <p class="sub"><?= count($books) ?> storybook<?= count($books)===1?'':'s' ?>.</p>
      </div>
      <a href="storybook-create.php" class="ja-btn ja-btn-primary" data-magnetic>+ New Storybook</a>
    </div>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container">
    <?php if (!$books): ?>
      <div class="sb-my-empty" style="border:1px solid var(--sb-border);border-radius:14px;background:var(--sb-surface)">
        <div class="sb-my-empty-icon">📖</div>
        <p class="sb-my-empty-text">You haven't created any storybooks yet.</p>
        <a href="storybook-create.php" class="ja-btn ja-btn-primary">Create your first storybook</a>
      </div>
    <?php else: ?>
      <div class="sb-my-grid">
        <?php foreach ($books as $b):
          $bid = (int)$b['book_id'];
          $cover = $b['cover_img'] ?: ($firstPhotos[$bid] ?? '');
          $vis = $b['visibility'];
          $isPublic = ($vis === 'public');
        ?>
          <div class="sb-my-card">
            <a href="storybook-edit.php?id=<?= $bid ?>" class="sb-my-cover-link">
              <?php if ($cover): ?>
                <img class="sb-my-cover" src="<?= htmlspecialchars($cover) ?>" alt="<?= htmlspecialchars($b['title']) ?>">
              <?php else: ?>
                <div class="sb-my-cover sb-my-cover-placeholder" data-sb-theme="<?= htmlspecialchars($b['theme']) ?>">
                  <span class="sb-my-cover-icon">📖</span>
                  <span class="sb-my-cover-title"><?= htmlspecialchars($b['title']) ?></span>
                </div>
              <?php endif; ?>
            </a>
            <div class="sb-my-info">
              <div class="sb-my-title"><?= htmlspecialchars($b['title']) ?></div>
              <?php if (!empty($b['subtitle'])): ?>
                <div style="font-size:.82rem;color:var(--sb-text-dim);margin-bottom:4px"><?= htmlspecialchars($b['subtitle']) ?></div>
              <?php endif; ?>
              <div class="sb-my-meta">
                <span><?= (int)$b['page_count'] ?> page<?= (int)$b['page_count']===1?'':'s' ?></span>
                <span>·</span>
                <span><?= date('M j, Y', strtotime($b['updated_at'])) ?></span>
              </div>
              <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;align-items:center">
                <span style="font-size:.72rem;padding:2px 8px;border-radius:20px;background:var(--sb-slot-bg);color:var(--sb-accent2)"><?= htmlspecialchars(ucfirst($b['theme'])) ?></span>
                <label class="sb-vis-toggle" title="<?= $isPublic ? 'Public — visible to everyone' : 'Private — only you can see this' ?>">
                  <input type="checkbox" <?= $isPublic ? 'checked' : '' ?>
                    onchange="toggleVis(<?= $bid ?>, this.checked)">
                  <span class="sb-vis-slider"></span>
                  <span class="sb-vis-label"><?= $isPublic ? 'Public' : 'Private' ?></span>
                </label>
              </div>
            </div>
            <div class="sb-my-actions">
              <a href="storybook-edit.php?id=<?= $bid ?>" class="ja-btn ja-btn-ghost" style="padding:7px 14px;font-size:.82rem" onclick="event.stopPropagation()">✏️ Edit</a>
              <a href="storybook-view.php?id=<?= $bid ?>" class="ja-btn ja-btn-ghost" style="padding:7px 14px;font-size:.82rem" target="_blank" onclick="event.stopPropagation()">👁 View</a>
              <a href="storybook-print.php?id=<?= $bid ?>" class="ja-btn ja-btn-ghost" style="padding:7px 14px;font-size:.82rem" target="_blank" onclick="event.stopPropagation()">🖨 Print</a>
              <button class="ja-btn ja-btn-ghost" style="padding:7px 14px;font-size:.82rem;color:var(--ja-coral);border-color:var(--ja-coral)"
                onclick="event.stopPropagation();deleteBook(<?= $bid ?>)">🗑 Delete</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</main>

<script>
function deleteBook(id){
  if(!confirm('Delete this storybook? This cannot be undone.')) return;
  fetch('storybook-api.php?action=delete_book',{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'book_id='+id
  }).then(function(r){return r.json()}).then(function(d){
    if(d.error){alert(d.error);return;}
    location.reload();
  }).catch(function(){alert('Delete failed.');});
}
function toggleVis(id, isPublic){
  var vis = isPublic ? 'public' : 'private';
  fetch('storybook-api.php?action=update_book',{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'book_id='+id+'&visibility='+vis
  }).then(function(r){return r.json()}).then(function(d){
    if(d.error){alert(d.error);location.reload();return;}
    var label = event.target.closest('.sb-vis-toggle').querySelector('.sb-vis-label');
    if(label) label.textContent = isPublic ? 'Public' : 'Private';
    var wrap = event.target.closest('.sb-vis-toggle');
    if(wrap) wrap.title = isPublic ? 'Public — visible to everyone' : 'Private — only you can see this';
  }).catch(function(){});
}
</script>

<style>
.sb-my-cover-link { display:block; text-decoration:none; color:inherit; }
.sb-my-cover-placeholder {
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  gap:8px; min-height:220px;
}
.sb-my-cover-placeholder[data-sb-theme="floral"] { background:#FFF5F7; }
.sb-my-cover-placeholder[data-sb-theme="dark-academia"] { background:#2C2519; color:#E8DCC8; }
.sb-my-cover-placeholder[data-sb-theme="retro-travel"] { background:#F5E6C8; }
.sb-my-cover-placeholder[data-sb-theme="minimal"] { background:#F5F5F5; }
.sb-my-cover-placeholder[data-sb-theme="y2k"] { background:#FFE8F5; }
.sb-my-cover-placeholder[data-sb-theme="moody-film"] { background:#2A2520; color:#D4C8B8; }
.sb-my-cover-placeholder[data-sb-theme="cottagecore"] { background:#F0EDE4; }
.sb-my-cover-icon { font-size:2.4rem; opacity:.5; }
.sb-my-cover-title { font-family:var(--font-display);font-weight:700;font-size:1rem;opacity:.6;max-width:80%;text-align:center }
.sb-my-actions { display:flex; gap:6px; flex-wrap:wrap; padding:0 14px 14px; }

/* visibility toggle */
.sb-vis-toggle {
  display:inline-flex; align-items:center; gap:6px; cursor:pointer; user-select:none;
  font-size:.72rem; position:relative;
}
.sb-vis-toggle input { position:absolute; opacity:0; width:0; height:0; }
.sb-vis-slider {
  width:32px; height:18px; border-radius:100px; background:var(--sb-border);
  position:relative; transition:background .2s;
}
.sb-vis-slider::after {
  content:''; position:absolute; top:2px; left:2px;
  width:14px; height:14px; border-radius:50%; background:#fff;
  transition:transform .2s; box-shadow:0 1px 3px rgba(0,0,0,.2);
}
.sb-vis-toggle input:checked + .sb-vis-slider { background:var(--sb-accent); }
.sb-vis-toggle input:checked + .sb-vis-slider::after { transform:translateX(14px); }
.sb-vis-label { color:var(--sb-text-dim); font-weight:600; }
</style>

<?php include 'ja-footer.php'; ?>
