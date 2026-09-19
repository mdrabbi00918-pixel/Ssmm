<?php
declare(strict_types=1);

/**
 * Persistent PostgreSQL store for Render + Supabase.
 * Uses PostgreSQL/Supabase in production. JSON fallback is kept for local development only.
 */
final class JsonStore {
    private ?PDO $pdo = null;
    private ?string $file = null;

    public function __construct(string $dir) {
        $databaseUrl = trim((string)(getenv('DATABASE_URL') ?: ''));
        if ($databaseUrl !== '') {
            $this->pdo = $this->connectPostgres($databaseUrl);
            $this->initSchema();
            return;
        }
        // Never silently fall back to JSON on production/Render. A missing DATABASE_URL
        // must fail loudly instead of creating a fresh empty customer database.
        $production = strtolower((string)(getenv('APP_ENV') ?: '')) === 'production' || getenv('RENDER') === 'true' || getenv('RENDER_SERVICE_ID');
        if ($production) throw new RuntimeException('DATABASE_URL is required in production. Customer data is not allowed to fall back to local JSON storage.');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Cannot create data directory.');
        $this->file = rtrim($dir, '/\\') . '/panel.json';
        if (!is_file($this->file)) $this->writeJson($this->emptyData());
    }

    private function connectPostgres(string $url): PDO {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) throw new RuntimeException('Invalid DATABASE_URL.');
        $host = (string)$parts['host'];
        $port = (int)($parts['port'] ?? 5432);
        $db = ltrim((string)($parts['path'] ?? '/postgres'), '/');
        $user = rawurldecode((string)($parts['user'] ?? ''));
        $pass = rawurldecode((string)($parts['pass'] ?? ''));
        if ($user === '' || $pass === '' || $db === '') throw new RuntimeException('DATABASE_URL is incomplete.');
        $dsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode=require";
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function initSchema(): void {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
  id BIGSERIAL PRIMARY KEY,
  name TEXT NOT NULL,
  email TEXT NOT NULL,
  password TEXT NOT NULL,
  balance NUMERIC(14,2) NOT NULL DEFAULT 0,
  role TEXT NOT NULL DEFAULT 'customer',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_uq ON users (LOWER(email));

CREATE TABLE IF NOT EXISTS deposits (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  method TEXT NOT NULL,
  amount NUMERIC(14,2) NOT NULL,
  trx_id TEXT NOT NULL,
  note TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'pending',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS deposits_status_idx ON deposits(status);

CREATE TABLE IF NOT EXISTS orders (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  service_id TEXT NOT NULL,
  service_name TEXT NOT NULL,
  link TEXT NOT NULL,
  quantity INTEGER NOT NULL,
  unit_price NUMERIC(14,4) NOT NULL,
  total NUMERIC(14,2) NOT NULL,
  provider_order_id TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'Pending',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS orders_user_idx ON orders(user_id);

CREATE TABLE IF NOT EXISTS service_prices (
  service_id TEXT PRIMARY KEY,
  price NUMERIC(14,4) NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS remember_tokens (
  token_hash TEXT PRIMARY KEY,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  expires_at BIGINT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS remember_user_idx ON remember_tokens(user_id);

CREATE TABLE IF NOT EXISTS settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS digital_products (
  id BIGSERIAL PRIMARY KEY,
  name TEXT NOT NULL,
  image_url TEXT NOT NULL DEFAULT '',
  description TEXT NOT NULL DEFAULT '',
  price NUMERIC(14,2) NOT NULL,
  item_link TEXT NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS digital_purchases (
  id BIGSERIAL PRIMARY KEY,
  product_id BIGINT NOT NULL REFERENCES digital_products(id) ON DELETE CASCADE,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  amount NUMERIC(14,2) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE(product_id,user_id)
);
CREATE INDEX IF NOT EXISTS digital_purchases_user_idx ON digital_purchases(user_id);
SQL;
        $this->pdo?->exec($sql);
    }

    private function emptyData(): array {
        return ['users'=>[],'deposits'=>[],'orders'=>[],'service_prices'=>[],'remember_tokens'=>[],'settings'=>[],'digital_products'=>[],'digital_purchases'=>[],'seq'=>['users'=>0,'deposits'=>0,'orders'=>0,'digital_products'=>0,'digital_purchases'=>0]];
    }

    private function readJson(): array {
        $fp=@fopen((string)$this->file,'c+'); if(!$fp) throw new RuntimeException('Cannot open data store.');
        flock($fp, LOCK_SH); rewind($fp); $raw=stream_get_contents($fp) ?: ''; flock($fp, LOCK_UN); fclose($fp);
        $data=json_decode($raw,true); return is_array($data)?$data:$this->emptyData();
    }
    private function writeJson(array $data): void {
        $fp=@fopen((string)$this->file,'c+'); if(!$fp) throw new RuntimeException('Cannot write data store.');
        flock($fp, LOCK_EX); ftruncate($fp,0); rewind($fp); fwrite($fp,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); fflush($fp); flock($fp,LOCK_UN); fclose($fp);
    }
    private function mutateJson(callable $fn): mixed {
        $data=$this->readJson(); $result=$fn($data); $this->writeJson($data); return $result;
    }
    private function jsonId(array &$d,string $type): int { $d['seq'][$type]=((int)($d['seq'][$type]??0))+1; return $d['seq'][$type]; }

    public function findUserByEmail(string $email): ?array {
        if ($this->pdo) { $s=$this->pdo->prepare('SELECT * FROM users WHERE LOWER(email)=LOWER(:email) LIMIT 1');$s->execute(['email'=>$email]);$u=$s->fetch();return $u?:null; }
        foreach($this->readJson()['users'] as $u) if(strtolower((string)$u['email'])===strtolower($email))return $u; return null;
    }
    public function findUser(int $id): ?array {
        if ($this->pdo) { $s=$this->pdo->prepare('SELECT * FROM users WHERE id=:id LIMIT 1');$s->execute(['id'=>$id]);$u=$s->fetch();return $u?:null; }
        foreach($this->readJson()['users'] as $u) if((int)$u['id']===$id)return $u; return null;
    }
    public function createUser(string $name,string $email,string $password,string $role='customer'): array {
        if ($this->pdo) { $s=$this->pdo->prepare('INSERT INTO users(name,email,password,balance,role) VALUES(:name,LOWER(:email),:password,0,:role) RETURNING *');$s->execute(['name'=>$name,'email'=>$email,'password'=>$password,'role'=>$role]);return $s->fetch(); }
        return $this->mutateJson(function(&$d)use($name,$email,$password,$role){foreach($d['users'] as $u)if(strtolower($u['email'])===strtolower($email))throw new RuntimeException('duplicate');$u=['id'=>$this->jsonId($d,'users'),'name'=>$name,'email'=>strtolower($email),'password'=>$password,'balance'=>0.0,'role'=>$role,'created_at'=>date('Y-m-d H:i:s')];$d['users'][]=$u;return $u;});
    }
    public function updateUser(int $id,array $changes): ?array {
        if ($this->pdo) { $allowed=['name','email','password','balance','role'];$sets=[];$params=['id'=>$id];foreach($changes as $k=>$v){if(in_array($k,$allowed,true)){$sets[]=$k.'=:'.$k;$params[$k]=$k==='email'?strtolower((string)$v):$v;}}if(!$sets)return $this->findUser($id);$s=$this->pdo->prepare('UPDATE users SET '.implode(',',$sets).' WHERE id=:id RETURNING *');$s->execute($params);$u=$s->fetch();return $u?:null; }
        return $this->mutateJson(function(&$d)use($id,$changes){foreach($d['users'] as &$u)if((int)$u['id']===$id){$u=array_merge($u,$changes);return $u;}return null;});
    }
    public function users(): array {
        if ($this->pdo) return $this->pdo->query('SELECT * FROM users ORDER BY id ASC')->fetchAll();
        $rows=$this->readJson()['users'];usort($rows,fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);return $rows;
    }
    public function setSetting(string $key,string $value): void {
        if ($this->pdo) {$s=$this->pdo->prepare('INSERT INTO settings(key,value) VALUES(:key,:value) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value');$s->execute(['key'=>$key,'value'=>$value]);return;}
        $this->mutateJson(function(&$d)use($key,$value){$d['settings'][$key]=$value;});
    }
    public function setting(string $key,?string $default=null): ?string {
        if ($this->pdo) {$s=$this->pdo->prepare('SELECT value FROM settings WHERE key=:key');$s->execute(['key'=>$key]);$v=$s->fetchColumn();return $v===false?$default:(string)$v;}
        $d=$this->readJson();$v=$d['settings'][$key]??$default;return $v===null?null:(string)$v;
    }
    public function rememberForUser(int $uid): void {
        if ($this->pdo) {$s=$this->pdo->prepare('DELETE FROM remember_tokens WHERE user_id=:id');$s->execute(['id'=>$uid]);return;}
        $this->mutateJson(function(&$d)use($uid){$d['remember_tokens']=array_values(array_filter($d['remember_tokens'],fn($t)=>(int)$t['user_id']!==$uid));});
    }
    public function addRemember(int $uid,string $hash,int $expires): void {
        if ($this->pdo) {$s=$this->pdo->prepare('INSERT INTO remember_tokens(token_hash,user_id,expires_at) VALUES(:hash,:uid,:exp) ON CONFLICT(token_hash) DO UPDATE SET user_id=EXCLUDED.user_id,expires_at=EXCLUDED.expires_at');$s->execute(['hash'=>$hash,'uid'=>$uid,'exp'=>$expires]);return;}
        $this->mutateJson(function(&$d)use($uid,$hash,$expires){$d['remember_tokens']=array_values(array_filter($d['remember_tokens'],fn($t)=>(string)$t['token_hash']!==$hash));$d['remember_tokens'][]=['user_id'=>$uid,'token_hash'=>$hash,'expires_at'=>$expires,'created_at'=>date('Y-m-d H:i:s')];});
    }
    public function restoreToken(string $hash): ?array {
        if ($this->pdo) {$s=$this->pdo->prepare('SELECT u.* FROM remember_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=:hash AND t.expires_at>:now LIMIT 1');$s->execute(['hash'=>$hash,'now'=>time()]);$u=$s->fetch();return $u?:null;}
        foreach($this->readJson()['remember_tokens'] as $t)if($t['token_hash']===$hash&&(int)$t['expires_at']>time())return $this->findUser((int)$t['user_id']);return null;
    }
    public function deleteRememberHash(string $hash): void {
        if ($this->pdo) {$s=$this->pdo->prepare('DELETE FROM remember_tokens WHERE token_hash=:hash');$s->execute(['hash'=>$hash]);return;}
        $this->mutateJson(function(&$d)use($hash){$d['remember_tokens']=array_values(array_filter($d['remember_tokens'],fn($t)=>(string)$t['token_hash']!==$hash));});
    }
    public function price(string $sid): ?float {
        if ($this->pdo) {$s=$this->pdo->prepare('SELECT price FROM service_prices WHERE service_id=:sid');$s->execute(['sid'=>$sid]);$v=$s->fetchColumn();return $v===false?null:(float)$v;}
        foreach($this->readJson()['service_prices'] as $p)if((string)$p['service_id']===$sid)return (float)$p['price'];return null;
    }
    public function allPrices(): array {
        if ($this->pdo) return $this->pdo->query('SELECT service_id,price,updated_at FROM service_prices ORDER BY service_id')->fetchAll();
        return $this->readJson()['service_prices'];
    }
    public function setPrice(string $sid,float $price): void {
        if ($this->pdo) {$s=$this->pdo->prepare('INSERT INTO service_prices(service_id,price) VALUES(:sid,:price) ON CONFLICT(service_id) DO UPDATE SET price=EXCLUDED.price,updated_at=NOW()');$s->execute(['sid'=>$sid,'price'=>$price]);return;}
        $this->mutateJson(function(&$d)use($sid,$price){$found=false;foreach($d['service_prices'] as &$p)if((string)$p['service_id']===$sid){$p['price']=$price;$p['updated_at']=date('Y-m-d H:i:s');$found=true;break;}if(!$found)$d['service_prices'][]=['service_id'=>$sid,'price'=>$price,'updated_at'=>date('Y-m-d H:i:s')];});
    }
    public function deletePrice(string $sid): void {
        if ($this->pdo) {$s=$this->pdo->prepare('DELETE FROM service_prices WHERE service_id=:sid');$s->execute(['sid'=>$sid]);return;}
        $this->mutateJson(function(&$d)use($sid){$d['service_prices']=array_values(array_filter($d['service_prices'],fn($p)=>(string)$p['service_id']!==$sid));});
    }
    public function addDeposit(int $uid,string $method,float $amount,string $trx,string $note): array {
        if ($this->pdo) {$s=$this->pdo->prepare("INSERT INTO deposits(user_id,method,amount,trx_id,note,status) VALUES(:uid,:method,:amount,:trx,:note,'pending') RETURNING *");$s->execute(['uid'=>$uid,'method'=>$method,'amount'=>$amount,'trx'=>$trx,'note'=>$note]);return $s->fetch();}
        return $this->mutateJson(function(&$d)use($uid,$method,$amount,$trx,$note){$x=['id'=>$this->jsonId($d,'deposits'),'user_id'=>$uid,'method'=>$method,'amount'=>$amount,'trx_id'=>$trx,'note'=>$note,'status'=>'pending','created_at'=>date('Y-m-d H:i:s')];$d['deposits'][]=$x;return $x;});
    }
    public function deposit(int $id): ?array {
        if ($this->pdo) {$s=$this->pdo->prepare('SELECT * FROM deposits WHERE id=:id');$s->execute(['id'=>$id]);$d=$s->fetch();return $d?:null;}
        foreach($this->readJson()['deposits'] as $x)if((int)$x['id']===$id)return $x;return null;
    }
    public function adminDeposits(): array {
        if ($this->pdo) return $this->pdo->query('SELECT d.*,u.name,u.email FROM deposits d JOIN users u ON u.id=d.user_id ORDER BY d.id DESC')->fetchAll();
        $d=$this->readJson();$users=[];foreach($d['users'] as $u)$users[$u['id']]=$u;foreach($d['deposits'] as &$x){$u=$users[$x['user_id']]??[];$x['name']=$u['name']??'';$x['email']=$u['email']??'';}usort($d['deposits'],fn($a,$b)=>(int)$b['id']<=>(int)$a['id']);return $d['deposits'];
    }
    public function approveDeposit(int $id): bool {
        if ($this->pdo) { $this->pdo->beginTransaction();try{$s=$this->pdo->prepare("SELECT * FROM deposits WHERE id=:id FOR UPDATE");$s->execute(['id'=>$id]);$dep=$s->fetch();if(!$dep||$dep['status']!=='pending'){$this->pdo->rollBack();return false;}$s=$this->pdo->prepare("UPDATE deposits SET status='approved' WHERE id=:id");$s->execute(['id'=>$id]);$s=$this->pdo->prepare('UPDATE users SET balance=balance+:amount WHERE id=:uid');$s->execute(['amount'=>$dep['amount'],'uid'=>$dep['user_id']]);$this->pdo->commit();return true;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;} }
        return $this->mutateJson(function(&$d)use($id){foreach($d['deposits'] as &$dep)if((int)$dep['id']===$id&&$dep['status']==='pending'){$dep['status']='approved';foreach($d['users'] as &$u)if((int)$u['id']===(int)$dep['user_id']){$u['balance']=(float)$u['balance']+(float)$dep['amount'];break;}return true;}return false;});
    }
    public function rejectDeposit(int $id): bool {
        if ($this->pdo) {$s=$this->pdo->prepare("UPDATE deposits SET status='rejected' WHERE id=:id AND status='pending'");$s->execute(['id'=>$id]);return $s->rowCount()===1;}
        return $this->mutateJson(function(&$d)use($id){foreach($d['deposits'] as &$x)if((int)$x['id']===$id&&$x['status']==='pending'){$x['status']='rejected';return true;}return false;});
    }
    public function createOrder(int $uid,string $sid,string $name,string $link,int $qty,float $price,float $total,string $provider,string $status): ?array {
        if ($this->pdo) { $this->pdo->beginTransaction();try{$s=$this->pdo->prepare('SELECT * FROM users WHERE id=:id FOR UPDATE');$s->execute(['id'=>$uid]);$u=$s->fetch();if(!$u||((float)$u['balance']<$total)){$this->pdo->rollBack();return null;}$s=$this->pdo->prepare('UPDATE users SET balance=balance-:total WHERE id=:id');$s->execute(['total'=>$total,'id'=>$uid]);$s=$this->pdo->prepare('INSERT INTO orders(user_id,service_id,service_name,link,quantity,unit_price,total,provider_order_id,status) VALUES(:uid,:sid,:name,:link,:qty,:price,:total,:provider,:status) RETURNING *');$s->execute(['uid'=>$uid,'sid'=>$sid,'name'=>$name,'link'=>$link,'qty'=>$qty,'price'=>$price,'total'=>$total,'provider'=>$provider,'status'=>$status]);$o=$s->fetch();$this->pdo->commit();return $o?:null;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;} }
        return $this->mutateJson(function(&$d)use($uid,$sid,$name,$link,$qty,$price,$total,$provider,$status){foreach($d['users'] as &$u)if((int)$u['id']===$uid){if((float)$u['balance']<$total)return null;$u['balance']=(float)$u['balance']-$total;$o=['id'=>$this->jsonId($d,'orders'),'user_id'=>$uid,'service_id'=>$sid,'service_name'=>$name,'link'=>$link,'quantity'=>$qty,'unit_price'=>$price,'total'=>$total,'provider_order_id'=>$provider,'status'=>$status,'created_at'=>date('Y-m-d H:i:s')];$d['orders'][]=$o;return $o;}return null;});
    }
    public function userOrders(int $uid): array {
        if ($this->pdo) {$s=$this->pdo->prepare('SELECT * FROM orders WHERE user_id=:uid ORDER BY id DESC');$s->execute(['uid'=>$uid]);return $s->fetchAll();}
        $rows=array_values(array_filter($this->readJson()['orders'],fn($o)=>(int)$o['user_id']===$uid));usort($rows,fn($a,$b)=>(int)$b['id']<=>(int)$a['id']);return $rows;
    }
    public function updateOrderStatus(int $id,string $status): bool {
        if ($this->pdo) {$s=$this->pdo->prepare('UPDATE orders SET status=:status WHERE id=:id');$s->execute(['status'=>$status,'id'=>$id]);return $s->rowCount()===1;}
        return $this->mutateJson(function(&$d)use($id,$status){foreach($d['orders'] as &$o)if((int)$o['id']===$id){$o['status']=$status;return true;}return false;});
    }
    public function adminOrders(): array {
        if ($this->pdo) return $this->pdo->query('SELECT o.*,u.name FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 100')->fetchAll();
        $d=$this->readJson();$users=[];foreach($d['users'] as $u)$users[$u['id']]=$u;$rows=$d['orders'];foreach($rows as &$o)$o['name']=$users[$o['user_id']]['name']??'';usort($rows,fn($a,$b)=>(int)$b['id']<=>(int)$a['id']);return array_slice($rows,0,100);
    }
    public function digitalProducts(bool $activeOnly=false): array {
        if ($this->pdo) {
            $sql=$activeOnly?'SELECT * FROM digital_products WHERE active=TRUE ORDER BY id DESC':'SELECT * FROM digital_products ORDER BY id DESC';
            return $this->pdo->query($sql)->fetchAll();
        }
        $rows=$this->readJson()['digital_products']??[];
        if($activeOnly)$rows=array_values(array_filter($rows,fn($x)=>!empty($x['active'])));
        usort($rows,fn($a,$b)=>(int)$b['id']<=>(int)$a['id']); return $rows;
    }
    public function digitalProduct(int $id): ?array {
        if($this->pdo){$s=$this->pdo->prepare('SELECT * FROM digital_products WHERE id=:id');$s->execute(['id'=>$id]);$r=$s->fetch();return $r?:null;}
        foreach($this->readJson()['digital_products']??[] as $r)if((int)$r['id']===$id)return $r; return null;
    }
    public function createDigitalProduct(string $name,string $image,string $description,float $price,string $link): array {
        if($this->pdo){$s=$this->pdo->prepare('INSERT INTO digital_products(name,image_url,description,price,item_link,active) VALUES(:name,:image,:description,:price,:link,TRUE) RETURNING *');$s->execute(['name'=>$name,'image'=>$image,'description'=>$description,'price'=>$price,'link'=>$link]);return $s->fetch();}
        return $this->mutateJson(function(&$d)use($name,$image,$description,$price,$link){$d['seq']['digital_products']=((int)($d['seq']['digital_products']??0))+1;$x=['id'=>$d['seq']['digital_products'],'name'=>$name,'image_url'=>$image,'description'=>$description,'price'=>$price,'item_link'=>$link,'active'=>true,'created_at'=>date('Y-m-d H:i:s')];$d['digital_products'][]=$x;return $x;});
    }
    public function updateDigitalProduct(int $id,array $changes): ?array {
        if($this->pdo){$allowed=['name','image_url','description','price','item_link','active'];$sets=[];$params=['id'=>$id];foreach($changes as $k=>$v)if(in_array($k,$allowed,true)){$sets[]=$k.'=:'.$k;$params[$k]=$v;}if(!$sets)return $this->digitalProduct($id);$s=$this->pdo->prepare('UPDATE digital_products SET '.implode(',',$sets).' WHERE id=:id RETURNING *');$s->execute($params);$r=$s->fetch();return $r?:null;}
        return $this->mutateJson(function(&$d)use($id,$changes){foreach($d['digital_products']??[] as &$x)if((int)$x['id']===$id){$x=array_merge($x,$changes);return $x;}return null;});
    }
    public function deleteDigitalProduct(int $id): void {
        if($this->pdo){$s=$this->pdo->prepare('DELETE FROM digital_products WHERE id=:id');$s->execute(['id'=>$id]);return;}
        $this->mutateJson(function(&$d)use($id){$d['digital_products']=array_values(array_filter($d['digital_products']??[],fn($x)=>(int)$x['id']!==$id));});
    }
    public function userDigitalPurchases(int $uid): array {
        if($this->pdo){$s=$this->pdo->prepare('SELECT p.*,d.name,d.image_url,d.description,d.item_link FROM digital_purchases p JOIN digital_products d ON d.id=p.product_id WHERE p.user_id=:uid ORDER BY p.id DESC');$s->execute(['uid'=>$uid]);return $s->fetchAll();}
        $d=$this->readJson();$products=[];foreach($d['digital_products']??[] as $x)$products[$x['id']]=$x;$rows=[];foreach($d['digital_purchases']??[] as $x)if((int)$x['user_id']===$uid){$pr=$products[$x['product_id']]??[];$rows[]=array_merge($x,['name'=>$pr['name']??'','image_url'=>$pr['image_url']??'','description'=>$pr['description']??'','item_link'=>$pr['item_link']??'']);}usort($rows,fn($a,$b)=>(int)$b['id']<=>(int)$a['id']);return $rows;
    }
    public function hasDigitalPurchase(int $uid,int $pid): bool {
        if($this->pdo){$s=$this->pdo->prepare('SELECT 1 FROM digital_purchases WHERE user_id=:uid AND product_id=:pid LIMIT 1');$s->execute(['uid'=>$uid,'pid'=>$pid]);return (bool)$s->fetchColumn();}
        foreach($this->readJson()['digital_purchases']??[] as $x)if((int)$x['user_id']===$uid&&(int)$x['product_id']===$pid)return true;return false;
    }
    public function buyDigitalProduct(int $uid,int $pid): bool {
        if($this->pdo){$this->pdo->beginTransaction();try{$s=$this->pdo->prepare('SELECT * FROM digital_products WHERE id=:id AND active=TRUE FOR UPDATE');$s->execute(['id'=>$pid]);$pr=$s->fetch();if(!$pr||$this->hasDigitalPurchase($uid,$pid)){$this->pdo->rollBack();return false;}$s=$this->pdo->prepare('SELECT balance FROM users WHERE id=:id FOR UPDATE');$s->execute(['id'=>$uid]);$bal=$s->fetchColumn();if($bal===false||(float)$bal<(float)$pr['price']){$this->pdo->rollBack();return false;}$s=$this->pdo->prepare('UPDATE users SET balance=balance-:amount WHERE id=:id');$s->execute(['amount'=>$pr['price'],'id'=>$uid]);$s=$this->pdo->prepare('INSERT INTO digital_purchases(product_id,user_id,amount) VALUES(:pid,:uid,:amount)');$s->execute(['pid'=>$pid,'uid'=>$uid,'amount'=>$pr['price']]);$this->pdo->commit();return true;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}}
        return $this->mutateJson(function(&$d)use($uid,$pid){foreach($d['digital_purchases']??[] as $x)if((int)$x['user_id']===$uid&&(int)$x['product_id']===$pid)return false;foreach($d['digital_products']??[] as $pr)if((int)$pr['id']===$pid&&!empty($pr['active'])){foreach($d['users'] as &$u)if((int)$u['id']===$uid){if((float)$u['balance']<(float)$pr['price'])return false;$u['balance']=(float)$u['balance']-(float)$pr['price'];$d['seq']['digital_purchases']=((int)($d['seq']['digital_purchases']??0))+1;$d['digital_purchases'][]=['id'=>$d['seq']['digital_purchases'],'product_id'=>$pid,'user_id'=>$uid,'amount'=>$pr['price'],'created_at'=>date('Y-m-d H:i:s')];return true;}}return false;});
    }
    public function counts(): array {
        if ($this->pdo) {$users=(int)$this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();$orders=(int)$this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();$pending=(int)$this->pdo->query("SELECT COUNT(*) FROM deposits WHERE status='pending'")->fetchColumn();$sales=(float)$this->pdo->query('SELECT COALESCE(SUM(total),0) FROM orders')->fetchColumn();return ['users'=>$users,'orders'=>$orders,'deposits_pending'=>$pending,'sales'=>$sales];}
        $d=$this->readJson();$pending=0;foreach($d['deposits'] as $x)if($x['status']==='pending')$pending++;return ['users'=>count($d['users']),'orders'=>count($d['orders']),'deposits_pending'=>$pending,'sales'=>array_sum(array_map(fn($o)=>(float)$o['total'],$d['orders']))];
    }
}
