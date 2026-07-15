<?php
$ja_title = "Dashboard"; $ja_active = "dashboard";
session_start();
require 'connection.php';
require 'ml_client.php';
if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$em = $_SESSION['eml'];
$fname = $_SESSION['fname'] ?? 'Traveller';

// recent journal entries
$stmt = mysqli_prepare($conn, "SELECT entry_id, Title, City, Country, cd FROM db WHERE eml=? ORDER BY entry_id DESC LIMIT 4");
mysqli_stmt_bind_param($stmt,'s',$em); mysqli_stmt_execute($stmt);
$entries = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// saved plans (interactions type=plan/save)
$stmt = mysqli_prepare($conn, "SELECT destination, MAX(created_at) t FROM interactions WHERE eml=? AND interaction_type IN ('plan','save') GROUP BY destination ORDER BY t DESC LIMIT 6");
mysqli_stmt_bind_param($stmt,'s',$em); mysqli_stmt_execute($stmt);
$plans = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// personalised picks (small set)
$reco = ml_recommend(['eml'=>$em,'budget'=>null,'duration_days'=>5,'interests'=>[],'top_n'=>3]);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">

<div class="ja-pagehead">
  <div class="ja-container">
    <div class="ja-eyebrow">✦ Your travel hub</div>
    <h1>Welcome back, <?= htmlspecialchars($fname) ?>.</h1>
    <p class="sub">Plan a new trip, revisit your journals, or see what JourneyAI recommends for you.</p>
  </div>
</div>

<main class="ja-main">
  <div class="ja-container">

    <!-- quick actions -->
    <div class="ja-quick" style="margin-bottom:36px">
      <a href="plan-trip.php"><span class="qi"><?= ja_icon('compass',26) ?></span>Plan a Trip<span class="ja-muted" style="font-weight:400;font-size:.85rem">Get AI recommendations</span></a>
      <a href="new-entry.php"><span class="qi"><?= ja_icon('pen',26) ?></span>New Journal<span class="ja-muted" style="font-weight:400;font-size:.85rem">Record a trip</span></a>
      <a href="my-entries.php"><span class="qi"><?= ja_icon('book',26) ?></span>My Entries<span class="ja-muted" style="font-weight:400;font-size:.85rem"><?= count($entries) ? count($entries).' recent' : 'Start your journal' ?></span></a>
      <a href="analytics.php"><span class="qi"><?= ja_icon('chart',26) ?></span>Analytics<span class="ja-muted" style="font-weight:400;font-size:.85rem">Explore insights</span></a>
    </div>

    <div class="ja-hub-grid">
      <!-- left column -->
      <div>
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:16px">
          <h2 style="font-size:1.5rem;margin:0">Recommended for you</h2>
          <a href="recommendations.php" style="color:var(--ja-teal);font-weight:600;font-size:.9rem">See all →</a>
        </div>
        <?php if (!empty($reco['__error'])): ?>
          <?= ml_offline_banner($reco) ?>
        <?php elseif (!empty($reco['recommendations'])): ?>
          <?php require 'ja-cards.php'; ja_render_cards(array_slice($reco['recommendations'],0,3)); ?>
        <?php else: ?>
          <div class="ja-empty">Add a few journal entries or plan a trip, and personalised picks will appear here.</div>
        <?php endif; ?>

        <div style="display:flex;justify-content:space-between;align-items:baseline;margin:40px 0 16px">
          <h2 style="font-size:1.5rem;margin:0">Recent journal entries</h2>
          <a href="my-entries.php" style="color:var(--ja-teal);font-weight:600;font-size:.9rem">View all →</a>
        </div>
        <?php if ($entries): ?>
          <div class="ja-grid cols-3">
            <?php foreach ($entries as $e): ?>
              <a href="display.php?id=<?= (int)$e['entry_id'] ?>" class="ja-card" style="display:block">
                <h3 style="font-size:1.15rem;margin-bottom:4px"><?= htmlspecialchars($e['Title']) ?></h3>
                <div style="color:var(--text-mut);font-size:.88rem"><?= htmlspecialchars(trim(($e['City']??'').', '.($e['Country']??''),', ')) ?></div>
                <div style="color:var(--text-mut);font-size:.8rem;margin-top:8px"><?= htmlspecialchars($e['cd']) ?></div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="ja-empty">No journal entries yet. <a href="new-entry.php" style="color:var(--ja-teal);font-weight:600">Write your first →</a></div>
        <?php endif; ?>
      </div>

      <!-- right column -->
      <aside>
        <div class="ja-card">
          <h3 style="font-size:1.25rem">Saved plans</h3>
          <?php if ($plans): ?>
            <?php foreach ($plans as $p): ?>
              <div style="display:flex;justify-content:space-between;align-items:center;padding:11px 0;border-bottom:1px solid var(--brd)">
                <span style="font-weight:500"><?= htmlspecialchars(explode(',',$p['destination'])[0]) ?></span>
                <a href="plan-trip.php" style="color:var(--ja-teal);font-size:.85rem">plan →</a>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <p style="color:var(--text-mut);font-size:.9rem">You haven't saved any plans yet. Explore <a href="plan-trip.php" style="color:var(--ja-teal)">recommendations</a> and hit “Save”.</p>
          <?php endif; ?>
        </div>

        <div class="ja-card" style="margin-top:20px;background:var(--grad-hero);border:none;color:#fff">
          <h3 style="color:#fff;font-size:1.2rem">Discover a hidden gem</h3>
          <p style="color:#d7f6fb;font-size:.9rem;margin:8px 0 16px">Let JourneyAI surface an authentic, lesser-known destination that fits you.</p>
          <a href="plan-trip.php" class="ja-btn ja-btn-primary" style="padding:11px 22px">Surprise me →</a>
        </div>
      </aside>
    </div>
  </div>
</main>

<?php include 'ja-footer.php'; ?>
