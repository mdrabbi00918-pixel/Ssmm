<?php
declare(strict_types=1);
final class SmmsunClient {
    public function __construct(private string $apiUrl, private string $apiKey) {}
    private function post(array $data): array {
        if ($this->apiUrl === '' || $this->apiKey === '') return ['error'=>'SMM_API_URL or SMM_API_KEY is not configured.'];
        $data['key']=$this->apiKey;
        $ch=curl_init($this->apiUrl);
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
        $body=curl_exec($ch);$err=curl_error($ch);$http=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($body===false)return ['error'=>$err?:'API request failed'];$decoded=json_decode($body,true);if(!is_array($decoded))return ['error'=>'Invalid API response','http_code'=>$http];return $decoded;
    }
    public function services():array{return $this->post(['action'=>'services']);}
    public function addOrder(string $service,string $link,int $quantity):array{return $this->post(['action'=>'add','service'=>$service,'link'=>$link,'quantity'=>$quantity]);}
    public function orderStatus(string $order):array{return $this->post(['action'=>'status','order'=>$order]);}
}
