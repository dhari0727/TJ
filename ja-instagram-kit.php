<?php
/**
 * JourneyAI - Instagram Kit: Instagram-ready images, caption and hashtags made from a member's own public, approved content.
 *
 *   igk_item_ok($kind, $id, $eml, $path)      -> [$item, $error]   owner + public + approved only (same rules as the share sheet)
 *   igk_meta($item)                           -> what the Kit modal shows (formats, templates, slides, caption, hashtags, alt text)
 *   igk_render($item, $format, $template, $n) -> JPEG bytes (1080x1350 / 1080x1080 / 1080x1920, sRGB, no EXIF)
 *
 * Everything on a card (place, days, cost, sentences) comes from the member's own saved data. Nothing is invented.
 * Images are drawn server side with PHP GD, no external service. Output is re-encoded, so no EXIF or GPS can survive.
 * Direct posting helpers (public image links for Meta's servers) are at the bottom.
 */
require_once __DIR__ . '/ja-social.php';

const IGK_MAX_SLIDES = 10;
const IGK_CAPTION_MAX = 2200;
const IGK_HOOK_MAX = 125;
const IGK_QUEUE_TTL = 900;   // 15 minutes
const IGK_FORMATS = ['feed' => [1080, 1350, 'Feed 4:5'], 'square' => [1080, 1080, 'Square'], 'story' => [1080, 1920, 'Story 9:16'], 'carousel' => [1080, 1350, 'Carousel']];
const IGK_TEMPLATES = ['photo' => 'Photo and caption', 'polaroid' => 'Polaroid', 'journey' => 'Journey card', 'quote' => 'Quote card'];

/* ------------------------------------------------------------------ fonts and drawing helpers */
function igk_font($role) {
    static $cache = [];
    if (isset($cache[$role])) return $cache[$role];
    $w = 'C:/Windows/Fonts/'; $l = '/usr/share/fonts/truetype/dejavu/';
    $map = [
        'serifb' => [__DIR__ . '/fonts/Fraunces-Bold.ttf', $w . 'georgiab.ttf', $l . 'DejaVuSerif-Bold.ttf'],
        'serif'  => [__DIR__ . '/fonts/Fraunces.ttf', $w . 'georgia.ttf', $l . 'DejaVuSerif.ttf'],
        'serifi' => [__DIR__ . '/fonts/Fraunces-Italic.ttf', $w . 'georgiai.ttf', $l . 'DejaVuSerif-Italic.ttf'],
        'sans'   => [__DIR__ . '/fonts/InstrumentSans.ttf', $w . 'segoeui.ttf', $w . 'arial.ttf', $l . 'DejaVuSans.ttf'],
        'sansb'  => [__DIR__ . '/fonts/InstrumentSans-Bold.ttf', $w . 'segoeuib.ttf', $w . 'arialbd.ttf', $l . 'DejaVuSans-Bold.ttf'],
    ];
    foreach ($map[$role] ?? [] as $f) if (is_file($f)) return $cache[$role] = $f;
    throw new RuntimeException('No font is available on the server for the image templates. Put Fraunces and Instrument Sans .ttf files in the fonts folder.');
}
function igk_pt($px) { return $px * 0.75; }                       // GD sizes are points at 96 dpi
function igk_w($role, $px, $text) { $b = imagettfbbox(igk_pt($px), 0, igk_font($role), (string)$text); return $b[2] - $b[0]; }
function igk_col($im, $hex, $alpha = 0) { sscanf($hex, '#%02x%02x%02x', $r, $g, $b); return imagecolorallocatealpha($im, $r, $g, $b, $alpha); }
function igk_text($im, $role, $px, $x, $y, $hex, $text, $shadow = false, $alpha = 0) {
    $f = igk_font($role);
    if ($shadow) imagettftext($im, igk_pt($px), 0, (int)$x + 2, (int)$y + 3, igk_col($im, '#000000', 92), $f, (string)$text);
    imagettftext($im, igk_pt($px), 0, (int)$x, (int)$y, igk_col($im, $hex, $alpha), $f, (string)$text);
}
function igk_wrap($role, $px, $text, $maxW, $maxLines) {
    $words = preg_split('/\s+/u', trim((string)$text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $lines = []; $cur = '';
    foreach ($words as $w) {
        $try = $cur === '' ? $w : $cur . ' ' . $w;
        if ($cur !== '' && igk_w($role, $px, $try) > $maxW) { $lines[] = $cur; $cur = $w; } else $cur = $try;
    }
    if ($cur !== '') $lines[] = $cur;
    $over = count($lines) > $maxLines;
    if ($over) {
        $lines = array_slice($lines, 0, $maxLines);
        $last = rtrim($lines[$maxLines - 1], " ,.;:");
        while ($last !== '' && igk_w($role, $px, $last . '...') > $maxW) { $p = mb_strrpos($last, ' '); $last = $p ? mb_substr($last, 0, $p) : mb_substr($last, 0, -1); }
        $lines[$maxLines - 1] = $last . '...';
    }
    return $lines;
}
/** Largest size in $sizes whose wrapped text fits $maxLines. Returns [size, lines]. */
function igk_fit($role, array $sizes, $text, $maxW, $maxLines) {
    foreach ($sizes as $s) {
        $lines = igk_wrap($role, $s, $text, $maxW, 99);
        if (count($lines) <= $maxLines) return [$s, $lines];
    }
    $s = end($sizes);
    return [$s, igk_wrap($role, $s, $text, $maxW, $maxLines)];
}
/** Draw lines so the LAST baseline sits at $bottom. Returns the top y of the block. */
function igk_block_bottom($im, $role, $px, array $lines, $x, $bottom, $hex, $lh, $shadow = false, $center = null) {
    $n = count($lines); $y = $bottom - ($n - 1) * $lh;
    foreach ($lines as $i => $ln) {
        $xx = $center === null ? $x : $center - igk_w($role, $px, $ln) / 2;
        igk_text($im, $role, $px, $xx, $y + $i * $lh, $hex, $ln, $shadow);
    }
    return $y - $px;
}
function igk_block_top($im, $role, $px, array $lines, $x, $top, $hex, $lh, $center = null) {
    $y = $top + $px;
    foreach ($lines as $ln) {
        $xx = $center === null ? $x : $center - igk_w($role, $px, $ln) / 2;
        igk_text($im, $role, $px, $xx, $y, $hex, $ln);
        $y += $lh;
    }
    return $y - $lh + ($lh - $px);
}
function igk_canvas($W, $H, $hex = '#123524') {
    $im = imagecreatetruecolor($W, $H);
    imagealphablending($im, true);
    imagefilledrectangle($im, 0, 0, $W, $H, igk_col($im, $hex));
    return $im;
}
function igk_load($abs, $maxSide = 2600) {
    $raw = @file_get_contents($abs);
    $im = $raw === false ? false : @imagecreatefromstring($raw);
    if (!$im) throw new RuntimeException('One of the photos could not be read.');
    $w = imagesx($im); $h = imagesy($im);
    if (max($w, $h) > $maxSide) { $s = $maxSide / max($w, $h); $t = imagescale($im, (int)round($w * $s), (int)round($h * $s)); if ($t) { imagedestroy($im); $im = $t; } }
    return $im;
}
/** Copy $src into the box, scaled to cover it and cropped around the centre. */
function igk_cover($dst, $src, $x, $y, $w, $h) {
    $sw = imagesx($src); $sh = imagesy($src);
    $r = max($w / $sw, $h / $sh);
    $cw = (int)round($w / $r); $ch = (int)round($h / $r);
    imagecopyresampled($dst, $src, (int)$x, (int)$y, (int)(($sw - $cw) / 2), (int)(($sh - $ch) / 2), (int)$w, (int)$h, $cw, $ch);
}
/** Vertical colour fade from $y0 to $y1; alpha goes $a0 -> $a1 (0 opaque, 127 clear). */
function igk_fade($im, $W, $y0, $y1, $rgb, $a0, $a1, $curve = 1.4) {
    $n = max(1, $y1 - $y0);
    for ($i = 0; $i <= $n; $i++) {
        $t = pow($i / $n, $curve);
        $a = (int)round($a0 + ($a1 - $a0) * $t);
        imagefilledrectangle($im, 0, $y0 + $i, $W, $y0 + $i, imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], max(0, min(127, $a))));
    }
}
function igk_jpeg($im) {
    imageinterlace($im, false);
    ob_start(); imagejpeg($im, null, 90); $b = ob_get_clean();
    imagedestroy($im);
    return $b;
}
/** Margins (px) that keep key text inside the area Instagram never crops or covers. */
function igk_safe($format, $W, $H) {
    if ($format === 'story') return ['l' => 72, 'r' => 72, 't' => 250, 'b' => 340];      // top and bottom bars of the Stories viewer
    if ($format === 'square') return ['l' => 150, 'r' => 150, 't' => 110, 'b' => 110];   // profile grid shows the middle 3:4 (810 px)
    return ['l' => 72, 'r' => 72, 't' => 80, 'b' => 100];                               // 4:5 grid crop leaves the middle 1012 px
}
function igk_inr($n) {
    $n = (int)round($n); $s = (string)abs($n);
    if (strlen($s) > 3) { $last = substr($s, -3); $rest = substr($s, 0, -3); $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest); $s = $rest . ',' . $last; }
    return "\u{20B9}" . $s;
}

