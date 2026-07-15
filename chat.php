<?php
/**
 * JourneyAI — chat proxy. Browser POSTs JSON {message, history, location} here;
 * we forward to the Flask /chat endpoint and return {reply, cards}.
 */
session_start();
require 'ml_client.php';
header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) $in = [];

$message = trim($in['message'] ?? '');
if ($message === '') { echo json_encode(['reply'=>'Ask me anything about your trip!','cards'=>[]]); exit; }

$payload = [
    'message'  => mb_substr($message, 0, 2000),
    'history'  => array_slice($in['history'] ?? [], -10),
    'location' => $in['location'] ?? null,
    'eml'      => $_SESSION['eml'] ?? null,
];

$res = ml_request('POST', '/chat', $payload, 60);
if (!empty($res['__error'])) {
    echo json_encode(['reply'=>'The assistant is offline right now. Start the JourneyAI service and try again.','cards'=>[]]);
    exit;
}
echo json_encode(['reply'=>$res['reply'] ?? 'Here you go.', 'cards'=>$res['cards'] ?? []]);
