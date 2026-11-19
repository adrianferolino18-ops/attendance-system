<?php
declare(strict_types=1);
/**
 * api/challenge.php  https://github.com/juan-csv/face_liveness_detection-Anti-spoofing
 *
 * POST JSON {"action":"new"} https://github.com/Gourab69420/face-liveness-recognition
 *   -> {"ok":true,"token":"...","steps":[{"type":"blink","count":2},{"type":"turn_left"}],"expires_in":60} 
 * POST JSON {"action":"verify","token":"...","result":{"steps_done":["blink","turn_left"]}}
 *   -> {"ok":true}   or   {"ok":false,"error":"..."}
 *
 * Header X-CSRF-Token is required (js/liveness.js sends it from the page's csrf-token meta tag).
 */
require_once __DIR__ . '/../includes/challenge.php';

secureSessionStart();
requirePost();
requireCsrf();

$body   = readJsonBody();
$action = is_string($body['action'] ?? null) ? $body['action'] : '';

if ($action === 'new') {
    jsonReply(200, ['ok' => true] + createChallenge());
}

if ($action === 'verify') {
    $token  = is_string($body['token'] ?? null) ? $body['token'] : '';
    $result = is_array($body['result'] ?? null) ? $body['result'] : [];
    $r = verifyChallenge($token, $result);
    jsonReply($r['ok'] ? 200 : 400, ['ok' => $r['ok'], 'error' => $r['error']]);
}

jsonReply(400, ['ok' => false, 'error' => 'Unknown action.']);
