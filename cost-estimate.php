<?php
/**
 * JourneyAI — read-only JSON endpoint behind the live budget helper on plan-trip.php.
 *   GET cost-estimate.php?days=4&party=2&month=11&style=mid-range[&destination=Jaipur]
 * With a destination: the cost model's total and band for that place.
 * Without: three reference places (lower-cost, typical, pricier) taken from the real destination list.
 * It changes nothing, so it needs no CSRF token; inputs are clamped before they reach the service.
 */
require_once __DIR__ . '/ml_client.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$days  = max(1, min(30, (int)($_GET['days'] ?? 4)));
$party = max(1, min(20, (int)($_GET['party'] ?? 1)));
$month = (int)($_GET['month'] ?? 0); $month = ($month >= 1 && $month <= 12) ? $month : null;
$styles = ['budget', 'mid-range', 'luxury', 'adventure', 'family', 'solo', 'backpacker'];
$style = in_array($_GET['style'] ?? '', $styles, true) ? $_GET['style'] : 'mid-range';
$dest  = trim((string)($_GET['destination'] ?? ''));
$dest  = mb_substr($dest, 0, 120);

function est_one($name, $days, $party, $month, $style) {
    $c = ml_predict_cost(['destination' => $name, 'duration_days' => $days, 'party_size' => $party, 'month' => $month, 'travel_style' => $style]);
    if (!empty($c['__error'])) return ['__error' => $c['__error']];
    return ['name' => $name, 'total' => (int)$c['predicted_cost'], 'low' => (int)$c['low'], 'high' => (int)$c['high'], 'per_day' => (int)$c['per_day'],
        'confidence' => (string)($c['confidence'] ?? ''), 'n_observations' => (int)($c['n_observations'] ?? 0)];
}

$out = ['status' => 'ok', 'days' => $days, 'party' => $party];
if ($dest !== '') {
    $p = ja_dest_lookup($dest);
    if ($p === null) { http_response_code(404); echo json_encode(['status' => 'error', 'error' => 'We have no data for "' . $dest . '". Pick a place from the suggestions or clear the box.']); exit; }
    $e = est_one($p, $days, $party, $month, $style);
    if (isset($e['__error'])) { http_response_code(502); echo json_encode(['status' => 'error', 'error' => 'The cost model did not answer. Try again in a moment.']); exit; }
    $out['mode'] = 'place'; $out['items'] = [$e];
} else {
    $list = ml_request('POST', '/destination', ['list' => true], 8);
    $rows = $list['destinations'] ?? [];
    if (!$rows) { http_response_code(502); echo json_encode(['status' => 'error', 'error' => 'The destination list is unavailable right now.']); exit; }
    usort($rows, function ($a, $b) { return ($a['per_day'] ?? 0) <=> ($b['per_day'] ?? 0); });
    $n = count($rows);
    $pick = [['label' => 'Lower-cost places', 'i' => (int)floor($n * 0.2)], ['label' => 'Typical places', 'i' => (int)floor($n * 0.5)], ['label' => 'Pricier places', 'i' => (int)floor($n * 0.8)]];
    $items = [];
    foreach ($pick as $pk) {
        $e = est_one($rows[$pk['i']]['destination'], $days, $party, $month, $style);
        if (isset($e['__error'])) { http_response_code(502); echo json_encode(['status' => 'error', 'error' => 'The cost model did not answer. Try again in a moment.']); exit; }
        $e['label'] = $pk['label']; $items[] = $e;
    }
    $out['mode'] = 'range'; $out['items'] = $items;
}
echo json_encode($out);

/** Resolve typed text ("jaipur") to a canonical destination name via the service; null when unknown. */
function ja_dest_lookup($text) {
    $r = ml_request('POST', '/destination', ['destination' => $text], 8);
    if (!empty($r['destinations'][0]['destination'])) return $r['destinations'][0]['destination'];
    return null;
}
