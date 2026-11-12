<?php
/**
 * Shared helpers for the community pages (feed, journals, writing, traveller profiles).
 * Everything here returns already-escaped HTML or plain values; user text never leaves escaped.
 */
require_once __DIR__ . '/ja-icons.php';

/** Small inline SVG icons the main set does not have. Same stroke style as ja_icon(). */
function pc_icon($name, $size = 18) {
    $p = [
        'chat'   => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.5-4.6A8 8 0 1 1 21 12z"/>',
        'close'  => '<path d="M6 6l12 12M18 6L6 18"/>',
        'upload' => '<path d="M12 16V4M7 9l5-5 5 5M5 20h14"/>',
        'link'   => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'image'  => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="M21 16l-5-5-8 9"/>',
        'left'   => '<path d="M15 5l-7 7 7 7"/>',
        'right'  => '<path d="M9 5l7 7-7 7"/>',
        'dots'   => '<circle cx="5" cy="12" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="19" cy="12" r="1.2"/>',
        'play'   => '<path d="M8 5l11 7-11 7z"/>',
        'bell'   => '<path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15zM10 21h4"/>',
        'book'   => '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2zM4 19V5"/>',
    ];
    if (!isset($p[$name])) return '';
    return '<svg width="' . (int)$size . '" height="' . (int)$size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p[$name] . '</svg>';
}

function pc_words($text) {
    return (int)preg_match_all('/\S+/u', (string)$text);
}

/** Whole minutes to read, 1 minimum, at 200 words a minute. */
function pc_read_min($words) {
    return max(1, (int)ceil($words / 200));
}

/**
 * Turn a journal's free text into safe HTML. Blank lines split paragraphs. A line that starts with
 * "## " (or "# "), or a standalone "Day 3: title" line, becomes a section heading and a TOC entry.
 * @return array{html:string, toc:array<int,array{0:string,1:string}>, words:int}
 */
function pc_story($text) {
    $text = trim(str_replace(["\r\n", "\r"], "\n", (string)$text));
    $html = ''; $toc = []; $n = 0;
    if ($text === '') return ['html' => '', 'toc' => [], 'words' => 0];
    foreach (preg_split('/\n{2,}/', $text) as $block) {
        $buf = [];
        $flush = function () use (&$buf, &$html) {
            if ($buf) { $html .= '<p>' . implode('<br>', array_map(function ($l) { return htmlspecialchars($l, ENT_QUOTES, 'UTF-8'); }, $buf)) . '</p>'; $buf = []; }
        };
        foreach (explode("\n", $block) as $ln) {
            $t = trim($ln);
            if ($t === '') continue;
            $label = null;
            if (preg_match('/^#{1,3}\s+(.{1,90})$/u', $t, $m)) $label = trim($m[1]);
            elseif (preg_match('/^(Day\s+\d+\s*(?:[:\-\x{2013}.]\s*.{0,70})?)$/iu', $t, $m)) $label = trim($m[1]);
            if ($label !== null && $label !== '') {
                $flush();
                $id = 's' . (++$n);
                $toc[] = [$id, $label];
                $html .= '<h2 id="' . $id . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</h2>';
                continue;
            }
            $buf[] = $t;
        }
        $flush();
    }
    return ['html' => $html, 'toc' => $toc, 'words' => pc_words(preg_replace('/^#{1,3}\s+/m', '', $text))];
}

/** Empty state: says what is missing and offers the next step. $cta = [label, href] or null. */
function pc_empty($title, $text, $cta = null, $icon = 'image') {
    $h = '<div class="pc-empty"><span class="pc-empty-ic">' . pc_icon($icon, 26) . '</span><div><h3>' . htmlspecialchars($title) . '</h3><p>' . htmlspecialchars($text) . '</p>';
    if ($cta) $h .= '<a class="ja-btn ja-btn-primary" href="' . htmlspecialchars($cta[1]) . '">' . htmlspecialchars($cta[0]) . '</a>';
    return $h . '</div></div>';
}

/** Aspect ratio for a stored upload, clamped so masonry stays calm. Returns e.g. "4 / 5". */
function pc_ratio($filepath) {
    static $root = null;
    if ($root === null) $root = __DIR__ . '/';
    $f = $root . ltrim((string)$filepath, '/');
    if (!is_file($f)) return '4 / 5';
    $s = @getimagesize($f);
    if (!$s || !$s[0] || !$s[1]) return '4 / 5';
    $r = max(0.75, min(1.6, $s[0] / $s[1]));
    return round($r * 100) . ' / 100';
}
