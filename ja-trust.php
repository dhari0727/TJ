<?php
/**
 * JourneyAI — trust and verification layer.
 *
 * Question it answers: "what stops someone flooding the site with fake journals, and biasing it?"
 * Answer, in layers (none is perfect alone; together they make abuse slow, visible and reversible):
 *
 *   1. E-mail verification        an unverified account can write privately but cannot publish anything.
 *   2. Trust levels               new -> verified -> trusted. Only trusted accounts publish instantly; everyone
 *                                 else's public content waits in a moderation queue (status "pending").
 *   3. Content checks             too short, link spam, repeated text, copied from someone else (exact or near
 *                                 duplicate), implausible costs or dates -> held for review with the reason.
 *   4. Rate limits                per-day caps on public items, per-hour caps on uploads/comments.
 *   5. Community reports          3 reports from different people hide an item automatically until a moderator looks.
 *   6. Media checks               real file type, re-encoding (strips GPS/EXIF), size limits, duplicate-file
 *                                 detection across accounts (see ja-media.php).
 *   7. Bias control for the models  only approved items from verified accounts reach the ML pipeline, and one
 *                                 account can contribute at most 3 journals per destination.
 *   8. Audit log                  who did what, so abuse can be traced and undone.
 *
 *   require_once 'ja-trust.php';   // also loads ja-lib.php
 */
require_once __DIR__ . '/ja-lib.php';

const JA_AUTO_HIDE_REPORTS = 3;       // distinct reporters that hide an item automatically
const JA_TRUST_MIN_AGE_DAYS = 3;      // account age before it can become "trusted"
const JA_TRUST_MIN_APPROVED = 2;      // approved public items before it can become "trusted"
const JA_DAILY_PUBLIC_LIMIT = 5;      // public journals/storybooks per day for non-trusted accounts

function ja_is_system_account($eml) {
    return (bool)preg_match('/@(seed|real|demo)\.journeyai$/i', (string)$eml);
}

function ja_audit($actor, $action, $detail = '') {
    global $conn; ja_db();
    $ip = ja_client_ip(); $d = mb_substr((string)$detail, 0, 390);
    $st = mysqli_prepare($conn, "INSERT INTO audit_log (actor, action, detail, ip) VALUES (?,?,?,?)");
    mysqli_stmt_bind_param($st, 'ssss', $actor, $action, $d, $ip);
    @mysqli_stmt_execute($st); mysqli_stmt_close($st);
}

