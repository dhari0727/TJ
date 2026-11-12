<?php
/**
 * Instagram publishing through "Instagram API with Instagram Login" (Instagram Business Login).
 *
 * The member signs in on instagram.com itself. This site never sees, asks for or stores an Instagram username or password: the only
 * credential it keeps is the OAuth token (AES-256-GCM encrypted). No Facebook Page is needed. The Instagram account must be a Business
 * or Creator account. Scopes: instagram_business_basic, instagram_business_content_publish.
 *
 * Flow (https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login):
 *   authorize  https://www.instagram.com/oauth/authorize?client_id&redirect_uri&response_type=code&scope&state
 *   code       POST https://api.instagram.com/oauth/access_token      -> short-lived token (about 1 hour)
 *   long-lived GET  https://graph.instagram.com/access_token?grant_type=ig_exchange_token   -> 60 day token
 *   refresh    GET  https://graph.instagram.com/refresh_access_token?grant_type=ig_refresh_token  (token older than 24 h, not expired)
 *   profile    GET  https://graph.instagram.com/me?fields=user_id,username,account_type
 *   publish    POST /<ig-user-id>/media (image_url, caption, alt_text | is_carousel_item, children), poll status_code, POST /media_publish
 *
 * Instagram DOWNLOADS the images from a public https address, so the images are staged behind short-lived random links
 * (ig-media.php) for the few minutes the post takes, then deleted. A site on localhost or plain http is refused with a plain explanation.
 * Only photos and photo carousels are implemented. Reels and Stories are not published from here.
 * The base addresses can be changed by settings only so the tests can talk to a local mock; they are not shown in the admin screen.
 */
require_once __DIR__ . '/../ja-instagram-kit.php';

class JaSocial_instagram extends JaSocialOAuth {
    public $id = 'instagram'; public $label = 'Instagram'; public $tier = 3;
    public $note = 'Log in with Instagram on instagram.com. Business or Creator account. Direct posting needs the site to be online over https.';
    const VERSION = 'v23.0';
    const LIFETIME = 5184000;      // 60 days
    const DAILY_LIMIT = 25;        // stay under Meta's cap on API-published posts per 24 hours (the cap has changed over time)
    protected $scope = 'instagram_business_basic,instagram_business_content_publish';

    public function __construct() { $this->authorizeUrl = $this->authBase() . '/oauth/authorize'; }
    private function base($key, $default) { $v = rtrim(trim((string)ja_setting($key)), '/'); return $v !== '' ? $v : $default; }
    private function authBase() { return $this->base('social_instagram_auth_base', 'https://www.instagram.com'); }
    private function apiBase() { return $this->base('social_instagram_api_base', 'https://api.instagram.com'); }
    private function graph() { return $this->base('social_instagram_graph_base', 'https://graph.instagram.com'); }
    private function gv() { return $this->graph() . '/' . $this->base('social_instagram_version', self::VERSION); }
    private function bearer($tok) { return ['Authorization' => 'Bearer ' . $tok]; }

    public function limits(array $account = null) {
        return ['chars' => 2200, 'counter' => 'chars', 'images' => 10, 'image_required' => true, 'alt' => true, 'needs_public_url' => true, 'visibility' => false];
    }
    public function validate(array $payload, array $account) {
        $l = $this->limits($account);
        $n = ja_social_count($payload['text'], 'chars');
        if (!$payload['images']) return 'Instagram posts need a photo. Choose one.';
        if ($n > $l['chars']) return "That is $n characters and Instagram allows {$l['chars']}. Shorten the caption.";
        if (count($payload['images']) > $l['images']) return 'Instagram allows up to 10 photos in one post.';
        if (preg_match_all('/#[\p{L}\p{N}_]+/u', $payload['text']) > 30) return 'Instagram allows up to 30 hashtags in a post.';
        if (!igk_public_ok()) return $this->notPublicMessage();
        return null;
    }
    private function notPublicMessage() {
        return "Instagram downloads the images from your website, so posting directly needs this site to be online over https. It is not reachable from the internet yet. Use the Instagram Kit to share the images from your phone instead, or try again once the site is live.";
    }

