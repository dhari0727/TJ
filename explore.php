<?php
// Explore was merged into Plan a Trip (one search, not two). Old links/bookmarks land there.
$q = isset($_GET['q']) ? '?q=' . urlencode($_GET['q']) : '';
header('Location: plan-trip.php' . $q);
exit;
