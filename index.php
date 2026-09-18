<?php
declare(strict_types=1);
session_start();

require __DIR__ . '/SmmsunClient.php';

$dbDir = getenv('DB_DIR') ?: __DIR__ . '/data';
if (!is_dir($dbDir)) @mkdir($dbDir, 0775, true);
$db = new PDO('sqlite:' . $dbDir . '/panel.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA foreign_keys = ON;");
$db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT UNIQUE NOT NULL, password TEXT NOT NULL, balance REAL NOT NULL DEFAULT 0, role TEXT NOT NULL DEFAULT 'customer', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS deposits (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, method TEXT NOT NULL, amount REAL NOT NULL, trx_id TEXT NOT NULL, note TEXT, status TEXT NOT NULL DEFAULT 'pending', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(user_id) REFERENCES users(id))");
$db->exec("CREATE TABLE IF NOT EXISTS orders (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, service_id TEXT NOT NULL, service_name TEXT NOT NULL, link TEXT NOT NULL, quantity INTEGER NOT NULL, unit_price REAL NOT NULL, total REAL NOT NULL, provider_order_id TEXT, status TEXT NOT NULL DEFAULT 'Pending', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(user_id) REFERENCES users(id))");

$adminEmail = getenv('ADMIN_EMAIL') ?: 'admin@sumonvai.local';
$adminPass = getenv('ADMIN_PASSWORD') ?: 'ChangeMe123!';
$minDeposit=50.0;
$paymentNumbers=['bKash'=>'01782242264','Nagad'=>'01887928771'];
$st = $db->prepare('SELECT id FROM users WHERE email=?'); $st->execute([$adminEmail]);
if (!$st->fetchColumn()) {
  $st=$db->prepare('INSERT INTO users(name,email,password,role) VALUES(?,?,?,?)');
  $st->execute(['Admin',$adminEmail,password_hash($adminPass,PASSWORD_DEFAULT),'admin']);
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function user(): ?array { return $_SESSION['user'] ?? null; }
function go(string $url): never { header('Location: '.$url); exit; }
function flash(?string $msg=null): ?string { if($msg!==null){$_SESSION['flash']=$msg; return null;} $x=$_SESSION['flash']??null; unset($_SESSION['flash']); return $x; }
function csrf(): string { if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function checkCsrf(): void { if(!hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'')) die('Invalid request'); }
function needLogin(): void { if(!user()) go('?page=login'); }
function needAdmin(): void { needLogin(); if((user()['role']??'')!=='admin') go('?page=home'); }

if($_SERVER['REQUEST_METHOD']==='POST') {
  checkCsrf(); $action=$_POST['action']??'';
  if($action==='register'){
    $name=trim($_POST['name']??''); $email=strtolower(trim($_POST['email']??'')); $pass=$_POST['password']??'';
    if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pass)<6){flash('সঠিক তথ্য দিন। পাসওয়ার্ড কমপক্ষে ৬ অক্ষরের হতে হবে।');go('?page=register');}
    try{$st=$db->prepare('INSERT INTO users(name,email,password) VALUES(?,?,?)');$st->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT)]);flash('Account তৈরি হয়েছে। এখন Login করুন।');go('?page=login');}catch(Throwable $x){flash('এই email দিয়ে account আগে থেকেই থাকতে পারে।');go('?page=register');}
  }
  if($action==='login'){
    $email=strtolower(trim((string)($_POST['email']??'')));$pass=(string)($_POST['password']??'');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$pass===''){flash('সঠিক email ও password দিন।');go('?page=login');}
    $st=$db->prepare('SELECT * FROM users WHERE lower(email)=lower(?) LIMIT 1');$st->execute([$email]);$u=$st->fetch(PDO::FETCH_ASSOC);
    if($u && password_verify($pass,(string)$u['password'])){
      if(password_needs_rehash((string)$u['password'],PASSWORD_DEFAULT)){$db->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($pass,PASSWORD_DEFAULT),$u['id']]);$u['password']=password_hash($pass,PASSWORD_DEFAULT);}
      session_regenerate_id(true);$_SESSION['user']=$u;go('?page=dashboard');
    }
    flash('Email অথবা password সঠিক নয়। Account না থাকলে Register করুন।');go('?page=login');
  }
  if($action==='logout'){session_destroy();go('?page=home');}
  if($action==='deposit'){
    needLogin();$amount=(float)($_POST['amount']??0);$method=trim((string)($_POST['method']??''));$trx=trim((string)($_POST['trx_id']??''));
    if($amount<$minDeposit){flash('সর্বনিম্ন Deposit ৳50।');go('?page=deposit');}
    if(!array_key_exists($method,$paymentNumbers)||$trx===''){flash('সঠিক payment method ও Transaction ID দিন।');go('?page=deposit');}
    $st=$db->prepare('INSERT INTO deposits(user_id,method,amount,trx_id,note) VALUES(?,?,?,?,?)');$st->execute([user()['id'],$method,$amount,$trx,trim((string)($_POST['note']??''))]);flash('Deposit request জমা হয়েছে। Admin approve করলে balance যোগ হবে।');go('?page=deposit');
  }
  if($action==='order'){
    needLogin();$sid=trim($_POST['service_id']??'');$link=trim($_POST['link']??'');$qty=(int)($_POST['quantity']??0);$name=trim($_POST['service_name']??'');$price=(float)($_POST['unit_price']??0);
    if($sid===''||$link===''||$qty<=0||$price<=0){flash('Order তথ্য সঠিক নয়।');go('?page=services');}
    $total=round($price*$qty/1000,2);$st=$db->prepare('SELECT balance FROM users WHERE id=?');$st->execute([user()['id']]);$bal=(float)$st->fetchColumn();
    if($bal<$total){flash('Balance কম। আগে Deposit করুন।');go('?page=deposit');}
    $api=new SmmsunClient(getenv('SMM_API_URL')?:'https://my.smmsun.com/api/v2',getenv('SMM_API_KEY')?:'');
    $resp=$api->addOrder($sid,$link,$qty);
    if(isset($resp['error'])){flash('Provider order failed: '.($resp['error']));go('?page=services');}
    $providerId=(string)($resp['order']??'');
    $db->beginTransaction();$st=$db->prepare('UPDATE users SET balance=balance-? WHERE id=?');$st->execute([$total,user()['id']]);$st=$db->prepare('INSERT INTO orders(user_id,service_id,service_name,link,quantity,unit_price,total,provider_order_id,status) VALUES(?,?,?,?,?,?,?,?,?)');$st->execute([user()['id'],$sid,$name,$link,$qty,$price,$total,$providerId,$providerId?'Pending':'Submitted']);$db->commit();flash('Order সফলভাবে পাঠানো হয়েছে। Order ID: '.($providerId?:'pending'));go('?page=orders');
  }
  if($action==='approve_deposit'){
    needAdmin();$id=(int)$_POST['id'];$st=$db->prepare('SELECT * FROM deposits WHERE id=?');$st->execute([$id]);$d=$st->fetch(PDO::FETCH_ASSOC);if($d&&$d['status']==='pending'){$db->beginTransaction();$db->prepare("UPDATE deposits SET status='approved' WHERE id=?")->execute([$id]);$db->prepare('UPDATE users SET balance=balance+? WHERE id=?')->execute([$d['amount'],$d['user_id']]);$db->commit();}go('?page=admin');
  }
  if($action==='reject_deposit'){needAdmin();$db->prepare("UPDATE deposits SET status='rejected' WHERE id=? AND status='pending'")->execute([(int)$_POST['id']]);go('?page=admin');}
}

