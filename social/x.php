<?php
/**
 * X (Twitter). Tier 3: needs an X developer app (OAuth 2.0, Client ID + Secret) on a paid API tier that allows posting.
 * Flow: OAuth 2.0 Authorization Code with PKCE; tweets and media go through the v2 API.
 */
class JaSocial_x extends JaSocialOAuth {
    public $id = 'x'; public $label = 'X'; public $tier = 3;
    public $note = 'Needs an X API plan that allows posting.';
    protected $authorizeUrl = 'https://x.com/i/oauth2/authorize';
    protected $tokenUrl = 'https://api.x.com/2/oauth2/token';
    protected $scope = 'tweet.read tweet.write users.read media.write offline.access';
    protected $pkce = true; protected $clientAuth = 'basic';
    public function limits(array $account = null) {
        return ['chars' => 280, 'counter' => 'url23', 'images' => 4, 'image_required' => false, 'alt' => true, 'needs_public_url' => false, 'visibility' => false];
    }
    protected function profiles($token, array $resp, $eml) {
        $r = ja_social_http('GET', 'https://api.x.com/2/users/me', ['Authorization' => 'Bearer ' . $token]);
        $d = $r['json']['data'] ?? null;
        if ($r['status'] !== 200 || empty($d['id'])) return ja_social_http_reason('X', $r['status'], ja_social_remote_msg($r));
        return [['remote_id' => $d['id'], 'handle' => '@' . ($d['username'] ?? $d['id']), 'name' => (string)($d['name'] ?? '')]];
    }
    protected function tokenRequest(array $fields, array $st = []) {
        [$cid, $sec] = $this->creds();
        $h = ja_social_form_headers() + ['Accept' => 'application/json', 'Authorization' => 'Basic ' . base64_encode($cid . ':' . $sec)];
        $fields['client_id'] = $cid;
        return ja_social_http('POST', $this->tokenUrl, $h, $fields);
    }
    public function revoke(array $a) {
        [$tok] = ja_social_tokens($a); [$cid, $sec] = $this->creds();
        if ($tok !== '' && $cid !== '') ja_social_http('POST', 'https://api.x.com/2/oauth2/revoke', ja_social_form_headers() + ['Authorization' => 'Basic ' . base64_encode($cid . ':' . $sec)], ['token' => $tok, 'token_type_hint' => 'access_token', 'client_id' => $cid], ['timeout' => 8]);
    }
    public function post(array $a, array $payload) {
        [$tok] = ja_social_tokens($a);
        $auth = ['Authorization' => 'Bearer ' . $tok];
        $ids = [];
        foreach ($payload['images'] as $img) {
            $bin = $this->imageBytes($img, 4800000, 2048);
            if (!$bin) return $this->fail('A photo could not be prepared for X.');
            $tmp = tempnam(sys_get_temp_dir(), 'jas'); file_put_contents($tmp, $bin[0]);
            $r = ja_social_http('POST', 'https://api.x.com/2/media/upload', $auth, ['__multipart' => ['media' => new CURLFile($tmp, $bin[1], 'photo.jpg'), 'media_category' => 'tweet_image', 'media_type' => $bin[1]]], ['timeout' => 60]);
            @unlink($tmp);
            $mid = $r['json']['data']['id'] ?? ($r['json']['media_id_string'] ?? null);
            if (!in_array($r['status'], [200, 201], true) || !$mid) return $this->fail(ja_social_http_reason('X', $r['status'], ja_social_remote_msg($r)));
            if ($img['alt'] !== '') ja_social_http('POST', 'https://api.x.com/2/media/metadata', $auth + ['Content-Type' => 'application/json'], json_encode(['id' => (string)$mid, 'metadata' => ['alt_text' => ['text' => mb_substr($img['alt'], 0, 1000)]]]));
            $ids[] = (string)$mid;
        }
        $body = ['text' => $payload['text']];
        if ($ids) $body['media'] = ['media_ids' => $ids];
        $r = ja_social_http('POST', 'https://api.x.com/2/tweets', ja_social_json_headers($tok), json_encode($body, JSON_UNESCAPED_UNICODE));
        $d = $r['json']['data'] ?? null;
        if (!in_array($r['status'], [200, 201], true) || empty($d['id'])) {
            if ($r['status'] === 402) return $this->fail('Your X API plan does not allow posting. The site owner needs a plan that includes it.');
            return $this->fail(ja_social_http_reason('X', $r['status'], ja_social_remote_msg($r)));
        }
        return $this->done($d['id'], 'https://x.com/' . ltrim($a['handle'], '@') . '/status/' . $d['id']);
    }
}
