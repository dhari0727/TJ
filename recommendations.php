<?php
$ja_title = "For You"; $ja_active = "recs";
session_start();
require 'ml_client.php';

if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$eml = $_SESSION['eml'];
$flash = $_SESSION['ja_flash'] ?? null; unset($_SESSION['ja_flash']);

// personalised picks from the user's history — no form, just their taste + CF.
$result = ml_recommend([
    'eml' => $eml,
    'budget' => null, 'duration_days' => 6, 'travel_style' => 'mid-range',
    'interests' => [], 'top_n' => 6,
]);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">

<section class="ja-section" style="padding-top:56px">
  <div class="ja-container">
    <div class="ja-eyebrow reveal">✦ Personalised from your journeys</div>
    <h1 class="reveal" style="font-size:clamp(2.2rem,5vw,3.6rem)">Recommended for you</h1>
    <p class="sub reveal">Based on the trips you've saved and rated, and travellers who share your taste.</p>

    <?php if ($flash): ?><div class="ja-ok reveal">✓ <?= htmlspecialchars($flash) ?></div><?php endif; ?>

    <?php if (!empty($result['__error'])): ?>
      <?= ml_offline_banner($result) ?>
    <?php else: ?>
      <?php require 'ja-cards.php'; ja_render_cards($result['recommendations'] ?? []); ?>
      <div class="reveal" style="margin-top:36px">
        <a href="plan-trip.php" class="ja-btn ja-btn-ghost">Plan a specific trip →</a>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php include 'ja-footer.php'; ?>
