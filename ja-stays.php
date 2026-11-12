<?php
/**
 * JourneyAI — "Where to stay": request cleaning, the ML call, and the HTML for the section and its cards.
 * Used by trip.php (the section shell, filled in by js/stays.js) and by stays-api.php (JSON + rendered cards).
 * Every value that came from OpenStreetMap, Wikivoyage or Wikimedia is escaped on output; links must be http(s).
 */
require_once __DIR__ . '/ja-icons.php';
require_once __DIR__ . '/ml_client.php';

const JA_STAY_STYLES = ['budget', 'mid-range', 'luxury', 'adventure', 'family', 'solo', 'backpacker'];
/** Trip types the toggle offers: key => label. Keys are in the ML service's activity vocabulary. */
const JA_STAY_TYPES = ['beach' => 'Beach', 'history' => 'Heritage', 'temples' => 'Spiritual', 'nature' => 'Nature and wildlife',
                       'adventure' => 'Adventure', 'food' => 'Food', 'relaxation' => 'Relaxing', 'romantic' => 'Romantic'];
const JA_STAY_ALLOWED = ['beach', 'trekking', 'food', 'nightlife', 'history', 'temples', 'museums', 'shopping', 'wildlife', 'adventure',
                         'relaxation', 'photography', 'nature', 'culture', 'backwaters', 'mountains', 'desert', 'snow', 'diving',
                         'architecture', 'family', 'romantic', 'spiritual', 'budget'];

/** Clamp and whitelist everything that came from the browser. */
function ja_stays_params($src) {
    $dest = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string)($src['dest'] ?? '')));
    $dest = mb_substr($dest, 0, 120);
    $days = max(1, min(30, (int)($src['days'] ?? 3)));
    $style = in_array($src['style'] ?? '', JA_STAY_STYLES, true) ? $src['style'] : 'mid-range';
    $raw = $src['interests'] ?? '';
    if (is_array($raw)) $raw = implode(',', array_filter($raw, 'is_string'));
    $int = [];
    foreach (explode(',', strtolower((string)$raw)) as $i) {
        $i = trim($i);
        if (in_array($i, JA_STAY_ALLOWED, true) && !in_array($i, $int, true)) $int[] = $i;
        if (count($int) >= 6) break;
    }
    $party = max(1, min(20, (int)($src['party'] ?? 1)));
    return ['dest' => $dest, 'days' => $days, 'style' => $style, 'interests' => $int, 'party' => $party];
}

function ja_stays_fetch($p) {
    if ($p['dest'] === '') return ['status' => 'error', 'error' => 'Choose a destination first.'];
    $r = ml_request('POST', '/stays', ['destination' => $p['dest'], 'days' => $p['days'], 'travel_style' => $p['style'],
                    'interests' => $p['interests'], 'party_size' => $p['party']], 150);
    if (!empty($r['__error'])) return ['status' => 'error', 'error' => $r['__error'], 'down' => stripos($r['__error'], 'offline') !== false];
    return $r;
}

function st_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Only http(s) URLs get through; a bare domain from OSM gets https:// added. */
function st_url($u) {
    $u = trim((string)$u);
    if ($u === '' || preg_match('/[\s<>"\']/', $u)) return '';
    if (!preg_match('#^https?://#i', $u)) $u = 'https://' . $u;
    $host = parse_url($u, PHP_URL_HOST);
    return ($host && strpos($host, '.') !== false) ? $u : '';
}

function st_type_icon($type) {
    return ['hostel' => 'bed', 'camp' => 'tent', 'eco_lodge' => 'tent', 'guest_house' => 'home', 'homestay' => 'home', 'apartment' => 'home',
            'dharamshala' => 'home'][$type] ?? 'hotel';
}

