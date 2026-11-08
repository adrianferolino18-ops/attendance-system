<?php
declare(strict_types=1);


final class PythonClient {
    public function __construct(
        private string $baseUrl = 'http://127.0.0.1:8765',
        private int $timeoutSeconds = 30
    ) {}

    
    public function enroll(string $userId, array $imagesBase64, bool $replace = false): array {
        return $this->post('/enroll', [
            'user_id' => $userId,
            'images' => array_values($imagesBase64),
            'replace' => $replace,
        ]);
    }

    
    public function recognize(string $imageBase64): array {
        return $this->post('/recognize', ['image' => $imageBase64]);
    }


    public function detect(string $imageBase64): array {
        return $this->post('/detect', ['image' => $imageBase64]);
    }


    public function deleteUser(string $userId): array {
        return $this->post('/delete', ['user_id' => $userId]);
    }


    public function isUp(): bool {
        $r = $this->request('GET', '/health', null);
        return ($r['ok'] ?? false) === true;
    }


    private function post(string $path, array $payload): array {
        return $this->request('POST', $path, $payload);
    }

    private function request(string $method, string $path, ?array $payload): array {
        $ch = curl_init($this->baseUrl . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 3,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
        }

        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'code' => 'service_down', 'message' => 'The face recognition service is not running.'];
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'code' => 'bad_response', 'message' => 'The face recognition service sent an invalid reply.'];
        }

        return $data;
    }
}