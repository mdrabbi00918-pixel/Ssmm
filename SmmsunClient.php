<?php
declare(strict_types=1);
final class SmmsunClient {
    public function __construct(private string $apiUrl, private string $apiKey) {}
    private function post(array $data): array {
        if ($this->apiUrl === '' || $this->apiKey === '') return ['error'=>'SMM_API_URL or SMM_API_KEY is not configured.'];
        $data['key'] = $this->apiKey;
        // Provider calls must fail fast so a slow upstream never blocks the
        // whole page for 25 seconds. Normal catalogue requests are cached by index.php.
        $body = @file_get_contents($this->apiUrl, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\nConnection: close\r\n",
                'content' => http_build_query($data),
                'timeout' => 8,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
        ]));
        if ($body === false) return ['error'=>'Provider API request failed.'];
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) return ['error'=>'Invalid API response from provider.'];
        return $decoded;
    }
    public function services():array{return $this->post(['action'=>'services']);}
    public function addOrder(string $service,string $link,int $quantity):array{return $this->post(['action'=>'add','service'=>$service,'link'=>$link,'quantity'=>$quantity]);}
    public function orderStatus(string $order):array{return $this->post(['action'=>'status','order'=>$order]);}
}
