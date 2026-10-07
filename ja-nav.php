<?php
/**
 * JourneyAI — the ONE sidebar menu for logged-in users (single source of truth).
 * Included by ja-head.php and ja-shell.php; edit here to change navigation everywhere.
 * Each entry: [active-key, href, icon, label]
 *
 * Where things live:
 *   Plan a Trip  — the only place to search (one-line box OR fine-tune form), incl. routes & itineraries
 *   My Trips     — everything you saved from planning: plans + routes
 *   For You      — shown on Home (personal picks); no separate menu item
 */
$ja_side_nav = [
  ['dashboard', 'dashboard.php',    'home',    'Home'],
  ['plan',      'plan-trip.php',    'search',  'Plan a Trip'],
  ['plans',     'my-plans.php',     'wallet',  'My Trips'],
  ['entries',   'my-entries.php',   'book',    'My Journal'],
  ['feed',      'feed.php',         'heart',   'Community'],
  ['analytics', 'analytics.php',    'chart',   'Insights'],
];

// Admins get one extra item (shown only when the signed-in user has role = admin).
require_once __DIR__ . '/ja-lib.php';
if (function_exists('ja_is_admin') && ja_is_admin()) {
    $ja_side_nav[] = ['admin', 'admin.php', 'gem', 'Admin'];
}
