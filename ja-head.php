<?php
/**
 * JourneyAI — shared <head> + page chrome (top bar for visitors, sidebar for members).
 * Include at the top of every JourneyAI page AFTER opening <html>.
 *
 * Set $ja_title and $ja_active before including, e.g.:
 *   $ja_title = "Plan a Trip"; $ja_active = "plan"; include 'ja-head.php';
 * Optional: $ja_css_extra = ['pages-account.css'];  (extra stylesheets from css/, loaded after the brand layer)
 *
 * The chrome adds, on every page: a no-flash theme script, a "Skip to content" link, one <main id="main">
 * landmark (added by the output filter below when a page does not have its own) and the Ctrl/Cmd+K palette.
 */
if (!isset($ja_title))  $ja_title = "JourneyAI";
if (!isset($ja_active)) $ja_active = "";
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/ja-icons.php';
if (function_exists('ja_enforce_session_epoch')) ja_enforce_session_epoch();
$ja_logged_in = isset($_SESSION['eml']);
function ja_nav_class($k, $active){ return $k === $active ? 'active' : ''; }

/**
 * Output filter: make sure every page has exactly one <main id="main"> for the skip link and for screen readers.
 * Pages that already use <main> keep it (it only gains the id); pages in the sidebar shell get their content
 * wrapper promoted to <main>; anything else gets an empty anchor right after the top bar.
 */
