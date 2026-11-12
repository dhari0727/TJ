<?php
/**
 * JourneyAI — shared helpers for the destination guide, compare page and recommendation cards.
 * All facts come from the ML service's destination profiles (Wikivoyage, Wikipedia, real journals)
 * and the trained cost model. Nothing here invents data: missing facts are simply left out.
 */
require_once __DIR__ . '/ja-icons.php';
require_once __DIR__ . '/ja-images.php';
require_once __DIR__ . '/ml_client.php';

const JA_MONTHS_SHORT = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const JA_MONTHS_LONG  = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/** Link to the guide for a destination name such as "Jaipur, India" or "Jaipur". */
function ja_dest_url($name) {
    return 'destination.php?d=' . urlencode(trim(explode(',', (string)$name)[0]));
}

function ja_inr($n) { return '₹' . number_format((float)$n); }

/** Round a rupee figure to something a person would say (nearest 100 under 10k, else nearest 500). */
function ja_inr_round($n) {
    $n = (float)$n;
    $step = $n < 10000 ? 100 : 500;
    return (int)(round($n / $step) * $step);
}

/**
 * Fetch profiles for 1 to 3 destinations. Returns
 *   ['items' => [profile|null, ...] (same order as $names), 'error' => string|null, 'offline' => bool]
 */
function ja_dest_fetch(array $names, $month = null) {
    $names = array_values(array_filter(array_map('trim', $names), 'strlen'));
    $r = ml_request('POST', '/destination', ['destinations' => $names, 'month' => $month], 15);
    if (!empty($r['__error'])) {
        // a 404 means "none of these exist"; anything else means the service could not answer
        $unknown = ($r['__error'] === 'unknown destination');
        return ['items' => array_fill(0, count($names), null), 'error' => $unknown ? null : $r['__error'], 'offline' => !$unknown];
    }
    $items = array_map(function ($x) { return is_array($x) ? $x : null; }, $r['results'] ?? []);
    $items = array_pad($items, count($names), null);
    return ['items' => $items, 'error' => null, 'offline' => false];
}

/** "October to March" from a list of peak months (handles the wrap around December). */
function ja_month_span(array $peak) {
    $peak = array_values(array_unique(array_map('intval', $peak)));
    sort($peak);
    if (!$peak) return '';
    if (count($peak) === 12) return 'All year';
    $has = array_fill(1, 12, false);
    foreach ($peak as $m) $has[$m] = true;
    // find the start of each run: a peak month whose previous month is not a peak month
    $runs = [];
    foreach ($peak as $m) {
        $prev = $m === 1 ? 12 : $m - 1;
        if ($has[$prev]) continue;
        $len = 0; $c = $m;
        while ($has[$c] && $len < 12) { $len++; $c = $c === 12 ? 1 : $c + 1; }
        $last = ($m + $len - 2) % 12 + 1;
        $runs[] = $len === 1 ? JA_MONTHS_LONG[$m] : JA_MONTHS_LONG[$m] . ' to ' . JA_MONTHS_LONG[$last];
    }
    return implode(', ', $runs);
}

/**
 * Twelve-bar "best months" chart as inline SVG (no library).
 * $peak: months 1-12 that are peak season. $size 'lg' for the guide, 'sm' for compare columns.
 */
function ja_month_chart(array $peak, $size = 'lg', $id = 'mc') {
    $peak = array_map('intval', $peak);
    $lg = ($size === 'lg');
    $W = 360; $H = $lg ? 120 : 84; $top = 6; $base = $H - 22; $bw = 22; $gap = ($W - 12 * $bw) / 11;
    $nowM = (int)date('n');
    $span = ja_month_span($peak);
    $title = $span ? ('Best months: ' . $span) : 'Best months are not recorded for this place';
    $out = '<svg class="dz-months dz-months-' . $size . '" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-labelledby="' . $id . '-t" focusable="false">';
    $out .= '<title id="' . $id . '-t">' . htmlspecialchars($title) . '</title>';
    for ($m = 1; $m <= 12; $m++) {
        $x = round(($m - 1) * ($bw + $gap), 1);
        $isPeak = in_array($m, $peak, true);
        $h = $isPeak ? ($base - $top) : round(($base - $top) * 0.28);
        $y = $base - $h;
        $out .= '<rect class="dz-bar' . ($isPeak ? ' peak' : '') . '" x="' . $x . '" y="' . $y . '" width="' . $bw . '" height="' . $h . '" rx="3"><title>'
              . JA_MONTHS_LONG[$m] . ($isPeak ? ': peak season' : ': outside peak season') . '</title></rect>';
        $out .= '<text class="dz-mlab' . ($m === $nowM ? ' now' : '') . '" x="' . round($x + $bw / 2, 1) . '" y="' . ($H - 6) . '" text-anchor="middle">'
              . ($lg ? JA_MONTHS_SHORT[$m] : substr(JA_MONTHS_SHORT[$m], 0, 1)) . '</text>';
    }
    return $out . '</svg>';
}