$page=$_GET['page']??(user()?'dashboard':'login');
if(!user() && !in_array($page,['login','register'],true)){go('?page=login');}
$usd=(float)(getenv('USD_TO_BDT')?:122);$markup=(float)(getenv('MARKUP_BDT')?:10);
$services=[];$apiError='';
if(in_array($page,['services','home','dashboard'],true)){
  try{$api=new SmmsunClient(getenv('SMM_API_URL')?:'https://my.smmsun.com/api/v2',getenv('SMM_API_KEY')?:'');$r=$api->services();if(isset($r['error']))$apiError=$r['error'];else $services=is_array($r)?$r:[];}catch(Throwable $x){$apiError='Service API unavailable';}
}
if(user()){ $st=$db->prepare('SELECT * FROM users WHERE id=?');$st->execute([user()['id']]);$_SESSION['user']=$st->fetch(PDO::FETCH_ASSOC); }
$u=user();
function unitPrice(array $s,float $usd,float $markup): float{return (float)($s['rate']??0)*$usd+$markup;}
?><!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>সুমন ভাই Panel</title><style>
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f5f7fb;color:#182033}a{text-decoration:none;color:inherit}.nav{background:#111827;color:#fff;padding:14px 5%;display:flex;align-items:center;justify-content:space-between;gap:15px}.brand{font-size:21px;font-weight:800}.navlinks{display:flex;gap:10px;flex-wrap:wrap}.navlinks a,.navlinks button{padding:9px 12px;border-radius:9px;background:#1f2937;color:#fff;border:0;cursor:pointer}.wrap{max-width:1150px;margin:25px auto;padding:0 15px}.hero{background:#111827;color:white;border-radius:18px;padding:30px;margin-bottom:20px}.hero h1{margin:0 0 8px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:15px}.card{background:white;border:1px solid #e5e7eb;border-radius:15px;padding:17px;box-shadow:0 4px 14px #00000008}.btn{display:inline-block;background:#2563eb;color:#fff;border:0;border-radius:10px;padding:11px 15px;cursor:pointer}.btn.secondary{background:#374151}.btn.green{background:#059669}.input,select{width:100%;padding:11px;border:1px solid #d1d5db;border-radius:9px;margin:6px 0 12px}.price{font-size:22px;font-weight:800}.muted{color:#6b7280;font-size:13px}.table{width:100%;border-collapse:collapse;background:white;border-radius:12px;overflow:hidden}.table th,.table td{padding:11px;border-bottom:1px solid #eee;text-align:left;font-size:14px}.badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#eef2ff;font-size:12px}.form{max-width:500px;margin:30px auto}.alert{background:#fff7ed;border:1px solid #fed7aa;padding:12px;border-radius:10px;margin-bottom:15px}.balance{font-size:28px;font-weight:900}.toprow{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:15px}.service-card{display:flex;flex-direction:column;gap:8px}.service-card .bottom{margin-top:auto;display:flex;justify-content:space-between;align-items:center;gap:8px}.danger{color:#b91c1c}.small{font-size:12px}
.category-bar{display:flex;gap:9px;overflow-x:auto;padding:3px 0 14px;margin-bottom:3px;scrollbar-width:thin}.cat{flex:0 0 auto;padding:10px 14px;border:1px solid #d1d5db;background:#fff;color:#182033;border-radius:12px;cursor:pointer;font-weight:700}.cat.active{background:#2563eb;color:#fff;border-color:#2563eb}.payment-box{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px;margin:12px 0 18px}.payment-box>div{margin-bottom:10px}.payment-box>div:last-child{margin-bottom:0}.payment-number{font-size:21px;font-weight:900;letter-spacing:.5px;margin-top:3px} @media(max-width:600px){.wrap{padding:0 10px}.nav{padding:12px 4%}.brand{font-size:18px}.navlinks{gap:6px}.navlinks a,.navlinks button{padding:8px 10px}.category-bar{margin-left:-2px;margin-right:-2px}.card{padding:14px}.hero{padding:22px}.toprow{align-items:flex-start;flex-direction:column}.toprow .muted{align-self:flex-start}}</style></head><body><nav class="nav"><a class="brand" href="?page=home">সুমন ভাই Panel</a><div class="navlinks"><?php if($u):?><a href="?page=dashboard">Dashboard</a><a href="?page=services">Services</a><a href="?page=orders">Orders</a><a href="?page=deposit">Deposit</a><?php if($u['role']==='admin'):?><a href="?page=admin">Admin</a><?php endif;?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="logout"><button>Logout</button></form><?php else:?><a href="?page=login">Login</a><a href="?page=register">Register</a><?php endif;?></div></nav><main class="wrap"><?php if($m=flash()):?><div class="alert"><?=e($m)?></div><?php endif;?>
<?php if($page==='home'):?><section class="hero"><h1>সুমন ভাই Panel</h1><p>Login করুন, তারপর আপনার Dashboard থেকে Services, Categories, Deposit ও Orders ব্যবহার করুন।</p><a class="btn" href="?page=services">Services দেখুন</a></section><div class="grid"><div class="card"><h3>Live Services</h3><p>Provider API থেকে সার্ভিস লোড হয়।</p></div><div class="card"><h3>Wallet</h3><p>Deposit করে balance যোগ করুন। সর্বনিম্ন Deposit ৳50।</p></div><div class="card"><h3>Orders</h3><p>আপনার order history এখানেই থাকবে।</p></div></div>
<?php elseif($page==='login'||$page==='register'):?><div class="card form"><h2><?=$page==='login'?'Login':'Create account'?></h2><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="<?=$page==='login'?'login':'register'?>"><?php if($page==='register'):?><label>Name</label><input class="input" name="name" required><?php endif;?><label>Email</label><input class="input" type="email" name="email" required><label>Password</label><input class="input" type="password" name="password" required><?php if($page==='register'):?><p class="muted">কমপক্ষে ৬ অক্ষর।</p><?php endif;?><button class="btn"><?=$page==='login'?'Login':'Register'?></button></form></div>
<?php elseif($page==='dashboard'): needLogin();?><div class="toprow"><h2>Dashboard</h2><a class="btn green" href="?page=deposit">+ Deposit</a></div><div class="grid"><div class="card"><div class="muted">Available Balance</div><div class="balance">৳<?=number_format((float)$u['balance'],2)?></div></div><div class="card"><div class="muted">Account</div><h3><?=e($u['name'])?></h3><div class="muted"><?=e($u['email'])?></div></div></div>
<?php elseif($page==='services'):?>
<div class="toprow"><h2>Services</h2><span class="muted">USD/BDT: <?=e((string)$usd)?> • Markup: ৳<?=number_format($markup,2)?></span></div>
<?php if($apiError):?><div class="alert">API: <?=e($apiError)?></div><?php endif;?>
<div class="category-bar" id="categoryBar">
  <button class="cat active" data-cat="all">🌐 All</button>
  <button class="cat" data-cat="youtube">▶️ YouTube</button>
  <button class="cat" data-cat="facebook">f Facebook</button>
  <button class="cat" data-cat="instagram">◎ Instagram</button>
  <button class="cat" data-cat="tiktok">♪ TikTok</button>
  <button class="cat" data-cat="telegram">✈️ Telegram</button>
  <button class="cat" data-cat="twitter">𝕏 X</button>
  <button class="cat" data-cat="linkedin">in LinkedIn</button>
  <button class="cat" data-cat="discord">◉ Discord</button>
  <button class="cat" data-cat="spotify">● Spotify</button>
  <button class="cat" data-cat="twitch">◉ Twitch</button>
  <button class="cat" data-cat="soundcloud">☁ SoundCloud</button>
  <button class="cat" data-cat="other">＋ Other</button>
</div>
<input id="search" class="input" placeholder="Service search...">
<div class="grid" id="services">
<?php foreach($services as $s):
  $p=unitPrice($s,$usd,$markup);
  $raw=strtolower(($s['name']??'').' '.($s['category']??'').' '.($s['service']??''));
  $cat='other';
  $map=['youtube'=>['youtube','youtu.be'],'facebook'=>['facebook','fb'],'instagram'=>['instagram','ig'],'tiktok'=>['tiktok'],'telegram'=>['telegram','tg'],'twitter'=>['twitter',' x '],'linkedin'=>['linkedin'],'discord'=>['discord'],'spotify'=>['spotify'],'twitch'=>['twitch'],'soundcloud'=>['soundcloud']];
  foreach($map as $ck=>$words){foreach($words as $w){if(str_contains($raw,$w)){$cat=$ck;break 2;}}}
?>
<div class="card service-card service-item" data-search="<?=e($raw)?>" data-cat="<?=e($cat)?>">
 <div><span class="badge">ID <?=e((string)($s['service']??''))?></span> <span class="badge"><?=e((string)($s['category']??'Other'))?></span></div>
 <strong><?=e((string)($s['name']??'Service'))?></strong>
 <div class="muted">Min <?=e((string)($s['min']??''))?> • Max <?=e((string)($s['max']??''))?></div>
 <div class="bottom"><div><div class="price">৳<?=number_format($p,2)?></div><div class="muted">per 1,000</div></div><button class="btn" onclick='openOrder(<?=json_encode((string)($s['service']??''))?>,<?=json_encode((string)($s['name']??''))?>,<?=json_encode((float)$p)?>,<?=json_encode((int)($s['min']??1))?>,<?=json_encode((int)($s['max']??1000000))?>)'>Order</button></div>
</div>
<?php endforeach;?></div>
<div id="modal" style="display:none;position:fixed;inset:0;background:#0008;padding:20px;z-index:10"><div class="card" style="max-width:500px;margin:7vh auto"><div class="toprow"><h3>Place Order</h3><button onclick="closeOrder()">✕</button></div><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="order"><input type="hidden" name="service_id" id="sid"><input type="hidden" name="service_name" id="sname"><input type="hidden" name="unit_price" id="price"><label>Service</label><input class="input" id="slabel" disabled><label>Link</label><input class="input" name="link" placeholder="https://..." required><label>Quantity</label><input class="input" type="number" name="quantity" id="qty" required oninput="calc()"><div class="muted">Total: <b id="total">৳0.00</b></div><br><button class="btn green">Confirm Order</button></form></div></div>
<script>
const search=document.getElementById('search'), cards=[...document.querySelectorAll('.service-item')];let activeCat='all';
function renderServices(){const q=(search?.value||'').toLowerCase();cards.forEach(x=>{const okCat=activeCat==='all'||x.dataset.cat===activeCat;const okSearch=x.dataset.search.includes(q);x.style.display=okCat&&okSearch?'flex':'none'})}
if(search)search.oninput=renderServices;document.querySelectorAll('.cat').forEach(b=>b.onclick=()=>{document.querySelectorAll('.cat').forEach(x=>x.classList.remove('active'));b.classList.add('active');activeCat=b.dataset.cat;renderServices()});
function openOrder(id,name,p,min,max){document.getElementById('modal').style.display='block';document.getElementById('sid').value=id;document.getElementById('sname').value=name;document.getElementById('price').value=p;document.getElementById('slabel').value=name+' (#'+id+')';let q=document.getElementById('qty');q.min=min;q.max=max;q.value=min;calc()}
function closeOrder(){document.getElementById('modal').style.display='none'}function calc(){let q=+document.getElementById('qty').value||0,p=+document.getElementById('price').value||0;document.getElementById('total').innerText='৳'+(q*p/1000).toFixed(2)}
</script>
<?php elseif($page==='deposit'):needLogin();?><div class="card form"><h2>Deposit Request</h2><div class="payment-box"><div><b>bKash</b><div class="payment-number">01782242264</div></div><div><b>Nagad</b><div class="payment-number">01887928771</div></div><div class="muted">Send Money করার পর Transaction ID দিন। সর্বনিম্ন Deposit: <b>৳50</b></div></div><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="deposit"><label>Payment Method</label><select name="method" id="paymentMethod" required><option value="bKash">bKash — 01782242264</option><option value="Nagad">Nagad — 01887928771</option></select><label>Amount (BDT)</label><input class="input" type="number" min="50" step="0.01" name="amount" required><div class="muted">Minimum deposit ৳50</div><label>Transaction ID</label><input class="input" name="trx_id" required><label>Note</label><textarea class="input" name="note"></textarea><button class="btn green">Submit Deposit</button></form></div>
<?php elseif($page==='orders'):needLogin();$st=$db->prepare('SELECT * FROM orders WHERE user_id=? ORDER BY id DESC');$st->execute([$u['id']]);$rows=$st->fetchAll(PDO::FETCH_ASSOC);?><div class="toprow"><h2>My Orders</h2><a class="btn" href="?page=services">New Order</a></div><div style="overflow:auto"><table class="table"><tr><th>ID</th><th>Service</th><th>Qty</th><th>Total</th><th>Status</th><th>Date</th></tr><?php foreach($rows as $r):?><tr><td>#<?=e((string)$r['id'])?><?php if($r['provider_order_id']):?><div class="small">Provider: <?=e($r['provider_order_id'])?></div><?php endif;?></td><td><?=e($r['service_name'])?></td><td><?=e((string)$r['quantity'])?></td><td>৳<?=number_format((float)$r['total'],2)?></td><td><span class="badge"><?=e($r['status'])?></span></td><td><?=e($r['created_at'])?></td></tr><?php endforeach;?></table></div>
<?php elseif($page==='admin'):needAdmin();$deps=$db->query("SELECT d.*,u.name,u.email FROM deposits d JOIN users u ON u.id=d.user_id ORDER BY d.id DESC")->fetchAll(PDO::FETCH_ASSOC);$orders=$db->query("SELECT o.*,u.name FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);?><div class="toprow"><h2>Admin Panel</h2><span class="muted"><?=e($adminEmail)?></span></div><h3>Deposit Requests</h3><div style="overflow:auto"><table class="table"><tr><th>User</th><th>Method</th><th>Amount</th><th>TRX</th><th>Status</th><th>Action</th></tr><?php foreach($deps as $d):?><tr><td><?=e($d['name'])?><div class="small"><?=e($d['email'])?></div></td><td><?=e($d['method'])?></td><td>৳<?=number_format((float)$d['amount'],2)?></td><td><?=e($d['trx_id'])?></td><td><?=e($d['status'])?></td><td><?php if($d['status']==='pending'):?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="approve_deposit"><input type="hidden" name="id" value="<?=$d['id']?>"><button class="btn green">Approve</button></form> <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="reject_deposit"><input type="hidden" name="id" value="<?=$d['id']?>"><button class="btn secondary">Reject</button></form><?php endif;?></td></tr><?php endforeach;?></table></div><h3>Recent Orders</h3><div style="overflow:auto"><table class="table"><tr><th>ID</th><th>User</th><th>Service</th><th>Qty</th><th>Total</th><th>Provider</th></tr><?php foreach($orders as $o):?><tr><td>#<?=$o['id']?></td><td><?=e($o['name'])?></td><td><?=e($o['service_name'])?></td><td><?=$o['quantity']?></td><td>৳<?=number_format((float)$o['total'],2)?></td><td><?=e($o['provider_order_id']?:'-')?></td></tr><?php endforeach;?></table></div>
<?php else:go('?page=home');endif;?></main></body></html>
