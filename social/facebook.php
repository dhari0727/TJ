<?php
/**
 * Facebook Page posting. Tier 3: needs a Meta app registered by the site owner (App ID + App Secret in
 * Admin > Social apps) with the pages_manage_posts permission approved (App Review) for use beyond the app's own testers.
 * Posts go to a Page the user manages, never to a personal profile (Meta does not allow that).
 */
class JaSocial_facebook extends JaSocialOAuth {
    public $id = 'facebook'; public $label = 'Facebook Page'; public $tier = 3;
    public $note = 'Posts to a Facebook Page you manage.';
    const V = 'v21.0';
    protected $authorizeUrl = 'https://www.facebook.com/v21.0/dialog/oauth';
    protected $tokenUrl = 'https://graph.facebook.com/v21.0/oauth/access_token';
    protected $scope = 'pages_show_list,pages_manage_posts,pages_read_engagement';
    public function limits(array $account = null) {
        return ['chars' => 5000, 'counter' => 'chars', 'images' => 10, 'image_required' => false, 'alt' => false, 'needs_public_url' => false, 'visibility' => false];
    }
    protected function profiles($token, array $resp, $eml) {
        $r = ja_social_http('GET', 'https://graph.facebook.com/' . self::V . '/me/accounts?fields=id,name,access_token&limit=25', ['Authorization' => 'Bearer ' . $token]);
        if ($r['status'] !== 200) return ja_social_http_reason('Facebook', $r['status'], ja_social_remote_msg($r));
        $out = [];
        foreach ((array)($r['json']['data'] ?? []) as $pg) {
            if (empty($pg['id']) || empty($pg['access_token'])) continue;
            $out[] = ['remote_id' => $pg['id'], 'handle' => (string)$pg['name'], 'name' => (string)$pg['name'], 'token' => $pg['access_token'], 'expires' => null, 'refresh' => null];
        }
        return $out ?: 'You do not manage any Facebook Pages with this login. Posting needs a Page.';
    }
    public function ensureFresh(array $a) { return $a; }   // Page tokens from a long-lived login do not expire
    public function post(array $a, array $payload) {
        [$tok] = ja_social_tokens($a);
        $api = 'https://graph.facebook.com/' . self::V . '/' . rawurlencode($a['remote_id']);
        $auth = ['Authorization' => 'Bearer ' . $tok];
        $imgs = $payload['images'];
        if (!$imgs) {
            $f = ['message' => $payload['text']];
            if ($payload['url'] && preg_match('~^https?://~', $payload['url']) && ja_social_base_public()) $f['link'] = $payload['url'];
            $r = ja_social_http('POST', $api . '/feed', $auth, $f);
        } else {
            $ids = [];
            foreach ($imgs as $img) {
                $bin = $this->imageBytes($img, 4000000, 2048);
                if (!$bin) return $this->fail('A photo could not be prepared for Facebook.');
                $tmp = tempnam(sys_get_temp_dir(), 'jas'); file_put_contents($tmp, $bin[0]);
                $fields = ['source' => new CURLFile($tmp, $bin[1], 'photo.jpg')];
                if (count($imgs) === 1) $fields['message'] = $payload['text']; else $fields['published'] = 'false';
                if ($img['alt'] !== '') $fields['alt_text_custom'] = $img['alt'];
                $r = ja_social_http('POST', $api . '/photos', $auth, ['__multipart' => $fields], ['timeout' => 60]);
                @unlink($tmp);
                if ($r['status'] !== 200 || empty($r['json']['id'])) return $this->fail(ja_social_http_reason('Facebook', $r['status'], ja_social_remote_msg($r)));
                $ids[] = $r['json']['id'];
                if (count($imgs) === 1) { $pid = $r['json']['post_id'] ?? $r['json']['id']; return $this->done($pid, 'https://www.facebook.com/' . $pid); }
            }
            $f = ['message' => $payload['text']];
            foreach ($ids as $i => $id) $f["attached_media[$i]"] = json_encode(['media_fbid' => $id]);
            $r = ja_social_http('POST', $api . '/feed', $auth, $f);
        }
        if ($r['status'] !== 200 || empty($r['json']['id'])) return $this->fail(ja_social_http_reason('Facebook', $r['status'], ja_social_remote_msg($r)));
        return $this->done($r['json']['id'], 'https://www.facebook.com/' . $r['json']['id']);
    }
}
