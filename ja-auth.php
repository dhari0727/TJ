<?php
/**
 * JourneyAI — shared layout and form helpers for the account pages (log in, sign up, reset, verify).
 *   ja_auth_open('kerala', 'Log in', 'Lead sentence')   ...  fields / forms ...   ja_auth_close()
 *   echo ja_field(['id' => 'eml', 'name' => 'eml', 'label' => 'Email', 'type' => 'email', 'value' => $x, 'error' => $msg, ...]);
 * Photos are real pictures of the place named in the caption (images/dest). js/ja-auth.js adds show/hide password,
 * the password checklist and inline validation; every field works without it.
 */
require_once __DIR__ . '/ja-icons.php';
require_once __DIR__ . '/ja-images.php';

function ja_auth_photo($key) {
    $captions = ['jaipur' => 'Hawa Mahal, Jaipur', 'kerala' => 'Backwaters, Kerala', 'goa' => 'A beach on the Goa coast', 'taj-mahal' => 'Taj Mahal, Agra'];
    $imgs = ja_local_images();
    if (!isset($captions[$key]) || !isset($imgs[$key])) $key = isset($imgs['jaipur']) ? 'jaipur' : null;
    return $key ? [$imgs[$key], $captions[$key] ?? ''] : [null, ''];
}

function ja_auth_open($photoKey, $title, $lead = '') {
    [$src, $cap] = ja_auth_photo($photoKey);
    echo '<main class="acct-auth" id="main" tabindex="-1">';
    if ($src) {
        echo '<div class="acct-photo"><img src="' . htmlspecialchars($src) . '" alt="" width="900" height="1200" fetchpriority="high">'
           . '<p class="acct-photo-cap">' . ja_icon('map-pin', 16) . '<span>' . htmlspecialchars($cap) . '</span></p></div>';
    }
    echo '<div class="acct-pane"><div class="acct-tools">'
       . '<button type="button" class="ja-theme-toggle" data-ja-palette-open aria-label="Search" aria-haspopup="dialog">' . ja_icon('search', 18) . '</button>'
       . '<button type="button" class="ja-theme-toggle" data-ja-theme-toggle title="Switch theme" aria-label="Switch to dark theme"><span class="ja-tt-moon">' . ja_icon('moon', 18) . '</span><span class="ja-tt-sun">' . ja_icon('sun', 18) . '</span></button></div>'
       . '<div class="acct-box">'
       . '<a class="acct-brand" href="index.php"><img src="images/journeyai-logo.svg" class="ja-logo-mark" alt=""> <span>JourneyAI</span></a>'
       . '<h1>' . htmlspecialchars($title) . '</h1>'
       . ($lead !== '' ? '<p class="acct-lead">' . htmlspecialchars($lead) . '</p>' : '');
}

function ja_auth_close() {
    echo '</div></div></main>';
    include __DIR__ . '/ja-palette.php';
    echo '<script src="js/ja-auth.js?v=' . (int)@filemtime(__DIR__ . '/js/ja-auth.js') . '" defer></script>';
}

/** One labelled field with optional hint, inline error (aria-describedby), show/hide for passwords and a password checklist. */
function ja_field(array $o) {
    $id = 'f-' . $o['id']; $type = $o['type'] ?? 'text';
    $hint = $o['hint'] ?? ''; $err = $o['error'] ?? '';
    $desc = [];
    if ($hint !== '') $desc[] = $id . '-hint';
    $desc[] = $id . '-err';
    if (!empty($o['also'])) $desc[] = $o['also'];   // id of a form-level message that applies to this field too
    $attrs = ' id="' . $id . '" name="' . htmlspecialchars($o['name']) . '" type="' . $type . '"'
           . (isset($o['value']) ? ' value="' . htmlspecialchars($o['value']) . '"' : '')
           . (!empty($o['autocomplete']) ? ' autocomplete="' . $o['autocomplete'] . '"' : '')
           . (!empty($o['required']) ? ' required' : '')
           . (!empty($o['autofocus']) ? ' autofocus' : '')
           . (!empty($o['placeholder']) ? ' placeholder="' . htmlspecialchars($o['placeholder']) . '"' : '')
           . (!empty($o['maxlength']) ? ' maxlength="' . (int)$o['maxlength'] . '"' : '')
           . (!empty($o['rule']) ? ' data-rule="' . $o['rule'] . '"' : '')
           . (!empty($o['label_text']) ? ' data-label="' . htmlspecialchars($o['label_text']) . '"' : '')
           . ' aria-describedby="' . implode(' ', $desc) . (!empty($o['meter']) ? ' ' . $id . '-meter' : '') . '"'
           . ($err !== '' || !empty($o['invalid']) ? ' aria-invalid="true"' : '');
    $h = '<div class="ja-field acct-field' . ($err !== '' || !empty($o['invalid']) ? ' has-error' : '') . '">';
    $h .= '<div class="acct-labelrow"><label for="' . $id . '">' . htmlspecialchars($o['label']) . '</label>' . ($o['aside'] ?? '') . '</div>';
    $h .= '<div class="acct-ctl"><input class="ja-input"' . $attrs . '>';
    if ($type === 'password') {
        $h .= '<button type="button" class="acct-eye" data-pw-toggle="' . $id . '" aria-label="Show password" aria-pressed="false">'
            . '<span class="on">' . ja_icon('eye', 18) . '</span><span class="off">' . ja_icon('eye-off', 18) . '</span></button>';
    }
    $h .= '</div>';
    if ($hint !== '') $h .= '<p class="acct-hint" id="' . $id . '-hint">' . htmlspecialchars($hint) . '</p>';
    $h .= '<p class="acct-err" id="' . $id . '-err"' . ($err === '' ? ' hidden' : '') . '>' . ($err !== '' ? ja_icon('alert', 15) . '<span>' . htmlspecialchars($err) . '</span>' : '') . '</p>';
    if (!empty($o['meter'])) {
        $h .= '<div class="acct-meter" id="' . $id . '-meter" data-pw-meter="' . $id . '">'
            . '<div class="acct-bars" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></div>'
            . '<p class="acct-meter-l" aria-live="polite">Password strength: <strong data-pw-label>not started</strong></p>'
            . '<ul class="acct-rules">'
            . '<li data-r="len">8 or more characters</li><li data-r="up">An uppercase letter</li><li data-r="lo">A lowercase letter</li>'
            . '<li data-r="num">A number</li><li data-r="sym">A symbol, such as ! or @</li></ul></div>';
    }
    return $h . '</div>';
}