    /* ---------------------------------------------------- sign in */
    protected function profiles($token, array $resp, $eml) { return []; }   // not used: connectComplete is its own flow
    private function remoteMsg(array $r) {
        $j = $r['json'] ?? [];
        if (!empty($j['error_message']) && is_string($j['error_message'])) return $j['error_message'];
        return ja_social_remote_msg($r);
    }
    public function connectComplete($eml, array $state, array $query) {
        $code = preg_replace('/#_$/', '', trim((string)($query['code'] ?? '')));
        if ($code === '') return ['ok' => false, 'error' => 'Instagram did not return a sign-in code. Try again.'];
        [$cid, $sec] = $this->creds();
        $h = ja_social_form_headers(); $h['Accept'] = 'application/json';
        $r = ja_social_http('POST', $this->apiBase() . '/oauth/access_token', $h,
            ['client_id' => $cid, 'client_secret' => $sec, 'grant_type' => 'authorization_code', 'redirect_uri' => $state['redirect'], 'code' => $code]);
        $j = $r['json'] ?? [];
        $row = isset($j['data'][0]) && is_array($j['data'][0]) ? $j['data'][0] : $j;     // newer replies wrap the token in "data"
        if ($r['status'] !== 200 || empty($row['access_token'])) return ['ok' => false, 'error' => ja_social_http_reason('Instagram', $r['status'], $this->remoteMsg($r))];
        $perm = (string)($row['permissions'] ?? '');
        if ($perm !== '' && strpos($perm, 'instagram_business_content_publish') === false)
            return ['ok' => false, 'error' => 'Posting permission was not granted. Log in again and leave "Create and publish content" switched on.'];
        $l = ja_social_http('GET', $this->graph() . '/access_token?' . http_build_query(['grant_type' => 'ig_exchange_token', 'client_secret' => $sec, 'access_token' => $row['access_token']], '', '&'));
        $lj = $l['json'] ?? [];
        if ($l['status'] !== 200 || empty($lj['access_token'])) return ['ok' => false, 'error' => 'Instagram signed you in but did not give this site a 60 day sign-in. ' . ja_social_http_reason('Instagram', $l['status'], $this->remoteMsg($l))];
        $tok = $lj['access_token']; $exp = time() + (int)($lj['expires_in'] ?? self::LIFETIME);
        $me = ja_social_http('GET', $this->graph() . '/me?fields=user_id,username,account_type', $this->bearer($tok));
        $mj = $me['json'] ?? [];
        if ($me['status'] !== 200 || (empty($mj['user_id']) && empty($mj['id']))) return ['ok' => false, 'error' => ja_social_http_reason('Instagram', $me['status'], $this->remoteMsg($me))];
        $type = strtoupper((string)($mj['account_type'] ?? ''));
        if ($type === 'PERSONAL' || $type === 'MEMBER') return ['ok' => false, 'error' => 'This Instagram account is a personal account. Switch it to a Business or Creator account in the Instagram app (Settings, Account type and tools), then log in again.'];
        $uid = (string)($mj['user_id'] ?? $mj['id']); $uname = (string)($mj['username'] ?? $uid);
        $ok = ja_social_account_save($eml, 'instagram', $uid, '@' . $uname, $uname, $tok, null, $exp, $this->scope, ['account_type' => $type]);
        return ['ok' => $ok, 'count' => $ok ? 1 : 0, 'error' => $ok ? '' : 'Could not save the account.'];
    }

    /** 60 day token: refresh when it is within 10 days of expiring and at least 24 hours old. Expired tokens cannot be refreshed. */
    public function ensureFresh(array $a) {
        $exp = $a['expires_at'] ? strtotime($a['expires_at']) : 0;
        if (!$exp) return $a;
        $now = time();
        if ($exp <= $now) { ja_social_account_status($a['account_id'], 'expired'); return null; }
        if ($exp - $now > 10 * 86400) return $a;                    // not due yet
        if ($now - ($exp - self::LIFETIME) < 86400) return $a;      // Instagram refuses tokens younger than 24 hours
        [$tok] = ja_social_tokens($a);
        $r = ja_social_http('GET', $this->graph() . '/refresh_access_token?' . http_build_query(['grant_type' => 'ig_refresh_token', 'access_token' => $tok], '', '&'));
        $j = $r['json'] ?? [];
        if ($r['status'] === 200 && !empty($j['access_token'])) {
            ja_social_account_update_tokens($a['account_id'], $j['access_token'], null, $now + (int)($j['expires_in'] ?? self::LIFETIME));
            return ja_social_account($a['eml'], $a['account_id']);
        }
        if (in_array($r['status'], [400, 401], true) || (int)($j['error']['code'] ?? 0) === 190) { ja_social_account_status($a['account_id'], 'expired'); return null; }
        return $a;   // a temporary problem: the token is still valid, try with it
    }