if (!function_exists('ja_chrome_filter')) {
    function ja_chrome_filter($html) {
        // the buffer starts where this file was included (inside <head>), so look for our own skip link rather than <html>
        if (strpos($html, 'class="ja-skip"') === false || strpos($html, 'id="main"') !== false) return $html;
        if (stripos($html, '<main') === false) {
            $n = 0;
            $html = str_replace('<div class="ja-app-content">', '<main id="main" tabindex="-1" class="ja-app-content">', $html, $n);
            if ($n) {
                $html = str_replace('</div><!-- .ja-app-content -->', '</main>', $html);
                return $html;
            }
        } else {
            $n = 0;
            $html = preg_replace_callback('/<main(?![^>]*\sid=)([^>]*)>/i', function ($m) { return '<main id="main" tabindex="-1"' . $m[1] . '>'; }, $html, 1, $n);
            if ($n) return $html;
        }
        if (strpos($html, 'id="main"') === false) {
            $anchor = '<div id="main" tabindex="-1"></div>';
            $pos = stripos($html, '</nav>');
            if ($pos !== false) $html = substr_replace($html, '</nav>' . $anchor, $pos, 6);
        }
        return $html;
    }
    if (!defined('JA_CHROME_OB')) { define('JA_CHROME_OB', 1); ob_start('ja_chrome_filter'); }
}
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light dark">
<?php /* apply the saved theme before first paint so there is no flash; with no saved choice the system preference decides (see brand css) */ ?>
<script>(function(){try{var t=localStorage.getItem('ja-theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<title><?= htmlspecialchars($ja_title) ?> — JourneyAI</title>
<meta name="description" content="JourneyAI — AI-powered, budget-aware, explainable travel recommendations from real travel journals.">
<link rel="icon" type="image/svg+xml" href="images/journeyai-logo.svg">

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
<link rel="manifest" href="manifest.webmanifest">
<meta name="theme-color" content="#14213D">
<link rel="apple-touch-icon" href="images/icon-192.png">
<link rel="stylesheet" href="css/journeyai-brand.css?v=<?= (int)@filemtime(__DIR__ . '/css/journeyai-brand.css') ?>">
<?php if (!empty($ja_landing)): ?><link rel="stylesheet" href="css/journeyai-landing.css?v=<?= (int)@filemtime(__DIR__ . '/css/journeyai-landing.css') ?>"><?php endif; ?>
<?php foreach ((array)($ja_css_extra ?? []) as $ja_x): if (preg_match('/^[a-z0-9._-]+\.css$/i', $ja_x)): ?><link rel="stylesheet" href="css/<?= $ja_x ?>?v=<?= (int)@filemtime(__DIR__ . '/css/' . $ja_x) ?>">
<?php endif; endforeach; ?>
<script src="js/pwa.js" defer></script>
<script src="js/journey.js?v=<?= (int)@filemtime(__DIR__ . '/js/journey.js') ?>" defer></script>
<script src="js/ja-palette.js?v=<?= (int)@filemtime(__DIR__ . '/js/ja-palette.js') ?>" defer></script>
<script src="js/motion.js?v=<?= (int)@filemtime(__DIR__ . '/js/motion.js') ?>" defer></script>
<script src="js/journeyai.js?v=<?= (int)@filemtime(__DIR__ . '/js/journeyai.js') ?>" defer></script>

<?php // ---- page chrome (the browser moves everything below into <body>) ---- ?>
<div class="ja-aurora"></div>
<div class="ja-grain"></div>
<a class="ja-skip" href="#main" data-ja-skip>Skip to content</a>

<?php
// Members get the fixed SIDEBAR on every page (unless a page opts out with $ja_no_shell);
// visitors get the top bar. This keeps the whole app consistent without rewriting each page.
require_once __DIR__ . '/ja-nav.php';   // single source of truth for the menu
$ja_use_sidebar = $ja_logged_in && empty($ja_no_shell) && empty($ja_nav_overlay);
$ja_theme_btn = '<span class="ja-tt-moon">' . ja_icon('moon', 18) . '</span><span class="ja-tt-sun">' . ja_icon('sun', 18) . '</span>';
if ($ja_use_sidebar):
  echo '<script>window.JA_CSRF=' . json_encode(ja_csrf()) . ';</script><script src="js/csrf.js?v=' . (int)@filemtime(__DIR__ . '/js/csrf.js') . '"></script><script src="js/pin.js?v=' . (int)@filemtime(__DIR__ . '/js/pin.js') . '" defer></script>';
?>
<aside class="ja-sidebar" id="jaSidebar" aria-label="Sidebar">
  <a class="ja-side-brand" href="dashboard.php"><img src="images/journeyai-logo.svg" class="ja-logo-mark" alt=""> <span>JourneyAI</span></a>
  <button type="button" class="ja-side-search" data-ja-palette-open aria-haspopup="dialog" aria-keyshortcuts="Control+K Meta+K"><?= ja_icon('search', 18) ?><span>Search</span><kbd class="ja-kbd" data-ja-kbd>Ctrl K</kbd></button>
  <nav class="ja-side-nav" aria-label="Main">
    <?php foreach ($ja_side_nav as [$k,$href,$ic,$lbl]): ?>
      <a href="<?= $href ?>" class="<?= $k===$ja_active?'active':'' ?>"<?= $k===$ja_active?' aria-current="page"':'' ?>><?= ja_icon($ic,20) ?><span><?= $lbl ?></span></a>
    <?php endforeach; ?>
  </nav>
  <div class="ja-side-bottom">
    <a href="new-entry.php" class="ja-btn ja-btn-primary ja-side-new"><?= ja_icon('pen',16) ?><span>New journal</span></a>
    <button type="button" class="ja-side-row" data-ja-theme-toggle title="Switch theme"><?= $ja_theme_btn ?><span data-ja-theme-label>Dark theme</span></button>
    <a href="profile.php" class="<?= $ja_active==='profile'?'active':'' ?>"<?= $ja_active==='profile'?' aria-current="page"':'' ?>><?= ja_icon('user',20) ?><span><?= htmlspecialchars($_SESSION['fname'] ?? 'Profile') ?></span></a>
    <?= ja_logout_form('class="ja-side-row ja-side-logout"', ja_icon('logout',20) . '<span>Log out</span>') ?>
  </div>
</aside>
<button class="ja-side-toggle" id="jaSideToggle" aria-label="Open menu" aria-expanded="false" aria-controls="jaSidebar"><?= ja_icon('menu', 22) ?></button>
<button type="button" class="ja-side-searchfab" data-ja-palette-open aria-label="Search" aria-haspopup="dialog"><?= ja_icon('search', 20) ?></button>
<div class="ja-app-main"><div class="ja-app-content">
<?php
  if (!empty($_SESSION['ja_flash'])) { echo '<div class="ja-ok ja-notice" role="status">' . htmlspecialchars($_SESSION['ja_flash']) . '</div>'; unset($_SESSION['ja_flash']); }
  if (!empty($_SESSION['eml'])) {
      $ja_vq = mysqli_query(ja_db(), "SELECT email_verified FROM signup WHERE eml='" . mysqli_real_escape_string(ja_db(), $_SESSION['eml']) . "'");
      $ja_vr = $ja_vq ? mysqli_fetch_row($ja_vq) : null;
      if ($ja_vr && !(int)$ja_vr[0] && basename($_SERVER['SCRIPT_NAME']) !== 'verify-email.php') {
          echo '<div class="ja-warn ja-notice"><strong>Confirm your email.</strong> You can write and plan privately, but sharing publicly unlocks once your email is verified. <a href="verify-email.php">Verify now</a></div>';
      }
  }
?>
<?php elseif (empty($ja_no_nav)): ?>
<nav class="ja-nav" id="jaNav" aria-label="Main">
  <div class="inner">
    <a class="ja-brand" href="<?= $ja_logged_in ? 'dashboard.php' : 'index.php' ?>">
      <img src="images/journeyai-logo.svg" class="ja-logo-mark" alt=""> <span>JourneyAI</span>
    </a>
    <div class="ja-nav-links" id="jaNavLinks">
      <?php if ($ja_logged_in): ?>
        <?php foreach (array_slice($ja_side_nav, 0, 5) as [$k,$href,$ic,$lbl]): ?>
        <a href="<?= $href ?>" class="<?= ja_nav_class($k,$ja_active) ?>"<?= $k===$ja_active?' aria-current="page"':'' ?>><?= $lbl ?></a>
        <?php endforeach; ?>
      <?php else: foreach ($ja_public_nav as [$k,$href,$ic,$lbl]): ?>
        <a href="<?= $href ?>" class="<?= ja_nav_class($k,$ja_active) ?>"<?= $k===$ja_active?' aria-current="page"':'' ?>><?= $lbl ?></a>
      <?php endforeach; ?>
        <a href="login.php" class="ja-nav-login-m <?= ja_nav_class('login',$ja_active) ?>">Log in</a>
      <?php endif; ?>
    </div>
    <div class="ja-nav-actions">
      <button type="button" class="ja-nav-search" data-ja-palette-open aria-haspopup="dialog" aria-keyshortcuts="Control+K Meta+K" aria-label="Search"><?= ja_icon('search', 18) ?><span class="ja-nav-search-t">Search</span><kbd class="ja-kbd" data-ja-kbd>Ctrl K</kbd></button>
      <button type="button" class="ja-theme-toggle" data-ja-theme-toggle title="Switch theme" aria-label="Switch to dark theme"><?= $ja_theme_btn ?></button>
      <?php if ($ja_logged_in): ?>
        <?= ja_logout_form('class="ja-theme-toggle" title="Log out" aria-label="Log out"', ja_icon('logout',18)) ?>
      <?php else: ?>
        <a href="login.php" class="ja-nav-login <?= ja_nav_class('login',$ja_active) ?>">Log in</a>
        <a href="register.php" class="ja-btn ja-btn-primary ja-nav-signup">Sign up</a>
      <?php endif; ?>
      <button type="button" class="ja-nav-menu" id="jaNavMenu" aria-label="Open menu" aria-expanded="false" aria-controls="jaNavLinks"><?= ja_icon('menu', 20) ?></button>
    </div>
  </div>
</nav>
<?php endif; /* end sidebar-vs-topnav */ ?>
