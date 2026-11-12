<?php
/**
 * JourneyAI — the ONE menu definition (single source of truth) for the sidebar, the top bar,
 * the footer and the command palette. Edit here to change navigation everywhere.
 * Each entry: [active-key, href, icon, label]
 *
 * Where things live:
 *   Plan a trip  — the only place to search (one-line box OR fine-tune form), incl. routes & itineraries
 *   My trips     — everything you saved from planning: plans + routes
 *   For you      — shown on Home (personal picks); no separate menu item
 */
$ja_side_nav = [
  ['dashboard', 'dashboard.php',    'home',    'Home'],
  ['plan',      'plan-trip.php',    'search',  'Plan a trip'],
  ['plans',     'my-plans.php',     'wallet',  'My trips'],
  ['entries',   'my-entries.php',   'book',    'My journal'],
  ['camera',    'camera.php',       'camera',  'Camera'],
  ['roll',      'camera-roll.php',  'images',  'Camera roll'],
  ['feed',      'feed.php',         'heart',   'Community'],
  ['analytics', 'analytics.php',    'chart',   'Insights'],
];

// Admins get one extra item (shown only when the signed-in user has role = admin).
require_once __DIR__ . '/ja-lib.php';
if (function_exists('ja_is_admin') && ja_is_admin()) {
    $ja_side_nav[] = ['admin', 'admin.php', 'gem', 'Admin'];
}

// Signed-out visitors: the same places, minus the personal ones.
$ja_public_nav = [
  ['home',      'index.php',        'home',    'Home'],
  ['plan',      'plan-trip.php',    'search',  'Plan a trip'],
  ['feed',      'feed.php',         'heart',   'Community'],
  ['analytics', 'analytics.php',    'chart',   'Insights'],
];
