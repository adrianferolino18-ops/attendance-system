<?php
declare(strict_types=1);
/**
 * api/verify_pin.php - Member 4 (the endpoint pin.php already calls).
 *
 * POST JSON {"pin":"1234"}   (+ X-CSRF-Token header)
 * Reply: {"ok":true,"redirect":"attendance.php"}
 *        {"ok":false,"error":"...","redirect":"..."}  (redirect only when the flow must restart)
 *
 * 3 wrong PINs lock that employee for PIN_LOCK_SECONDS (see includes/security.php).
 */
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/users.php';

secureSessionStart();
requirePost();
requireCsrf();

$userId = getPendingUser();
if ($userId === null) {
    jsonReply(401, ['ok' => false, 'error' => 'Session expired. Please scan your face again.',
                    'redirect' => 'login.php']);
}

$lock = pinLockStatus($userId);
if ($lock['locked']) {
    clearPending();
    jsonReply(423, ['ok' => false, 'redirect' => 'index.php',
                    'error' => 'Too many wrong PINs. Try again in ' . $lock['seconds_left'] . ' seconds.']);
}

$body = readJsonBody();
$pin  = $body['pin'] ?? '';
if (!is_string($pin) || !preg_match('/^\d{4,6}$/', $pin)) {
    jsonReply(400, ['ok' => false, 'error' => 'PIN must be 4-6 digits.']);   // not counted as an attempt
}

$user = findUser($userId);
if ($user === null) {
    clearPending();
    jsonReply(401, ['ok' => false, 'error' => 'Account not found.', 'redirect' => 'login.php']);
}

if (password_verify($pin, $user['pin_hash'])) {
    pinRecordSuccess($userId);
    completeLogin($user['user_id'], $user['name']);   // new session ID + user_id in session
    jsonReply(200, ['ok' => true, 'redirect' => 'attendance.php']);
}

$after = pinRecordFailure($userId);
if ($after['locked']) {
    clearPending();   // after the lock ends they must do face + liveness again
    jsonReply(423, ['ok' => false, 'redirect' => 'index.php',
                    'error' => 'Too many wrong PINs. Locked for ' . $after['seconds_left'] . ' seconds.']);
}
jsonReply(401, ['ok' => false,
                'error' => 'Wrong PIN. ' . $after['attempts_left'] . ' attempt(s) left.']);
