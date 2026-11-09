<?php
/**
 * JourneyAI — destination image resolver.
 * Returns a locally-stored curated photo when we have one, else a keyword
 * fallback. Maps canonical destination -> local file in images/dest/.
 */
function ja_local_images() {
    static $m = null;
    if ($m === null) {
        $m = [];
        foreach (glob(__DIR__ . '/images/dest/*.jpg') as $f) {
            $m[basename($f, '.jpg')] = 'images/dest/' . basename($f);
        }
    }
    return $m;
}

/**
 * Photo for a destination, or null when we do not have one for THAT place.
 * Order: a curated local photo named after the place, then the Wikipedia lead photo for the place
 * (ml/data/dest_images.json, built by ml/scrape/dest_images.py; hotlinked, credited to Wikipedia),
 * else null. We never substitute a photo of a different place.
 */
function ja_image_for($destination) {
    $imgs = ja_local_images();
    $city = strtolower(trim(explode(',', $destination)[0]));
    $slug = preg_replace('/[^a-z]+/', '-', $city);
    foreach ([$slug, str_replace('-', '', $slug)] as $k) {
        if (isset($imgs[$k])) return $imgs[$k];
    }
    // places whose curated photo is genuinely of that place (same town or its landmark)
    $alias = ['agra' => 'taj-mahal', 'goa' => 'goa', 'jaipur' => 'jaipur', 'varanasi' => 'varanasi', 'banaras' => 'varanasi'];
    if (isset($alias[$city]) && isset($imgs[$alias[$city]])) return $imgs[$alias[$city]];
    $w = ja_wiki_images();
    $key = trim($destination);
    if (isset($w[$key]['src'])) return $w[$key]['src'];
    $first = trim(explode(',', $destination)[0]);
    if (isset($w[$first]['src'])) return $w[$first]['src'];
    return null;
}

/** Wikipedia photo links keyed by destination name. */
function ja_wiki_images() {
    static $m = null;
    if ($m === null) {
        $f = __DIR__ . '/ml/data/dest_images.json';
        $m = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }
    return $m;
}

/** Page the photo came from (for the credit line), or ''. */
function ja_image_credit($destination) {
    $w = ja_wiki_images();
    $first = trim(explode(',', $destination)[0]);
    return $w[trim($destination)]['page'] ?? ($w[$first]['page'] ?? '');
}

/** Curated hero slides (destination, tagline, image). Uses local photos. */
function ja_hero_slides() {
    $s = [
        ['name'=>'Kerala','sub'=>"Drift through emerald backwaters where houseboats trace centuries-old canals.",'img'=>'kerala','region'=>'South India'],
        ['name'=>'Ladakh','sub'=>"High-desert passes, turquoise lakes and monasteries above the clouds.",'img'=>'mountains','region'=>'Himalayas'],
        ['name'=>'Jaipur','sub'=>"Rose-pink palaces and forts that hold the stories of Rajput kings.",'img'=>'jaipur','region'=>'North India'],
        ['name'=>'Goa','sub'=>"Sun-warmed sand, Portuguese lanes and the slow rhythm of the coast.",'img'=>'goa','region'=>'West India'],
    ];
    $imgs = ja_local_images();
    foreach ($s as &$x) { $x['src'] = $imgs[$x['img']] ?? ja_image_for($x['name']); unset($x['rating']); }
    return $s;
}
