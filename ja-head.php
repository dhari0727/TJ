<?php
/**
 * JourneyAI — shared <head> + top navbar partial.
 * Include at the top of every JourneyAI page AFTER opening <html>.
 *
 * Set $ja_title and $ja_active before including, e.g.:
 *   $ja_title = "Plan a Trip"; $ja_active = "plan"; include 'ja-head.php';
 */
if (!isset($ja_title))  $ja_title = "JourneyAI";
if (!isset($ja_active)) $ja_active = "";
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/ja-icons.php';
$ja_logged_in = isset($_SESSION['eml']);
function ja_nav_class($k, $active){ return $k === $active ? 'active' : ''; }
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($ja_title) ?> — JourneyAI</title>
<meta name="description" content="JourneyAI — AI-powered, budget-aware, explainable travel recommendations from real travel journals.">

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&family=Bagel+Fat+One&display=swap" rel="stylesheet">

<!-- JourneyAI design system -->
<link rel="stylesheet" href="css/journeyai.css">

<!-- Motion libs (progressive enhancement; page works if these fail) -->
<script src="https://cdn.jsdelivr.net/gh/studio-freight/lenis@1.0.42/dist/lenis.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
<script src="js/journeyai.js" defer></script>

<?php // ---- render the aurora background + navbar ---- ?>
<div class="ja-aurora"></div>
<div class="ja-grain"></div>

<nav class="ja-nav<?= !empty($ja_nav_overlay) ? ' overlay' : '' ?>" id="jaNav">
  <div class="inner">
    <a class="ja-brand" href="<?= $ja_logged_in ? 'dashboard.php' : 'index.php' ?>">
      <img src="images/journeyai-logo.svg" class="ja-logo-mark" alt=""> JourneyAI
    </a>
    <div class="ja-navlinks">
      <?php if ($ja_logged_in): ?>
        <a href="dashboard.php" class="<?= ja_nav_class('dashboard',$ja_active) ?>">Dashboard</a>
        <a href="plan-trip.php" class="<?= ja_nav_class('plan',$ja_active) ?>">Plan a Trip</a>
        <a href="recommendations.php" class="<?= ja_nav_class('recs',$ja_active) ?>">For You</a>
        <a href="my-entries.php" class="<?= ja_nav_class('entries',$ja_active) ?>">My Entries</a>
        <a href="analytics.php" class="<?= ja_nav_class('analytics',$ja_active) ?>">Analytics</a>
        <a href="new-entry.php" class="ja-btn ja-btn-primary" style="padding:9px 18px;font-size:.9rem">+ New</a>
        <a href="profile.php" class="ja-theme-toggle" title="Profile" style="text-decoration:none"><?= ja_icon('user',18) ?></a>
        <a href="login.php?logout=1" class="ja-theme-toggle" title="Logout" style="text-decoration:none"><?= ja_icon('logout',18) ?></a>
      <?php else: ?>
        <a href="index.php" class="<?= ja_nav_class('home',$ja_active) ?>">Home</a>
        <a href="plan-trip.php" class="<?= ja_nav_class('plan',$ja_active) ?>">Plan a Trip</a>
        <a href="analytics.php" class="<?= ja_nav_class('analytics',$ja_active) ?>">Analytics</a>
        <a href="login.php" class="<?= ja_nav_class('login',$ja_active) ?>">Login</a>
        <a href="register.php" class="ja-btn ja-btn-primary" style="padding:9px 20px;font-size:.9rem">Sign up</a>
      <?php endif; ?>
      <button class="ja-theme-toggle" data-ja-theme-toggle title="Toggle theme"><?= ja_icon('sun',18) ?></button>
    </div>
  </div>
</nav>
