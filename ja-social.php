<?php
/**
 * JourneyAI - sharing: what can be shared, the share links, Open Graph tags and the Share button.
 *
 *   require_once 'ja-social.php';
 *   echo ja_social_button('journal', $id);            // a Share button; its sheet loads on click
 *   echo ja_social_button('plan', $planId, ['label' => 'Share on social']);
 *   ja_social_og(['title' => ..., 'description' => ..., 'image' => 'uploads/x.jpg', 'url' => ...]);   // inside <head>
 *
 * Kinds: journal, book (storybook), post (a community photo), plan (a saved trip plan, owner only),
 *        upload (a public copy made from the camera roll, owner only).
 * Only items that are public AND approved by moderation are shareable. Owners of anything else get a plain
 * "make it public first" message. Other people get nothing.
 */
require_once __DIR__ . '/ja-social-core.php';
require_once __DIR__ . '/ja-media.php';
require_once __DIR__ . '/ja-icons.php';

function ja_social_abs_url($rel) {
    $rel = (string)$rel;
    if (preg_match('~^https?://~i', $rel)) return $rel;
    return rtrim(ja_base_url(), '/') . '/' . ltrim($rel, '/');
}
function ja_social_tagify($s) {
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', '', (string)$s);
    $w = preg_split('/\s+/u', trim($s)) ?: [];
    $o = ''; foreach ($w as $x) $o .= mb_strtoupper(mb_substr($x, 0, 1)) . mb_substr($x, 1);
    return mb_strlen($o) >= 3 && mb_strlen($o) <= 30 && !preg_match('/^\d+$/', $o) ? $o : '';
}
function ja_social_hashtags(array $words) {
    $out = [];
    foreach ($words as $w) { $t = ja_social_tagify($w); if ($t !== '' && !in_array($t, $out, true)) $out[] = $t; }
    foreach (['Travel'] as $t) if (!in_array($t, $out, true)) $out[] = $t;
    return array_slice($out, 0, 4);
}
function ja_social_excerpt($text, $max = 140) {
    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$text), ENT_QUOTES, 'UTF-8')));
    $t = preg_replace('/[#*_>`]+/u', '', $t);
    if (mb_strlen($t) <= $max) return $t;
    $cut = mb_substr($t, 0, $max);
    $sp = mb_strrpos($cut, ' ');
    return rtrim(mb_substr($cut, 0, $sp > $max * 0.6 ? $sp : $max), " ,.;:") . '...';
}

/**
 * Resolve one shareable item for $viewer (email or null). Never returns private data to someone who is not the owner.
 * @return array|null null = nothing to show (not found, or private and not yours)
 */
