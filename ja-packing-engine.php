<?php
/**
 * JourneyAI — packing recommendation engine (rule based, explainable).
 *
 *   $items = ja_packing_recommend([
 *       'destination' => 'Manali, India', 'days' => 5, 'month' => 12, 'style' => 'adventure',
 *       'party' => 2, 'options' => ['kids','trekking','photography'],
 *   ]);
 *   // => [['category'=>'clothing','label'=>'T-shirts / tops','qty'=>5,'why'=>'5 days, rewear or wash'], ...]
 *
 * Climate comes from the destination's region (destinations table) + month; activities from the
 * chips the user picks plus keywords in the destination name. Every item carries a short "why".
 */

const JA_PACK_OPTIONS = [
    'beach' => 'Beach & water', 'trekking' => 'Trekking / hiking', 'temples' => 'Temples & pilgrimage',
    'wildlife' => 'Wildlife & safari', 'snow' => 'Snow & high altitude', 'desert' => 'Desert',
    'photography' => 'Photography', 'nightlife' => 'Nightlife & dining', 'work' => 'Working remotely',
    'kids' => 'Travelling with kids', 'elderly' => 'Elderly travellers', 'camping' => 'Camping',
];
const JA_PACK_CATEGORIES = [
    'documents' => 'Documents & money', 'clothing' => 'Clothing', 'footwear' => 'Footwear',
    'health' => 'Health & toiletries', 'electronics' => 'Electronics', 'weather' => 'Weather protection',
    'gear' => 'Gear', 'family' => 'Kids & family', 'extras' => 'Extras', 'general' => 'Other',
];

/** Work out climate flags from region + month. */
function ja_packing_climate($region, $month, $destLower) {
    $c = ['cold' => false, 'hot' => false, 'rain' => false, 'humid' => false, 'altitude' => false];
    $m = (int)$month;
    $region = (string)$region;
    if ($region === 'Himalayas') { $c['altitude'] = true; $c['cold'] = !in_array($m, [6, 7, 8], true); $c['rain'] = in_array($m, [7, 8], true); }
    elseif ($region === 'North India') { $c['cold'] = in_array($m, [12, 1, 2], true); $c['hot'] = in_array($m, [4, 5, 6], true); $c['rain'] = in_array($m, [7, 8, 9], true); }
    elseif ($region === 'South India') { $c['hot'] = in_array($m, [3, 4, 5], true); $c['humid'] = true; $c['rain'] = in_array($m, [6, 7, 8, 9, 10, 11], true); }
    elseif ($region === 'West India') { $c['hot'] = in_array($m, [3, 4, 5, 6], true); $c['rain'] = in_array($m, [6, 7, 8, 9], true); $c['cold'] = in_array($m, [12, 1], true) && strpos($destLower, 'abu') !== false; }
    elseif ($region === 'East India') { $c['hot'] = in_array($m, [4, 5, 6], true); $c['humid'] = true; $c['rain'] = in_array($m, [6, 7, 8, 9, 10], true); }
    elseif ($region === 'Northeast India') { $c['rain'] = !in_array($m, [12, 1, 2], true); $c['humid'] = true; $c['cold'] = in_array($m, [12, 1, 2], true); }
    elseif ($region === 'Central India') { $c['hot'] = in_array($m, [4, 5, 6], true); $c['rain'] = in_array($m, [7, 8, 9], true); $c['cold'] = in_array($m, [12, 1], true); }
    else { $c['hot'] = in_array($m, [4, 5, 6], true); $c['rain'] = in_array($m, [7, 8, 9], true); $c['cold'] = in_array($m, [12, 1, 2], true); }
    foreach (['leh', 'ladakh', 'spiti', 'kaza', 'auli', 'tawang', 'gulmarg', 'pahalgam', 'kedarnath', 'manali', 'mcleodganj', 'munnar', 'ooty', 'kodaikanal'] as $kw) {
        if (strpos($destLower, $kw) !== false) { $c['altitude'] = $c['altitude'] || $kw !== 'ooty'; if (in_array($m, [10, 11, 12, 1, 2, 3], true)) $c['cold'] = true; }
    }
    return $c;
}

