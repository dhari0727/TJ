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

<?php // ---- render the aurora background ---- ?>
<div class="ja-aurora"></div>
<div class="ja-grain"></div>

<?php
// Logged-in users get the fixed SIDEBAR shell on every page (unless a page opts
// out with $ja_no_shell, e.g. the cinematic landing). Logged-out users get the
// top navbar. This keeps the whole app consistent without rewriting each page.
$ja_use_sidebar = $ja_logged_in && empty($ja_no_shell) && empty($ja_nav_overlay);
if ($ja_use_sidebar):
  $ja_side_nav = [
    ['dashboard','dashboard.php','home','Dashboard'],
    ['explore','explore.php','compass','Explore'],
    ['plan','plan-trip.php','search','Plan a Trip'],
    ['recs','recommendations.php','star','For You'],
    ['feed','feed.php','heart','Feed'],
    ['routes','my-routes.php','map-pin','My Routes'],
    ['plans','my-plans.php','wallet','My Plans'],
    ['entries','my-entries.php','book','My Journal'],
    ['analytics','analytics.php','chart','Analytics'],
  ];
?>
<aside class="ja-sidebar" id="jaSidebar">
  <a class="ja-side-brand" href="dashboard.php"><img src="images/journeyai-logo.svg" class="ja-logo-mark" alt=""> <span>JourneyAI</span></a>
  <nav class="ja-side-nav">
    <?php foreach ($ja_side_nav as [$k,$href,$ic,$lbl]): ?>
      <a href="<?= $href ?>" class="<?= $k===$ja_active?'active':'' ?>"><?= ja_icon($ic,20) ?><span><?= $lbl ?></span></a>
    <?php endforeach; ?>
  </nav>
  <div class="ja-side-bottom">
    <a href="new-entry.php" class="ja-btn ja-btn-primary ja-side-new"><?= ja_icon('pen',16) ?><span>New Journal</span></a>
    <button class="ja-theme-toggle ja-side-theme" data-ja-theme-toggle title="Toggle theme" style="width:auto;justify-content:flex-start;gap:12px;padding:11px 14px;border:none;background:none;color:var(--text-dim);font-weight:500;font-size:.95rem;display:flex;align-items:center"><?= ja_icon('sun',20) ?><span>Theme</span></button>
    <a href="profile.php" class="<?= $ja_active==='profile'?'active':'' ?>"><?= ja_icon('user',20) ?><span><?= htmlspecialchars($_SESSION['fname'] ?? 'Profile') ?></span></a>
    <a href="login.php?logout=1"><?= ja_icon('logout',20) ?><span>Log out</span></a>
  </div>
</aside>
<button class="ja-side-toggle" id="jaSideToggle" aria-label="Menu"><?= ja_icon('compass',22) ?></button>
<div class="ja-app-main"><div class="ja-app-content">
<?php else: ?>
<nav class="ja-nav<?= !empty($ja_nav_overlay) ? ' overlay' : '' ?>" id="jaNav">
  <div class="inner">
    <a class="ja-brand" href="<?= $ja_logged_in ? 'dashboard.php' : 'index.php' ?>">
      <img src="images/journeyai-logo.svg" class="ja-logo-mark" alt=""> JourneyAI
    </a>
    <div class="ja-navlinks">
      <?php if ($ja_logged_in): ?>
        <a href="dashboard.php" class="<?= ja_nav_class('dashboard',$ja_active) ?>">Dashboard</a>
        <a href="explore.php" class="<?= ja_nav_class('explore',$ja_active) ?>">Explore</a>
        <a href="login.php?logout=1" class="ja-theme-toggle" title="Logout" style="text-decoration:none"><?= ja_icon('logout',18) ?></a>
      <?php else: ?>
        <a href="index.php" class="<?= ja_nav_class('home',$ja_active) ?>">Home</a>
        <a href="plan-trip.php" class="<?= ja_nav_class('plan',$ja_active) ?>">Plan a Trip</a>
        <a href="feed.php" class="<?= ja_nav_class('feed',$ja_active) ?>">Feed</a>
        <a href="analytics.php" class="<?= ja_nav_class('analytics',$ja_active) ?>">Analytics</a>
        <a href="login.php" class="<?= ja_nav_class('login',$ja_active) ?>">Login</a>
        <a href="register.php" class="ja-btn ja-btn-primary" style="padding:9px 20px;font-size:.9rem">Sign up</a>
      <?php endif; ?>
      <button class="ja-theme-toggle" data-ja-theme-toggle title="Toggle theme"><?= ja_icon('sun',18) ?></button>
    </div>
  </div>
</nav>
<?php endif; /* end sidebar-vs-topnav */ ?>
