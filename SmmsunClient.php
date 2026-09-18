<?php
final class SmmsunClient {
    public function __construct(
        private string $apiUrl,
        private string $apiKey
    ) {}

    public function services(): array {
        if ($this->apiUrl === '' || $this->apiKey === '') {
            return ['error' => 'SMM_API_URL or SMM_API_KEY is not configured.'];
        }

        $payload = http_build_query([
            'key' => $this->apiKey,
            'action' => 'services',
        ]);

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) return ['error' => $error ?: 'API request failed'];

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : ['raw' => $body];
    }
}
