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
if (function_exists('ja_enforce_session_epoch')) ja_enforce_session_epoch();
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
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700;12..96,800&family=Instrument+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700&family=Caveat:wght@600&family=Familjen+Grotesk:wght@500;600;700&family=Literata:opsz,wght@7..72,400;7..72,500&display=swap" rel="stylesheet">

<!-- JourneyAI design system -->
<link rel="stylesheet" href="css/journeyai.css?v=<?= (int)@filemtime(__DIR__ . '/css/journeyai.css') ?>">

<!-- Motion libs (progressive enhancement; page works if these fail) -->
<script src="https://cdn.jsdelivr.net/gh/studio-freight/lenis@1.0.42/dist/lenis.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
<link rel="stylesheet" href="css/journeyai-community.css?v=<?= (int)@filemtime(__DIR__ . '/css/journeyai-community.css') ?>">
<link rel="stylesheet" href="css/journeyai-watermelon.css?v=<?= (int)@filemtime(__DIR__ . '/css/journeyai-watermelon.css') ?>">
<link rel="stylesheet" href="css/journeyai-calm.css?v=<?= (int)@filemtime(__DIR__ . '/css/journeyai-calm.css') ?>">
<?php if (!empty($ja_landing)): ?><link rel="stylesheet" href="css/journeyai-landing.css?v=<?= (int)@filemtime(__DIR__ . '/css/journeyai-landing.css') ?>"><?php endif; ?>
<script src="js/journey.js?v=<?= (int)@filemtime(__DIR__ . '/js/journey.js') ?>" defer></script>
<script src="js/motion.js?v=<?= (int)@filemtime(__DIR__ . '/js/motion.js') ?>" defer></script>
<script src="js/journeyai.js?v=<?= (int)@filemtime(__DIR__ . '/js/journeyai.js') ?>" defer></script>

<?php // ---- render the aurora background ---- ?>
<div class="ja-aurora"></div>
<div class="ja-grain"></div>

<?php
// Logged-in users get the fixed SIDEBAR shell on every page (unless a page opts
// out with $ja_no_shell, e.g. the cinematic landing). Logged-out users get the
// top navbar. This keeps the whole app consistent without rewriting each page.
$ja_use_sidebar = $ja_logged_in && empty($ja_no_shell) && empty($ja_nav_overlay);
if ($ja_use_sidebar):
  require_once __DIR__ . '/ja-nav.php';   // single source of truth for the menu
  echo '<script>window.JA_CSRF=' . json_encode(ja_csrf()) . ';</script><script src="js/csrf.js?v=' . (int)@filemtime(__DIR__ . '/js/csrf.js') . '"></script><script src="js/pin.js?v=' . (int)@filemtime(__DIR__ . '/js/pin.js') . '" defer></script>';
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
    <?= ja_logout_form('class="ja-side-logout" style="width:100%;justify-content:flex-start;gap:12px;padding:11px 14px;border:none;background:none;color:var(--text-dim);font:inherit;font-weight:500;font-size:.95rem;display:flex;align-items:center;cursor:pointer"', ja_icon('logout',20) . '<span>Log out</span>') ?>
  </div>
</aside>
<button class="ja-side-toggle" id="jaSideToggle" aria-label="Open menu" aria-expanded="false" aria-controls="jaSidebar"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
<div class="ja-app-main"><div class="ja-app-content">
<?php
  if (!empty($_SESSION['ja_flash'])) { echo '<div class="ja-ok ja-notice">' . htmlspecialchars($_SESSION['ja_flash']) . '</div>'; unset($_SESSION['ja_flash']); }
  if (!empty($_SESSION['eml'])) {
      $ja_vq = mysqli_query(ja_db(), "SELECT email_verified FROM signup WHERE eml='" . mysqli_real_escape_string(ja_db(), $_SESSION['eml']) . "'");
      $ja_vr = $ja_vq ? mysqli_fetch_row($ja_vq) : null;
      if ($ja_vr && !(int)$ja_vr[0] && basename($_SERVER['SCRIPT_NAME']) !== 'verify-email.php') {
          echo '<div class="ja-warn ja-notice"><strong>Confirm your email.</strong> You can write and plan privately, but sharing publicly unlocks once your email is verified. <a href="verify-email.php">Verify now</a></div>';
      }
  }
?>
<?php else: ?>
<nav class="ja-nav<?= !empty($ja_nav_overlay) ? ' overlay' : '' ?>" id="jaNav">
  <div class="inner">
    <a class="ja-brand" href="<?= $ja_logged_in ? 'dashboard.php' : 'index.php' ?>">
      <img src="images/journeyai-logo.svg" class="ja-logo-mark" alt=""> JourneyAI
    </a>
    <div class="ja-navlinks">
      <?php if ($ja_logged_in): ?>
        <a href="dashboard.php" class="<?= ja_nav_class('dashboard',$ja_active) ?>">Home</a>
        <a href="plan-trip.php" class="<?= ja_nav_class('plan',$ja_active) ?>">Plan a Trip</a>
        <?= ja_logout_form('class="ja-theme-toggle" title="Logout" aria-label="Log out" style="cursor:pointer"', ja_icon('logout',18)) ?>
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
