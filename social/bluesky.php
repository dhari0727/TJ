<?php
/**
 * Bluesky (AT Protocol). Tier 2: no developer approval. The user gives their handle and an APP PASSWORD
 * (Bluesky > Settings > Privacy and security > App passwords). The password is used once to create a session and is never stored;
 * we keep only the encrypted session tokens.
 */
class JaSocial_bluesky extends JaSocialProvider {
    public $id = 'bluesky'; public $label = 'Bluesky'; public $tier = 2; public $auth = 'password';
    public $note = 'Sign in with an app password. No approval needed.';

    /** Server address: the setting (tests and self-hosted servers) or bsky.social. Must pass the same host rules as every outbound call. */
    private function base(array $a = null) {
        $m = $a ? ja_social_meta($a) : [];
        $b = rtrim((string)($m['base'] ?? ja_setting('social_bluesky_base', 'https://bsky.social')), '/');
        return ja_social_url_ok($b) ? $b : 'https://bsky.social';
    }
    public function limits(array $account = null) {
        return ['chars' => 300, 'counter' => 'graphemes', 'images' => 4, 'image_required' => false, 'alt' => true, 'needs_public_url' => false, 'visibility' => false];
    }
    private static function jwtExp($jwt) {
        $p = explode('.', (string)$jwt);
        $j = isset($p[1]) ? json_decode(base64_decode(strtr($p[1], '-_', '+/')), true) : null;
        return is_array($j) && !empty($j['exp']) ? (int)$j['exp'] : time() + 3000;
    }
    public function connectPassword($eml, array $in) {
        $handle = ltrim(trim((string)($in['handle'] ?? '')), '@');
        $pw = (string)($in['app_password'] ?? '');
        if ($handle === '' || $pw === '') return ['ok' => false, 'error' => 'Enter your Bluesky handle and an app password.'];
        if (!preg_match('/^[A-Za-z0-9._@:-]{3,253}$/', $handle)) return ['ok' => false, 'error' => 'That does not look like a Bluesky handle. Example: name.bsky.social'];
        if (!preg_match('/^[A-Za-z0-9-]{4,60}$/', $pw)) return ['ok' => false, 'error' => 'That does not look like an app password. It has the form xxxx-xxxx-xxxx-xxxx. Do not use your main password.'];
        $base = $this->base();
        $r = ja_social_http('POST', $base . '/xrpc/com.atproto.server.createSession', ja_social_json_headers(), json_encode(['identifier' => $handle, 'password' => $pw]));
        $j = $r['json'] ?? [];
        if ($r['status'] !== 200 || empty($j['accessJwt']) || empty($j['did'])) {
            if (in_array($r['status'], [400, 401], true)) return ['ok' => false, 'error' => 'Bluesky did not accept that handle and app password. Create a new app password and try again.'];
            return ['ok' => false, 'error' => ja_social_http_reason('Bluesky', $r['status'], ja_social_remote_msg($r))];
        }
        $h = (string)($j['handle'] ?? $handle);
        $ok = ja_social_account_save($eml, 'bluesky', $j['did'], '@' . $h, $h, $j['accessJwt'], $j['refreshJwt'] ?? '', self::jwtExp($j['accessJwt']), 'app-password', ['base' => $base]);
        return ['ok' => $ok, 'error' => $ok ? '' : 'Could not save the account.'];
    }
    public function ensureFresh(array $a) {
        if ($a['expires_at'] && strtotime($a['expires_at']) > time() + 120) return $a;
        [, $ref] = ja_social_tokens($a);
        if ($ref === '') { ja_social_account_status($a['account_id'], 'expired'); return null; }
        $r = ja_social_http('POST', $this->base($a) . '/xrpc/com.atproto.server.refreshSession', ['Authorization' => 'Bearer ' . $ref, 'Accept' => 'application/json']);
        $j = $r['json'] ?? [];
        if ($r['status'] !== 200 || empty($j['accessJwt'])) { ja_social_account_status($a['account_id'], 'expired'); return null; }
        ja_social_account_update_tokens($a['account_id'], $j['accessJwt'], $j['refreshJwt'] ?? $ref, self::jwtExp($j['accessJwt']));
        return ja_social_account($a['eml'], $a['account_id']);
    }
    public function revoke(array $a) {
        [, $ref] = ja_social_tokens($a);
        if ($ref !== '') ja_social_http('POST', $this->base($a) . '/xrpc/com.atproto.server.deleteSession', ['Authorization' => 'Bearer ' . $ref], null, ['timeout' => 8]);
    }
    /** Link and hashtag facets. Positions are UTF-8 byte offsets, as the protocol requires. */
    public static function facets($text) {
        $f = [];
        if (preg_match_all('~https?://[^\s]+~u', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$u, $off]) {
                $u2 = rtrim($u, '.,;:!?)]}\'"');
                if ($u2 === '') continue;
                $f[] = ['index' => ['byteStart' => $off, 'byteEnd' => $off + strlen($u2)], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $u2]]];
            }
        }
        if (preg_match_all('/(?<![\w#\/])#([\p{L}\p{N}_]*\p{L}[\p{L}\p{N}_]*)/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $i => [$full, $off]) {
                $f[] = ['index' => ['byteStart' => $off, 'byteEnd' => $off + strlen($full)], 'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => $m[1][$i][0]]]];
            }
        }
        return $f;
    }
    public function post(array $a, array $payload) {
        [$tok] = ja_social_tokens($a);
        $base = $this->base($a);
        $auth = ['Authorization' => 'Bearer ' . $tok];
        $embedImgs = [];
        foreach ($payload['images'] as $img) {
            $bin = $this->imageBytes($img, 950000, 2000);
            if (!$bin) return $this->fail('A photo could not be prepared for Bluesky.');
            $r = ja_social_http('POST', $base . '/xrpc/com.atproto.repo.uploadBlob', $auth + ['Content-Type' => $bin[1]], $bin[0]);
            if ($r['status'] !== 200 || empty($r['json']['blob'])) return $this->fail(ja_social_http_reason('Bluesky', $r['status'], ja_social_remote_msg($r)));
            $embedImgs[] = ['alt' => (string)$img['alt'], 'image' => $r['json']['blob']];
        }
        $rec = ['$type' => 'app.bsky.feed.post', 'text' => $payload['text'], 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z'), 'langs' => ['en']];
        if ($fc = self::facets($payload['text'])) $rec['facets'] = $fc;
        if ($embedImgs) $rec['embed'] = ['$type' => 'app.bsky.embed.images', 'images' => $embedImgs];
        $r = ja_social_http('POST', $base . '/xrpc/com.atproto.repo.createRecord', ja_social_json_headers($tok),
            json_encode(['repo' => $a['remote_id'], 'collection' => 'app.bsky.feed.post', 'record' => $rec], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $j = $r['json'] ?? [];
        if ($r['status'] !== 200 || empty($j['uri'])) {
            if ($r['status'] === 400 && in_array($j['error'] ?? '', ['ExpiredToken', 'InvalidToken'], true)) ja_social_account_status($a['account_id'], 'expired');
            return $this->fail(ja_social_http_reason('Bluesky', $r['status'], ja_social_remote_msg($r)));
        }
        $rkey = substr(strrchr($j['uri'], '/'), 1);
        return $this->done($j['uri'], 'https://bsky.app/profile/' . ltrim($a['handle'], '@') . '/post/' . $rkey);
    }
}
