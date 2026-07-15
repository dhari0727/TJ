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

/** Best image path for a destination name/city. */
function ja_image_for($destination) {
    $imgs = ja_local_images();
    $city = strtolower(trim(explode(',', $destination)[0]));
    $slug = preg_replace('/[^a-z]+/', '-', $city);
    // direct city match
    foreach ([$slug, str_replace('-', '', $slug)] as $k) {
        if (isset($imgs[$k])) return $imgs[$k];
    }
    // region/keyword fallbacks to a themed local photo
    $map = [
        'munnar'=>'kerala','alleppey'=>'kerala','kochi'=>'kerala','wayanad'=>'kerala','varkala'=>'kerala',
        'leh'=>'mountains','manali'=>'mountains','spiti'=>'mountains','shimla'=>'mountains','gangtok'=>'mountains',
        'darjeeling'=>'mountains','tawang'=>'mountains','auli'=>'mountains','kasol'=>'mountains','nainital'=>'mountains',
        'udaipur'=>'jaipur','jodhpur'=>'jaipur','jaisalmer'=>'jaipur','pushkar'=>'jaipur','agra'=>'taj-mahal',
        'gokarna'=>'goa','pondicherry'=>'goa','diu'=>'goa','puri'=>'goa','kanyakumari'=>'goa',
        'phuket'=>'bali','krabi'=>'bali','ubud'=>'bali','chiang mai'=>'bangkok','hanoi'=>'bangkok','hoi an'=>'bangkok',
        'male'=>'maldives','rome'=>'rome','prague'=>'rome','lisbon'=>'rome','istanbul'=>'rome','cappadocia'=>'rome',
    ];
    if (isset($map[$city]) && isset($imgs[$map[$city]])) return $imgs[$map[$city]];
    // last resort: any local photo (stable by hash) or keyword source
    if ($imgs) { $vals = array_values($imgs); return $vals[crc32($city) % count($vals)]; }
    return 'https://images.unsplash.com/photo-1476514525535-07fb3b4ae5f1?auto=format&fit=crop&w=1200&q=70';
}

/** Curated hero slides (destination, tagline, image). Uses local photos. */
function ja_hero_slides() {
    $s = [
        ['name'=>'Kerala','sub'=>"Drift through emerald backwaters where houseboats trace centuries-old canals.",'img'=>'kerala','region'=>'South India','rating'=>'4.8'],
        ['name'=>'Ladakh','sub'=>"High-desert passes, turquoise lakes and monasteries above the clouds.",'img'=>'mountains','region'=>'Himalayas','rating'=>'4.9'],
        ['name'=>'Jaipur','sub'=>"Rose-pink palaces and forts that hold the stories of Rajput kings.",'img'=>'jaipur','region'=>'North India','rating'=>'4.7'],
        ['name'=>'Goa','sub'=>"Sun-warmed sand, Portuguese lanes and the slow rhythm of the coast.",'img'=>'goa','region'=>'West India','rating'=>'4.6'],
    ];
    $imgs = ja_local_images();
    foreach ($s as &$x) { $x['src'] = $imgs[$x['img']] ?? ja_image_for($x['name']); }
    return $s;
}
