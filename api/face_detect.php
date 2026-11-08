<?php
declare(strict_types=1);


header('Content-Type: application/json');


function reply(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(405, ['ok' => false, 'code' => 'bad_request', 'message' => 'POST only.']);
}

$data = json_decode((string) file_get_contents('php://input', false, null, 0, 600000), true);

if (!is_array($data) || !isset($data['image']) || !is_string($data['image'])) {
    reply(400, ['ok' => false, 'code' => 'bad_request', 'message' => 'Send JSON like {"image": "<base64>"}.']);
}


require_once __DIR__ . '/../includes/python_client.php';
reply(200, (new PythonClient())->detect($data['image']));