function ja_packing_recommend(array $o) {
    $dest = (string)($o['destination'] ?? '');
    $destLower = strtolower($dest);
    $days = max(1, min(60, (int)($o['days'] ?? 4)));
    $month = (int)($o['month'] ?? 0);
    $style = strtolower((string)($o['style'] ?? 'mid-range'));
    $party = max(1, (int)($o['party'] ?? 1));
    $opts = array_map('strtolower', (array)($o['options'] ?? []));
    $region = (string)($o['region'] ?? '');
    $intl = !empty($o['international']);

    // destination keywords switch activity options on automatically
    $auto = [
        'beach' => ['goa', 'beach', 'island', 'andaman', 'lakshadweep', 'varkala', 'gokarna', 'kovalam', 'diu', 'puri', 'alibag', 'mandvi', 'tarkarli', 'pondicherry', 'maldives', 'bali', 'phuket'],
        'snow' => ['leh', 'ladakh', 'spiti', 'auli', 'gulmarg', 'kedarnath', 'tawang', 'manali', 'kasol', 'kaza', 'zanskar'],
        'desert' => ['jaisalmer', 'kutch', 'rann', 'bikaner', 'pushkar', 'thar', 'sam dunes', 'dubai', 'jodhpur'],
        'wildlife' => ['jim corbett', 'corbett', 'ranthambore', 'kaziranga', 'gir', 'bandhavgarh', 'kanha', 'periyar', 'sundarban', 'nagarhole', 'safari', 'sanctuary', 'national park'],
        'temples' => ['varanasi', 'rishikesh', 'haridwar', 'tirupati', 'dwarka', 'somnath', 'amritsar', 'madurai', 'rameswaram', 'puri', 'ayodhya', 'mathura', 'vrindavan', 'shirdi', 'ujjain', 'pushkar', 'kedarnath', 'badrinath'],
        'trekking' => ['kasol', 'triund', 'kedarnath', 'har ki dun', 'valley of flowers', 'munnar', 'coorg', 'ladakh', 'spiti'],
    ];
    foreach ($auto as $opt => $kws) foreach ($kws as $kw) if (strpos($destLower, $kw) !== false) { $opts[] = $opt; break; }
    $opts = array_values(array_unique($opts));
    $has = function ($x) use ($opts) { return in_array($x, $opts, true); };

    $cl = ja_packing_climate($region, $month, $destLower);
    if ($has('snow')) { $cl['cold'] = true; $cl['altitude'] = true; }
    if ($has('desert')) { $cl['hot'] = $cl['hot'] || !$month || in_array($month, [3, 4, 5, 6, 9, 10], true); $cl['cold'] = $cl['cold'] || in_array($month, [12, 1, 2], true); }

    $items = [];
    $add = function ($cat, $label, $qty, $why) use (&$items) { $items[strtolower($label)] = ['category' => $cat, 'label' => $label, 'qty' => max(1, (int)$qty), 'why' => $why]; };

    // ---- documents & money
    $add('documents', 'Government photo ID (+ copies)', 1, 'Needed for hotel check-in and many entry tickets');
    $add('documents', 'Tickets & hotel booking confirmations', 1, 'Keep offline copies in case of no signal');
    $add('documents', 'Cash (small notes) + cards / UPI', 1, 'Small vendors and rural areas often do not take cards');
    $add('documents', 'Travel insurance details', 1, 'Medical and trip-cancellation cover');
    if ($intl) { $add('documents', 'Passport (valid 6+ months) & visa', 1, 'International trip'); $add('electronics', 'Universal travel adapter', 1, 'Different plug types abroad'); $add('documents', 'Forex card / foreign currency', 1, 'International trip'); }
    if ($has('snow') || $cl['altitude']) $add('documents', 'Inner Line / area permits (if required)', 1, 'Some high-altitude and border areas need permits');
    if ($has('wildlife')) $add('documents', 'Safari booking + ID used for booking', 1, 'Parks check the ID against the booking');

    // ---- clothing (quantities scale with days)
    $tops = min($days, 6); $bottoms = min((int)ceil($days / 2) + 1, 4); $under = min($days + 1, 8);
    $wash = $days > 6 ? ' (plan one laundry stop)' : '';
    $add('clothing', 'T-shirts / tops', $tops, "$days days$wash");
    $add('clothing', 'Trousers / jeans / skirts', $bottoms, 'Rewear bottoms; pack fewer than tops');
    $add('clothing', 'Underwear', $under, 'One per day plus a spare');
    $add('clothing', 'Socks', min($days, 6), 'One pair per day, up to 6');
    $add('clothing', 'Sleepwear', min(2, $days), 'Comfortable, light');
    if ($cl['hot'] && !$cl['cold']) { $add('clothing', 'Light cotton / linen clothes', min($days, 4), 'Hot weather: breathable fabrics'); $add('weather', 'Sunscreen SPF 50+', 1, 'Strong sun'); $add('weather', 'Sunglasses & hat / cap', 1, 'Strong sun'); }
    if ($cl['cold']) { $add('clothing', 'Warm jacket / fleece', 1, 'Cold weather at this time of year'); $add('clothing', 'Thermal innerwear', min(3, max(1, (int)ceil($days / 2))), 'Cold nights'); $add('clothing', 'Woollen cap, gloves & scarf', 1, 'Cold weather'); $add('weather', 'Moisturiser & lip balm', 1, 'Dry cold air'); }
    if ($cl['altitude']) { $add('health', 'Altitude sickness tablets (ask your doctor)', 1, 'High altitude'); $add('health', 'ORS & glucose sachets', 4, 'Dehydration at altitude'); $add('weather', 'UV sunglasses & high-SPF sunscreen', 1, 'UV is stronger at altitude'); }
    if ($cl['rain']) { $add('weather', 'Umbrella / raincoat', 1, 'Rain expected'); $add('weather', 'Waterproof bag / dry sack', 1, 'Keep phone and documents dry'); $add('clothing', 'Quick-dry clothes', min($days, 3), 'Rain: cotton stays wet'); $add('footwear', 'Waterproof sandals / shoes', 1, 'Wet streets and trails'); }
    if ($cl['humid']) { $add('health', 'Mosquito repellent', 1, 'Humid, mosquito-prone'); $add('clothing', 'Extra light shirts', 2, 'You will sweat through clothes'); }
    if ($has('temples')) { $add('clothing', 'Modest clothes covering shoulders & knees', 2, 'Required at many temples and shrines'); $add('clothing', 'Scarf / dupatta / stole', 1, 'Head covering at some shrines'); $add('footwear', 'Easy slip-off footwear + spare socks', 1, 'Shoes are removed at temples'); }
    if ($has('beach')) { $add('clothing', 'Swimwear', 2, 'Beach / pool'); $add('gear', 'Beach towel (quick-dry)', 1, 'Beach'); $add('footwear', 'Flip-flops', 1, 'Beach'); $add('weather', 'Reef-safe sunscreen', 1, 'Water resistant'); }
    if ($has('nightlife') || $style === 'luxury') $add('clothing', 'One smart outfit', 1, 'Restaurants, clubs or fine dining');
    if ($has('desert')) { $add('clothing', 'Scarf to cover face (dust)', 1, 'Sand and wind'); $add('health', 'Lip balm & eye drops', 1, 'Dry air'); if ($cl['cold']) $add('clothing', 'Warm layers for desert nights', 1, 'Desert nights drop sharply'); }

    // ---- footwear
    $add('footwear', 'Comfortable walking shoes', 1, 'Sightseeing means lots of walking');
    if ($has('trekking') || $has('snow') || in_array($style, ['adventure', 'backpacker'], true)) { $add('footwear', 'Trekking shoes with grip', 1, 'Trails and uneven ground'); $add('gear', 'Day backpack (20-30L)', 1, 'Carry water and layers on hikes'); $add('gear', 'Trekking pole / knee support', 1, 'Steep descents'); $add('gear', 'Headlamp / torch', 1, 'Early starts, power cuts'); }

    // ---- health & toiletries
    $add('health', 'Prescription medicines (full trip + 2 days spare)', 1, 'Pharmacies may not stock your brand');
    $add('health', 'Basic first-aid: plasters, antiseptic, pain relief', 1, 'Minor injuries on the road');
    $add('health', 'Stomach care: ORS, anti-diarrhoeal, antacid', 1, 'New food and water');
    $add('health', 'Hand sanitiser & wet wipes', 1, 'Before eating');
    $add('health', 'Toothbrush, paste, soap, shampoo', 1, 'Toiletries');
    $add('health', 'Sanitary / personal hygiene items', 1, 'Hard to find in remote areas');
    if ($days > 5) $add('health', 'Laundry detergent sachets', 2, 'Long trip');

    // ---- electronics
    $add('electronics', 'Phone + charger', 1, 'Navigation, tickets and UPI');
    $add('electronics', 'Power bank (10,000 mAh+)', 1, 'Long travel days');
    $add('electronics', 'Earphones', 1, 'Long journeys');
    if ($has('photography')) { $add('electronics', 'Camera + spare batteries + memory cards', 1, 'You are shooting photos'); $add('electronics', 'Lens cloth & small tripod', 1, 'Sharper low-light shots'); }
    if ($has('work')) { $add('electronics', 'Laptop + charger', 1, 'Working remotely'); $add('electronics', 'Portable wifi / extra SIM', 1, 'Backup connectivity'); }
    if ($has('wildlife')) $add('gear', 'Binoculars', 1, 'Spotting wildlife');
    if ($has('wildlife')) $add('clothing', 'Neutral-coloured clothes (khaki, olive)', 2, 'Bright colours are discouraged on safari');

    // ---- gear
    $add('gear', 'Reusable water bottle', 1, 'Refill instead of buying plastic');
    $add('gear', 'Small day bag', 1, 'Daily sightseeing');
    if ($has('camping')) { $add('gear', 'Tent, sleeping bag & mat', 1, 'Camping'); $add('gear', 'Stove / lighter + food box', 1, 'Camping'); }
    if ($days >= 4) $add('extras', 'Luggage lock & tags', 1, 'Trains and buses');
    $add('extras', 'Snacks for the journey', 1, 'Delays happen');
    $add('extras', 'Book / downloaded shows', 1, 'Long rides');

    // ---- kids & elderly
    if ($has('kids')) {
        $add('family', 'Kids: extra clothes (double the usual)', min($days + 1, 8), 'Spills and muddy play');
        $add('family', 'Kids: medicines (fever, cold) + thermometer', 1, 'Doctors may be far away');
        $add('family', 'Kids: snacks, milk powder / favourite food', 1, 'Fussy eaters on the road');
        $add('family', 'Kids: toys, colouring books, tablet', 1, 'Keeps them calm in transit');
        $add('family', 'Kids: sunscreen, hat, mosquito repellent', 1, 'Sensitive skin');
    }
    if ($has('elderly')) {
        $add('family', 'Elders: medicines + doctor prescription copy', 1, 'Regular medicines must not run out');
        $add('family', 'Elders: walking stick / knee cap', 1, 'Uneven ground and steps');
        $add('family', 'Elders: spare spectacles & hearing-aid batteries', 1, 'Easy to lose or run out');
        $add('family', 'Elders: slip-resistant footwear', 1, 'Safer on wet floors');
    }
    if ($party > 2) $add('extras', 'Shared items: one first-aid kit, one power strip', 1, "$party travellers can share these");
    if ($style === 'luxury') $add('extras', 'Gift / tipping cash', 1, 'Service staff');
    if ($style === 'solo') $add('gear', 'Door stopper / small padlock', 1, 'Extra security in hostels');
    if ($style === 'backpacker') { $add('gear', 'Quick-dry travel towel', 1, 'Hostels rarely provide towels'); $add('gear', 'Earplugs & eye mask', 1, 'Dorm sleeping'); }

    return array_values($items);
}
