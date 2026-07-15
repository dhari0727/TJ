<?php
/**
 * JourneyAI — rule-based packing checklist suggestions.
 * ja_suggest_packing($destination, $days, $travel_style, $month) returns an
 * array of ['label'=>..., 'category'=>...] suggestions. No ML call — simple
 * static rule table, deliberately not overengineered.
 */
function ja_suggest_packing($destination, $days, $travel_style, $month) {
    $items = [];
    $add = function ($label, $category) use (&$items) {
        $items[] = ['label' => $label, 'category' => $category];
    };

    // Always-include basics.
    $add('ID / passport copies', 'documents');
    $add('Travel insurance & booking printouts', 'documents');
    $add('Phone charger & power bank', 'electronics');
    $add('First-aid kit & personal medicines', 'health');
    $add('Reusable water bottle', 'essentials');
    $add('Toiletries kit', 'essentials');
    $add('Cash & cards', 'essentials');

    // Duration-aware.
    $days = (int)$days;
    if ($days > 5) {
        $add('Laundry bag', 'essentials');
        $add('Extra pair of shoes', 'clothing');
    }
    if ($days >= 3) {
        $add('Daypack / small backpack', 'essentials');
    }

    // Season-aware (India monsoon: Jun-Sep).
    $month = $month ? (int)$month : null;
    if ($month && $month >= 6 && $month <= 9) {
        $add('Umbrella / raincoat', 'weather');
        $add('Waterproof bag covers', 'weather');
        $add('Quick-dry clothing', 'clothing');
    } elseif ($month && in_array($month, [12, 1, 2], true)) {
        $add('Warm jacket / layers', 'clothing');
        $add('Thermal wear', 'clothing');
    } elseif ($month && in_array($month, [3, 4, 5], true)) {
        $add('Sunscreen (high SPF)', 'weather');
        $add('Cap / hat', 'clothing');
    }

    // Travel-style-aware.
    $style = strtolower((string)$travel_style);
    if (in_array($style, ['adventure', 'trekking', 'backpacker'], true)) {
        $add('Trekking shoes', 'gear');
        $add('Backpack rain cover', 'gear');
        $add('Headlamp / torch', 'gear');
        $add('Reusable trekking pole / gloves', 'gear');
    }
    if ($style === 'family') {
        $add('Snacks for kids', 'essentials');
        $add('Entertainment (books/games) for travel time', 'essentials');
    }
    if ($style === 'luxury') {
        $add('Formal outfit for fine dining', 'clothing');
    }
    if ($style === 'solo') {
        $add('Portable door lock / travel safety alarm', 'gear');
    }

    // Destination keyword-aware (beach).
    $destLower = strtolower((string)$destination);
    $beachKeywords = ['goa', 'beach', 'island', 'maldives', 'andaman', 'lakshadweep',
        'phuket', 'bali', 'varkala', 'gokarna', 'pondicherry', 'diu', 'kovalam'];
    foreach ($beachKeywords as $kw) {
        if (strpos($destLower, $kw) !== false) {
            $add('Swimwear', 'clothing');
            $add('Sunscreen (water-resistant)', 'weather');
            $add('Flip-flops', 'clothing');
            $add('Beach towel', 'gear');
            break;
        }
    }

    // Mountain/hill keyword-aware.
    $hillKeywords = ['manali', 'shimla', 'leh', 'ladakh', 'spiti', 'gangtok', 'darjeeling',
        'nainital', 'munnar', 'ooty', 'kasol', 'auli', 'tawang'];
    foreach ($hillKeywords as $kw) {
        if (strpos($destLower, $kw) !== false) {
            $add('Warm layers (even off-season nights get cold)', 'clothing');
            $add('Motion sickness tablets', 'health');
            break;
        }
    }

    // De-duplicate by label, preserve order.
    $seen = [];
    $out = [];
    foreach ($items as $it) {
        $key = strtolower($it['label']);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $it;
    }
    return $out;
}