/* ------------------------------------------------------------------ the item, as the Kit may use it */
/** Owner only, public + approved only. Returns [item, null] or [null, plain error]. */
function igk_item_ok($kind, $id, $eml, $path = null) {
    if (!$eml) return [null, 'Log in to make Instagram images.'];
    $item = ja_social_item($kind, $id, $eml, $path);
    if (!$item || !$item['owner']) return [null, 'You can only make Instagram images from your own items.'];
    if (!$item['shareable']) return [null, $item['reason'] !== '' ? $item['reason'] : 'Make this public first.'];
    return [$item, null];
}

/** Facts for the cards. Only saved data; missing values stay empty and are simply not drawn. */
function igk_facts(array $item) {
    static $cache = [];
    $key = $item['kind'] . '|' . $item['id'];
    if (isset($cache[$key])) return $cache[$key];
    $conn = ja_db();
    $f = ['title' => (string)$item['title'], 'place' => '', 'city' => '', 'country' => '', 'region' => '', 'days' => 0, 'cost' => null, 'cost_hi' => null,
          'cost_kind' => '', 'text' => '', 'places' => [], 'activities' => [], 'media_ids' => []];
    $id = $item['id'];
    if ($item['kind'] === 'journal') {
        $st = mysqli_prepare($conn, "SELECT City, Country, Description, duration_days, true_total, ptv FROM journals WHERE entry_id = ?");
        $i = (int)$id; mysqli_stmt_bind_param($st, 'i', $i); mysqli_stmt_execute($st);
        $r = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
        if ($r) {
            $f['city'] = trim((string)$r['City']); $f['country'] = trim((string)$r['Country']);
            $f['days'] = (int)$r['duration_days']; $f['text'] = (string)$r['Description'];
            if ((float)$r['true_total'] > 0) { $f['cost'] = (float)$r['true_total']; $f['cost_kind'] = 'spent'; }
            foreach (preg_split('/\s*,\s*/', (string)$r['ptv']) ?: [] as $p) if ($p !== '' && mb_strlen($p) <= 28 && count($f['places']) < 6) $f['places'][] = $p;
        }
        $q = mysqli_prepare($conn, "SELECT media_id FROM media WHERE entry_id = ? AND is_public = 1 AND moderation_status = 'approved' LIMIT 30");
        mysqli_stmt_bind_param($q, 'i', $i); mysqli_stmt_execute($q);
        foreach (mysqli_fetch_all(mysqli_stmt_get_result($q), MYSQLI_ASSOC) as $m) $f['media_ids'][] = (int)$m['media_id'];
        mysqli_stmt_close($q);
    } elseif ($item['kind'] === 'book') {
        $st = mysqli_prepare($conn, "SELECT subtitle FROM storybooks WHERE book_id = ?");
        $i = (int)$id; mysqli_stmt_bind_param($st, 'i', $i); mysqli_stmt_execute($st);
        $r = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
        $f['text'] = trim((string)($r['subtitle'] ?? ''));
    } elseif ($item['kind'] === 'post' || $item['kind'] === 'upload') {
        if ($item['kind'] === 'post') { $st = mysqli_prepare($conn, "SELECT media_id, caption, destination FROM media WHERE media_id = ?"); $i = (int)$id; mysqli_stmt_bind_param($st, 'i', $i); }
        else { $st = mysqli_prepare($conn, "SELECT media_id, caption, destination FROM media WHERE filepath = ? LIMIT 1"); $i = (string)$id; mysqli_stmt_bind_param($st, 's', $i); }
        mysqli_stmt_execute($st);
        $r = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
        if ($r) {
            $f['text'] = trim((string)$r['caption']); $f['media_ids'][] = (int)$r['media_id'];
            $parts = array_map('trim', explode(',', (string)$r['destination']));
            $f['city'] = $parts[0] ?? ''; $f['country'] = count($parts) > 1 ? end($parts) : '';
        }
    } elseif ($item['kind'] === 'plan') {
        $st = mysqli_prepare($conn, "SELECT destination, days, itinerary FROM saved_plans WHERE plan_id = ?");
        $i = (int)$id; mysqli_stmt_bind_param($st, 'i', $i); mysqli_stmt_execute($st);
        $r = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
        if ($r) {
            $parts = array_map('trim', explode(',', (string)$r['destination']));
            $f['city'] = $parts[0] ?? ''; $f['country'] = count($parts) > 1 ? end($parts) : '';
            $f['days'] = (int)$r['days'];
            $j = json_decode((string)$r['itinerary'], true);
            if (is_array($j)) {
                if (!empty($j['cost_low']) && !empty($j['cost_high'])) { $f['cost'] = (float)$j['cost_low']; $f['cost_hi'] = (float)$j['cost_high']; $f['cost_kind'] = 'estimate'; }
                foreach ((array)($j['activities'] ?? []) as $a) if (is_string($a) && count($f['activities']) < 5) $f['activities'][] = $a;
                if (!$f['country'] && !empty($j['country'])) $f['country'] = (string)$j['country'];
            }
        }
    }
    if ($f['city'] !== '') {
        $st = mysqli_prepare($conn, "SELECT region FROM destinations WHERE city = ? OR canonical_name = ? LIMIT 1");
        mysqli_stmt_bind_param($st, 'ss', $f['city'], $f['city']); mysqli_stmt_execute($st);
        $r = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
        $reg = trim((string)($r['region'] ?? ''));
        if ($reg !== '' && strcasecmp($reg, $f['city']) !== 0 && strcasecmp($reg, $f['country']) !== 0) $f['region'] = $reg;
    }
    if ($f['city'] === '' && $f['region'] === '') {   // a storybook or photo with no saved place: use a destination named in its own title
        $words = array_values(array_unique(array_filter(preg_split('/[^\p{L}]+/u', mb_strtolower($f['title'])), function ($w) { return mb_strlen($w) >= 4; })));
        if ($words) {
            $in = implode(',', array_map(function ($w) use ($conn) { return "'" . mysqli_real_escape_string($conn, $w) . "'"; }, array_slice($words, 0, 12)));
            $rs = mysqli_query($conn, "SELECT city, country, region FROM destinations WHERE LOWER(city) IN ($in) OR LOWER(region) IN ($in) ORDER BY popularity_tier LIMIT 1");
            $r = $rs ? mysqli_fetch_assoc($rs) : null;
            if ($r) {
                if (in_array(mb_strtolower((string)$r['city']), $words, true)) { $f['city'] = (string)$r['city']; $f['country'] = (string)$r['country']; }
                else { $f['region'] = (string)$r['region']; $f['country'] = (string)$r['country']; }
            }
        }
    }
    $f['place'] = trim($f['city'] . ($f['country'] !== '' && strcasecmp($f['country'], $f['city']) !== 0 ? ', ' . $f['country'] : ''), ', ');
    return $cache[$key] = $f;
}

