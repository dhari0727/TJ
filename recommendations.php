<?php
$ja_title = "For You"; $ja_active = "recs";
session_start();
require 'connection.php';
require 'ml_client.php';

if (empty($_SESSION['eml'])) { header('Location: login.php'); exit; }
$eml = $_SESSION['eml'];
$flash = $_SESSION['ja_flash'] ?? null; unset($_SESSION['ja_flash']);

// derive user context from their journal data
$user_budget = null;
$user_interests = [];

$emEsc = mysqli_real_escape_string($conn, $eml);
$br = @mysqli_query($conn, "SELECT AVG(CAST(d1.total AS DECIMAL(10,2))) avg_b
    FROM db d LEFT JOIN db1 d1 ON d1.Title=d.Title AND d1.eml=d.eml
    WHERE d.eml='$emEsc' AND d1.total IS NOT NULL AND d1.total > 0");
if ($br && $row = mysqli_fetch_assoc($br)) {
    if ($row['avg_b'] && $row['avg_b'] > 0) $user_budget = (float)$row['avg_b'];
}

$dr = @mysqli_query($conn, "SELECT Description, Title FROM db
    WHERE eml='$emEsc' ORDER BY cd DESC LIMIT 8");
if ($dr) {
    $allText = '';
    while ($r = mysqli_fetch_assoc($dr)) $allText .= ' ' . ($r['Description'] ?? '') . ' ' . ($r['Title'] ?? '');
    $interests_gazetteer = [
        'beach'=>['beach','beaches','shore','coast','sand','seaside','surf','snorkel','island'],
        'trekking'=>['trek','trekking','hike','hiking','trail','trails'],
        'food'=>['food','cuisine','restaurant','cafe','dining','culinary','spice','biryani','curry','street food','delicacies'],
        'nightlife'=>['nightlife','bar','club','party','pub'],
        'history'=>['history','historic','ancient','heritage','ruins','fort','forts','palace','castle'],
        'temples'=>['temple','temples','shrine','monastery','spiritual','pilgrimage','ghat','ghats','aarti'],
        'museums'=>['museum','museums','gallery','galleries','exhibition','art'],
        'shopping'=>['shopping','market','bazaar','souvenir','souvenirs','handicraft','boutique'],
        'wildlife'=>['wildlife','safari','jungle','national park','sanctuary','birds','tiger','elephant'],
        'adventure'=>['adventure','rafting','paragliding','zip line','ziplining','bungee','kayak','thrill'],
        'relaxation'=>['relax','relaxation','relaxing','spa','peaceful','serene','unwind','tranquil','calm'],
        'photography'=>['photography','photo','photos','photogenic','instagram','scenic','viewpoint','views'],
        'nature'=>['nature','waterfall','waterfalls','lake','valley','forest','gardens','tea garden','meadow','meadows'],
        'culture'=>['culture','cultural','tradition','traditional','festival','local life','village','tribal'],
        'backwaters'=>['backwater','backwaters','houseboat'],
        'mountains'=>['mountain','mountains','himalaya','himalayas','peak','hills','hill station','summit'],
        'desert'=>['desert','dunes','rann','sand dunes'],
        'snow'=>['snow','snowfall','ski','skiing','snowy','glacier'],
        'diving'=>['diving','scuba','snorkel','snorkeling','reef'],
        'architecture'=>['architecture','architectural','monument','cathedral','mosque','buildings','old town'],
    ];
    foreach ($interests_gazetteer as $canonical => $keywords) {
        foreach ($keywords as $kw) {
            if (stripos($allText, $kw) !== false) { $user_interests[] = $canonical; break; }
        }
    }
}

// personalised picks from the user's history
$result = ml_recommend([
    'eml' => $eml,
    'budget' => $user_budget, 'duration_days' => 6, 'travel_style' => 'mid-range',
    'interests' => $user_interests, 'top_n' => 6,
]);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'ja-head.php'; ?></head>
<body class="ja">

<section class="ja-section" style="padding-top:56px">
  <div class="ja-container">
    <div class="ja-eyebrow reveal">✦ Personalised from your journeys</div>
    <h1 class="reveal" style="font-size:clamp(2.2rem,5vw,3.6rem)">Recommended for you</h1>
    <p class="sub reveal">Based on the trips you've saved and rated, and travellers who share your taste.</p>

    <?php if ($flash): ?><div class="ja-ok reveal">✓ <?= htmlspecialchars($flash) ?></div><?php endif; ?>

    <?php if (!empty($result['__error'])): ?>
      <?= ml_offline_banner($result) ?>
    <?php else: ?>
      <?php require 'ja-cards.php'; ja_render_cards($result['recommendations'] ?? []); ?>
      <div class="reveal" style="margin-top:36px">
        <a href="plan-trip.php" class="ja-btn ja-btn-ghost">Plan a specific trip →</a>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php include 'ja-footer.php'; ?>