    /** "Test connection": who does Instagram say this sign-in belongs to? */
    public function testConnection(array $a) {
        $fresh = $this->ensureFresh($a);
        if (!$fresh) return ['ok' => false, 'message' => 'Your Instagram sign-in has expired or was revoked. Log in with Instagram again.'];
        [$tok] = ja_social_tokens($fresh);
        $r = ja_social_http('GET', $this->graph() . '/me?fields=user_id,username,account_type', $this->bearer($tok));
        $j = $r['json'] ?? [];
        if ($r['status'] === 200 && !empty($j['username'])) {
            $type = strtoupper((string)($j['account_type'] ?? ''));
            if ($type === 'PERSONAL') return ['ok' => false, 'message' => 'Signed in as @' . $j['username'] . ', but this is a personal account. Instagram only allows posting from Business or Creator accounts.'];
            $until = $fresh['expires_at'] ? ' The sign-in is valid until ' . date('j M Y', strtotime($fresh['expires_at'])) . ' and refreshes itself when you use it.' : '';
            $pub = igk_public_ok() ? '' : ' Note: this site is not online over https yet, so direct posting is switched off until it is.';
            return ['ok' => true, 'message' => 'Connected as @' . $j['username'] . ($type ? ' (' . ucfirst(strtolower(str_replace('_', ' ', $type))) . ' account)' : '') . '.' . $until . $pub];
        }
        if ($r['status'] === 401 || (int)($j['error']['code'] ?? 0) === 190) { ja_social_account_status($fresh['account_id'], 'expired'); return ['ok' => false, 'message' => 'Instagram no longer accepts this sign-in. Log in with Instagram again.']; }
        return ['ok' => false, 'message' => ja_social_http_reason('Instagram', $r['status'], $this->remoteMsg($r))];
    }
    public function revoke(array $a) {}   // Instagram has no revoke call for this token; it is deleted here. Remove the app under Instagram Settings, Apps and websites.