function st_card($s, $i) {
    $img = $s['image'] ?? null;
    $media = '';
    if ($img && preg_match('#^https://commons\.wikimedia\.org/#', (string)($img['src'] ?? ''))) {
        $lic = trim(($img['license'] ?? '') . (!empty($img['author']) ? ', ' . $img['author'] : ''));
        $media = '<figure class="st-media"><img src="' . st_h($img['src']) . '" alt="' . st_h($s['name']) . '" loading="lazy" width="400" height="260" referrerpolicy="no-referrer">'
               . '<figcaption><a href="' . st_h($img['credit_page']) . '" target="_blank" rel="noopener">Photo' . ($lic !== '' ? ': ' . st_h($lic) : '') . '</a></figcaption></figure>';
    } else {
        $media = '<figure class="st-media st-nophoto">' . ja_icon(st_type_icon($s['type'] ?? ''), 34) . '<figcaption>No open-licensed photo for this place</figcaption></figure>';
    }
    $h = '<article class="st-card" data-mo style="--i:' . (int)$i . '">' . $media . '<div class="st-main">';
    $h .= '<p class="st-kind"><span>' . st_h($s['type_label']) . '</span><span class="st-tier' . (empty($s['tier']) ? ' st-tier-unk' : '') . '">' . (empty($s['tier']) ? 'Tier not mapped' : st_h($s['tier_label'])) . '</span></p>';
    $h .= '<h4>' . st_h($s['name']) . '</h4>';
    $h .= '<p class="st-meta"><span>' . ja_icon('map-pin', 14) . ' ' . number_format((float)$s['distance_km'], 1) . ' km from ' . st_h($s['base_label'] ?? 'the base') . '</span>';
    if (!empty($s['address'])) $h .= '<span>' . st_h($s['address']) . '</span>';
    $h .= '</p>';
    if (!empty($s['price']['inr'])) {
        $pu = st_url($s['price']['url'] ?? '');
        $h .= '<p class="st-price" title="' . st_h($s['price']['raw'] ?? '') . '"><strong>About ₹' . number_format((float)$s['price']['inr']) . ' a night</strong> '
            . '<span>as listed on ' . ($pu ? '<a href="' . st_h($pu) . '" target="_blank" rel="noopener">' . st_h($s['price']['source']) . '</a>' : st_h($s['price']['source'])) . '</span></p>';
    } else {
        $h .= '<p class="st-price st-noprice">No price listed in our sources</p>';
    }
    if (!empty($s['why'])) {
        $h .= '<ul class="st-why">';
        foreach (array_slice($s['why'], 0, 4) as $w) $h .= '<li>' . st_h($w) . '</li>';
        $h .= '</ul>';
    }
    $maps = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($s['lat'] . ',' . $s['lon']);
    $h .= '<div class="st-links"><a class="st-link" href="' . st_h($maps) . '" target="_blank" rel="noopener">' . ja_icon('map-pin', 15) . ' Open in maps</a>';
    $web = st_url($s['website'] ?? '');
    if ($web) $h .= '<a class="st-link" href="' . st_h($web) . '" target="_blank" rel="noopener nofollow">' . ja_icon('arrow', 15) . ' Website</a>';
    if (!empty($s['phone']) && preg_match('/^[+0-9 ()\-;,]{5,30}$/', $s['phone'])) $h .= '<span class="st-phone">' . st_h($s['phone']) . '</span>';
    $h .= '</div></div></article>';
    return $h;
}

/** Night strip: one block per night, coloured by base. */
function st_nights($plan) {
    if (empty($plan['nights'])) return '';
    $h = '<ol class="st-nights" aria-label="Where you sleep each night">';
    $n = 1;
    foreach ($plan['bases'] as $b) {
        for ($j = 0; $j < (int)$b['nights']; $j++, $n++) {
            $h .= '<li class="st-n st-b' . ((int)$b['id'] % 3) . '" title="' . st_h($b['label']) . '"><span class="st-sr">Night ' . $n . ': ' . st_h($b['label']) . '</span></li>';
        }
    }
    return $h . '</ol>';
}

