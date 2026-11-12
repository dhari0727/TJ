<?php
/**
 * Shared pieces of a rendered itinerary: used by trip.php, my-plans.php (saved plan) and shared-plan.php,
 * so a plan looks the same wherever you open it. All output is escaped. $it is the itinerary array from the ML service.
 */
require_once __DIR__ . '/ja-icons.php';

if (!function_exists('tp_icon')) {
/** Small stroke icons used by the trip pages (the shared ja_icon() set has no share, print or download). */
function tp_icon($name, $size = 18) {
    $p = [
        'share'    => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 10.5l6.8-4M8.6 13.5l6.8 4"/>',
        'print'    => '<path d="M6 9V3h12v6M6 18H4a1 1 0 0 1-1-1v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6a1 1 0 0 1-1 1h-2"/><rect x="6" y="14" width="12" height="7"/>',
        'bag'      => '<path d="M5 8h14l-1 12H6L5 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        'save'     => '<path d="M6 3h12v18l-6-4-6 4z"/>',
        'download' => '<path d="M12 3v12m0 0l-4-4m4 4l4-4M4 20h16"/>',
        'check'    => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'chev'     => '<path d="M6 9l6 6 6-6"/>',
        'pin'      => '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'wifi-off' => '<path d="M2 8.8a15 15 0 0 1 4-2.4M22 8.8a15 15 0 0 0-9.5-3.7M5 12.5a10 10 0 0 1 3-1.8M19 12.5a10 10 0 0 0-4.5-2.5M8.5 16a5 5 0 0 1 7 0M12 20h.01M3 3l18 18"/>',
        'undo'     => '<path d="M9 14L4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/>',
        'trash'    => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
    ][$name] ?? '';
    return '<svg class="tp-ico" width="' . (int)$size . '" height="' . (int)$size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p . '</svg>';
}
}

/** Labels for the cost_breakdown keys. */
function tp_cost_labels() {
    return ['food' => 'Food', 'transport' => 'Transport', 'accommodation' => 'Stay', 'shopping' => 'Shopping', 'fees_misc' => 'Fees and misc'];
}

/**
 * Cost breakdown: one stacked bar drawn as inline SVG (no library), with a plain table underneath that carries
 * every number, so the chart is never the only way to read it. $it needs cost_breakdown (+ optional cost_low/high).
 */
function tp_cost_chart($it) {
    $bd = array_filter((array)($it['cost_breakdown'] ?? []), function ($v) { return is_numeric($v) && $v > 0; });
    if (!$bd) return '';
    $total = array_sum($bd);
    $L = tp_cost_labels();
    $i = 0; $x = 0; $rects = ''; $rows = ''; $summary = [];
    foreach ($bd as $k => $v) {
        $w = $v / $total * 1000;
        $rects .= '<rect class="tp-c' . ($i % 5) . '" x="' . round($x, 2) . '" y="0" width="' . round($w, 2) . '" height="28" rx="3"><title>' . htmlspecialchars(($L[$k] ?? $k) . ': ₹' . number_format($v)) . '</title></rect>';
        $pct = round($v / $total * 100);
        $rows .= '<tr><th scope="row"><i class="tp-sw tp-c' . ($i % 5) . '"></i>' . htmlspecialchars($L[$k] ?? $k) . '</th><td>' . $pct . '%</td><td>₹' . number_format($v) . '</td></tr>';
        $summary[] = ($L[$k] ?? $k) . ' ' . $pct . ' percent';
        $x += $w; $i++;
    }
    $h  = '<div class="tp-cost">';
    $h .= '<svg class="tp-bar" viewBox="0 0 1000 28" preserveAspectRatio="none" role="img" aria-label="Where the money goes: ' . htmlspecialchars(implode(', ', $summary)) . '">' . $rects . '</svg>';
    $h .= '<table class="tp-costtable"><caption class="tp-sr">Estimated cost by category</caption><thead class="tp-sr"><tr><th scope="col">Category</th><th scope="col">Share</th><th scope="col">Amount</th></tr></thead><tbody>' . $rows . '</tbody>';
    $h .= '<tfoot><tr><th scope="row">Total</th><td></td><td>₹' . number_format($total) . '</td></tr></tfoot></table>';
    if (!empty($it['cost_low']) && !empty($it['cost_high'])) {
        $h .= '<p class="tp-range">Likely range ₹' . number_format($it['cost_low']) . ' to ₹' . number_format($it['cost_high']) . '. These are estimates, not quotes.</p>';
    }
    return $h . '</div>';
}

/** Collapsible day-by-day sections. Days open by default so print and search-in-page see everything. */
function tp_days($it) {
    $h = '<div class="tp-days" id="tpDays">';
    foreach (($it['plan'] ?? []) as $d) {
        $h .= '<details class="tp-day" open><summary><span class="tp-daynum">' . (int)$d['day'] . '</span>'
            . '<span class="tp-daytitle">' . htmlspecialchars($d['title']) . '</span>'
            . '<span class="tp-daycost">about ₹' . number_format($d['est_cost'] ?? 0) . '</span>'
            . tp_icon('chev', 18) . '</summary><div class="tp-daybody"><ol class="tp-tl">';
        foreach (($d['timeline'] ?? []) as $t) {
            $h .= '<li><span class="tp-time">' . htmlspecialchars($t['time']) . '</span><span class="tp-what">' . htmlspecialchars($t['text'])
                . (!empty($t['meal']) ? '<small>' . htmlspecialchars($t['meal']) . '</small>' : '') . '</span></li>';
        }
        $h .= '</ol>';
        if (!empty($d['stay'])) $h .= '<p class="tp-stay">Suggested stay: <strong>' . htmlspecialchars($d['stay']) . '</strong></p>';
        $h .= '</div></details>';
    }
    return $h . '</div>';
}

function tp_highlights($it) {
    if (empty($it['highlights'])) return '';
    $h = '<ul class="tp-hl">';
    foreach ($it['highlights'] as $x) $h .= '<li>' . tp_icon('pin', 15) . '<span>' . htmlspecialchars($x) . '</span></li>';
    return $h . '</ul>';
}
