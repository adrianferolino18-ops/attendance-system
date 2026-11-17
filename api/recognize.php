<?php
declare(strict_types=1);
/**
 * api/recognize.php - Member 4 (reference version for Member 5's login flow).
 *
 * Recognises the face with Member 3's Python service, but ONLY if this session
 * has just passed the liveness challenge. On a match it stores the user as
 * "pending" (face OK, PIN still needed) and tells the browser to go to pin.php.
 *
 * POST JSON {"image": "<data URL from Face.captureFrame(video)>"}
 * Reply: {"ok":false,"code":"no_face|liveness_required|...","message":"..."}
 *        {"ok":true,"matched":false}
 *        {"ok":true,"matched":true,"name":"...","redirect":"pin.php"}
 */
require_once __DIR__ . '/../includes/challenge.php';
require_once __DIR__ . '/../includes/users.php';
require_once __DIR__ . '/../includes/python_client.php';

secureSessionStart();
requirePost();
requireCsrf();

if (!livenessIsActive()) {
    jsonReply(403, ['ok' => false, 'code' => 'liveness_required',
                    'message' => 'Please complete the liveness check first.']);
}

$body  = readJsonBody(600000);
$image = $body['image'] ?? null;
if (!is_string($image) || $image === '') {
    jsonReply(400, ['ok' => false, 'code' => 'bad_request', 'message' => 'No image received.']);
}

$r = (new PythonClient())->recognize($image);

// Not a real yes/no yet (no face, too dark, service down...): the pass is kept so the
// person can retry within the time window. Nothing was decided, so nothing can be abused.
if (!($r['ok'] ?? false)) {
    jsonReply(200, ['ok' => false, 'code' => (string)($r['code'] ?? 'error'),
                    'message' => (string)($r['message'] ?? 'Face check failed.')]);
}

consumeLiveness();   // a real answer was given: one pass = one recognition attempt

$user = ($r['matched'] ?? false) ? findUser((string)($r['user_id'] ?? '')) : null;
if ($user === null) {
    jsonReply(200, ['ok' => true, 'matched' => false]);   // never reveal distance/votes
}

setPendingUser($user['user_id']);   // also regenerates the session ID
jsonReply(200, ['ok' => true, 'matched' => true, 'name' => $user['name'], 'redirect' => 'pin.php']);