function ja_social_item($kind, $id, $viewer = null, $path = null) {
    $conn = ja_db();
    $id = (int)$id;
    $item = ['kind' => $kind, 'id' => $id, 'owner' => false, 'shareable' => false, 'reason' => '', 'title' => '', 'text' => '', 'url' => '',
             'images' => [], 'hashtags' => [], 'og_image' => '', 'og_type' => 'article'];
    $mine = function ($eml) use ($viewer) { return $viewer && strcasecmp((string)$eml, (string)$viewer) === 0; };
    $addImage = function (&$item, $rel, $alt) {
        $rel = ja_safe_media_path($rel);
        if (!$rel || !ja_social_abs_media($rel)) return;
        foreach ($item['images'] as $i) if ($i['rel'] === $rel) return;
        $item['images'][] = ['rel' => $rel, 'url' => ja_social_abs_url($rel), 'alt' => mb_substr(trim((string)$alt), 0, 400)];
    };

    if ($kind === 'journal') {
        $st = mysqli_prepare($conn, "SELECT j.entry_id, j.eml, j.Title, j.Description, j.City, j.Country, j.duration_days, d.visibility, d.moderation_status
            FROM journals j JOIN db d ON d.entry_id = j.entry_id WHERE j.entry_id = ?");
        mysqli_stmt_bind_param($st, 'i', $id); mysqli_stmt_execute($st);
        $e = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
        if (!$e) return null;
        $item['owner'] = $mine($e['eml']);
        $public = $e['visibility'] === 'public' && $e['moderation_status'] === 'approved';
        if (!$public && !$item['owner']) return null;
        if (!$public) $item['reason'] = $e['visibility'] !== 'public' ? 'This journal is not public yet. Make it public first (My journal, then Share), and then you can post it.' : 'This journal is waiting for review. You can share it as soon as it is approved.';
        $item['shareable'] = $public;
        $place = trim(trim((string)$e['City']) . ', ' . trim((string)$e['Country']), ', ');
        $item['title'] = ja_title($e['Title']);
        $ex = ja_social_excerpt($e['Description'], 150);
        $item['text'] = $item['title'] . ($place ? " ($place)" : '') . ($ex !== '' ? "\n\n" . $ex : '');
        $item['url'] = ja_social_abs_url('journal.php?id=' . $id);
        $item['hashtags'] = ja_social_hashtags([$e['City'] ?? '', $e['Country'] ?? '']);
        $item['description'] = $ex !== '' ? $ex : 'A travel journal on JourneyAI.';
        if ($public) {
            $q = mysqli_prepare($conn, "SELECT filepath, caption FROM media WHERE entry_id = ? AND kind = 'photo' AND is_public = 1 AND moderation_status = 'approved' ORDER BY media_id LIMIT 10");
            mysqli_stmt_bind_param($q, 'i', $id); mysqli_stmt_execute($q);
            foreach (mysqli_fetch_all(mysqli_stmt_get_result($q), MYSQLI_ASSOC) as $m) $addImage($item, $m['filepath'], $m['caption'] ?: 'Photo from ' . $item['title']);
            mysqli_stmt_close($q);
        }
    } elseif ($kind === 'book') {
        $st = mysqli_prepare($conn, "SELECT book_id, eml, title, subtitle, cover_img, visibility, moderation_status FROM storybooks WHERE book_id = ?");
        mysqli_stmt_bind_param($st, 'i', $id); mysqli_stmt_execute($st);
        $b = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
        if (!$b) return null;
        $item['owner'] = $mine($b['eml']);
        $public = $b['visibility'] === 'public' && $b['moderation_status'] === 'approved';
        if (!$public && !$item['owner']) return null;
        if (!$public) $item['reason'] = $b['visibility'] !== 'public' ? 'This storybook is not public yet. Make it public first (My storybooks, then Share), and then you can post it.' : 'This storybook is waiting for review. You can share it as soon as it is approved.';
        $item['shareable'] = $public;
        $item['title'] = (string)$b['title'];
        $item['text'] = $b['title'] . ($b['subtitle'] ? "\n\n" . ja_social_excerpt($b['subtitle'], 150) : '');
        $item['description'] = $b['subtitle'] ? ja_social_excerpt($b['subtitle'], 200) : 'A storybook from a trip, made on JourneyAI.';
        $item['url'] = ja_social_abs_url('storybook-view.php?id=' . $id);
        $item['hashtags'] = ja_social_hashtags(['Storybook']);
        if ($public) {
            if ($b['cover_img']) $addImage($item, $b['cover_img'], 'Cover of ' . $b['title']);
            $q = mysqli_prepare($conn, "SELECT photo_1, photo_1_cap, photo_2, photo_2_cap, photo_3, photo_3_cap, photo_4, photo_4_cap FROM storybook_pages WHERE book_id = ? ORDER BY sort_order LIMIT 8");
            mysqli_stmt_bind_param($q, 'i', $id); mysqli_stmt_execute($q);
            foreach (mysqli_fetch_all(mysqli_stmt_get_result($q), MYSQLI_ASSOC) as $pg)
                for ($n = 1; $n <= 4; $n++) if (!empty($pg["photo_$n"])) $addImage($item, $pg["photo_$n"], $pg["photo_{$n}_cap"] ?: 'Photo from ' . $b['title']);
            mysqli_stmt_close($q);
            $item['images'] = array_slice($item['images'], 0, 10);
        }
    } elseif ($kind === 'post') {
        $st = mysqli_prepare($conn, "SELECT media_id, eml, kind, filepath, caption, destination, is_public, moderation_status FROM media WHERE media_id = ?");
        mysqli_stmt_bind_param($st, 'i', $id); mysqli_stmt_execute($st);
        $m = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
        if (!$m) return null;
        $item['owner'] = $mine($m['eml']);
        $public = (int)$m['is_public'] === 1 && $m['moderation_status'] === 'approved';
        if (!$public && !$item['owner']) return null;
        if (!$public) $item['reason'] = (int)$m['is_public'] !== 1 ? 'This photo is private. Share it to the community first, and then you can post it.' : 'This photo is waiting for review. You can share it as soon as it is approved.';
        $item['shareable'] = $public;
        $cap = trim((string)$m['caption']);
        $item['title'] = $cap !== '' ? ja_social_excerpt($cap, 70) : ($m['destination'] ? 'A photo from ' . explode(',', $m['destination'])[0] : 'A travel photo');
        $item['text'] = $cap !== '' ? $cap : $item['title'];
        $item['description'] = $cap !== '' ? ja_social_excerpt($cap, 200) : 'A photo shared by a traveller on JourneyAI.';
        $item['url'] = ja_social_abs_url('post.php?id=' . $id);
        $item['hashtags'] = ja_social_hashtags([$m['destination'] ? explode(',', $m['destination'])[0] : '']);
        if ($public && $m['kind'] === 'photo') $addImage($item, $m['filepath'], $cap !== '' ? $cap : $item['title']);
        $item['og_type'] = 'website';
    } elseif ($kind === 'plan') {
        if (!$viewer) return null;
        $st = mysqli_prepare($conn, "SELECT plan_id, destination, days, share_token FROM saved_plans WHERE plan_id = ? AND eml = ?");
        mysqli_stmt_bind_param($st, 'is', $id, $viewer); mysqli_stmt_execute($st);
        $p = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
        if (!$p) return null;
        $item['owner'] = true;
        $item['title'] = (int)$p['days'] . '-day trip plan for ' . $p['destination'];
        $item['text'] = 'My ' . $item['title'] . ', planned on JourneyAI.';
        $item['description'] = $item['text'];
        $item['hashtags'] = ja_social_hashtags([explode(',', $p['destination'])[0]]);
        $item['og_type'] = 'website';
        if (!empty($p['share_token'])) { $item['shareable'] = true; $item['url'] = ja_social_abs_url('shared-plan.php?t=' . $p['share_token']); }
        else { $item['reason'] = 'This plan has no share link yet. Create the share link first (anyone with the link can view the plan), and then you can post it.'; $item['needs_link'] = true; }
    } elseif ($kind === 'upload') {
        if (!$viewer) return null;
        $p = ja_picked_path_ok($conn, (string)$path, $viewer);
        if (!$p) return null;
        $st = mysqli_prepare($conn, "SELECT caption, destination, moderation_status FROM media WHERE filepath = ? AND eml = ? LIMIT 1");
        mysqli_stmt_bind_param($st, 'ss', $p, $viewer); mysqli_stmt_execute($st);
        $m = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
        if (!$m) return null;
        $item['id'] = $p;
        $item['owner'] = true;
        $ok = ($m['moderation_status'] ?? '') === 'approved';
        if (!$ok) $item['reason'] = 'This photo is not approved, so it cannot be shared.';
        $item['shareable'] = $ok;
        $cap = trim((string)$m['caption']);
        $item['title'] = $cap !== '' ? ja_social_excerpt($cap, 70) : 'A travel photo';
        $item['text'] = $cap !== '' ? $cap : '';
        $item['description'] = $item['title'];
        $item['url'] = ja_social_abs_url($p);   // no page for a loose photo: the link is the picture itself
        $item['hashtags'] = ja_social_hashtags([$m['destination'] ? explode(',', $m['destination'])[0] : '']);
        if ($ok) $addImage($item, $p, $cap !== '' ? $cap : 'Travel photo');
        $item['link_is_image'] = true;
    } else {
        return null;
    }
    $item['og_image'] = $item['images'] ? $item['images'][0]['url'] : '';
    $tags = $item['hashtags'] ? '#' . implode(' #', $item['hashtags']) : '';
    $parts = [];
    if (trim($item['text']) !== '') $parts[] = trim($item['text']);
    if (empty($item['link_is_image']) && $item['url']) $parts[] = $item['url'];
    if ($tags !== '') $parts[] = $tags;
    $item['caption'] = implode("\n\n", $parts);
    return $item;
}

/** Share-intent links for a shareable item. All use the item's public URL. */
function ja_social_share_links(array $item) {
    if (empty($item['shareable'])) return [];
    $u = $item['url']; $title = $item['title'];
    $short = ja_social_excerpt($item['text'] !== '' ? $item['text'] : $title, 200);
    $tagCsv = implode(',', $item['hashtags']);
    $img = $item['og_image'];
    $e = 'rawurlencode';
    $links = [
        ['id' => 'whatsapp', 'label' => 'WhatsApp', 'url' => 'https://wa.me/?text=' . $e($short . ' ' . $u)],
        ['id' => 'facebook', 'label' => 'Facebook', 'url' => 'https://www.facebook.com/sharer/sharer.php?u=' . $e($u)],
        ['id' => 'x', 'label' => 'X', 'url' => 'https://twitter.com/intent/tweet?text=' . $e(ja_social_excerpt($short, 200)) . '&url=' . $e($u) . ($tagCsv ? '&hashtags=' . $e($tagCsv) : '')],
        ['id' => 'linkedin', 'label' => 'LinkedIn', 'url' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $e($u)],
        ['id' => 'bluesky', 'label' => 'Bluesky', 'url' => 'https://bsky.app/intent/compose?text=' . $e(ja_social_excerpt($short, 200) . ' ' . $u)],
        ['id' => 'pinterest', 'label' => 'Pinterest', 'url' => $img ? 'https://pinterest.com/pin/create/button/?url=' . $e($u) . '&media=' . $e($img) . '&description=' . $e($title) : ''],
        ['id' => 'telegram', 'label' => 'Telegram', 'url' => 'https://t.me/share/url?url=' . $e($u) . '&text=' . $e($short)],
        ['id' => 'reddit', 'label' => 'Reddit', 'url' => 'https://www.reddit.com/submit?url=' . $e($u) . '&title=' . $e($title)],
        ['id' => 'email', 'label' => 'Email', 'url' => 'mailto:?subject=' . $e($title) . '&body=' . $e($short . "\n\n" . $u)],
    ];
    return array_values(array_filter($links, function ($l) { return $l['url'] !== ''; }));
}

/** Everything the share sheet needs, as JSON-ready data. */
function ja_social_describe($kind, $id, $viewer = null, $path = null) {
    $item = ja_social_item($kind, $id, $viewer, $path);
    if (!$item) return ['ok' => false, 'error' => 'This cannot be shared. It may be private, or it no longer exists.'];
    $providers = [];
    if ($viewer && $item['owner'] && $item['shareable']) {
        foreach (ja_social_providers() as $p) $providers[$p->id] = ['label' => $p->label, 'limits' => $p->limits()];
    }
    return ['ok' => true, 'item' => [
        'kind' => $item['kind'], 'id' => $item['id'], 'owner' => $item['owner'], 'shareable' => $item['shareable'], 'reason' => $item['reason'],
        'needs_link' => !empty($item['needs_link']), 'title' => $item['title'], 'caption' => $item['caption'], 'text' => $item['text'], 'url' => $item['url'],
        'hashtags' => $item['hashtags'], 'images' => $item['images'], 'link_is_image' => !empty($item['link_is_image']),
        'public_site' => ja_social_base_public(),
    ], 'links' => ja_social_share_links($item), 'can_post' => (bool)($viewer && $item['owner'] && $item['shareable']),
       'logged_in' => (bool)$viewer, 'providers' => $providers];
}

/* ------------------------------------------------------------------ Open Graph / Twitter Card */
/** Print link-preview tags. Call inside <head>, and only for pages anyone may see. $o: title, description, image (relative or absolute), url, type. */
function ja_social_og(array $o) {
    $esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $title = mb_substr((string)($o['title'] ?? 'JourneyAI'), 0, 90);
    $desc = ja_social_excerpt($o['description'] ?? '', 200);
    $img = !empty($o['image']) ? ja_social_abs_url($o['image']) : '';
    $url = !empty($o['url']) ? ja_social_abs_url($o['url']) : '';
    $t = [];
    $t[] = ['property', 'og:site_name', ja_setting('site_name', 'JourneyAI')];
    $t[] = ['property', 'og:type', $o['type'] ?? 'article'];
    $t[] = ['property', 'og:title', $title];
    if ($desc !== '') $t[] = ['property', 'og:description', $desc];
    if ($url !== '') $t[] = ['property', 'og:url', $url];
    if ($img !== '') { $t[] = ['property', 'og:image', $img]; if (!empty($o['image_alt'])) $t[] = ['property', 'og:image:alt', $o['image_alt']]; }
    $t[] = ['name', 'twitter:card', $img !== '' ? 'summary_large_image' : 'summary'];
    $t[] = ['name', 'twitter:title', $title];
    if ($desc !== '') $t[] = ['name', 'twitter:description', $desc];
    if ($img !== '') $t[] = ['name', 'twitter:image', $img];
    $out = '';
    foreach ($t as [$k, $n, $v]) $out .= '<meta ' . $k . '="' . $n . '" content="' . $esc($v) . '">' . "\n";
    echo $out;
}

/* ------------------------------------------------------------------ icons, assets, the button */
/** Simple monochrome line icons for each service (drawn here, nothing hotlinked). */
function ja_social_icon_paths() {
    return [
        'bluesky'   => '<path d="M12 11c-1-2.2-3.8-5.8-6.2-7.2C3.6 2.5 2 3 2 5.2c0 .5.3 4.200.5 4.900.7 2.400 3.200 3 5.300 2.700-3.800.600-4.500 2.800-2.500 5C8.800 20.600 10.700 17 12 14.800c1.300 2.200 3.200 5.800 6.700 2 2-2.200 1.300-4.400-2.500-5 2.100.3 4.600-.3 5.300-2.700.2-.7.500-4.400.5-4.900 0-2.200-1.600-2.700-3.800-1.400C15.800 5.200 13 8.800 12 11z"/>',
        'mastodon'  => '<path d="M20.500 8.700c0-4-2.600-5.200-2.600-5.200C16.700 3 14.300 2.700 12 2.700h-.1c-2.300 0-4.700.3-5.900.8 0 0-2.600 1.200-2.600 5.200 0 .9 0 2 .1 3.100.3 3.900 1.100 7.500 4.500 8.400 1.600.4 3 .5 4.100.5 2 0 3.200-.6 3.200-.6l-.1-1.500s-1.400.4-3 .4c-1.600-.1-3.200-.2-3.500-2.100 0-.2-.1-.4-.1-.6 0 0 1.600.4 3.600.5h2.500c1.800-.1 3.500-.2 4.400-.5 2.400-.7 3.100-3 3.100-5 .1-.3.100-3.700.1-4.400z"/><path d="M8 13V8.800c0-1.800 1.300-2 2-2s1.900.5 2 2c.1-1.500 1.300-2 2-2s2 .2 2 2V13"/>',
        'facebook'  => '<path d="M14 8h3V4h-3a4 4 0 0 0-4 4v3H7v4h3v6h4v-6h3l1-4h-4V8.500a.5.5 0 0 1 .5-.5z"/>',
        'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.200" cy="6.800" r="0.800"/>',
        'x'         => '<path d="M4 4l16 16M20 4L4 20"/>',
        'linkedin'  => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M8 10.500V16M8 7.500v.1M12 16v-5.500M12 13c0-1.700 1-2.500 2.200-2.500S16 11.300 16 13v3"/>',
        'pinterest' => '<circle cx="12" cy="12" r="9"/><path d="M11 8.500c-2.500 0-3.500 2-2.600 3.800.5.900 1.800 1 2.400 0M11 8.500c2 0 3 1.600 2.500 3.500s-2 3-3.300 2.300M10.200 21l1.600-6.500"/>',
        'threads'   => '<path d="M17.500 10.500C17 6.500 14.500 5 12 5c-3 0-5 2.300-5 7s2 7 5 7c2.500 0 4.500-1.300 4.500-3.500 0-2.700-2.500-3.500-4.500-3.500-1.500 0-2.500.8-2.500 1.800 0 1 .9 1.700 2 1.700 2 0 3-1.800 3-5"/>',
        'whatsapp'  => '<path d="M3 21l1.500-4.500A8.500 8.500 0 1 1 8 19.500L3 21z"/><path d="M9 8.500c0 3.500 3 6.500 6.500 6.500l1-1.500-2-1-1 .8c-1-.4-2-1.400-2.400-2.400l.8-1-1-2L9 8.500z"/>',
        'telegram'  => '<path d="M21.500 3.500L2.500 11l6 2 2 6.500 3-4 5.500 4 2.500-16z"/><path d="M8.500 13l13-9.500"/>',
        'reddit'    => '<circle cx="12" cy="14" r="7"/><circle cx="9.500" cy="13.500" r="1"/><circle cx="14.500" cy="13.500" r="1"/><path d="M9.500 17c1.500 1 3.500 1 5 0M12 7l1-4 4 1"/><circle cx="18" cy="4.500" r="1"/>',
        'email'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'link'      => '<path d="M10 14a4.500 4.500 0 0 0 6.400 0l3-3a4.500 4.500 0 0 0-6.400-6.400l-1 1M14 10a4.500 4.500 0 0 0-6.400 0l-3 3a4.500 4.500 0 0 0 6.400 6.400l1-1"/>',
        'copy'      => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>',
        'download'  => '<path d="M12 3v12m0 0l-4-4m4 4l4-4M4 20h16"/>',
        'share'     => '<circle cx="18" cy="5" r="2.500"/><circle cx="6" cy="12" r="2.500"/><circle cx="18" cy="19" r="2.500"/><path d="M8.200 10.800l7.600-4.600M8.200 13.200l7.600 4.600"/>',
        'send'      => '<path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>',
        'close'     => '<path d="M18 6L6 18M6 6l12 12"/>',
        'check'     => '<path d="M5 12.500l4.500 4.500L19 7.500"/>',
        'alert'     => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.500v.1"/>',
    ];
}
function ja_social_icon($name, $size = 18) {
    $p = ja_social_icon_paths();
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int)$size . '" height="' . (int)$size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}
/** Load the share sheet's CSS/JS once per page. Safe to call from several places. */
function ja_social_assets() {
    static $done = false;
    if ($done) return '';
    $done = true;
    $v = function ($f) { return (int)@filemtime(__DIR__ . '/' . $f); };
    $logged = !empty($_SESSION['eml']);
    return '<link rel="stylesheet" href="css/social.css?v=' . $v('css/social.css') . '">'
        . '<script>window.JA_SOC_ICONS=' . json_encode(ja_social_icon_paths(), JSON_HEX_TAG | JSON_HEX_AMP) . ';window.JA_SOC={api:"social-api.php",kit:"instagram-kit.php",kitJs:"js/instagram-kit.js?v=' . $v('js/instagram-kit.js') . '",loggedIn:' . ($logged ? 'true' : 'false') . ',csrf:' . ($logged ? json_encode(ja_csrf()) : 'null') . '};</script>'
        . '<script src="js/social-share.js?v=' . $v('js/social-share.js') . '" defer></script>';
}
/** A Share button. Clicking loads the share sheet for this item. */
function ja_social_button($kind, $id, array $opts = []) {
    $label = $opts['label'] ?? 'Share';
    $class = $opts['class'] ?? 'ja-btn ja-btn-ghost';
    $path = isset($opts['path']) ? ' data-path="' . htmlspecialchars((string)$opts['path'], ENT_QUOTES) . '"' : '';
    return ja_social_assets() . '<button type="button" class="' . htmlspecialchars($class, ENT_QUOTES) . ' soc-open" data-soc-open data-kind="' . htmlspecialchars((string)$kind, ENT_QUOTES)
        . '" data-id="' . htmlspecialchars((string)$id, ENT_QUOTES) . '"' . $path . ' aria-haspopup="dialog">' . ja_social_icon('share', 16) . '<span>' . htmlspecialchars($label) . '</span></button>';
}
