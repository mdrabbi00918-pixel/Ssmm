<?php
declare(strict_types=1);

/** Small dependency-free JSON datastore for Render deployments without apt/native DB packages. */
final class JsonStore {
    private string $file;
    public function __construct(string $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Cannot create data directory.');
        $this->file = rtrim($dir, '/\\') . '/panel.json';
        if (!is_file($this->file)) $this->write(['users'=>[],'deposits'=>[],'orders'=>[],'service_prices'=>[],'remember_tokens'=>[],'settings'=>[],'seq'=>['users'=>0,'deposits'=>0,'orders'=>0]]);
    }
    private function read(): array {
        $fp=@fopen($this->file,'c+'); if(!$fp) throw new RuntimeException('Cannot open data store.');
        flock($fp, LOCK_SH); rewind($fp); $raw=stream_get_contents($fp) ?: ''; flock($fp, LOCK_UN); fclose($fp);
        $data=json_decode($raw,true); return is_array($data)?$data:['users'=>[],'deposits'=>[],'orders'=>[],'service_prices'=>[],'remember_tokens'=>[],'settings'=>[],'seq'=>['users'=>0,'deposits'=>0,'orders'=>0]];
    }
    private function write(array $data): void {
        $fp=@fopen($this->file,'c+'); if(!$fp) throw new RuntimeException('Cannot write data store.');
        flock($fp, LOCK_EX); ftruncate($fp,0); rewind($fp); fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    }
    private function mutate(callable $fn): mixed {
        $fp=@fopen($this->file,'c+'); if(!$fp) throw new RuntimeException('Cannot open data store.');
        flock($fp, LOCK_EX); rewind($fp); $raw=stream_get_contents($fp) ?: ''; $data=json_decode($raw,true);
        if(!is_array($data)) $data=['users'=>[],'deposits'=>[],'orders'=>[],'service_prices'=>[],'remember_tokens'=>[],'settings'=>[],'seq'=>['users'=>0,'deposits'=>0,'orders'=>0]];
        $result=$fn($data); ftruncate($fp,0); rewind($fp); fwrite($fp,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); fflush($fp); flock($fp,LOCK_UN); fclose($fp); return $result;
    }
    private function id(array &$d,string $type): int { $d['seq'][$type]=((int)($d['seq'][$type]??0))+1; return $d['seq'][$type]; }
    public function findUserByEmail(string $email): ?array { $email=strtolower($email); foreach($this->read()['users'] as $u) if(strtolower((string)$u['email'])===$email)return $u; return null; }
    public function findUser(int $id): ?array { foreach($this->read()['users'] as $u) if((int)$u['id']===$id)return $u; return null; }
    public function createUser(string $name,string $email,string $password,string $role='customer'): array { return $this->mutate(function(&$d)use($name,$email,$password,$role){foreach($d['users'] as $u)if(strtolower($u['email'])===strtolower($email))throw new RuntimeException('duplicate');$u=['id'=>$this->id($d,'users'),'name'=>$name,'email'=>strtolower($email),'password'=>$password,'balance'=>0.0,'role'=>$role,'created_at'=>date('Y-m-d H:i:s')];$d['users'][]=$u;return $u;}); }
    public function updateUser(int $id,array $changes): ?array { return $this->mutate(function(&$d)use($id,$changes){foreach($d['users'] as &$u)if((int)$u['id']===$id){$u=array_merge($u,$changes);return $u;}return null;}); }
    public function users(): array { $rows=$this->read()['users']; usort($rows,fn($a,$b)=>(int)$a['id']<=>(int)$b['id']); return $rows; }
    public function setSetting(string $key,string $value): void { $this->mutate(function(&$d)use($key,$value){ if(!isset($d['settings'])||!is_array($d['settings']))$d['settings']=[]; $d['settings'][$key]=$value; }); }
    public function setting(string $key,?string $default=null): ?string { $d=$this->read(); $v=$d['settings'][$key]??$default; return $v===null?null:(string)$v; }
    public function rememberForUser(int $uid): void { $this->mutate(function(&$d)use($uid){$d['remember_tokens']=array_values(array_filter($d['remember_tokens'],fn($t)=>(int)$t['user_id']!==$uid));}); }
    public function addRemember(int $uid,string $hash,int $expires): void { $this->mutate(function(&$d)use($uid,$hash,$expires){$d['remember_tokens']=array_values(array_filter($d['remember_tokens'],fn($t)=>(string)$t['token_hash']!==$hash));$d['remember_tokens'][]=['user_id'=>$uid,'token_hash'=>$hash,'expires_at'=>$expires,'created_at'=>date('Y-m-d H:i:s')];}); }
    public function restoreToken(string $hash): ?array { $d=$this->read();$now=time();foreach($d['remember_tokens'] as $t)if($t['token_hash']===$hash&&(int)$t['expires_at']>$now)return $this->findUser((int)$t['user_id']);return null; }
    public function deleteRememberHash(string $hash): void { $this->mutate(function(&$d)use($hash){$d['remember_tokens']=array_values(array_filter($d['remember_tokens'],fn($t)=>(string)$t['token_hash']!==$hash));}); }
    public function price(string $sid): ?float { foreach($this->read()['service_prices'] as $p)if((string)$p['service_id']===$sid)return (float)$p['price'];return null; }
    public function allPrices(): array { return $this->read()['service_prices']; }
    public function setPrice(string $sid,float $price): void { $this->mutate(function(&$d)use($sid,$price){$found=false;foreach($d['service_prices'] as &$p)if((string)$p['service_id']===$sid){$p['price']=$price;$p['updated_at']=date('Y-m-d H:i:s');$found=true;break;}if(!$found)$d['service_prices'][]=['service_id'=>$sid,'price'=>$price,'updated_at'=>date('Y-m-d H:i:s')];}); }
    public function deletePrice(string $sid): void { $this->mutate(function(&$d)use($sid){$d['service_prices']=array_values(array_filter($d['service_prices'],fn($p)=>(string)$p['service_id']!==$sid));}); }
    public function addDeposit(int $uid,string $method,float $amount,string $trx,string $note): array { return $this->mutate(function(&$d)use($uid,$method,$amount,$trx,$note){$x=['id'=>$this->id($d,'deposits'),'user_id'=>$uid,'method'=>$method,'amount'=>$amount,'trx_id'=>$trx,'note'=>$note,'status'=>'pending','created_at'=>date('Y-m-d H:i:s')];$d['deposits'][]=$x;return $x;}); }
    public function deposit(int $id): ?array { foreach($this->read()['deposits'] as $x)if((int)$x['id']===$id)return $x;return null; }
    public function adminDeposits(): array { $d=$this->read();$users=[];foreach($d['users'] as $u)$users[$u['id']]=$u;foreach($d['deposits'] as &$x){$u=$users[$x['user_id']]??[];$x['name']=$u['name']??'';$x['email']=$u['email']??'';}usort($d['deposits'],fn($a,$b)=>(int)$b['id']<=>(int)$a['id']);return $d['deposits']; }
    public function approveDeposit(int $id): bool { return $this->mutate(function(&$d)use($id){foreach($d['deposits'] as &$dep)if((int)$dep['id']===$id&&$dep['status']==='pending'){ $dep['status']='approved'; foreach($d['users'] as &$u)if((int)$u['id']===(int)$dep['user_id']){$u['balance']=(float)$u['balance']+(float)$dep['amount'];break;} return true;}return false;}); }
    public function rejectDeposit(int $id): bool { return $this->mutate(function(&$d)use($id){foreach($d['deposits'] as &$x)if((int)$x['id']===$id&&$x['status']==='pending'){$x['status']='rejected';return true;}return false;}); }
    public function createOrder(int $uid,string $sid,string $name,string $link,int $qty,float $price,float $total,string $provider,string $status): ?array { return $this->mutate(function(&$d)use($uid,$sid,$name,$link,$qty,$price,$total,$provider,$status){foreach($d['users'] as &$u)if((int)$u['id']===$uid){if((float)$u['balance']<$total)return null;$u['balance']=(float)$u['balance']-$total;$o=['id'=>$this->id($d,'orders'),'user_id'=>$uid,'service_id'=>$sid,'service_name'=>$name,'link'=>$link,'quantity'=>$qty,'unit_price'=>$price,'total'=>$total,'provider_order_id'=>$provider,'status'=>$status,'created_at'=>date('Y-m-d H:i:s')];$d['orders'][]=$o;return $o;}return null;}); }
    public function userOrders(int $uid): array { $rows=array_values(array_filter($this->read()['orders'],fn($o)=>(int)$o['user_id']===$uid));usort($rows,fn($a,$b)=>(int)$b['id']<=>(int)$a['id']);return $rows; }
    public function adminOrders(): array { $d=$this->read();$users=[];foreach($d['users'] as $u)$users[$u['id']]=$u;$rows=$d['orders'];foreach($rows as &$o)$o['name']=$users[$o['user_id']]['name']??'';usort($rows,fn($a,$b)=>(int)$b['id']<=>(int)$a['id']);return array_slice($rows,0,100); }
    public function counts(): array { $d=$this->read();$pending=0;foreach($d['deposits'] as $x)if($x['status']==='pending')$pending++;return ['users'=>count($d['users']),'orders'=>count($d['orders']),'deposits_pending'=>$pending,'sales'=>array_sum(array_map(fn($o)=>(float)$o['total'],$d['orders']))]; }
}
