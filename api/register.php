<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/users.php';
require_once __DIR__ . '/../includes/python_client.php';

secureSessionStart();
requirePost();
requireCsrf();

function fail(int $status, string $message): void {
    jsonReply($status, ['ok' => false, 'message' => $message]);
}

// 25 photos is about 1-3 MB, so allow a bigger body than the other APIs
$body = readJsonBody(12000000);

$name   = cleanString(is_string($body['name'] ?? null) ? $body['name'] : '', 50);
$id     = strtoupper(trim(is_string($body['employee_id'] ?? null) ? $body['employee_id'] : ''));   // the one canonical ID
$pin    = is_string($body['pin'] ?? null) ? $body['pin'] : '';
$photos = is_array($body['photos'] ?? null) ? $body['photos'] : [];

// same ID rule as the Python service (python_recog/face_utils.py), because the ID becomes a folder name
if (!preg_match('/^[A-Z0-9][A-Z0-9\-]{2,19}$/', $id)) {
    fail(400, 'Employee ID must be 3-20 letters, numbers or -.');
}
$err = validateUserData($id, $name, $pin);
if ($err !== null) {
    fail(400, $err);
}
if (count($photos) < 15 || count($photos) > 40) {
    fail(400, 'Please take at least 15 clear face photos.');
}
foreach ($photos as $p) {
    if (!is_string($p)) {
        fail(400, 'Invalid photo data.');
    }
}
if (userExists($id)) {
    fail(409, 'Employee ID already registered.');
}

// 1. Python saves the face photos (replace = true: the ID is not in users.csv, so any old folder is a leftover)
$py = new PythonClient();
$r  = $py->enroll($id, $photos, true);
if (!($r['ok'] ?? false)) {
    fail(200, (string)($r['message'] ?? 'The face could not be saved.'));
}

// 2. save the user in users.csv. If that fails, undo the photos.
$u = createUser($id, $name, $pin);
if (!$u['success']) {
    if (!userExists($id)) {
        $py->deleteUser($id);
    }
    fail(409, $u['error']);
}

jsonReply(200, ['ok' => true, 'redirect' => 'login.php']);