function igk_sentences($text) {
    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$text), ENT_QUOTES, 'UTF-8')));
    $t = preg_replace('/#\w+/u', '', $t);
    if ($t === '') return [];
    $parts = preg_split('/(?<=[.!?])\s+(?=[\p{Lu}\p{N}"\'(])/u', $t) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if (mb_strlen($p) < 20 || preg_match('~https?://|www\.~i', $p)) continue;
        if (!preg_match('/\p{L}{3}/u', $p)) continue;
        $out[] = $p;
    }
    return $out;
}
function igk_trim_words($s, $max) {
    $s = trim((string)$s);
    if (mb_strlen($s) <= $max) return $s;
    $cut = mb_substr($s, 0, $max - 3);
    $sp = mb_strrpos($cut, ' ');
    return rtrim(mb_substr($cut, 0, $sp !== false && $sp > $max * 0.5 ? $sp : $max - 3), " ,.;:-") . '...';
}
function igk_quote_text(array $f) {
    $s = igk_sentences($f['text']); if (!$s) return '';
    $q = $s[0];
    if (mb_strlen($q) < 70 && isset($s[1]) && mb_strlen($q . ' ' . $s[1]) <= 220) $q .= ' ' . $s[1];
    return mb_strlen($q) >= 40 ? igk_trim_words($q, 240) : '';
}