    /* ---------------------------------------------------- publishing */
    private function graphError(array $a, array $r, $what) {
        $j = $r['json'] ?? []; $code = (int)($j['error']['code'] ?? 0);
        if ($r['status'] === 401 || $code === 190) { ja_social_account_status($a['account_id'], 'expired'); return 'Instagram no longer accepts this sign-in. Log in with Instagram again in your profile.'; }
        if (in_array($code, [4, 17, 32, 613], true) || $r['status'] === 429) return 'Instagram says there were too many requests. Wait a while and try again.';
        $m = $this->remoteMsg($r);
        return $what . ' ' . ja_social_http_reason('Instagram', $r['status'], $m);
    }
    private function waitFor($id, array $auth, &$why) {
        $ms = max(20, (int)ja_setting('social_instagram_poll_ms') ?: 2000);
        $tries = (int)ceil(60000 / $ms);
        for ($i = 0; $i < $tries; $i++) {
            $r = ja_social_http('GET', $this->gv() . '/' . rawurlencode($id) . '?fields=status_code,status', $auth);
            $s = (string)($r['json']['status_code'] ?? '');
            if ($s === 'FINISHED' || $s === 'PUBLISHED') return true;
            if ($s === 'ERROR' || $s === 'EXPIRED') { $why = $s === 'EXPIRED' ? 'Instagram dropped the upload because it took too long.' : 'Instagram could not process the image' . (!empty($r['json']['status']) ? ' (' . ja_social_clean_error($r['json']['status'], 100) . ')' : '') . '.'; return false; }
            if ($r['status'] >= 400) { $why = ja_social_http_reason('Instagram', $r['status'], $this->remoteMsg($r)); return false; }
            usleep($ms * 1000);
        }
        $why = 'Instagram is still processing the images. Check your Instagram profile in a minute before trying again.';
        return false;
    }
    /** Make the final JPEGs and park each one behind a short-lived link. Returns [urls, tokens] or an error string. */
    private function stage(array $a, array $payload) {
        $urls = []; $tokens = [];
        foreach ($payload['images'] as $i => $img) {
            try {
                if (strncmp($img['rel'], 'kit:', 4) === 0) {
                    $k = $payload['kit'] ?? null;
                    if (!$k || empty($payload['item'])) return 'The Instagram images could not be prepared.';
                    $bytes = igk_render($payload['item'], $k['format'], $k['template'], $k['format'] === 'carousel' ? (int)substr($img['rel'], 4) : (int)($k['n'] ?? 0));
                } else {
                    $abs = ja_social_abs_media($img['rel']);
                    if (!$abs) return 'One of the photos is not available.';
                    $bytes = igk_prepare_photo($abs);
                }
            } catch (Throwable $e) { return 'One of the photos could not be prepared for Instagram.'; }
            $q = igk_queue_put($a['eml'], $bytes);
            if (!$q) return 'Could not prepare the images for Instagram.';
            $urls[] = $q['url']; $tokens[] = $q['token'];
        }
        return [$urls, $tokens];
    }
    public function post(array $a, array $payload) {
        if (!igk_public_ok()) return $this->fail($this->notPublicMessage());
        if (!ja_rate_limit('igpub:' . (int)$a['account_id'], self::DAILY_LIMIT, 86400))
            return $this->fail('This account has reached ' . self::DAILY_LIMIT . ' posts made from this site in 24 hours. Instagram limits posts published through its API. Try again tomorrow.');
        @set_time_limit(180);
        $staged = $this->stage($a, $payload);
        if (is_string($staged)) return $this->fail($staged);
        [$urls, $tokens] = $staged;
        try { return $this->publish($a, $payload, $urls); }
        finally { igk_queue_clear($tokens); }
    }
    private function publish(array $a, array $payload, array $urls) {
        [$tok] = ja_social_tokens($a);
        $auth = $this->bearer($tok);
        $api = $this->gv() . '/' . rawurlencode($a['remote_id']);
        $imgs = $payload['images']; $why = '';
        if (count($imgs) === 1) {
            $f = ['image_url' => $urls[0], 'caption' => $payload['text']];
            if (($imgs[0]['alt'] ?? '') !== '') $f['alt_text'] = mb_substr($imgs[0]['alt'], 0, 1000);
            $r = ja_social_http('POST', $api . '/media', $auth, $f);
        } else {
            $kids = [];
            foreach ($imgs as $i => $img) {
                $f = ['image_url' => $urls[$i], 'is_carousel_item' => 'true'];
                if (($img['alt'] ?? '') !== '') $f['alt_text'] = mb_substr($img['alt'], 0, 1000);
                $c = ja_social_http('POST', $api . '/media', $auth, $f);
                if ($c['status'] !== 200 || empty($c['json']['id'])) return $this->fail($this->graphError($a, $c, 'Slide ' . ($i + 1) . ' was refused.'));
                $kids[] = $c['json']['id'];
            }
            foreach ($kids as $i => $k) if (!$this->waitFor($k, $auth, $why)) return $this->fail('Slide ' . ($i + 1) . ': ' . $why);
            $r = ja_social_http('POST', $api . '/media', $auth, ['media_type' => 'CAROUSEL', 'children' => implode(',', $kids), 'caption' => $payload['text']]);
        }
        if ($r['status'] !== 200 || empty($r['json']['id'])) return $this->fail($this->graphError($a, $r, 'The post was refused.'));
        $cid = $r['json']['id'];
        if (!$this->waitFor($cid, $auth, $why)) return $this->fail($why);
        $p = ja_social_http('POST', $api . '/media_publish', $auth, ['creation_id' => $cid]);
        if ($p['status'] !== 200 || empty($p['json']['id'])) return $this->fail($this->graphError($a, $p, 'Publishing failed.'));
        $pl = ja_social_http('GET', $this->gv() . '/' . rawurlencode($p['json']['id']) . '?fields=permalink', $auth);
        return $this->done($p['json']['id'], (string)($pl['json']['permalink'] ?? ''));
    }
}
