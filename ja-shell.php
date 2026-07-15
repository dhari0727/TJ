<?php
/**
 * JourneyAI — app shell with a fixed left sidebar (for logged-in users).
 * Usage at top of a page:
 *   $ja_title = "Explore"; $ja_active = "explore";
 *   include 'ja-shell.php';        // opens <html><body> + sidebar + <main>
 *   ... page content ...
 *   include 'ja-shell-end.php';    // closes <main></body></html>
 */
if (!isset($ja_title))  $ja_title = "JourneyAI";
if (!isset($ja_active)) $ja_active = "";
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/ja-icons.php';
$ja_fname = $_SESSION['fname'] ?? 'Traveller';

$nav = [
  ['dashboard','dashboard.php','home','Dashboard'],
  ['explore','explore.php','compass','Explore'],
  ['plan','plan-trip.php','search','Plan a Trip'],
  ['recs','recommendations.php','star','For You'],
  ['routes','my-routes.php','map-pin','My Routes'],
  ['entries','my-entries.php','book','My Journal'],
  ['analytics','analytics.php','chart','Analytics'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($ja_title) ?> — JourneyAI</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&family=Bagel+Fat+One&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/journeyai.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
<script src="js/journeyai.js" defer></script>
</head>
<body class="ja ja-app">
<div class="ja-aurora"></div>

<aside class="ja-sidebar" id="jaSidebar">
  <a class="ja-side-brand" href="dashboard.php">
    <img src="images/journeyai-logo.svg" class="ja-logo-mark" alt=""> <span>JourneyAI</span>
  </a>
  <nav class="ja-side-nav">
    <?php foreach ($nav as [$key,$href,$icon,$label]): ?>
      <a href="<?= $href ?>" class="<?= $key===$ja_active?'active':'' ?>"><?= ja_icon($icon,20) ?><span><?= $label ?></span></a>
    <?php endforeach; ?>
  </nav>
  <div class="ja-side-bottom">
    <a href="new-entry.php" class="ja-btn ja-btn-primary ja-side-new"><?= ja_icon('pen',16) ?><span>New Journal</span></a>
    <a href="profile.php" class="<?= $ja_active==='profile'?'active':'' ?>"><?= ja_icon('user',20) ?><span><?= htmlspecialchars($ja_fname) ?></span></a>
    <a href="login.php?logout=1"><?= ja_icon('logout',20) ?><span>Log out</span></a>
  </div>
</aside>

<button class="ja-side-toggle" id="jaSideToggle" aria-label="Menu"><?= ja_icon('compass',22) ?></button>

<div class="ja-app-main">
  <main class="ja-app-content">