/* ------------------------------------------------------------------ caption, hashtags, alt text */
function igk_hashtags(array $item, array $f) {
    $conn = ja_db();
    $B = ['dest' => [], 'feed' => [], 'text' => [], 'region' => [], 'activity' => [], 'place' => []];
    $add = function ($w, $src, $bucket) use (&$B) { $t = ja_social_tagify($w); if ($t !== '' && mb_strlen($t) >= 3) $B[$bucket][] = [$t, $src]; };
    $add($f['city'], 'destination', 'dest'); $add($f['country'], 'destination', 'dest');
    // tags the community feed already uses on this member's media
    if ($f['media_ids']) {
        $ids = implode(',', array_map('intval', $f['media_ids']));
        $rs = mysqli_query($conn, "SELECT h.tag FROM media_hashtags mh JOIN hashtags h ON h.tag_id = mh.tag_id WHERE mh.media_id IN ($ids) GROUP BY h.tag_id ORDER BY h.uses DESC LIMIT 6");
        while ($rs && ($r = mysqli_fetch_assoc($rs))) $add($r['tag'], 'feed', 'feed');
    }
    // a tag that already exists in the feed table and appears in the member's own text
    $words = array_unique(array_filter(array_map('mb_strtolower', preg_split('/[^\p{L}\p{N}]+/u', (string)$f['text']) ?: []), function ($w) { return mb_strlen($w) >= 4; }));
    if ($words) {
        $in = implode(',', array_map(function ($w) use ($conn) { return "'" . mysqli_real_escape_string($conn, $w) . "'"; }, array_slice($words, 0, 80)));
        $rs = mysqli_query($conn, "SELECT tag FROM hashtags WHERE tag IN ($in) ORDER BY uses DESC LIMIT 3");
        while ($rs && ($r = mysqli_fetch_assoc($rs))) $add($r['tag'], 'text', 'text');
    }
    $add($f['region'], 'region', 'region');
    foreach ($f['activities'] as $a) $add($a, 'activity', 'activity');
    foreach (array_slice($f['places'], 0, 3) as $p) if (str_word_count($p) <= 2) $add($p, 'place', 'place');
    $cand = array_merge($B['dest'], $B['feed'], $B['text'], $B['region'], $B['activity'], $B['place']);
    $out = []; $seen = [];
    foreach ($cand as [$t, $src]) { $k = mb_strtolower($t); if (isset($seen[$k])) continue; $seen[$k] = 1; $out[] = ['tag' => $t, 'source' => $src]; if (count($out) >= 5) break; }
    foreach (['Travel', 'TravelJournal', 'Wanderlust'] as $g) {
        if (count($out) >= 3) break;
        if (!isset($seen[mb_strtolower($g)])) { $seen[mb_strtolower($g)] = 1; $out[] = ['tag' => $g, 'source' => 'general']; }
    }
    return array_slice($out, 0, 5);
}
function igk_caption(array $item, array $f, array $tags) {
    $sents = igk_sentences($f['text']);
    $tagLine = $tags ? '#' . implode(' #', array_column($tags, 'tag')) : '';
    $site = ja_setting('site_name', 'JourneyAI');
    if ($sents) { $hook = igk_trim_words($sents[0], IGK_HOOK_MAX); $rest = mb_strlen($sents[0]) > IGK_HOOK_MAX ? array_slice($sents, 1) : array_slice($sents, 1); }
    else {
        if ($item['kind'] === 'plan') $hook = igk_trim_words(($f['days'] ? $f['days'] . '-day' : 'A') . ' trip plan for ' . ($f['place'] ?: $f['title']), IGK_HOOK_MAX);
        else $hook = igk_trim_words($f['title'] . ($f['place'] && stripos($f['title'], $f['city']) === false ? ' in ' . $f['place'] : ''), IGK_HOOK_MAX);
        $rest = [];
    }
    $body = ''; foreach ($rest as $s) { if (mb_strlen($body . ' ' . $s) > 600) break; $body .= ($body === '' ? '' : ' ') . $s; }
    $facts = [];
    if ($item['kind'] === 'journal' && $f['place']) $facts[] = $f['title'] . ($f['days'] ? ': ' . $f['days'] . ($f['days'] === 1 ? ' day' : ' days') : '') . ' in ' . $f['place'] . '.';
    if ($item['kind'] === 'plan' && $f['cost_kind'] === 'estimate') $facts[] = 'Estimated cost for the trip: ' . igk_inr($f['cost']) . ' to ' . igk_inr($f['cost_hi']) . '.';
    $parts = array_values(array_filter([$hook, $body, implode(' ', $facts), 'Plan your own trip on ' . $site . '.', $tagLine], function ($x) { return trim((string)$x) !== ''; }));
    $cap = implode("\n\n", $parts);
    while (mb_strlen($cap) > IGK_CAPTION_MAX && $body !== '') { $body = igk_trim_words($body, max(0, mb_strlen($body) - 120)); $parts[1] = $body; $cap = implode("\n\n", array_filter($parts)); if (mb_strlen($body) < 20) break; }
    if (mb_strlen($cap) > IGK_CAPTION_MAX) $cap = mb_substr($cap, 0, IGK_CAPTION_MAX);
    return ['caption' => $cap, 'hook' => $hook];
}

