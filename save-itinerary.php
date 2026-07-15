<?php
/**
 * JourneyAI — save the current itinerary (from itinerary.php) as a saved_plans
 * row for the logged-in user, then auto-generate a starter packing checklist.
 * Re-fetches ml_itinerary() server-side using the same params posted from
 * itinerary.php's hidden fields (simplest way to get the full JSON snapshot).
 */
session_start();
require 'connection.php';
require 'ml_client.php';
require 'ja-packing.php';

$eml = $_SESSION['eml'] ?? null;
if (!$eml) { header('Location: login.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: plan-trip.php'); exit; }

$STYLES = ['budget','mid-range','luxury','adventure','family','solo','backpacker'];
$dest  = trim($_POST['dest'] ?? '');
$days  = max(1, min(21, (int)($_POST['days'] ?? 5)));
$style = in_array($_POST['style'] ?? '', $STYLES, true) ? $_POST['style'] : 'mid-range';
$month = (int)($_POST['month'] ?? 0); $month = ($month >= 1 && $month <= 12) ? $month : null;
$party = max(1, min(20, (int)($_POST['party'] ?? 1)));

if (!$dest) { header('Location: plan-trip.php'); exit; }

$it = ml_itinerary([
    'destination'  => $dest,
    'days'         => $days,
    'travel_style' => $style,
    'month'        => $month,
    'party_size'   => $party,
]);

if (!empty($it['__error']) || ($it['status'] ?? '') === 'error') {
    header('Location: itinerary.php?' . http_build_query(['dest'=>$dest,'days'=>$days,'style'=>$style,'month'=>$month,'party'=>$party]));
    exit;
}

$itineraryJson = json_encode($it);

$stmt = mysqli_prepare($conn,
    "INSERT INTO saved_plans (eml, destination, days, travel_style, month, party_size, itinerary) VALUES (?,?,?,?,?,?,?)");
mysqli_stmt_bind_param($stmt, 'ssisiis', $eml, $dest, $days, $style, $month, $party, $itineraryJson);
mysqli_stmt_execute($stmt);
$planId = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

// Auto-generate starter packing checklist.
$suggestions = ja_suggest_packing($dest, $days, $style, $month);
if ($planId && $suggestions) {
    $stmt = mysqli_prepare($conn,
        "INSERT INTO packing_items (plan_id, label, category, sort_order) VALUES (?,?,?,?)");
    $sort = 0;
    foreach ($suggestions as $s) {
        mysqli_stmt_bind_param($stmt, 'issi', $planId, $s['label'], $s['category'], $sort);
        mysqli_stmt_execute($stmt);
        $sort++;
    }
    mysqli_stmt_close($stmt);
}

header('Location: my-plans.php?view=' . (int)$planId . '&saved=1');
exit;
