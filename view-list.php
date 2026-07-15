<?php
$ja_title = "All Entries"; $ja_active = "entries";
session_start();
require 'connection.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$em = $_SESSION['eml'];

// all of this user's journal entries via the normalized view (prepared)
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
<section class="ja-section" style="padding-top:56px">
  <div class="ja-container">
    <div class="ja-eyebrow reveal">✦ Your travel journal</div>
    <h1 class="reveal" style="font-size:clamp(2.2rem,5vw,3.4rem)">My Entries</h1>
    <p class="sub reveal"><?= count($rows) ?> trip<?= count($rows)===1?'':'s' ?> recorded.
      <a href="dashboard.php" style="color:var(--ja-teal);font-weight:600">+ New entry</a></p>

    <?php if (!$rows): ?>
      <div class="ja-card reveal">You haven't recorded any trips yet.
        <a href="dashboard.php" class="ja-btn ja-btn-primary" style="margin-top:14px">Create your first entry</a></div>
    <?php else: ?>
      <div class="ja-grid cols-3 stagger">
        <?php foreach ($rows as $r): $city = htmlspecialchars($r['City'] ?: $r['Title']); ?>
          <div class="ja-card" data-tilt>
            <div style="display:flex;justify-content:space-between;align-items:start;gap:10px">
              <div>
                <h3 style="margin-bottom:2px"><?= htmlspecialchars($r['Title']) ?></h3>
                <div style="color:var(--text-mut);font-size:.88rem"><?= $city ?><?= $r['Country']?', '.htmlspecialchars($r['Country']):'' ?></div>
              </div>
              <span class="ja-fit within"><?= (int)$r['duration_days'] ?>d</span>
            </div>
            <div class="ja-cost" style="font-size:1.4rem;margin:14px 0 4px">₹<?= number_format((float)$r['true_total']) ?></div>
            <div style="color:var(--text-mut);font-size:.82rem;margin-bottom:16px">Total trip cost</div>
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
</section>
<footer class="ja-footer"><div class="ja-container">JourneyAI</div></footer>
</body>
</html>