/* ------------------------------------------------------------------ slides */
/** The slides for a format. Single formats return one spec per photo (the member picks which); carousel returns the sequence. */
function igk_slides(array $item, $format, $tpl) {
    $f = igk_facts($item); $imgs = $item['images']; $nImg = count($imgs);
    $hasStats = $f['days'] > 0 || $f['cost'] !== null;
    $quote = igk_quote_text($f);
    if ($format !== 'carousel') {
        if (in_array($tpl, ['photo', 'polaroid'], true) && !$nImg) $tpl = 'journey';
        if ($tpl === 'quote' && $quote === '') $tpl = $nImg ? 'photo' : 'journey';
        if (!isset(IGK_TEMPLATES[$tpl])) $tpl = $nImg ? 'photo' : 'journey';
        $out = [];
        if (in_array($tpl, ['photo', 'polaroid'], true)) foreach ($imgs as $i => $_) $out[] = ['type' => $tpl, 'img' => $i, 'cover' => true];
        else $out[] = ['type' => $tpl, 'img' => $nImg ? 0 : null, 'cover' => true];
        return $out;
    }
    $style = $tpl === 'polaroid' ? 'polaroid' : 'photo';
    $s = [];
    $s[] = $nImg ? ['type' => 'photo', 'img' => 0, 'cover' => true] : ['type' => 'journey', 'img' => null, 'cover' => true];
    if ($nImg && $hasStats) $s[] = ['type' => 'journey', 'img' => null, 'cover' => false];
    $tail = 1 + ($quote !== '' ? 1 : 0);
    for ($i = 1; $i < $nImg && count($s) < IGK_MAX_SLIDES - $tail; $i++) $s[] = ['type' => $style, 'img' => $i, 'cover' => false];
    if ($quote !== '') $s[] = ['type' => 'quote', 'img' => null, 'cover' => false];
    $s[] = ['type' => 'close', 'img' => null, 'cover' => false];
    return array_slice($s, 0, IGK_MAX_SLIDES);
}
function igk_alt(array $item, array $f, array $spec) {
    $img = $spec['img'] !== null ? ($item['images'][$spec['img']] ?? null) : null;
    $where = $f['place'] !== '' ? ' in ' . $f['place'] : '';
    switch ($spec['type']) {
        case 'photo': case 'polaroid':
            $a = $img ? trim((string)$img['alt']) : '';
            return $a !== '' ? mb_substr($a, 0, 400) : 'Photo from ' . $f['title'] . $where . '.';
        case 'journey':
            $bits = [$f['place'] ?: $f['title']]; if ($f['days']) $bits[] = $f['days'] . ($f['days'] === 1 ? ' day' : ' days');
            if ($f['cost'] !== null) $bits[] = $f['cost_kind'] === 'estimate' ? 'estimated ' . igk_inr($f['cost']) . ' to ' . igk_inr($f['cost_hi']) : igk_inr($f['cost']) . ' in total';
            return 'Trip card: ' . implode(', ', $bits) . '.';
        case 'quote': return 'Quote card: ' . igk_quote_text($f);
        default: return 'Closing card: Plan a trip like this on ' . ja_setting('site_name', 'JourneyAI') . '.';
    }
}

/** Everything the Kit modal needs for an item. */
function igk_meta(array $item) {
    $f = igk_facts($item);
    $tags = igk_hashtags($item, $f);
    $cap = igk_caption($item, $f, $tags);
    $nImg = count($item['images']);
    $templates = [];
    if ($nImg) { $templates[] = 'photo'; $templates[] = 'polaroid'; }
    if ($f['days'] > 0 || $f['cost'] !== null || $f['place'] !== '' || !$nImg) $templates[] = 'journey';
    if (igk_quote_text($f) !== '') $templates[] = 'quote';
    $formats = [];
    foreach (IGK_FORMATS as $id => [$w, $h, $label]) {
        $tp = $id === 'carousel' ? array_values(array_intersect($templates, ['photo', 'polaroid'])) : $templates;
        if ($id === 'carousel' && !$tp) $tp = ['photo'];
        $slides = igk_slides($item, $id, $tp[0]);
        $alts = []; foreach ($slides as $sp) $alts[] = igk_alt($item, $f, $sp);
        $formats[] = ['id' => $id, 'label' => $label, 'w' => $w, 'h' => $h, 'templates' => $tp, 'count' => count($slides), 'alts' => $alts,
                      'safe' => igk_safe($id, $w, $h)];
    }
    return ['title' => $f['title'], 'place' => $f['place'], 'caption' => $cap['caption'], 'hook' => $cap['hook'], 'hook_max' => IGK_HOOK_MAX, 'caption_max' => IGK_CAPTION_MAX,
            'hashtags' => $tags, 'formats' => $formats, 'template_labels' => IGK_TEMPLATES, 'photos' => $nImg,
            'facts' => ['days' => $f['days'], 'cost' => $f['cost'], 'cost_hi' => $f['cost_hi'], 'cost_kind' => $f['cost_kind']]];
}

/* ------------------------------------------------------------------ rendering */
const IGK_GREEN = '#123524'; const IGK_RIND = '#1E7B4F'; const IGK_MELON = '#E63457'; const IGK_CREAM = '#FFF6EE';

