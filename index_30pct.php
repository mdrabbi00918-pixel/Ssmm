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
function unitPrice(array $s,float $usd,float $markup): float{
    $base=(float)($s['rate']??0)*$usd;
    return $base*1.30;
}
?><!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>সুমন ভাই Panel</title><style>
:root{--primary:#6d5dfc;--primary2:#8b5cf6;--accent:#06b6d4;--dark:#101828;--text:#182033;--muted:#667085;--surface:#ffffff;--line:#e7eaf0;--shadow:0 18px 50px rgba(16,24,40,.09)}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{margin:0;min-height:100vh;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--text);background:radial-gradient(circle at 10% 0%,rgba(109,93,252,.12),transparent 28%),radial-gradient(circle at 90% 10%,rgba(6,182,212,.10),transparent 25%),linear-gradient(180deg,#f8f9ff 0%,#f4f7fb 100%)}
a{text-decoration:none;color:inherit}
button,input,select,textarea{font:inherit}
.nav{position:sticky;top:0;z-index:50;background:rgba(16,24,40,.92);backdrop-filter:blur(16px);color:#fff;padding:14px 5%;display:flex;align-items:center;justify-content:space-between;gap:15px;box-shadow:0 8px 30px rgba(16,24,40,.18)}
.brand{display:flex;align-items:center;gap:10px;font-size:21px;font-weight:900;letter-spacing:-.3px}.brand:before{content:"SV";display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,var(--primary),var(--accent));box-shadow:0 8px 20px rgba(109,93,252,.35);font-size:12px;font-weight:900}
.navlinks{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.navlinks a,.navlinks button{padding:9px 13px;border-radius:10px;background:rgba(255,255,255,.09);color:#fff;border:1px solid rgba(255,255,255,.08);cursor:pointer;transition:.2s}.navlinks a:hover,.navlinks button:hover{background:rgba(255,255,255,.17);transform:translateY(-1px)}
.wrap{max-width:1180px;margin:28px auto;padding:0 16px}.hero{position:relative;overflow:hidden;background:linear-gradient(135deg,#141b34 0%,#24204b 48%,#0f7890 100%);color:#fff;border-radius:24px;padding:42px;margin-bottom:22px;box-shadow:0 24px 60px rgba(36,32,75,.22)}.hero:after{content:"";position:absolute;width:260px;height:260px;border-radius:50%;right:-80px;top:-100px;background:rgba(255,255,255,.12)}.hero h1{position:relative;z-index:1;margin:0 0 10px;font-size:clamp(28px,5vw,46px);letter-spacing:-1px}.hero p,.hero a{position:relative;z-index:1}.hero p{max-width:680px;color:rgba(255,255,255,.82);line-height:1.7}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:17px}.card{background:rgba(255,255,255,.94);border:1px solid rgba(231,234,240,.9);border-radius:20px;padding:20px;box-shadow:var(--shadow);transition:transform .2s,box-shadow .2s}.card:hover{transform:translateY(-2px);box-shadow:0 22px 55px rgba(16,24,40,.12)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;border:0;border-radius:11px;padding:11px 16px;cursor:pointer;font-weight:800;box-shadow:0 8px 18px rgba(109,93,252,.22);transition:.2s}.btn:hover{transform:translateY(-1px);box-shadow:0 12px 24px rgba(109,93,252,.28)}.btn.secondary{background:#344054;box-shadow:none}.btn.green{background:linear-gradient(135deg,#059669,#10b981);box-shadow:0 8px 18px rgba(16,185,129,.2)}
.input,select,textarea{width:100%;padding:13px 14px;border:1px solid #d9dee8;border-radius:12px;margin:7px 0 14px;background:#fff;color:var(--text);outline:none;transition:.2s}.input:focus,select:focus,textarea:focus{border-color:var(--primary);box-shadow:0 0 0 4px rgba(109,93,252,.11)}label{font-weight:700;font-size:14px}.price{font-size:24px;font-weight:900}.muted{color:var(--muted);font-size:13px}.table{width:100%;border-collapse:separate;border-spacing:0;background:white;border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(16,24,40,.05)}.table th,.table td{padding:13px;border-bottom:1px solid #eef0f4;text-align:left;font-size:14px}.table th{background:#f8f9fc;font-weight:800}.table tr:last-child td{border-bottom:0}.badge{display:inline-block;padding:5px 9px;border-radius:999px;background:#eef2ff;color:#5146c8;font-size:12px;font-weight:800}.form{max-width:500px;margin:55px auto}.form h2{font-size:30px;margin:0 0 7px}.form:before{content:"Premium Panel";display:inline-block;margin-bottom:12px;padding:6px 10px;border-radius:999px;background:linear-gradient(135deg,#ede9fe,#cffafe);color:#5146c8;font-size:12px;font-weight:900}.alert{background:linear-gradient(135deg,#fff8ed,#fff3e0);border:1px solid #fed7aa;color:#9a3412;padding:13px 15px;border-radius:13px;margin-bottom:16px;box-shadow:0 8px 20px rgba(234,88,12,.06)}.balance{font-size:32px;font-weight:950;background:linear-gradient(135deg,var(--primary),var(--accent));-webkit-background-clip:text;background-clip:text;color:transparent}.toprow{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:17px}.toprow h2{margin:0;font-size:28px}.service-card{display:flex;flex-direction:column;gap:9px}.service-card .bottom{margin-top:auto;display:flex;justify-content:space-between;align-items:center;gap:8px}.danger{color:#b91c1c}.small{font-size:12px}
.category-bar{display:flex;gap:9px;overflow-x:auto;padding:3px 0 14px;margin-bottom:3px;scrollbar-width:thin}.cat{flex:0 0 auto;padding:10px 14px;border:1px solid #dfe3eb;background:#fff;color:#344054;border-radius:12px;cursor:pointer;font-weight:800;transition:.2s;box-shadow:0 5px 14px rgba(16,24,40,.04)}.cat:hover{border-color:#b8b1ff;transform:translateY(-1px)}.cat.active{background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;border-color:transparent;box-shadow:0 8px 18px rgba(109,93,252,.22)}
.payment-box{background:linear-gradient(135deg,#ecfdf5,#eff6ff);border:1px solid #c7f0df;border-radius:16px;padding:16px;margin:12px 0 18px}.payment-box>div{margin-bottom:11px}.payment-box>div:last-child{margin-bottom:0}.payment-number{font-size:22px;font-weight:950;letter-spacing:.5px;margin-top:3px;color:#0f766e}
.password-wrap{position:relative}.password-wrap .input{padding-right:50px}.password-toggle{position:absolute;right:8px;top:50%;transform:translateY(-50%);width:38px;height:38px;border:0;border-radius:10px;background:#f2f4f7;color:#667085;cursor:pointer;display:grid;place-items:center;font-size:18px}.password-toggle:hover{background:#e9e7ff;color:#5146c8}.password-toggle:focus{outline:3px solid rgba(109,93,252,.14)}
#modal{backdrop-filter:blur(6px)!important}#modal>.card{border:0;box-shadow:0 30px 90px rgba(0,0,0,.28)}
@media(max-width:700px){.nav{padding:11px 4%;align-items:flex-start}.brand{font-size:17px}.brand:before{width:34px;height:34px}.navlinks{justify-content:flex-end;gap:5px}.navlinks a,.navlinks button{padding:8px 9px;font-size:12px}.wrap{padding:0 11px;margin:20px auto}.hero{padding:28px 22px;border-radius:20px}.card{padding:16px}.form{margin:28px auto}.form h2{font-size:26px}.toprow{align-items:flex-start;flex-direction:column}.toprow .muted{align-self:flex-start}.table th,.table td{padding:10px;font-size:13px}}
</style></head><body><nav class="nav"><a class="brand" href="?page=home">সুমন ভাই Panel</a><div class="navlinks"><?php if($u):?><a href="?page=dashboard">Dashboard</a><a href="?page=services">Services</a><a href="?page=orders">Orders</a><a href="?page=deposit">Deposit</a><?php if($u['role']==='admin'):?><a href="?page=admin">Admin</a><?php endif;?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="logout"><button>Logout</button></form><?php else:?><a href="?page=login">Login</a><a href="?page=register">Register</a><?php endif;?></div></nav><main class="wrap"><?php if($m=flash()):?><div class="alert"><?=e($m)?></div><?php endif;?>
<?php if($page==='home'):?><section class="hero"><h1>সুমন ভাই Panel</h1><p>Login করুন, তারপর আপনার Dashboard থেকে Services, Categories, Deposit ও Orders ব্যবহার করুন।</p><a class="btn" href="?page=services">Services দেখুন</a></section><div class="grid"><div class="card"><h3>Live Services</h3><p>Provider API থেকে সার্ভিস লোড হয়।</p></div><div class="card"><h3>Wallet</h3><p>Deposit করে balance যোগ করুন। সর্বনিম্ন Deposit ৳50।</p></div><div class="card"><h3>Orders</h3><p>আপনার order history এখানেই থাকবে।</p></div></div>
<?php elseif($page==='login'||$page==='register'):?><div class="card form"><div class="muted" style="margin-bottom:6px"><?= $page==='login'?'Welcome back 👋':'Start your journey ✨' ?></div><h2><?=$page==='login'?'Login':'Create account'?></h2><p class="muted" style="margin-top:0;margin-bottom:22px"><?=$page==='login'?'আপনার account-এ নিরাপদে Login করুন।':'নতুন account তৈরি করে panel ব্যবহার শুরু করুন।'?></p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="<?=$page==='login'?'login':'register'?>"><?php if($page==='register'):?><label>Name</label><input class="input" name="name" placeholder="আপনার নাম" autocomplete="name" required><?php endif;?><label>Email</label><input class="input" type="email" name="email" placeholder="you@example.com" autocomplete="email" required><label>Password</label><div class="password-wrap"><input class="input" id="passwordField" type="password" name="password" placeholder="আপনার password লিখুন" autocomplete="<?=$page==='login'?'current-password':'new-password'?>" required><button class="password-toggle" type="button" onclick="togglePassword('passwordField',this)" aria-label="Show password" title="Show password">👁️</button></div><?php if($page==='register'):?><p class="muted" style="margin-top:-5px">কমপক্ষে ৬ অক্ষর।</p><?php endif;?><button class="btn" style="width:100%;margin-top:5px"><?=$page==='login'?'Login →':'Create Account →'?></button></form><div style="text-align:center;margin-top:18px" class="muted"><?php if($page==='login'):?>নতুন account? <a href="?page=register" style="color:#5b4ee8;font-weight:800">Register করুন</a><?php else:?>আগেই account আছে? <a href="?page=login" style="color:#5b4ee8;font-weight:800">Login করুন</a><?php endif;?></div></div>
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
<?php else:go('?page=home');endif;?><script>function togglePassword(id,btn){const input=document.getElementById(id);if(!input)return;const show=input.type==='password';input.type=show?'text':'password';btn.textContent=show?'🙈':'👁️';btn.setAttribute('aria-label',show?'Hide password':'Show password');btn.title=show?'Hide password':'Show password';}</script></main></body></html>
