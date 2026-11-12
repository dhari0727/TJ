<?php
/**
 * JourneyAI - where a platform sends the browser back after the user approves the connection.
 * Redirect URI to register with each platform:  <site address>/social-callback.php
 * Checks a one-time random state saved in this session (so a forged or replayed link is refused), then hands the code to the provider.
 */
require_once __DIR__ . '/ja-social-core.php';
$u = ja_current_user();
if (!$u) { header('Location: login.php'); exit; }
$eml = $u['eml'];

function ja_soc_back($ok, $msg) {
    $_SESSION['soc_result'] = ['ok' => $ok, 'msg' => $msg];
    header('Location: profile.php#connected-accounts'); exit;
}
[$entry, $err] = ja_social_oauth_take($_GET['state'] ?? '', $eml);
if ($err) ja_soc_back(false, $err);
$p = ja_social_provider($entry['p']);
if (!$p) ja_soc_back(false, 'Unknown service.');
if (!empty($_GET['error'])) {
    $denied = in_array($_GET['error'], ['access_denied', 'user_cancelled_authorize', 'user_cancelled_login'], true);
    ja_soc_back(false, $denied ? 'You cancelled the connection to ' . $p->label . '. Nothing was changed.' : $p->label . ' did not complete the sign-in. Try again.');
}
try {
    $r = $p->connectComplete($eml, $entry, $_GET);
} catch (Throwable $e) {
    $r = ['ok' => false, 'error' => 'Something went wrong while connecting ' . $p->label . '.'];
}
if (!empty($r['ok'])) {
    ja_audit($eml, 'social_connect', $p->id);
    ja_soc_back(true, 'Connected to ' . $p->label . '.');
}
ja_soc_back(false, $r['error'] ?? 'Could not connect.');