function igk_brand($im, $W, $S, $y, $hex = '#FFF6EE', $shadow = true, $px = 30) {
    igk_text($im, 'sansb', $px, $S['l'], $y, $hex, ja_setting('site_name', 'JourneyAI'), $shadow);
}
function igk_photo_src(array $item, $idx) {
    $im = $item['images'][$idx] ?? null;
    if (!$im) return null;
    $abs = ja_social_abs_media($im['rel']);
    return $abs ? igk_load($abs) : null;
}
function igk_draw_photo_card($format, $W, $H, array $item, array $f, array $spec) {
    $S = igk_safe($format, $W, $H);
    $im = igk_canvas($W, $H);
    $src = igk_photo_src($item, $spec['img']);
    if ($src) { igk_cover($im, $src, 0, 0, $W, $H); imagedestroy($src); }
    igk_fade($im, $W, (int)($H * 0.46), $H, [6, 22, 14], 127, 18, 1.25);
    igk_fade($im, $W, 0, (int)($H * 0.16) + ($format === 'story' ? 120 : 0), [6, 22, 14], 70, 127, 1.0);
    $tw = $W - $S['l'] - $S['r'];
    $bottom = $H - $S['b'];
    $img = $item['images'][$spec['img']] ?? null;
    $cap = $img ? trim((string)$img['alt']) : '';
    $isFallback = $cap === '' || preg_match('/^(Photo from|Cover of|Travel photo)/', $cap);
    if (!empty($spec['cover'])) {
        if ($f['place'] !== '') { igk_text($im, 'sans', 36, $S['l'], $bottom, '#FFF6EE', $f['place'], true); $bottom -= 84; }
        [$px, $lines] = igk_fit('serifb', [104, 92, 80, 70, 60], $f['title'], $tw, 3);
        igk_block_bottom($im, 'serifb', $px, $lines, $S['l'], $bottom, '#FFF6EE', (int)($px * 1.14), true);
        if ($f['place'] !== '') imagefilledrectangle($im, $S['l'], $bottom + 26, $S['l'] + 96, $bottom + 32, igk_col($im, IGK_MELON));
    } elseif (!$isFallback) {
        [$px, $lines] = igk_fit('serifi', [50, 44, 38, 34], $cap, $tw, 3);
        igk_block_bottom($im, 'serifi', $px, $lines, $S['l'], $bottom, '#FFF6EE', (int)($px * 1.3), true);
    } elseif ($f['place'] !== '') {
        igk_text($im, 'sans', 34, $S['l'], $bottom, '#FFF6EE', $f['place'], true);
    }
    igk_brand($im, $W, $S, $S['t'] + 30);
    return $im;
}
function igk_draw_polaroid($format, $W, $H, array $item, array $f, array $spec) {
    $S = igk_safe($format, $W, $H);
    $im = igk_canvas($W, $H);
    $cw = min($W - $S['l'] - $S['r'], $format === 'story' ? 880 : 820);
    $pad = 36; $strip = $format === 'story' ? 200 : 170;
    $cw = min($cw, $H - $S['t'] - $S['b'] - $strip + $pad - 60);   // the tilted card must fit inside the safe band
    $ph = $cw - 2 * $pad; $ch = $pad + $ph + $strip;
    $m = 60; // room for the shadow
    $card = imagecreatetruecolor($cw + 2 * $m, $ch + 2 * $m);
    imagealphablending($card, false); imagesavealpha($card, true);
    imagefill($card, 0, 0, imagecolorallocatealpha($card, 0, 0, 0, 127));
    imagealphablending($card, true);
    for ($i = 22; $i >= 1; $i--) imagefilledrectangle($card, $m - $i + 8, $m - $i + 22, $m + $cw + $i + 8, $m + $ch + $i + 22, imagecolorallocatealpha($card, 0, 0, 0, 125 - (int)(3 * (22 - $i) * 0.18)));
    imagefilledrectangle($card, $m, $m, $m + $cw, $m + $ch, igk_col($card, '#FFFDF9'));
    $src = igk_photo_src($item, $spec['img']);
    if ($src) { igk_cover($card, $src, $m + $pad, $m + $pad, $ph, $ph); imagedestroy($src); }
    $cap = ($item['images'][$spec['img']]['alt'] ?? '');
    $real = $cap !== '' && !preg_match('/^(Photo from|Cover of|Travel photo)/', $cap);
    $line = $real ? $cap : $f['title'];
    [$px, $lines] = igk_fit('serifi', [46, 40, 34, 30], $line, $cw - 2 * $pad, 2);
    $cy = $m + $pad + $ph + 56;
    igk_block_top($card, 'serifi', $px, $lines, 0, $cy - $px, IGK_GREEN, (int)($px * 1.25), $m + $cw / 2);
    if ($f['place'] !== '') igk_text($card, 'sans', 28, $m + $cw / 2 - igk_w('sans', 28, $f['place']) / 2, $m + $ch - 28, IGK_RIND, $f['place']);
    $bg = imagecolorallocatealpha($card, 0, 0, 0, 127);
    $rot = imagerotate($card, 3, $bg);
    imagesavealpha($rot, true);
    $x = (int)(($W - imagesx($rot)) / 2);
    $bandT = $S['t']; $bandH = $H - $S['t'] - $S['b'];
    $y = (int)($bandT + ($bandH - imagesy($rot)) / 2);
    imagealphablending($im, true);
    imagecopy($im, $rot, $x, $y, 0, 0, imagesx($rot), imagesy($rot));
    imagedestroy($card); imagedestroy($rot);
    igk_text($im, 'sansb', 30, $W / 2 - igk_w('sansb', 30, ja_setting('site_name', 'JourneyAI')) / 2, $H - max(52, $S['b'] - 40), '#FFF6EE', ja_setting('site_name', 'JourneyAI'), false, 30);
    return $im;
}
function igk_draw_journey($format, $W, $H, array $item, array $f, array $spec) {
    $S = igk_safe($format, $W, $H);
    $im = igk_canvas($W, $H);
    $tw = $W - $S['l'] - $S['r'];
    $src = igk_photo_src($item, $spec['img'] ?? null);
    $hp = $src ? (int)($H * ($format === 'story' ? 0.40 : 0.44)) : 0;
    if ($src) { igk_cover($im, $src, 0, 0, $W, $hp); imagedestroy($src); igk_fade($im, $W, (int)($hp * 0.45), $hp, [18, 53, 36], 127, 0, 1.2); }
    $stats = [];
    if ($f['days'] > 0) $stats[] = [(string)$f['days'], $f['days'] === 1 ? 'day' : 'days'];
    if ($f['cost'] !== null) {
        $v = $f['cost_kind'] === 'estimate' ? igk_inr($f['cost']) . ' to ' . igk_inr($f['cost_hi']) : igk_inr($f['cost']);
        $stats[] = [$v, $f['cost_kind'] === 'estimate' ? 'estimated for the whole trip' : 'spent in total'];
    }
    $name = $f['place'] !== '' ? ($f['city'] !== '' ? $f['city'] : $f['place']) : $f['title'];
    [$px, $lines] = igk_fit('serifb', [150, 128, 108, 92, 78], $name, $tw, 2);
    $sub = $f['place'] !== '' && $name !== $f['place'] ? $f['place'] : ($name !== $f['title'] ? $f['title'] : '');
    $blockH = count($lines) * (int)($px * 1.1) + 46 + ($sub !== '' ? 60 : 0) + ($stats ? 40 + count($stats) * 150 : 0);
    $top = $hp ? $hp + 10 : $S['t'] + (int)max(0, ($H - $S['t'] - $S['b'] - $blockH) / 2);
    if ($hp && $top < $S['t']) $top = $S['t'];
    $y = igk_block_top($im, 'serifb', $px, $lines, $S['l'], $top, '#FFF6EE', (int)($px * 1.1));
    $y += 26;
    imagefilledrectangle($im, $S['l'], $y, $S['l'] + 110, $y + 8, igk_col($im, IGK_MELON));
    $y += 8 + 44;
    if ($sub !== '') { igk_text($im, 'sans', 38, $S['l'], $y + 26, '#B9D3C3', igk_trim_words($sub, 60)); $y += 64; }
    $y += 20;
    foreach ($stats as [$val, $label]) {
        $vp = igk_w('sansb', 104, $val) > $tw ? 66 : (igk_w('sansb', 104, $val) > $tw * 0.8 ? 84 : 104);
        igk_text($im, 'sansb', $vp, $S['l'], $y + $vp, '#FFF6EE', $val);
        igk_text($im, 'sans', 36, $S['l'] + igk_w('sansb', $vp, $val) + 22, $y + $vp, '#B9D3C3', $label);
        $y += $vp + 54;
    }
    igk_text($im, 'sansb', 30, $S['l'], $H - max(52, $S['b'] - 40), '#FFF6EE', ja_setting('site_name', 'JourneyAI'), false, 30);
    return $im;
}
function igk_draw_quote($format, $W, $H, array $item, array $f, array $spec) {
    $S = igk_safe($format, $W, $H);
    $im = igk_canvas($W, $H, IGK_CREAM);
    $tw = $W - $S['l'] - $S['r'];
    $q = igk_quote_text($f);
    $maxLines = $format === 'story' ? 12 : ($format === 'square' ? 7 : 10);
    [$px, $lines] = igk_fit('serif', [66, 58, 52, 46, 42, 38], $q, $tw, $maxLines);
    $lh = (int)($px * 1.34); $textH = count($lines) * $lh;
    $bandT = $S['t'] + 120; $bandH = $H - $bandT - $S['b'] - 130;
    $top = $bandT + max(0, (int)(($bandH - $textH) / 2));
    igk_text($im, 'serifb', 240, $S['l'] - 6, $top - 6, IGK_MELON, "\u{201C}");
    igk_block_top($im, 'serif', $px, $lines, $S['l'], $top + 70, IGK_GREEN, $lh);
    $by = $top + 70 + $textH + 36;
    $attr = $f['title'] . ($f['place'] !== '' && stripos($f['title'], $f['city']) === false ? ', ' . $f['place'] : '');
    foreach (igk_wrap('sansb', 34, $attr, $tw, 2) as $i => $ln) igk_text($im, 'sansb', 34, $S['l'], $by + $i * 46, IGK_RIND, $ln);
    igk_text($im, 'sansb', 30, $S['l'], $H - max(52, $S['b'] - 40), IGK_GREEN, ja_setting('site_name', 'JourneyAI'));
    return $im;
}
function igk_draw_close($format, $W, $H, array $item, array $f, array $spec) {
    $S = igk_safe($format, $W, $H);
    $im = igk_canvas($W, $H);
    $tw = $W - $S['l'] - $S['r'];
    [$px, $lines] = igk_fit('serifb', [128, 112, 96, 84], 'Plan a trip like this', $tw, 3);
    $lh = (int)($px * 1.1);
    $sub = 'Build your own day-by-day plan on ' . ja_setting('site_name', 'JourneyAI') . '.';
    $sl = igk_wrap('sans', 40, $sub, $tw, 3);
    $total = count($lines) * $lh + 60 + count($sl) * 56;
    $top = $S['t'] + max(0, (int)(($H - $S['t'] - $S['b'] - $total) / 2));
    $y = igk_block_top($im, 'serifb', $px, $lines, $S['l'], $top, '#FFF6EE', $lh);
    imagefilledrectangle($im, $S['l'], $y + 26, $S['l'] + 110, $y + 34, igk_col($im, IGK_MELON));
    igk_block_top($im, 'sans', 40, $sl, $S['l'], $y + 66, '#B9D3C3', 56);
    igk_text($im, 'sansb', 30, $S['l'], $H - max(52, $S['b'] - 40), '#FFF6EE', ja_setting('site_name', 'JourneyAI'), false, 30);
    return $im;
}

