<?php
$ja_title = "My Entries"; $ja_active = "entries";
session_start();
require 'connection.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$em = $_SESSION['eml'];

$stmt = mysqli_prepare($conn,
  "SELECT entry_id, Title, City, Country, cd, duration_days, true_total
   FROM journals WHERE eml = ? ORDER BY entry_id DESC");
mysqli_stmt_bind_param($stmt, 's', $em);
mysqli_stmt_execute($stmt);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">

<div class="ja-pagehead">
  <div class="ja-container">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px">
      <div>
        <div class="ja-eyebrow">✦ Your travel journal</div>
        <h1>My Entries</h1>
        <p class="sub"><?= count($rows) ?> trip<?= count($rows)===1?'':'s' ?> recorded.</p>
      </div>
      <a href="new-entry.php" class="ja-btn ja-btn-primary" data-magnetic>+ New entry</a>
    </div>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container">
    <?php if (!$rows): ?>
      <div class="ja-empty" style="padding:60px 30px">
        <div style="margin-bottom:12px;color:var(--ja-aqua)"><?= ja_icon('book',40) ?></div>
        <p style="font-size:1.1rem;color:var(--text-dim)">You haven't recorded any trips yet.</p>
        <a href="new-entry.php" class="ja-btn ja-btn-primary" style="margin-top:16px">Create your first entry</a>
      </div>
    <?php else: ?>
      <div class="ja-grid cols-3">
        <?php foreach ($rows as $r): $city = htmlspecialchars($r['City'] ?: $r['Title']); ?>
          <div class="ja-card" data-tilt>
            <div style="display:flex;justify-content:space-between;align-items:start;gap:10px">
              <div>
                <h3 style="margin-bottom:2px;font-size:1.2rem"><?= htmlspecialchars($r['Title']) ?></h3>
                <div style="color:var(--text-mut);font-size:.88rem"><?= $city ?><?= $r['Country']?', '.htmlspecialchars($r['Country']):'' ?></div>
              </div>
              <span class="ja-pill near"><?= (int)$r['duration_days'] ?>d</span>
            </div>
            <div class="ja-cost" style="font-size:1.5rem;margin:16px 0 2px">₹<?= number_format((float)$r['true_total']) ?></div>
            <div style="color:var(--text-mut);font-size:.82rem;margin-bottom:18px">Total trip cost</div>
            <div style="display:flex;gap:8px">
              <a href="display.php?id=<?= (int)$r['entry_id'] ?>" class="ja-btn ja-btn-ghost" style="padding:9px 16px;font-size:.9rem">View</a>
              <a href="update.php?id=<?= (int)$r['entry_id'] ?>" class="ja-btn ja-btn-ghost" style="padding:9px 16px;font-size:.9rem">Edit</a>
              <a href="delete.php?id=<?= (int)$r['entry_id'] ?>" class="ja-btn ja-btn-ghost" style="padding:9px 16px;font-size:.9rem;color:var(--ja-coral)"
                 onclick="return confirm('Delete this entry?')">Delete</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</main>

<?php include 'ja-footer.php'; ?>