/** Public, approved journals about this destination (matched on city or the journal's detected destination). */
function ja_dest_journals($city, $dest, $limit = 3) {
    $conn = ja_db();
    $sql = "SELECT d.entry_id, d.Title, d.City, d.Description, d.published_at, s.fname,
              (SELECT m.filepath FROM media m WHERE m.entry_id = d.entry_id AND m.kind = 'photo' ORDER BY m.media_id LIMIT 1) AS cover,
              (SELECT j.duration_days FROM journals j WHERE j.entry_id = d.entry_id) AS days
            FROM db d LEFT JOIN signup s ON s.eml = d.eml
            WHERE d.visibility = 'public' AND d.moderation_status = 'approved'
              AND (d.City = ? OR EXISTS (SELECT 1 FROM journal_features jf WHERE jf.entry_id = d.entry_id AND jf.canonical_dest = ?))
            ORDER BY d.published_at DESC, d.entry_id DESC LIMIT " . (int)$limit;
    $st = @mysqli_prepare($conn, $sql);
    if (!$st) return [];
    mysqli_stmt_bind_param($st, 'ss', $city, $dest);
    mysqli_stmt_execute($st);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);
    mysqli_stmt_close($st);
    return $rows;
}

/** Small compare toggle button (progressively enhanced by js/discover.js). */
function ja_compare_btn($destination, $label = 'Compare') {
    $d = htmlspecialchars(trim(explode(',', $destination)[0]), ENT_QUOTES);
    return '<button type="button" class="dz-compare" data-compare="' . $d . '" aria-pressed="false">'
         . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 4v16M5 8h14M5 8l-2 6a3 3 0 0 0 4 0zM19 8l-2 6a3 3 0 0 0 4 0z"/></svg>'
         . '<span>' . htmlspecialchars($label) . '</span></button>';
}

/** Ensure the discover stylesheet and script are on the page (cards can render on pages that do not link them). */
function ja_discover_assets() {
    static $done = false;
    if ($done) return;
    $done = true;
    $v = (int)@filemtime(__DIR__ . '/css/pages-discover.css');
    $j = (int)@filemtime(__DIR__ . '/js/discover.js');
    echo '<link rel="stylesheet" href="css/pages-discover.css?v=' . $v . '">';
    echo '<script src="js/discover.js?v=' . $j . '" defer></script>';
}

/** Plain-language error block for when the ML service cannot answer (replaces the old warning banner). */
function ja_service_error($result = null, $what = 'rank destinations') {
    $detail = '';
    if (!empty($result['__error']) && stripos($result['__error'], 'offline') === false && stripos($result['__error'], 'Invalid response') === false) {
        $detail = '<p class="dz-err-detail">' . htmlspecialchars($result['__error']) . '</p>';
    }
    return '<div class="dz-error" role="alert"><h3>We can\'t ' . htmlspecialchars($what) . ' right now</h3>'
         . '<p>The recommendation service is not responding. Your journals and saved trips are fine. Wait a minute and reload this page.</p>'
         . $detail
         . '<a class="ja-btn ja-btn-ghost" href="' . htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') . '">Reload</a></div>';
}

/** "₹2,400 to ₹2,600", or "about ₹2,400" when the model's band is too narrow to mean anything. */
function ja_range_text($low, $high) {
    $l = ja_inr_round($low); $h = ja_inr_round($high);
    return ($h - $l) <= max(100, 0.04 * $h) ? 'about ' . ja_inr(ja_inr_round(($low + $high) / 2)) : ja_inr($l) . ' to ' . ja_inr($h);
}
