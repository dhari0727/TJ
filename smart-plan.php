<?php
/** JourneyAI — smart-plan proxy: browser -> PHP -> Flask /smart-plan. */
session_start();
require 'ml_client.php';
header('Content-Type: application/json');
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$text = trim($in['text'] ?? '');
if ($text === '') { echo json_encode(['error'=>'Tell me about your trip.']); exit; }
$res = ml_request('POST', '/smart-plan', [
    'text' => mb_substr($text, 0, 500),
    'travel_style' => $in['travel_style'] ?? 'mid-range',
], 60);
if (!empty($res['__error'])) { echo json_encode(['error'=>'The planner is offline. Start the JourneyAI service.']); exit; }
echo json_encode($res);
