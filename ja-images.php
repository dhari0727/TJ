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
        // Kerala
        'munnar'=>'kerala','alleppey'=>'kerala','kochi'=>'kerala','wayanad'=>'kerala','varkala'=>'kerala',
        'thekkady'=>'kerala','bekal'=>'kerala','kovalam'=>'kerala','poovar'=>'kerala',
        // Mountains / Himalayas
        'leh'=>'mountains','manali'=>'mountains','spiti'=>'mountains','shimla'=>'mountains','gangtok'=>'mountains',
        'darjeeling'=>'mountains','tawang'=>'mountains','auli'=>'mountains','kasol'=>'mountains','nainital'=>'mountains',
        'rishikesh'=>'mountains','haridwar'=>'mountains','mussoorie'=>'mountains','dharamshala'=>'mountains',
        'mcleodganj'=>'mountains','triund'=>'mountains','chopta'=>'mountains','kedarnath'=>'mountains',
        'badrinath'=>'mountains','valley-of-flowers'=>'mountains','rohtang'=>'mountains','solang'=>'mountains',
        'manikaran'=>'mountains','jibhi'=>'mountains','tirthan'=>'mountains','chamba'=>'mountains',
        'kullu'=>'mountains','banjar'=>'mountains','dalhousie'=>'mountains','khajjiar'=>'mountains',
        'sonmarg'=>'mountains','pahalgam'=>'mountains','gulmarg'=>'mountains','patnitop'=>'mountains',
        'yusmarg'=>'mountains','doodhpathri'=>'mountains','araku'=>'mountains','coorg'=>'mountains',
        // Rajasthan
        'udaipur'=>'jaipur','jodhpur'=>'jaipur','jaisalmer'=>'jaipur','pushkar'=>'jaipur',
        'bikaner'=>'jaipur','mount-abu'=>'jaipur','ranakpur'=>'jaipur','chittorgarh'=>'jaipur',
        'kumbhalgarh'=>'jaipur','narlai'=>'jaipur','jawai'=>'jaipur','shekhawati'=>'jaipur',
        // Taj / UP
        'agra'=>'taj-mahal','varanasi'=>'varanasi','ayodhya'=>'varanasi','mathura'=>'varanasi',
        'vrindavan'=>'varanasi','lucknow'=>'varanasi','khajuraho'=>'varanasi','orchha'=>'varanasi',
        // Goa / West Coast
        'gokarna'=>'goa','pondicherry'=>'goa','diu'=>'goa','puri'=>'goa','kanyakumari'=>'goa',
        'mangalore'=>'goa','karwar'=>'goa','murudeshwar'=>'goa','gokarna'=>'goa',
        // South India
        'mysore'=>'jaipur','hampi'=>'varanasi','ooty'=>'kerala','kodaikanal'=>'kerala',
        'madurai'=>'kerala','coimbatore'=>'kerala','hampi'=>'varanasi','badami'=>'varanasi',
        'belur'=>'varanasi','halebidu'=>'varanasi','srirangapatna'=>'jaipur',
        // International — Southeast Asia
        'phuket'=>'bali','krabi'=>'bali','ubud'=>'bali','chiang mai'=>'bangkok','hanoi'=>'bangkok','hoi an'=>'bangkok',
        'kuala lumpur'=>'bangkok','siem reap'=>'bangkok','luang prabang'=>'bangkok','nepal'=>'mountains',
        'kathmandu'=>'mountains','pokhara'=>'mountains','lumbini'=>'mountains',
        // Maldives
        'male'=>'maldives',
        // Europe
        'rome'=>'rome','prague'=>'rome','lisbon'=>'rome','istanbul'=>'rome','cappadocia'=>'rome',
        'paris'=>'paris','london'=>'paris','amsterdam'=>'paris','berlin'=>'paris','barcelona'=>'paris',
        'madrid'=>'paris','vienna'=>'paris','budapest'=>'paris','zurich'=>'paris','munich'=>'paris',
        'athens'=>'rome','santorini'=>'santorini','mykonos'=>'santorini',
        // East Asia
        'tokyo'=>'tokyo','kyoto'=>'tokyo','osaka'=>'tokyo','seoul'=>'tokyo','taipei'=>'tokyo',
        'hong kong'=>'singapore','macau'=>'singapore',
        // Middle East
        'dubai'=>'dubai','abu dhabi'=>'dubai','doha'=>'dubai','muscat'=>'dubai','bahrain'=>'dubai',
        // Americas
        'new york'=>'paris','los angeles'=>'paris','san francisco'=>'paris','las vegas'=>'paris',
        'miami'=>'goa','cancun'=>'bali','toronto'=>'paris','vancouver'=>'mountains',
        // Oceania
        'sydney'=>'paris','melbourne'=>'paris','auckland'=>'paris','bali'=>'bali',
    ];
    if (isset($map[$city]) && isset($imgs[$map[$city]])) return $imgs[$map[$city]];
    // last resort: kerala as a warm, universally fitting fallback
    if (isset($imgs['kerala'])) return $imgs['kerala'];
    if ($imgs) { $vals = array_values($imgs); return $vals[0]; }
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