/** The whole result: trip-length header, the cards (grouped by base when there are several) and the honesty footer. */
function ja_stays_html($d) {
    if (($d['status'] ?? '') !== 'ok') {
        $down = !empty($d['down']);
        return '<div class="st-state" role="alert"><h3>' . ($down ? 'The stay finder is not answering' : 'We could not load places to stay') . '</h3><p>'
             . st_h($down ? 'Your trip is fine. Try again in a moment.' : ($d['error'] ?? 'Try again in a moment.'))
             . '</p><button type="button" class="ja-btn ja-btn-ghost" data-st-retry>Try again</button></div>';
    }
    $plan = $d['plan'];
    $h = '<div class="st-plan st-' . st_h($plan['kind']) . '"><div class="st-plan-text"><h3>' . st_h($plan['headline']) . '</h3><p>' . st_h($plan['summary']) . '</p></div>' . st_nights($plan) . '</div>';
    if (!empty($d['empty'])) {
        return $h . '<div class="st-state"><h3>' . st_h($d['message'] ?? 'No mapped places to stay here yet.') . '</h3><p>OpenStreetMap volunteers have not added hotels or guest houses for this area. Try a nearby town.</p></div>';
    }
    $stays = $d['stays'] ?? [];
    $multi = count($plan['bases']) > 1;
    if ($plan['kind'] === 'day') $h .= '<h4 class="st-sub">If you decide to stay over</h4>';
    if ($multi) {
        foreach ($plan['bases'] as $b) {
            $h .= '<section class="st-base"><h4 class="st-sub">' . st_h($b['label']) . ' <small>' . (int)$b['nights'] . ' ' . ((int)$b['nights'] === 1 ? 'night' : 'nights') . '</small></h4>';
            if (!empty($b['stops'])) $h .= '<p class="st-stops">Near ' . st_h(implode(', ', array_slice($b['stops'], 0, 4))) . '</p>';
            $h .= '<div class="st-grid">';
            $i = 0;
            foreach ($stays as $s) if ((int)$s['base'] === (int)$b['id']) $h .= st_card($s, $i++);
            $h .= '</div></section>';
        }
    } else {
        $h .= '<div class="st-grid">';
        foreach ($stays as $i => $s) $h .= st_card($s, $i);
        $h .= '</div>';
    }
    $cov = $d['coverage'] ?? [];
    $np = (int)($cov['with_photo'] ?? 0);
    $h .= '<p class="st-foot">' . count($stays) . ' of ' . (int)($cov['candidates'] ?? 0) . ' mapped places shown; ' . $np . ' ' . ($np === 1 ? 'has' : 'have')
        . ' an open-licensed photo. Places come from OpenStreetMap, prices only from Wikivoyage, photos only from Wikimedia. We never show a picture of somewhere else in place of the property.</p>';
    foreach (($d['notes'] ?? []) as $n) $h .= '<p class="st-foot">' . st_h($n) . '</p>';
    $h .= '<details class="st-how"><summary>How these were ranked</summary><p>Each place scores 28% on distance to the base, 24% on how well its type suits your trip type, '
        . '18% on how well its tier matches your travel style, 12% on how notable it is, 10% on how complete its listing is and 8% on having a real photo. '
        . 'The base is the point that minimises travel to your stops. ' . st_h($plan['explain']) . '</p></details>';
    return $h;
}

/** The section shell on trip.php; js/stays.js fills .st-body. */
function ja_stays_section($p) {
    $uid = 'st' . substr(md5($p['dest']), 0, 6);
    $h = '<section class="st-section" id="stays" data-st-api="stays-api.php" data-dest="' . st_h($p['dest']) . '" data-days="' . (int)$p['days'] . '" data-style="' . st_h($p['style'])
       . '" data-interests="' . st_h(implode(',', $p['interests'])) . '" data-party="' . (int)$p['party'] . '" aria-labelledby="' . $uid . '">';
    $h .= '<div class="st-top"><div><h2 id="' . $uid . '">Where to stay</h2><p class="st-lede">Chosen for a ' . (int)$p['days'] . '-day trip: how many nights, where to base, and real places mapped there.</p></div></div>';
    $h .= '<div class="st-controls tp-noprint"><div class="st-chips" role="group" aria-label="Trip type">';
    foreach (JA_STAY_TYPES as $k => $lab) {
        $on = in_array($k, $p['interests'], true);
        $h .= '<button type="button" class="st-chip" data-int="' . st_h($k) . '" aria-pressed="' . ($on ? 'true' : 'false') . '">' . st_h($lab) . '</button>';
    }
    $h .= '</div><label class="st-style">Style<select class="ja-select" data-st-style>';
    foreach (JA_STAY_STYLES as $s) $h .= '<option value="' . $s . '"' . ($s === $p['style'] ? ' selected' : '') . '>' . ucfirst($s) . '</option>';
    $h .= '</select></label></div>';
    $h .= '<div class="st-body" aria-live="polite" aria-busy="true"><div class="st-skel" role="status"><p>Finding places to stay. The first lookup for a destination can take up to a minute.</p>'
        . '<div class="st-skel-grid"><i></i><i></i><i></i></div></div></div>';
    $h .= '<noscript><p class="st-foot">Where to stay needs JavaScript to load.</p></noscript></section>';
    return $h;
}
