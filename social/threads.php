<?php
/**
 * Threads. Tier 3: needs a Threads app registered by the site owner (Meta for Developers) with threads_basic and
 * threads_content_publish. A photo is downloaded by Threads from a public https address, so a localhost site can post text only.
 */
class JaSocial_threads extends JaSocialOAuth {
    public $id = 'threads'; public $label = 'Threads'; public $tier = 3;
    public $note = 'Text posts work anywhere. Photos need the site to be online over https.';
    protected $authorizeUrl = 'https://threads.net/oauth/authorize';
    protected $tokenUrl = 'https://graph.threads.net/oauth/access_token';
    protected $scope = 'threads_basic,threads_content_publish';
    public function limits(array $account = null) {
        return ['chars' => 500, 'counter' => 'chars', 'images' => 1, 'image_required' => false, 'alt' => false, 'needs_public_url' => true, 'visibility' => false];
    }
    protected function profiles($token, array $resp, $eml) {
        // swap the short-lived token for a 60 day one
        [, $sec] = $this->creds();
        $l = ja_social_http('GET', 'https://graph.threads.net/access_token?' . http_build_query(['grant_type' => 'th_exchange_token', 'client_secret' => $sec, 'access_token' => $token]));
        $exp = null;
        if ($l['status'] === 200 && !empty($l['json']['access_token'])) { $token = $l['json']['access_token']; $exp = time() + (int)($l['json']['expires_in'] ?? 5184000); }
        $r = ja_social_http('GET', 'https://graph.threads.net/v1.0/me?fields=id,username,name', ['Authorization' => 'Bearer ' . $token]);
        if ($r['status'] !== 200 || empty($r['json']['id'])) return ja_social_http_reason('Threads', $r['status'], ja_social_remote_msg($r));
        $j = $r['json'];
        return [['remote_id' => $j['id'], 'handle' => '@' . ($j['username'] ?? $j['id']), 'name' => (string)($j['name'] ?? $j['username'] ?? ''), 'token' => $token, 'expires' => $exp, 'refresh' => null]];
    }
    public function ensureFresh(array $a) {
        if (!$a['expires_at'] || strtotime($a['expires_at']) > time() + 86400) return $a;
        [$tok] = ja_social_tokens($a);
        $r = ja_social_http('GET', 'https://graph.threads.net/refresh_access_token?' . http_build_query(['grant_type' => 'th_refresh_token', 'access_token' => $tok]));
        if ($r['status'] !== 200 || empty($r['json']['access_token'])) { if (strtotime($a['expires_at']) < time()) { ja_social_account_status($a['account_id'], 'expired'); return null; } return $a; }
        ja_social_account_update_tokens($a['account_id'], $r['json']['access_token'], null, time() + (int)($r['json']['expires_in'] ?? 5184000));
        return ja_social_account($a['eml'], $a['account_id']);
    }
    public function revoke(array $a) {}   // Threads has no token revocation call; the tokens are deleted here
    public function post(array $a, array $payload) {
        [$tok] = ja_social_tokens($a);
        $auth = ['Authorization' => 'Bearer ' . $tok];
        $api = 'https://graph.threads.net/v1.0/' . rawurlencode($a['remote_id']);
        $f = ['text' => $payload['text'], 'media_type' => 'TEXT'];
        if ($payload['images']) { $f['media_type'] = 'IMAGE'; $f['image_url'] = $this->publicImageUrl($payload['images'][0]); }
        $r = ja_social_http('POST', $api . '/threads', $auth, $f);
        if ($r['status'] !== 200 || empty($r['json']['id'])) return $this->fail(ja_social_http_reason('Threads', $r['status'], ja_social_remote_msg($r)));
        sleep(2);
        $p = ja_social_http('POST', $api . '/threads_publish', $auth, ['creation_id' => $r['json']['id']]);
        if ($p['status'] !== 200 || empty($p['json']['id'])) return $this->fail(ja_social_http_reason('Threads', $p['status'], ja_social_remote_msg($p)));
        $pl = ja_social_http('GET', 'https://graph.threads.net/v1.0/' . rawurlencode($p['json']['id']) . '?fields=permalink', $auth);
        return $this->done($p['json']['id'], (string)($pl['json']['permalink'] ?? ''));
    }
}
