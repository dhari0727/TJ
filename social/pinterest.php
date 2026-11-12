<?php
/**
 * Pinterest (API v5). Tier 3: needs a Pinterest developer app; new apps start in "trial" (pins are visible only to the app owner)
 * until Pinterest approves standard access. A pin needs one photo and a board; the first board found is used.
 */
class JaSocial_pinterest extends JaSocialOAuth {
    public $id = 'pinterest'; public $label = 'Pinterest'; public $tier = 3;
    public $note = 'Pins one photo, with a link back, to your first board.';
    protected $authorizeUrl = 'https://www.pinterest.com/oauth/';
    protected $tokenUrl = 'https://api.pinterest.com/v5/oauth/token';
    protected $scope = 'boards:read,pins:read,pins:write,user_accounts:read'; protected $scopeSep = ',';
    protected $clientAuth = 'basic';
    public function limits(array $account = null) {
        return ['chars' => 500, 'counter' => 'chars', 'images' => 1, 'image_required' => true, 'alt' => true, 'needs_public_url' => false, 'visibility' => false];
    }
    protected function profiles($token, array $resp, $eml) {
        $auth = ['Authorization' => 'Bearer ' . $token];
        $r = ja_social_http('GET', 'https://api.pinterest.com/v5/user_account', $auth);
        $j = $r['json'] ?? [];
        if ($r['status'] !== 200 || empty($j['username'])) return ja_social_http_reason('Pinterest', $r['status'], ja_social_remote_msg($r));
        $b = ja_social_http('GET', 'https://api.pinterest.com/v5/boards?page_size=25', $auth);
        $board = $b['json']['items'][0] ?? null;
        $meta = $board ? ['board' => (string)$board['id'], 'board_name' => (string)($board['name'] ?? '')] : [];
        return [['remote_id' => (string)($j['id'] ?? $j['username']), 'handle' => (string)$j['username'], 'name' => (string)($j['business_name'] ?? $j['username']), 'meta' => $meta]];
    }
    protected function tokenRequest(array $fields, array $st = []) {
        [$cid, $sec] = $this->creds();
        return ja_social_http('POST', $this->tokenUrl, ja_social_form_headers() + ['Accept' => 'application/json', 'Authorization' => 'Basic ' . base64_encode($cid . ':' . $sec)], $fields);
    }
    public function post(array $a, array $payload) {
        [$tok] = ja_social_tokens($a);
        $m = ja_social_meta($a);
        if (empty($m['board'])) return $this->fail('Create a board on Pinterest first, then reconnect this account.');
        $img = $payload['images'][0];
        $bin = $this->imageBytes($img, 9000000, 2400);
        if (!$bin) return $this->fail('The photo could not be prepared for Pinterest.');
        $body = ['board_id' => $m['board'], 'title' => mb_substr($payload['title'] ?: $payload['text'], 0, 100), 'description' => $payload['text'], 'alt_text' => mb_substr($img['alt'], 0, 500),
                 'media_source' => ['source_type' => 'image_base64', 'content_type' => $bin[1], 'data' => base64_encode($bin[0])]];
        if (preg_match('~^https?://~', (string)$payload['url']) && ja_social_base_public()) $body['link'] = $payload['url'];
        $r = ja_social_http('POST', 'https://api.pinterest.com/v5/pins', ja_social_json_headers($tok), json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['timeout' => 60]);
        if (!in_array($r['status'], [200, 201], true) || empty($r['json']['id'])) {
            if ($r['status'] === 401) ja_social_account_status($a['account_id'], 'expired');
            return $this->fail(ja_social_http_reason('Pinterest', $r['status'], ja_social_remote_msg($r)));
        }
        return $this->done($r['json']['id'], 'https://www.pinterest.com/pin/' . $r['json']['id'] . '/');
    }
}