/* ------------------------------------------------------------------ trust level */
function ja_trust($eml) {
    global $conn; ja_db();
    static $cache = [];
    if (isset($cache[$eml])) return $cache[$eml];
    $st = mysqli_prepare($conn, "SELECT role, email_verified, trust_override, created_at, is_active FROM signup WHERE eml = ?");
    mysqli_stmt_bind_param($st, 's', $eml); mysqli_stmt_execute($st);
    $u = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
    if (!$u) return $cache[$eml] = ['level' => 'none', 'can_publish' => false, 'instant' => false, 'why' => 'Unknown account.'];
    if (ja_is_system_account($eml) || $u['role'] === 'admin' || $u['trust_override'] === 'trusted') {
        return $cache[$eml] = ['level' => 'trusted', 'can_publish' => true, 'instant' => true, 'why' => ''];
    }
    if ($u['trust_override'] === 'restricted') {
        return $cache[$eml] = ['level' => 'restricted', 'can_publish' => false, 'instant' => false, 'why' => 'Publishing is switched off for this account. Contact the site team.'];
    }
    // reports upheld against this account in the last 90 days
    $e = mysqli_real_escape_string($conn, $eml);
    $upheld = (int)mysqli_fetch_row(mysqli_query($conn,
        "SELECT (SELECT COUNT(*) FROM reports r JOIN db d ON r.kind='journal' AND r.item_id=d.entry_id WHERE d.eml='$e' AND r.status='upheld' AND r.created_at > NOW() - INTERVAL 90 DAY)
              + (SELECT COUNT(*) FROM reports r JOIN media m ON r.kind='media' AND r.item_id=m.media_id WHERE m.eml='$e' AND r.status='upheld' AND r.created_at > NOW() - INTERVAL 90 DAY)"))[0];
    if ($upheld >= 2) {
        return $cache[$eml] = ['level' => 'restricted', 'can_publish' => false, 'instant' => false, 'why' => 'Several of your posts were removed after reports, so publishing is paused.'];
    }
    if (!(int)$u['email_verified']) {
        return $cache[$eml] = ['level' => 'new', 'can_publish' => false, 'instant' => false, 'why' => 'Verify your email to share publicly. Everything you write stays private until then.'];
    }
    $ageDays = (time() - strtotime($u['created_at'] ?: 'now')) / 86400;
    $approved = (int)mysqli_fetch_row(mysqli_query($conn,
        "SELECT (SELECT COUNT(*) FROM db WHERE eml='$e' AND visibility='public' AND moderation_status='approved')
              + (SELECT COUNT(*) FROM media WHERE eml='$e' AND is_public=1 AND moderation_status='approved')"))[0];
    if ($ageDays >= JA_TRUST_MIN_AGE_DAYS && $approved >= JA_TRUST_MIN_APPROVED && $upheld === 0) {
        return $cache[$eml] = ['level' => 'trusted', 'can_publish' => true, 'instant' => true, 'why' => ''];
    }
    return $cache[$eml] = ['level' => 'verified', 'can_publish' => true, 'instant' => false,
        'why' => 'New members\' public posts are checked by a moderator first, usually within a day.'];
}

/** Moderation state for a new public item from this user, plus extra reasons from content checks. */
function ja_moderation_for($eml, array $flags = []) {
    $t = ja_trust($eml);
    if (!$t['can_publish']) return ['status' => null, 'reason' => $t['why']];
    if ($flags) return ['status' => 'pending', 'reason' => 'Held for review: ' . implode('; ', $flags)];
    return $t['instant'] ? ['status' => 'approved', 'reason' => null] : ['status' => 'pending', 'reason' => 'New member: awaiting review'];
}

/** Daily cap on public journals/storybooks for non-trusted accounts. true = allowed. */
function ja_public_quota_ok($eml) {
    if (ja_trust($eml)['level'] === 'trusted') return true;
    return ja_rate_limit('public:' . strtolower($eml), JA_DAILY_PUBLIC_LIMIT, 86400);
}

/* ------------------------------------------------------------------ content checks */
function ja_norm_words($text) {
    $t = mb_strtolower(preg_replace('/https?:\/\/\S+/u', ' ', (string)$text));
    preg_match_all('/[\p{L}\p{N}]+/u', $t, $m);
    return $m[0];
}
function ja_text_hash($text) { return md5(implode(' ', ja_norm_words($text))); }
function ja_shingles($words, $k = 5) {
    $s = [];
    for ($i = 0, $n = count($words) - $k + 1; $i < $n; $i++) $s[md5(implode(' ', array_slice($words, $i, $k)))] = 1;
    return $s;
}

/**
 * Look at a journal before it goes public. Returns a list of plain-language flags (empty = looks fine).
 * $cost = ['total'=>float,'days'=>int]; $exclude = entry_id being edited.
 */
function ja_journal_flags($eml, $title, $story, $cost = null, $dates = null, $exclude = 0) {
    global $conn; ja_db();
    $flags = [];
    $words = ja_norm_words($story);
    $n = count($words);
    if ($n < 40) $flags[] = 'story is very short (' . $n . ' words)';
    if (preg_match_all('/https?:\/\/|www\./i', $story . ' ' . $title) > 2) $flags[] = 'contains several links';
    if ($n >= 20 && count(array_unique($words)) / $n < 0.35) $flags[] = 'text is highly repetitive';
    if (preg_match('/(.)\1{9,}/u', $story)) $flags[] = 'long runs of repeated characters';
    $letters = preg_replace('/[^A-Za-z]/', '', $story);
    if (strlen($letters) > 60 && strlen(preg_replace('/[^A-Z]/', '', $letters)) / strlen($letters) > 0.6) $flags[] = 'mostly capital letters';
    // exact or near-duplicate of someone else's journal
    if ($n >= 20) {
        $h = ja_text_hash($story);
        $e = mysqli_real_escape_string($conn, $eml);
        $dup = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM db WHERE text_hash='$h' AND eml<>'$e' AND entry_id<>" . (int)$exclude));
        if ((int)$dup[0] > 0) $flags[] = 'text matches another member\'s journal';
        else {
            $mine = ja_shingles($words);
            if ($mine) {
                $r = mysqli_query($conn, "SELECT Description FROM db WHERE visibility='public' AND eml<>'$e' AND entry_id<>" . (int)$exclude . " ORDER BY entry_id DESC LIMIT 300");
                while ($r && ($row = mysqli_fetch_row($r))) {
                    $other = ja_shingles(ja_norm_words($row[0]));
                    if (!$other) continue;
                    $inter = count(array_intersect_key($mine, $other));
                    if ($inter / max(1, count($mine) + count($other) - $inter) >= 0.6) { $flags[] = 'text is very similar to an existing journal'; break; }
                }
            }
        }
    }
    if ($cost && $cost['days'] > 0 && $cost['total'] > 0) {
        $daily = $cost['total'] / $cost['days'];
        if ($daily < 150 || $daily > 60000) $flags[] = 'cost per day looks implausible (Rs ' . number_format($daily) . ')';
    }
    if ($dates && $dates['dv'] && $dates['dr']) {
        $a = strtotime($dates['dv']); $b = strtotime($dates['dr']);
        if ($a && $b && $b < $a) $flags[] = 'return date is before the visit date';
        if ($a && $a > time() + 86400 * 30) $flags[] = 'trip date is far in the future';
        if ($a && $b && ($b - $a) / 86400 > 120) $flags[] = 'trip longer than 120 days';
    }
    return $flags;
}

/* ------------------------------------------------------------------ e-mail verification */
function ja_send_verification($eml, $force = false) {
    global $conn; ja_db();
    require_once __DIR__ . '/ja-mailer.php';
    $st = mysqli_prepare($conn, "SELECT fname, email_verified, verify_sent_at FROM signup WHERE eml = ?");
    mysqli_stmt_bind_param($st, 's', $eml); mysqli_stmt_execute($st);
    $u = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
    if (!$u || (int)$u['email_verified']) return ['ok' => false, 'status' => 'already', 'url' => ''];
    if (!ja_rate_limit('verify:' . strtolower($eml), 4, 3600)) return ['ok' => false, 'status' => 'limited', 'url' => ''];
    $token = bin2hex(random_bytes(32));
    $tokHash = ja_token_hash($token);   // store only the hash; the raw token goes in the emailed link
    $up = mysqli_prepare($conn, "UPDATE signup SET verify_token = ?, verify_sent_at = NOW() WHERE eml = ?");
    mysqli_stmt_bind_param($up, 'ss', $tokHash, $eml); mysqli_stmt_execute($up); mysqli_stmt_close($up);
    $url = ja_base_url() . '/verify-email.php?token=' . $token;
    $site = ja_setting('site_name', 'JourneyAI');
    $res = ja_send_mail($eml, "$site: confirm your email",
        "<p>Hi " . htmlspecialchars($u['fname']) . ",</p><p>Confirm your email to start sharing journals and photos with the community.</p>"
        . "<p><a href=\"" . htmlspecialchars($url) . "\">Confirm my email</a></p><p>The link works for 48 hours. If this wasn't you, ignore this message.</p>",
        "Confirm your $site email: $url (valid 48 hours)");
    $res['url'] = $url;
    ja_audit($eml, 'verify_sent', $res['status']);
    return $res;
}

function ja_disposable_email($eml) {
    static $bad = ['mailinator.com','10minutemail.com','guerrillamail.com','tempmail.com','temp-mail.org','yopmail.com','trashmail.com',
                   'sharklasers.com','getnada.com','dispostable.com','fakeinbox.com','throwawaymail.com','maildrop.cc','mintemail.com','moakt.com'];
    $d = strtolower(substr(strrchr((string)$eml, '@'), 1));
    return in_array($d, $bad, true);
}

function ja_common_password($p) {
    static $common = ['password','password1','password123','qwerty123','12345678','123456789','iloveyou','admin123','welcome1','letmein123',
                      'abc12345','qwertyuiop','passw0rd','p@ssw0rd','p@ssword1','test1234','india123','travel123','journey123'];
    $l = strtolower((string)$p);
    foreach ($common as $c) if (strpos($l, $c) !== false) return true;
    return false;
}

/** Hide an item (after reports or by a moderator) and tell the audit log. */
function ja_set_moderation($kind, $id, $status, $reason, $actor) {
    global $conn; ja_db();
    $map = ['journal' => ['db', 'entry_id'], 'media' => ['media', 'media_id'], 'book' => ['storybooks', 'book_id']];
    if (!isset($map[$kind]) || !in_array($status, ['approved', 'pending', 'rejected', 'hidden'], true)) return false;
    [$t, $c] = $map[$kind];
    $r = mb_substr((string)$reason, 0, 250);
    $st = mysqli_prepare($conn, "UPDATE `$t` SET moderation_status = ?, mod_reason = ? WHERE `$c` = ?");
    mysqli_stmt_bind_param($st, 'ssi', $status, $r, $id);
    $ok = mysqli_stmt_execute($st); mysqli_stmt_close($st);
    ja_audit($actor, 'moderate_' . $status, "$kind #$id " . $r);
    return $ok;
}

/* ------------------------------------------------------------------ publishing a journal */
/**
 * Change who can read one of MY journals. Single entry point for sharing, creating and editing.
 * Returns ['ok'=>bool, 'visibility'=>..., 'status'=>approved|pending|..., 'message'=>string, 'token'=>?string]
 */
function ja_publish_journal($entry_id, $eml, $vis) {
    global $conn; ja_db();
    $entry_id = (int)$entry_id;
    if (!in_array($vis, ['private', 'link', 'public'], true)) return ['ok' => false, 'message' => 'Invalid choice.'];
    $st = mysqli_prepare($conn, "SELECT j.Title, j.Description, j.true_total, j.duration_days, j.dv, j.dr, d.share_token, d.visibility, d.moderation_status
                                 FROM journals j JOIN db d ON d.entry_id = j.entry_id WHERE j.entry_id = ? AND j.eml = ?");
    mysqli_stmt_bind_param($st, 'is', $entry_id, $eml); mysqli_stmt_execute($st);
    $e = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); mysqli_stmt_close($st);
    if (!$e) return ['ok' => false, 'message' => 'Entry not found.'];

    $token = $e['share_token']; $status = 'approved'; $reason = null; $msg = ''; $hash = ja_text_hash($e['Description']);
    if ($vis !== 'private') {
        $t = ja_trust($eml);
        if (!$t['can_publish']) return ['ok' => false, 'message' => $t['why']];
        if (!$token) $token = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
    if ($vis === 'public') {
        if ($e['visibility'] !== 'public' && !ja_public_quota_ok($eml)) return ['ok' => false, 'message' => 'Daily limit for public items reached. Try again tomorrow.'];
        $flags = ja_journal_flags($eml, $e['Title'], $e['Description'],
            ['total' => (float)$e['true_total'], 'days' => (int)$e['duration_days']], ['dv' => $e['dv'], 'dr' => $e['dr']], $entry_id);
        $m = ja_moderation_for($eml, $flags);
        if ($m['status'] === null) return ['ok' => false, 'message' => $m['reason']];
        $status = $m['status']; $reason = $m['reason'];
        $msg = $status === 'pending'
            ? 'Submitted. A moderator will review it before it appears publicly' . ($flags ? ' (' . implode('; ', $flags) . ')' : '') . '.'
            : 'Published.';
    }
    $u = mysqli_prepare($conn, "UPDATE db SET visibility = ?, share_token = ?, moderation_status = ?, mod_reason = ?, text_hash = ?,
                                published_at = IF(? = 'public', COALESCE(published_at, NOW()), published_at) WHERE entry_id = ? AND eml = ?");
    mysqli_stmt_bind_param($u, 'ssssssis', $vis, $token, $status, $reason, $hash, $vis, $entry_id, $eml);
    $ok = mysqli_stmt_execute($u); mysqli_stmt_close($u);
    // photos attached to this journal follow the journal's visibility and review state
    if ($ok && $vis !== 'private') {
        $pub = ($vis === 'public') ? 1 : 0; $ms = mysqli_real_escape_string($conn, $status);
        mysqli_query($conn, "UPDATE media SET is_public = $pub, moderation_status = '$ms' WHERE entry_id = $entry_id AND eml = '" . mysqli_real_escape_string($conn, $eml) . "' AND moderation_status <> 'rejected'");
    } elseif ($ok) {
        mysqli_query($conn, "UPDATE media SET is_public = 0 WHERE entry_id = $entry_id AND eml = '" . mysqli_real_escape_string($conn, $eml) . "'");
    }
    ja_audit($eml, 'share_journal', "#$entry_id -> $vis ($status)");
    return ['ok' => (bool)$ok, 'visibility' => $vis, 'status' => $status, 'message' => $msg, 'token' => $token];
}