/** One finished JPEG. $n picks the photo (single formats) or the slide (carousel). Throws RuntimeException with a plain message. */
function igk_render(array $item, $format, $tpl, $n = 0) {
    if (!function_exists('imagettftext')) throw new RuntimeException('The server cannot draw images (PHP GD with FreeType is missing).');
    if (!isset(IGK_FORMATS[$format])) throw new RuntimeException('Unknown format.');
    [$W, $H] = IGK_FORMATS[$format];
    $f = igk_facts($item);
    $slides = igk_slides($item, $format, (string)$tpl);
    $n = max(0, min(count($slides) - 1, (int)$n));
    $spec = $slides[$n];
    $fmtKey = $format === 'carousel' ? 'feed' : $format;
    switch ($spec['type']) {
        case 'photo': $im = igk_draw_photo_card($fmtKey, $W, $H, $item, $f, $spec); break;
        case 'polaroid': $im = igk_draw_polaroid($fmtKey, $W, $H, $item, $f, $spec); break;
        case 'journey': $im = igk_draw_journey($fmtKey, $W, $H, $item, $f, $spec); break;
        case 'quote': $im = igk_draw_quote($fmtKey, $W, $H, $item, $f, $spec); break;
        default: $im = igk_draw_close($fmtKey, $W, $H, $item, $f, $spec);
    }
    return igk_jpeg($im);
}

