<?php
/**
 * Mastodon. Tier 2: no developer approval. Every Mastodon server has its own accounts, so the app is registered
 * automatically on the server the user names (POST /api/v1/apps) and the user signs in there with OAuth.
 * Scopes asked for: write:statuses write:media (post only, no reading or following).
 */
class JaSocial_mastodon extends JaSocialProvider {
    public $id = 'mastodon'; public $label = 'Mastodon'; public $tier = 2; public $auth = 'instance';
    public $note = 'Sign in on your own Mastodon server. No approval needed.';
    const SCOPES = 'write:statuses write:media';

    public function limits(array $account = null) {
        $m = $account ? ja_social_meta($account) : [];
        return ['chars' => max(100, (int)($m['max_chars'] ?? 500)), 'counter' => 'url23', 'images' => max(1, min(4, (int)($m['max_media'] ?? 4))),
                'image_required' => false, 'alt' => true, 'needs_public_url' => false, 'visibility' => true];
    }
    /** "mastodon.social", "https://mastodon.social/@me" or "me@mastodon.social" -> base URL, or null. */
    public static function normalize($in) {
        $in = trim((string)$in);
        if ($in === '') return null;
        if (!preg_match('~^https?://~i', $in)) { if (strpos($in, '@') !== false) $in = substr(strrchr($in, '@'), 1); $in = 'https://' . $in; }
        $p = parse_url($in);
        if (!$p || empty($p['host'])) return null;
        $host = strtolower($p['host']);
        $port = !empty($p['port']) ? ':' . (int)$p['port'] : '';
        $scheme = ja_social_is_local_host($host) && ($p['scheme'] ?? '') === 'http' ? 'http' : 'https';
        $base = $scheme . '://' . $host . $port;
        return ja_social_url_ok($base, true) ? $base : null;
    }
    private function appKey($base) { return 'social_mastodon_app_' . substr(sha1($base . '|' . ja_social_redirect_uri()), 0, 24); }
    private function app($base) {
        $key = $this->appKey($base);
        $cached = json_decode((string)ja_fresh_setting($key), true);
        if (is_array($cached) && !empty($cached['client_id']) && !empty($cached['client_secret'])) return [$cached, $key, null];
        $site = ja_social_base_public() ? rtrim(ja_base_url(), '/') : null;
        $form = ['client_name' => ja_setting('site_name', 'JourneyAI'), 'redirect_uris' => ja_social_redirect_uri(), 'scopes' => self::SCOPES];
        if ($site) $form['website'] = $site;
        $r = ja_social_http('POST', $base . '/api/v1/apps', ja_social_form_headers() + ['Accept' => 'application/json'], $form, ['user_supplied' => true]);
        $j = $r['json'] ?? [];
        if ($r['status'] !== 200 || empty($j['client_id']) || empty($j['client_secret'])) return [null, $key, ja_social_http_reason('That Mastodon server', $r['status'], ja_social_remote_msg($r))];
        $app = ['client_id' => $j['client_id'], 'client_secret' => $j['client_secret']];
        ja_setting_set($key, json_encode($app), true);
        return [$app, $key, null];
    }
    public function connectStart($eml, array $in) {
        $base = self::normalize($in['instance'] ?? '');
        if (!$base) return ['ok' => false, 'error' => 'Enter your Mastodon server, for example mastodon.social.'];
        [$app, $key, $err] = $this->app($base);
        if (!$app) return ['ok' => false, 'error' => $err];
        $s = ja_social_oauth_begin('mastodon', $eml, true, ['base' => $base, 'app_key' => $key]);
        $q = ['response_type' => 'code', 'client_id' => $app['client_id'], 'redirect_uri' => $s['redirect'], 'scope' => self::SCOPES, 'state' => $s['state'],
              'code_challenge' => $s['challenge'], 'code_challenge_method' => 'S256'];
        return ['ok' => true, 'url' => $base . '/oauth/authorize?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986)];
    }
    public function connectComplete($eml, array $state, array $query) {
        $code = (string)($query['code'] ?? '');
        $base = $state['x']['base'] ?? ''; $key = $state['x']['app_key'] ?? '';
        if ($code === '' || !self::normalize($base)) return ['ok' => false, 'error' => 'The Mastodon sign-in did not finish. Start again.'];
        $app = json_decode((string)ja_fresh_setting($key), true);
        if (empty($app['client_id'])) return ['ok' => false, 'error' => 'The app registration is missing. Start again.'];
        $r = ja_social_http('POST', $base . '/oauth/token', ja_social_form_headers() + ['Accept' => 'application/json'], [
            'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $app['client_id'], 'client_secret' => $app['client_secret'],
            'redirect_uri' => $state['redirect'], 'code_verifier' => $state['verifier'], 'scope' => self::SCOPES], ['user_supplied' => true]);
        $j = $r['json'] ?? [];
        if ($r['status'] !== 200 || empty($j['access_token'])) return ['ok' => false, 'error' => ja_social_http_reason('That Mastodon server', $r['status'], ja_social_remote_msg($r))];
        $tok = $j['access_token'];
        $me = ja_social_http('GET', $base . '/api/v1/accounts/verify_credentials', ['Authorization' => 'Bearer ' . $tok, 'Accept' => 'application/json'], null, ['user_supplied' => true]);
        $mj = $me['json'] ?? [];
        if ($me['status'] !== 200 || empty($mj['id'])) return ['ok' => false, 'error' => ja_social_http_reason('That Mastodon server', $me['status'], ja_social_remote_msg($me))];
        $host = parse_url($base, PHP_URL_HOST) . (parse_url($base, PHP_URL_PORT) ? ':' . parse_url($base, PHP_URL_PORT) : '');
        $meta = ['base' => $base, 'host' => $host, 'app_key' => $key, 'max_chars' => 500, 'max_media' => 4];
        $inst = ja_social_http('GET', $base . '/api/v2/instance', ['Accept' => 'application/json'], null, ['user_supplied' => true, 'timeout' => 10]);
        $ij = $inst['json'] ?? [];
        if ($inst['status'] === 200 && !empty($ij['configuration']['statuses'])) {
            $meta['max_chars'] = (int)($ij['configuration']['statuses']['max_characters'] ?? 500);
            $meta['max_media'] = (int)($ij['configuration']['statuses']['max_media_attachments'] ?? 4);
        } else {
            $inst = ja_social_http('GET', $base . '/api/v1/instance', ['Accept' => 'application/json'], null, ['user_supplied' => true, 'timeout' => 10]);
            $ij = $inst['json'] ?? [];
            if (!empty($ij['max_toot_chars'])) $meta['max_chars'] = (int)$ij['max_toot_chars'];
        }
        $acct = (string)($mj['acct'] ?? $mj['username'] ?? 'account');
        $ok = ja_social_account_save($eml, 'mastodon', $mj['id'] . '@' . $host, '@' . $acct . '@' . $host, (string)($mj['display_name'] ?: $acct), $tok, null, null, self::SCOPES, $meta);
        return ['ok' => $ok, 'count' => 1, 'error' => $ok ? '' : 'Could not save the account.'];
    }
    public function revoke(array $a) {
        $m = ja_social_meta($a);
        $app = json_decode((string)ja_fresh_setting($m['app_key'] ?? ''), true);
        [$tok] = ja_social_tokens($a);
        if ($tok !== '' && !empty($app['client_id']) && !empty($m['base']))
            ja_social_http('POST', $m['base'] . '/oauth/revoke', ja_social_form_headers(), ['client_id' => $app['client_id'], 'client_secret' => $app['client_secret'], 'token' => $tok], ['user_supplied' => true, 'timeout' => 8]);
    }
    public function post(array $a, array $payload) {
        $m = ja_social_meta($a); $base = $m['base'] ?? '';
        if (!self::normalize($base)) return $this->fail('The Mastodon server address is not valid. Reconnect the account.');
        [$tok] = ja_social_tokens($a);
        $auth = ['Authorization' => 'Bearer ' . $tok, 'Accept' => 'application/json'];
        $ids = [];
        foreach ($payload['images'] as $img) {
            $bin = $this->imageBytes($img, 7500000, 2400);
            if (!$bin) return $this->fail('A photo could not be prepared for Mastodon.');
            $tmp = tempnam(sys_get_temp_dir(), 'jas'); file_put_contents($tmp, $bin[0]);
            $cf = new CURLFile($tmp, $bin[1], 'photo.jpg');
            $r = ja_social_http('POST', $base . '/api/v2/media', $auth, ['__multipart' => ['file' => $cf, 'description' => (string)$img['alt']]], ['user_supplied' => true, 'timeout' => 60]);
            @unlink($tmp);
            $mid = $r['json']['id'] ?? null;
            if (!in_array($r['status'], [200, 202], true) || !$mid) return $this->fail(ja_social_http_reason('Mastodon', $r['status'], ja_social_remote_msg($r)));
            for ($i = 0; $r['status'] === 202 && $i < 12; $i++) {   // the server is still processing the upload
                sleep(1);
                $r = ja_social_http('GET', $base . '/api/v1/media/' . rawurlencode($mid), $auth, null, ['user_supplied' => true]);
                if (!in_array($r['status'], [200, 206], true)) { if ($r['status'] >= 400) return $this->fail(ja_social_http_reason('Mastodon', $r['status'], ja_social_remote_msg($r))); }
                if ($r['status'] === 200) break;
            }
            $ids[] = $mid;
        }
        $vis = in_array($payload['visibility'], ['public', 'unlisted'], true) ? $payload['visibility'] : 'public';
        $q = 'status=' . rawurlencode($payload['text']) . '&visibility=' . $vis;
        foreach ($ids as $id) $q .= '&media_ids[]=' . rawurlencode($id);
        $r = ja_social_http('POST', $base . '/api/v1/statuses', $auth + ['Content-Type' => 'application/x-www-form-urlencoded', 'Idempotency-Key' => bin2hex(random_bytes(12))], $q, ['user_supplied' => true]);
        $j = $r['json'] ?? [];
        if ($r['status'] !== 200 || empty($j['id'])) {
            if ($r['status'] === 401) ja_social_account_status($a['account_id'], 'expired');
            return $this->fail(ja_social_http_reason('Mastodon', $r['status'], ja_social_remote_msg($r)));
        }
        return $this->done($j['id'], (string)($j['url'] ?? $j['uri'] ?? ''));
    }
}
