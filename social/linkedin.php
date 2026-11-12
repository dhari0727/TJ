<?php
/**
 * LinkedIn (member profile). Tier 3: needs a LinkedIn developer app with the "Share on LinkedIn" product (scope w_member_social),
 * which LinkedIn approves on request. Tokens last about 60 days; without a partner refresh token the user reconnects when it ends.
 * Uses the current Images + Posts REST APIs (the older ugcPosts API is being retired).
 */
class JaSocial_linkedin extends JaSocialOAuth {
    public $id = 'linkedin'; public $label = 'LinkedIn'; public $tier = 3;
    public $note = 'Posts to your LinkedIn profile.';
    const VERSION = '202511';
    protected $authorizeUrl = 'https://www.linkedin.com/oauth/v2/authorization';
    protected $tokenUrl = 'https://www.linkedin.com/oauth/v2/accessToken';
    protected $scope = 'openid profile w_member_social';
    public function limits(array $account = null) {
        return ['chars' => 3000, 'counter' => 'chars', 'images' => 9, 'image_required' => false, 'alt' => true, 'needs_public_url' => false, 'visibility' => false];
    }
    protected function profiles($token, array $resp, $eml) {
        $r = ja_social_http('GET', 'https://api.linkedin.com/v2/userinfo', ['Authorization' => 'Bearer ' . $token]);
        $j = $r['json'] ?? [];
        if ($r['status'] !== 200 || empty($j['sub'])) return ja_social_http_reason('LinkedIn', $r['status'], ja_social_remote_msg($r));
        $name = trim((string)($j['name'] ?? ''));
        return [['remote_id' => $j['sub'], 'handle' => $name !== '' ? $name : 'LinkedIn member', 'name' => $name]];
    }
    /** Characters that LinkedIn's post text format treats as markup. */
    private static function esc($t) { return preg_replace('/([\\\\()\[\]{}<>|~_*])/u', '\\\\$1', $t); }
    private function headers($tok) { return ['Authorization' => 'Bearer ' . $tok, 'LinkedIn-Version' => self::VERSION, 'X-Restli-Protocol-Version' => '2.0.0', 'Content-Type' => 'application/json']; }
    public function post(array $a, array $payload) {
        [$tok] = ja_social_tokens($a);
        $author = 'urn:li:person:' . $a['remote_id'];
        $media = [];
        foreach ($payload['images'] as $img) {
            $bin = $this->imageBytes($img, 7500000, 2400);
            if (!$bin) return $this->fail('A photo could not be prepared for LinkedIn.');
            $i = ja_social_http('POST', 'https://api.linkedin.com/rest/images?action=initializeUpload', $this->headers($tok), json_encode(['initializeUploadRequest' => ['owner' => $author]]));
            $v = $i['json']['value'] ?? null;
            if ($i['status'] !== 200 || empty($v['uploadUrl']) || empty($v['image'])) return $this->fail(ja_social_http_reason('LinkedIn', $i['status'], ja_social_remote_msg($i)));
            $u = ja_social_http('PUT', $v['uploadUrl'], ['Authorization' => 'Bearer ' . $tok, 'Content-Type' => $bin[1]], $bin[0], ['timeout' => 60]);
            if (!in_array($u['status'], [200, 201], true)) return $this->fail(ja_social_http_reason('LinkedIn', $u['status'], ja_social_remote_msg($u)));
            $media[] = ['id' => $v['image'], 'altText' => mb_substr($img['alt'], 0, 4086)];
        }
        $post = ['author' => $author, 'commentary' => self::esc($payload['text']), 'visibility' => 'PUBLIC',
                 'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
                 'lifecycleState' => 'PUBLISHED', 'isReshareDisabledByAuthor' => false];
        if (count($media) === 1) $post['content'] = ['media' => $media[0]];
        elseif ($media) $post['content'] = ['multiImage' => ['images' => $media]];
        $r = ja_social_http('POST', 'https://api.linkedin.com/rest/posts', $this->headers($tok), json_encode($post, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (!in_array($r['status'], [200, 201], true)) {
            if ($r['status'] === 401) ja_social_account_status($a['account_id'], 'expired');
            return $this->fail(ja_social_http_reason('LinkedIn', $r['status'], ja_social_remote_msg($r)));
        }
        $urn = $r['headers']['x-restli-id'] ?? ($r['headers']['x-linkedin-id'] ?? '');
        return $this->done($urn, $urn !== '' ? 'https://www.linkedin.com/feed/update/' . $urn : '');
    }
}
