<?php
/**
 * JourneyAI — PHP client for the Flask ML microservice.
 *
 * All PHP -> ML calls go through here. Uses cURL with a short timeout and
 * returns a decoded array, or ['__error' => msg] on failure so callers can
 * render a graceful "service offline" banner instead of white-screening.
 */

if (!defined('JOURNEYAI_ML_BASE')) {
    define('JOURNEYAI_ML_BASE', 'http://127.0.0.1:5000');
}

/**
 * Low-level request. $method 'GET'|'POST', $path e.g. '/recommend',
 * $payload array (json for POST). Returns decoded array or ['__error'=>...].
 */
function ml_request($method, $path, $payload = null, $timeout = 8) {
    $url = JOURNEYAI_ML_BASE . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload ?: []));
    }
    $raw  = curl_exec($ch);
    $errno = curl_errno($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        return ['__error' => 'The recommendation service is offline. '
            . 'Start it with ml/start_service.bat (details: ' . $err . ').'];
    }
    $data = json_decode($raw, true);
    if ($data === null) {
        return ['__error' => "Invalid response from ML service (HTTP $code)."];
    }
    if ($code >= 400) {
        return ['__error' => $data['error'] ?? "ML service error (HTTP $code)."];
    }
    return $data;
}

/** Is the ML service reachable right now? */
function ml_is_up() {
    $r = ml_request('GET', '/health', null, 3);
    return empty($r['__error']) && ($r['models_loaded'] ?? false);
}

/** Get ranked, explained recommendations. */
function ml_recommend($params) {
    return ml_request('POST', '/recommend', $params, 12);
}

/** Predict cost for one destination. */
function ml_predict_cost($params) {
    return ml_request('POST', '/predict-cost', $params);
}

/** Analytics aggregates for the dashboard. */
function ml_analytics() {
    return ml_request('GET', '/analytics/summary', null, 8);
}

/** Generate a day-by-day itinerary for a destination. */
function ml_itinerary($params) {
    return ml_request('POST', '/itinerary', $params, 10);
}

/** Render a standard offline banner (call when a result has __error). */
function ml_offline_banner($result) {
    if (empty($result['__error'])) return '';
    return '<div class="ja-offline">⚠ ' . htmlspecialchars($result['__error']) . '</div>';
}