/** A member's plain photo, made ready for Instagram: JPEG, no metadata, 4:5 to 1.91:1, at most 1440 px wide. */
function igk_prepare_photo($abs) {
    $src = igk_load($abs, 3000);
    $w = imagesx($src); $h = imagesy($src);
    $r = $w / max(1, $h);
    $cx = 0; $cy = 0; $cw = $w; $ch = $h;
    if ($r < 0.8) { $ch = (int)floor($w / 0.8); $cy = (int)(($h - $ch) / 2); }
    elseif ($r > 1.91) { $cw = (int)floor($h * 1.91); $cx = (int)(($w - $cw) / 2); }
    $tw = min(1440, $cw); $th = (int)round($tw * $ch / $cw);
    $out = imagecreatetruecolor($tw, $th);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $src, 0, 0, $cx, $cy, $tw, $th, $cw, $ch);
    imagedestroy($src);
    return igk_jpeg($out);
}

/* ------------------------------------------------------------------ direct posting: short-lived public links for Meta's servers */
/** Can Meta's servers reach this site? https, a real public host. On a local dev install a test flag may stand in (never on a live host). */
function igk_public_ok() {
    if (ja_social_base_public()) return true;
    return ja_social_dev() && ja_setting('social_instagram_test_public') === '1';
}
function igk_queue_dir() {
    $d = __DIR__ . '/private/ig-queue';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    $h = __DIR__ . '/private/.htaccess';
    if (!is_file($h)) @file_put_contents($h, "Require all denied\n");
    return $d;
}
function igk_queue_purge() {
    $conn = ja_db();
    $rs = mysqli_query($conn, "SELECT id, path FROM ig_media_queue WHERE expires_at < NOW()");
    while ($rs && ($r = mysqli_fetch_assoc($rs))) { @unlink(igk_queue_dir() . '/' . basename($r['path'])); }
    mysqli_query($conn, "DELETE FROM ig_media_queue WHERE expires_at < NOW()");
}
/** Store one explicitly queued JPEG and return its public URL (valid 15 minutes). */
function igk_queue_put($eml, $bytes, $postId = null) {
    igk_queue_purge();
    $conn = ja_db();
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $name = bin2hex(random_bytes(12)) . '.jpg';
    if (@file_put_contents(igk_queue_dir() . '/' . $name, $bytes, LOCK_EX) === false) return null;
    $hash = hash('sha256', $token); $len = strlen($bytes); $ttl = IGK_QUEUE_TTL;
    $st = mysqli_prepare($conn, "INSERT INTO ig_media_queue (token_hash, eml, post_id, path, bytes, expires_at) VALUES (?,?,?,?,?, NOW() + INTERVAL ? SECOND)");
    mysqli_stmt_bind_param($st, 'ssisii', $hash, $eml, $postId, $name, $len, $ttl);
    $ok = mysqli_stmt_execute($st); mysqli_stmt_close($st);
    if (!$ok) { @unlink(igk_queue_dir() . '/' . $name); return null; }
    return ['token' => $token, 'url' => rtrim(ja_base_url(), '/') . '/ig-media.php?t=' . $token];
}
/** The row for a still-valid token, or null. */
function igk_queue_get($token) {
    if (!preg_match('/^[A-Za-z0-9_\-]{43}$/', (string)$token)) return null;
    $conn = ja_db(); $hash = hash('sha256', $token);
    $st = mysqli_prepare($conn, "SELECT id, path, bytes FROM ig_media_queue WHERE token_hash = ? AND expires_at > NOW()");
    mysqli_stmt_bind_param($st, 's', $hash); mysqli_stmt_execute($st);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
    return $r ?: null;
}
/** Remove queued files right after a post (done or failed). */
function igk_queue_clear(array $tokens) {
    $conn = ja_db();
    foreach ($tokens as $t) {
        $hash = hash('sha256', $t);
        $st = mysqli_prepare($conn, "SELECT path FROM ig_media_queue WHERE token_hash = ?");
        mysqli_stmt_bind_param($st, 's', $hash); mysqli_stmt_execute($st);
        $r = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
        if ($r) @unlink(igk_queue_dir() . '/' . basename($r['path']));
        $d = mysqli_prepare($conn, "DELETE FROM ig_media_queue WHERE token_hash = ?");
        mysqli_stmt_bind_param($d, 's', $hash); mysqli_stmt_execute($d); mysqli_stmt_close($d);
    }
}

/** Where direct posting stands for this member, in plain words (shown in the Kit modal). */
function igk_direct_state($eml) {
    $p = ja_social_provider('instagram');
    if (!$p || !$p->configured()) return ['state' => 'not_setup', 'message' => 'Posting directly from the site needs a public https site and an Instagram business account, set up by the site owner. Until then, use the phone share sheet or download the images.'];
    $accts = ja_social_accounts($eml, 'instagram');
    if (!$accts) return ['state' => 'not_connected', 'message' => 'Log in with Instagram in your profile to post directly from the site.'];
    $a = $accts[0];
    if ($a['status'] !== 'active') return ['state' => 'needs_reconnect', 'message' => 'Your Instagram sign-in expired. Log in with Instagram again in your profile.'];
    if (!igk_public_ok()) return ['state' => 'not_public', 'message' => 'Posting directly needs this site to be online over https, because Instagram downloads the images from it. This site is not reachable from the internet yet.'];
    return ['state' => 'ready', 'account_id' => (int)$a['account_id'], 'handle' => $a['handle'], 'message' => 'Ready to post to ' . $a['handle'] . '.'];
}
