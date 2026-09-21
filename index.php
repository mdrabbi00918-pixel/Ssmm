<?php
declare(strict_types=1);
$secureCookie = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'));
session_set_cookie_params(['lifetime'=>60*60*24*30,'path'=>'/','secure'=>$secureCookie,'httponly'=>true,'samesite'=>'Lax']);
session_start();
require __DIR__ . '/JsonStore.php';
require __DIR__ . '/SmmsunClient.php';
$dbDir=getenv('DB_DIR')?:__DIR__.'/data';
$store=new JsonStore($dbDir);
$adminEmail=getenv('ADMIN_EMAIL')?:'admin@sumonvai.local';
$adminPass=getenv('ADMIN_PASSWORD')?:'ChangeMe123!';
$minDeposit=50.0;$usdtRate=125.0;$paymentNumbers=['bKash'=>$store->setting('payment_bkash','01782242264')??'01782242264','Nagad'=>$store->setting('payment_nagad','01887928771')??'01887928771','Binance UID'=>'828400982'];
// Display currency rates are admin-configurable; BDT remains the base currency.
$usdToBdt=(float)($store->setting('currency_usd_bdt','130')??'130');
$inrPerUsd=(float)($store->setting('currency_inr_usd','102')??'102');
if($usdToBdt<=0)$usdToBdt=130.0;if($inrPerUsd<=0)$inrPerUsd=102.0;
$adminMarker=sys_get_temp_dir().'/trusted_bazaar_admin_v1';
if(!is_file($adminMarker) || (time()-(int)@filemtime($adminMarker))>3600){
    if(!$store->findUserByEmail($adminEmail)){$store->createUser('Admin',$adminEmail,password_hash($adminPass,PASSWORD_DEFAULT),'admin');}
    @touch($adminMarker);
}
function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function user():?array{return $_SESSION['user']??null;}
function go(string $url):never{header('Location: '.$url);exit;}
function flash(?string $msg=null):?string{if($msg!==null){$_SESSION['flash']=$msg;return null;}$x=$_SESSION['flash']??null;unset($_SESSION['flash']);return $x;}
function csrf():string{if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(16));return $_SESSION['csrf'];}
function checkCsrf():void{if(!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??''))die('Invalid request');}
function setRememberCookie(string $token,int $expires):void{global $secureCookie;setcookie('sv_remember',$token,['expires'=>$expires,'path'=>'/','secure'=>$secureCookie,'httponly'=>true,'samesite'=>'Lax']);}
function clearRememberCookie():void{global $secureCookie;setcookie('sv_remember','',['expires'=>time()-3600,'path'=>'/','secure'=>$secureCookie,'httponly'=>true,'samesite'=>'Lax']);}
function restoreRememberedUser(JsonStore $store):void{if(user()||empty($_COOKIE['sv_remember']))return;$hash=hash('sha256',(string)$_COOKIE['sv_remember']);$u=$store->restoreToken($hash);if($u){session_regenerate_id(true);$_SESSION['user']=$u;$store->deleteRememberHash($hash);$token=bin2hex(random_bytes(32));$store->addRemember((int)$u['id'],hash('sha256',$token),time()+60*60*24*30);setRememberCookie($token,time()+60*60*24*30);}else clearRememberCookie();}
restoreRememberedUser($store);
function needLogin():void{if(!user())go('?page=login');}
function needAdmin():void{needLogin();if((user()['role']??'')!=='admin')go('?page=home');}
if($_SERVER['REQUEST_METHOD']==='POST'){
 $action=$_POST['action']??'';
 // Login/Register do not need CSRF protection; authenticated state-changing actions remain protected.
 if(!in_array($action,['login','register'],true)) checkCsrf();
 if($action==='register'){ $name=trim((string)($_POST['name']??''));$email=strtolower(trim((string)($_POST['email']??'')));$pass=(string)($_POST['password']??'');if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pass)<6){flash('সঠিক তথ্য দিন। পাসওয়ার্ড কমপক্ষে ৬ অক্ষরের হতে হবে।');go('?page=register');}try{$store->createUser($name,$email,password_hash($pass,PASSWORD_DEFAULT));flash('Account তৈরি হয়েছে। এখন Login করুন।');go('?page=login');}catch(Throwable $x){flash('এই email দিয়ে account আগে থেকেই থাকতে পারে।');go('?page=register');}}
 if($action==='login'){ $email=strtolower(trim((string)($_POST['email']??'')));$pass=(string)($_POST['password']??'');$u=$store->findUserByEmail($email);if($u&&password_verify($pass,(string)$u['password'])){if(password_needs_rehash((string)$u['password'],PASSWORD_DEFAULT)){$new=password_hash($pass,PASSWORD_DEFAULT);$u=$store->updateUser((int)$u['id'],['password'=>$new])??$u;}$store->rememberForUser((int)$u['id']);session_regenerate_id(true);$_SESSION['user']=$u;$token=bin2hex(random_bytes(32));$store->addRemember((int)$u['id'],hash('sha256',$token),time()+60*60*24*30);setRememberCookie($token,time()+60*60*24*30);go('?page=dashboard');}flash('Email অথবা password সঠিক নয়। Account না থাকলে Register করুন।');go('?page=login');}
 if($action==='logout'){if(user())$store->rememberForUser((int)user()['id']);clearRememberCookie();$_SESSION=[];session_destroy();go('?page=home');}
 if($action==='deposit'){needLogin();$amount=(float)($_POST['amount']??0);$method=trim((string)($_POST['method']??''));$trx=trim((string)($_POST['trx_id']??''));if($amount<$minDeposit){flash('সর্বনিম্ন Deposit ৳50।');go('?page=deposit');}if(!array_key_exists($method,$paymentNumbers)||$trx===''){flash('সঠিক payment method ও Transaction ID দিন।');go('?page=deposit');}try{$store->addDeposit((int)user()['id'],$method,$amount,$trx,trim((string)($_POST['note']??'')));}catch(RuntimeException $x){if($x->getMessage()==='duplicate_trx'){flash('এই Transaction ID দিয়ে আগে একটি payment request করা হয়েছে। অন্য Transaction ID দিন।');go('?page=deposit');}throw $x;}flash('Deposit request জমা হয়েছে। Admin approve করলে balance যোগ হবে।');go('?page=deposit');}
 if($action==='buy_digital'){needLogin();$pid=(int)($_POST['product_id']??0);$p=$store->buyDigitalProduct((int)user()['id'],$pid);if(!$p){flash('Balance কম অথবা item unavailable।');go('?page=supercell');}flash('Item সফলভাবে কেনা হয়েছে। এখন My Supercell Items থেকে লিংক কপি করতে পারবেন।');go('?page=supercell');}
 if($action==='save_digital'){needAdmin();$id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));$desc=trim((string)($_POST['description']??''));$img=trim((string)($_POST['image_url']??''));$link=trim((string)($_POST['access_link']??''));$price=(float)($_POST['price']??0);$priceUsd=(float)($_POST['price_usd']??0);if($name===''||$link===''||$price<=0||$priceUsd<=0){flash('Item name, link ও valid price দিন।');go('?page=admin#supercell');}$active=true;$oldProduct=null;if($id){$oldProduct=$store->digitalProduct($id);if(!$oldProduct){flash('Item পাওয়া যায়নি।');go('?page=admin#supercell');}$active=!empty($oldProduct['active']);if(!$img)$img=trim((string)($oldProduct['image_url']??''));}
  if(isset($_FILES['image_file'])&&($_FILES['image_file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$f=$_FILES['image_file'];if(($f['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK||($f['size']??0)>5*1024*1024){flash('ছবির সাইজ সর্বোচ্চ 5MB হতে পারবে।');go('?page=admin#supercell');}$tmp=(string)$f['tmp_name'];$mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);$allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];if(!isset($allowed[$mime])||@getimagesize($tmp)===false){flash('শুধু JPG, PNG বা WEBP ছবি আপলোড করুন।');go('?page=admin#supercell');}$dir=__DIR__.'/uploads/supercell';if(!is_dir($dir)&&!@mkdir($dir,0775,true)){flash('Image upload folder তৈরি করা যাচ্ছে না।');go('?page=admin#supercell');}$filename='sc_'.bin2hex(random_bytes(12)).'.'.$allowed[$mime];if(!move_uploaded_file($tmp,$dir.'/'.$filename)){flash('ছবি আপলোড করা যায়নি।');go('?page=admin#supercell');}$img='uploads/supercell/'.$filename;}
  $store->saveDigitalProduct($id?:null,$name,$desc,$img,$link,$price,$priceUsd,$active);flash($id?'সুপারসেল গেম আইটেম আপডেট হয়েছে।':'সুপারসেল গেম আইটেম যোগ হয়েছে।');go('?page=admin#supercell');}
 if($action==='delete_digital'){needAdmin();$store->deleteDigitalProduct((int)($_POST['id']??0));flash('Item মুছে ফেলা হয়েছে।');go('?page=admin#supercell');}
 if($action==='order'){needLogin();$sid=trim((string)($_POST['service_id']??''));$link=trim((string)($_POST['link']??''));$qty=(int)($_POST['quantity']??0);$name=trim((string)($_POST['service_name']??''));$allowedCustomerServiceIds=array_fill_keys(['1086','1076','1762','104','105','1367','1368','664','665','3901','3902','851','854','1049','242','125','133','1205','265','234','463','448','1050','474','462','476','3847','531','537','2600','629','3313','2194','2945','369','1722','1726','9445','9453','1849'],true);if(!isset($allowedCustomerServiceIds[$sid])){flash('এই service বর্তমানে Customer Panel-এ available নয়।');go('?page=services');}$override=$store->price($sid);$price=$override!==null?adjustedSelectedServicePrice((float)$override,$sid):(float)($_POST['unit_price']??0);if($sid===''||$link===''||$qty<=0||$price<=0){flash('Order তথ্য সঠিক নয়।');go('?page=services');}$total=round($price*$qty/1000,2);$current=$store->findUser((int)user()['id']);if(!$current||((float)$current['balance']<$total)){flash('Balance কম। আগে Deposit করুন।');go('?page=deposit');}$api=new SmmsunClient(getenv('SMM_API_URL')?:'https://my.smmsun.com/api/v2',getenv('SMM_API_KEY')?:'');$resp=$api->addOrder($sid,$link,$qty);if(isset($resp['error'])){flash('Provider order failed: '.($resp['error']));go('?page=services');}$providerId=(string)($resp['order']??'');$order=$store->createOrder((int)user()['id'],$sid,$name,$link,$qty,$price,$total,$providerId,$providerId?'Pending':'Submitted');if(!$order){flash('Balance কম। Order তৈরি হয়নি।');go('?page=deposit');}flash('Order সফলভাবে পাঠানো হয়েছে। Order ID: '.($providerId?:'pending'));go('?page=orders');}
 if($action==='save_service_price'){needAdmin();$sid=trim((string)($_POST['service_id']??''));$price=(float)($_POST['price']??0);if($sid===''||$price<=0){flash('Valid price দিন।');go('?page=admin');}$store->setPrice($sid,$price);flash('Service price আপডেট হয়েছে।');go('?page=admin');}
 if($action==='delete_service_price'){needAdmin();$store->deletePrice(trim((string)($_POST['service_id']??'')));flash('Custom price reset হয়েছে।');go('?page=admin');}
 if($action==='save_payment_settings'){needAdmin();$bk=trim((string)($_POST['bkash']??''));$ng=trim((string)($_POST['nagad']??''));if(!preg_match('/^01\d{9}$/',$bk)||!preg_match('/^01\d{9}$/',$ng)){flash('সঠিক ১১ সংখ্যার bKash ও Nagad নম্বর দিন।');go('?page=admin#payments');}$store->setSetting('payment_bkash',$bk);$store->setSetting('payment_nagad',$ng);flash('Payment number সফলভাবে আপডেট হয়েছে।');go('?page=admin#payments');}
 if($action==='save_currency_settings'){needAdmin();$newUsd=(float)($_POST['usd_to_bdt']??0);$newInr=(float)($_POST['inr_per_usd']??0);if($newUsd<=0||$newInr<=0){flash('সঠিক USD ও INR rate দিন।');go('?page=admin#currency');}$store->setSetting('currency_usd_bdt',number_format($newUsd,6,'.',''));$store->setSetting('currency_inr_usd',number_format($newInr,6,'.',''));flash('Currency rate সফলভাবে আপডেট হয়েছে।');go('?page=admin#currency');}
 if($action==='set_user_role'){needAdmin();$uid=(int)($_POST['user_id']??0);$role=($_POST['role']??'customer')==='admin'?'admin':'customer';if($uid===(int)user()['id']&&$role!=='admin'){flash('নিজের admin access সরানো যাবে না।');go('?page=admin#users');}$target=$store->findUser($uid);if(!$target){flash('User পাওয়া যায়নি।');go('?page=admin#users');}$store->updateUser($uid,['role'=>$role]);flash($role==='admin'?'User-কে Admin করা হয়েছে।':'Admin access সরিয়ে Customer করা হয়েছে।');go('?page=admin#users');}
 if($action==='approve_deposit'){needAdmin();$store->approveDeposit((int)($_POST['id']??0));go('?page=admin');}
 if($action==='reject_deposit'){needAdmin();$store->rejectDeposit((int)($_POST['id']??0));go('?page=admin');}
}
$page=$_GET['page']??'home';
// Keep already-authenticated customers inside the panel. A valid remember cookie
// is restored above, so returning visitors can skip the login page.
if(user() && in_array($page,['home','login','register'],true)) go('?page=dashboard');
if(!user()&&!in_array($page,['login','register'],true))go('?page=login');
$usd=(float)(getenv('USD_TO_BDT')?:122);$markup=(float)(getenv('MARKUP_BDT')?:10);$services=[];$apiError='';
$allowedCustomerServiceIds=array_fill_keys(['1086','1076','1762','104','105','1367','1368','664','665','3901','3902','851','854','1049','242','125','133','1205','265','234','463','448','1050','474','462','476','3847','531','537','2600','629','3313','2194','2945','369','1722','1726','9445','9453','1849'],true);

// Cache the provider service catalogue briefly. The old version called the SMM API
// on almost every page request, which made the whole site feel slow on Render.
function cachedProviderServices(string $dir,int $ttl=300):array{
    if(!is_dir($dir)) @mkdir($dir,0775,true);
    $file=rtrim($dir,'/\\').'/services_cache.json';
    if(is_file($file)){
        $age=time()-(int)@filemtime($file);
        if($age >= 0 && $age < $ttl){
            $cached=json_decode((string)@file_get_contents($file),true);
            if(is_array($cached) && isset($cached['services']) && is_array($cached['services'])) return $cached['services'];
        }
    }
    $api=new SmmsunClient(getenv('SMM_API_URL')?:'https://my.smmsun.com/api/v2',getenv('SMM_API_KEY')?:'');
    $r=$api->services();
    if(isset($r['error'])){
        if(is_file($file)){
            $cached=json_decode((string)@file_get_contents($file),true);
            if(is_array($cached) && isset($cached['services']) && is_array($cached['services'])) return $cached['services'];
        }
        throw new RuntimeException((string)$r['error']);
    }
    $services=is_array($r)?$r:[];
    $tmp=$file.'.tmp';
    @file_put_contents($tmp,json_encode(['saved_at'=>time(),'services'=>$services],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);
    if(is_file($tmp)) @rename($tmp,$file);
    return $services;
}
// Only pages that actually render service data load the provider catalogue.
// Dashboard needs it for platform/category cards; other pages do not.
if(in_array($page,['services','dashboard','admin'],true)){
    try{$services=cachedProviderServices('/var/www/html/data',900);}catch(Throwable $x){$apiError='Service API unavailable';}
}
// Only the requested service IDs are exposed to customers. Admin can still see the full provider catalog for management.
if(in_array($page,['services','dashboard','admin'],true)){$services=array_values(array_filter($services,fn($s)=>isset($allowedCustomerServiceIds[(string)($s['service']??'')])));}
if(user() && in_array($page,['dashboard','services','deposit','account','orders','supercell','admin'],true)){
    $_SESSION['user']=$store->findUser((int)user()['id'])??user();
}
$u=user();
// Generate session values before releasing the session lock. This allows other
// requests from the same user to proceed while this page is being rendered.
$csrfToken=csrf();
$flashMessage=flash();
session_write_close();
function brandIconFile(string $brand):string{
    $brand=strtolower(trim($brand));
    $map=[
      'youtube'=>'youtube.svg','instagram'=>'instagram.svg','tiktok'=>'tiktok.svg','facebook'=>'facebook.svg',
      'telegram'=>'telegram.svg','whatsapp'=>'whatsapp.svg','twitter'=>'twitter.svg','x'=>'x-twitter.svg',
      'linkedin'=>'linkedin.svg','discord'=>'discord.svg','spotify'=>'spotify.svg','twitch'=>'twitch.svg',
      'soundcloud'=>'soundcloud.svg','snapchat'=>'snapchat.svg','threads'=>'threads.svg','pinterest'=>'pinterest.svg',
      'skype'=>'skype.svg','google play'=>'google-play.svg','amazon'=>'amazon.svg','line'=>'line.svg'
    ];
    return $map[$brand]??'';
}
function platformIcon(string $p):string{
    static $cache=[];
    $p=strtolower(trim($p));
    if(array_key_exists($p,$cache)) return $cache[$p];
    $file=brandIconFile($p);
    if($file==='') return $cache[$p]='';
    $path=__DIR__.'/uploads/brand-icons/'.$file;
    if(!is_file($path)) return $cache[$p]='';
    // Use a local cached <img> instead of inlining SVG markup into every card.
    // The browser can cache one tiny official SVG and reuse it across the page.
    $url='uploads/brand-icons/'.rawurlencode($file);
    return $cache[$p]='<span class="brand-svg-wrap"><img class="brand-icon-img" src="'.e($url).'" width="42" height="42" alt="" decoding="async"></span>';
}
function serviceIcon(array $s):string{
    $raw=strtolower(($s['name']??'').' '.($s['category']??''));
    $tests=['youtube'=>'youtube','instagram'=>'instagram','tiktok'=>'tiktok','facebook'=>'facebook','telegram'=>'telegram','whatsapp'=>'whatsapp','twitter'=>'twitter',' x '=>'x','linkedin'=>'linkedin','discord'=>'discord','spotify'=>'spotify','twitch'=>'twitch','soundcloud'=>'soundcloud','bigo'=>'bigo','poppo'=>'poppo','mico'=>'mico','tango'=>'tango','likee'=>'likee','chamet'=>'chamet','starmaker'=>'starmaker','snapchat'=>'snapchat','threads'=>'threads','pinterest'=>'pinterest','netflix'=>'netflix','zoom'=>'zoom','skype'=>'skype','roblox'=>'roblox','pubg'=>'pubg','free fire'=>'free fire','mobile legends'=>'mobile legends','efootball'=>'efootball','call of duty'=>'call of duty','clash of clans'=>'clash of clans','valorant'=>'valorant','amazon'=>'amazon','google play'=>'google play'];
    foreach($tests as $needle=>$brand) if(str_contains($raw,$needle)) return platformIcon($brand);
    return platformIcon(platformOf($s));
}
function adjustedSelectedServicePrice(float $base,string $sid):float{ static $ids=null; if($ids===null){$ids=array_fill_keys(['1086','1076','1762','104','105','1367','1368','664','665','3901','3902','851','854','1049','242','125','133','1205','265','234','463','448','1050','474','462','476','3847','531','537','2600','629','3313','2194','2945','369','1722','1726','9445','9453','1849'],true);} if(!isset($ids[$sid])) return $base; /* Pricing rule: normally add ৳20 per 100 quantity (=৳200 per 1,000). If the current 1,000-unit price is exactly ৳100, make it ৳300 (3x). */ if(abs($base-100.0)<0.000001) $base=300.0; else $base+=200.0; return max(100.0,$base);}
function unitPrice(array $s,float $usd,float $markup,?float $override=null):float{ $sid=(string)($s['service']??''); $base=$override!==null?$override:(float)($s['rate']??0)*$usd+$markup; return adjustedSelectedServicePrice($base,$sid);}
function platformOf(array $s):string{$raw=strtolower(($s['name']??'').' '.($s['category']??'').' '.($s['service']??''));$map=['youtube'=>['youtube','youtu.be'],'facebook'=>['facebook','fb'],'instagram'=>['instagram','ig'],'tiktok'=>['tiktok','tik tok'],'telegram'=>['telegram','tg'],'twitter'=>['twitter',' x '],'linkedin'=>['linkedin'],'discord'=>['discord'],'spotify'=>['spotify'],'twitch'=>['twitch'],'soundcloud'=>['soundcloud']];foreach($map as $k=>$words)foreach($words as $w)if(str_contains($raw,$w))return $k;return 'other';}
function serviceTypeOf(array $s,string $platform):string{$raw=strtolower(($s['name']??'').' '.($s['category']??''));$types=['followers'=>'Followers','subscribers'=>'Subscribers','views'=>'Views','likes'=>'Likes','comments'=>'Comments','shares'=>'Shares','watch time'=>'Watch Time','members'=>'Members','saves'=>'Saves','story views'=>'Story Views','reactions'=>'Reactions','engagement'=>'Engagement'];foreach($types as $needle=>$label)if(str_contains($raw,$needle))return $label;if($platform==='youtube'){if(str_contains($raw,'sub'))return 'Subscribers';if(str_contains($raw,'view'))return 'Views';if(str_contains($raw,'like'))return 'Likes';}if($platform==='tiktok'){if(str_contains($raw,'sub'))return 'Subscribers';if(str_contains($raw,'view'))return 'Views';if(str_contains($raw,'like'))return 'Likes';if(str_contains($raw,'follow'))return 'Followers';}if($platform==='instagram'&&str_contains($raw,'follow'))return 'Followers';return 'Other';}
function normalizeOrderStatus(string $status):string{$s=strtolower(trim($status));$s=str_replace(['_','-'],' ',$s);if(in_array($s,['completed','complete','done','success','successful'],true))return 'completed';if(in_array($s,['processing','in progress','running','started'],true))return 'processing';if(in_array($s,['pending','queued','awaiting'],true))return 'pending';if(in_array($s,['canceled','cancelled','failed','failure','error'],true))return 'canceled';if(in_array($s,['partial','partially completed'],true))return 'partial';if(in_array($s,['refunded','refund'],true))return 'refunded';if(in_array($s,['submitted','accepted'],true))return 'submitted';return $s!==''?$s:'pending';}
function statusLabel(string $status):string{$n=normalizeOrderStatus($status);return match($n){'completed'=>'Completed ✓','processing'=>'Processing…','pending'=>'Pending…','canceled'=>'Canceled ✕','partial'=>'Partial','refunded'=>'Refunded','submitted'=>'Submitted','approved'=>'Approved ✓','rejected'=>'Rejected ✕',default=>ucwords($n)};}
function statusClass(string $status):string{return 'status-'.preg_replace('/[^a-z0-9]+/','-',normalizeOrderStatus($status));}
function syncProviderStatuses(JsonStore $store,array $rows,int $limit=25):array{if(!$rows)return $rows;$api=new SmmsunClient(getenv('SMM_API_URL')?:'https://my.smmsun.com/api/v2',getenv('SMM_API_KEY')?:'');$n=0;foreach($rows as $r){if($n++>=$limit)break;$pid=trim((string)($r['provider_order_id']??''));if($pid==='')continue;$resp=$api->orderStatus($pid);$st=$resp['status']??null;if(is_string($st)&&$st!=='')$store->updateOrderStatus((int)$r['id'],normalizeOrderStatus($st));}return $rows;}
?><!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Trusted Bazaar - মোবাইল রিচার্জ, গেম টপ-আপ ও অনলাইন সেবা</title><meta name="description" content="Trusted Bazaar — মোবাইল রিচার্জ, গেম টপ-আপ, শিক্ষার্থী সেবা এবং নিরাপদ পেমেন্টসহ বিভিন্ন অনলাইন সেবা।"><meta name="robots" content="index, follow"><link rel="canonical" href="https://trustedbazaar.top/"><link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><style>
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
.input,select,textarea{width:100%;padding:13px 14px;border:1px solid #d9dee8;border-radius:12px;margin:7px 0 14px;background:#fff;color:var(--text);outline:none;transition:.2s}.input:focus,select:focus,textarea:focus{border-color:var(--primary);box-shadow:0 0 0 4px rgba(109,93,252,.11)}label{font-weight:700;font-size:14px}.price{font-size:24px;font-weight:900}.muted{color:var(--muted);font-size:13px}.table{width:100%;border-collapse:separate;border-spacing:0;background:white;border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(16,24,40,.05)}.table th,.table td{padding:13px;border-bottom:1px solid #eef0f4;text-align:left;font-size:14px}.table th{background:#f8f9fc;font-weight:800}.table tr:last-child td{border-bottom:0}.badge{display:inline-block;padding:5px 9px;border-radius:999px;background:#eef2ff;color:#5146c8;font-size:12px;font-weight:800}.status-badge{display:inline-flex;align-items:center;gap:5px;padding:7px 11px;border-radius:999px;font-size:12px;font-weight:900;white-space:nowrap}.status-completed{background:#dcfce7;color:#15803d;border:1px solid #86efac}.status-processing{background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd}.status-pending{background:#fef3c7;color:#a16207;border:1px solid #fcd34d}.status-canceled,.status-cancelled,.status-rejected{background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5}.status-partial{background:#ffedd5;color:#c2410c;border:1px solid #fdba74}.status-refunded{background:#f3e8ff;color:#7e22ce;border:1px solid #d8b4fe}.status-submitted{background:#e0f2fe;color:#0369a1;border:1px solid #7dd3fc}.refresh-btn{background:linear-gradient(135deg,#06b6d4,#2563eb);box-shadow:none}.form{max-width:500px;margin:55px auto}.status-badge{display:inline-flex;align-items:center;gap:5px;padding:7px 11px;border-radius:999px;font-size:12px;font-weight:900;white-space:nowrap}.status-completed{background:#dcfce7;color:#15803d;border:1px solid #86efac}.status-processing{background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd}.status-pending{background:#fef3c7;color:#a16207;border:1px solid #fcd34d}.status-canceled,.status-cancelled,.status-rejected{background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5}.status-partial{background:#ffedd5;color:#c2410c;border:1px solid #fdba74}.status-refunded{background:#f3e8ff;color:#7e22ce;border:1px solid #d8b4fe}.status-submitted{background:#e0f2fe;color:#0369a1;border:1px solid #7dd3fc}.refresh-btn{background:linear-gradient(135deg,#06b6d4,#2563eb);box-shadow:none}.form h2{font-size:30px;margin:0 0 7px}.status-badge{display:inline-flex;align-items:center;gap:5px;padding:7px 11px;border-radius:999px;font-size:12px;font-weight:900;white-space:nowrap}.status-completed{background:#dcfce7;color:#15803d;border:1px solid #86efac}.status-processing{background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd}.status-pending{background:#fef3c7;color:#a16207;border:1px solid #fcd34d}.status-canceled,.status-cancelled,.status-rejected{background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5}.status-partial{background:#ffedd5;color:#c2410c;border:1px solid #fdba74}.status-refunded{background:#f3e8ff;color:#7e22ce;border:1px solid #d8b4fe}.status-submitted{background:#e0f2fe;color:#0369a1;border:1px solid #7dd3fc}.refresh-btn{background:linear-gradient(135deg,#06b6d4,#2563eb);box-shadow:none}.form:before{content:"Premium Panel";display:inline-block;margin-bottom:12px;padding:6px 10px;border-radius:999px;background:linear-gradient(135deg,#ede9fe,#cffafe);color:#5146c8;font-size:12px;font-weight:900}.alert{background:linear-gradient(135deg,#fff8ed,#fff3e0);border:1px solid #fed7aa;color:#9a3412;padding:13px 15px;border-radius:13px;margin-bottom:16px;box-shadow:0 8px 20px rgba(234,88,12,.06)}.balance{font-size:32px;font-weight:950;background:linear-gradient(135deg,var(--primary),var(--accent));-webkit-background-clip:text;background-clip:text;color:transparent}.toprow{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:17px}.toprow h2{margin:0;font-size:28px}.service-card{display:flex;flex-direction:column;gap:9px}.service-card .bottom{margin-top:auto;display:flex;justify-content:space-between;align-items:center;gap:8px}.danger{color:#b91c1c}.small{font-size:12px}
.category-bar{display:flex;gap:9px;overflow-x:auto;padding:3px 0 14px;margin-bottom:3px;scrollbar-width:thin}.cat{flex:0 0 auto;padding:10px 14px;border:1px solid #dfe3eb;background:#fff;color:#344054;border-radius:12px;cursor:pointer;font-weight:800;transition:.2s;box-shadow:0 5px 14px rgba(16,24,40,.04)}.cat:hover{border-color:#b8b1ff;transform:translateY(-1px)}.cat.active{background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;border-color:transparent;box-shadow:0 8px 18px rgba(109,93,252,.22)}
.payment-box{background:linear-gradient(135deg,#ecfdf5,#eff6ff);border:1px solid #c7f0df;border-radius:16px;padding:16px;margin:12px 0 18px}.payment-box>div{margin-bottom:11px}.payment-box>div:last-child{margin-bottom:0}.payment-number{font-size:22px;font-weight:950;letter-spacing:.5px;margin-top:3px;color:#0f766e}
.password-wrap{position:relative}.password-wrap .input{padding-right:50px}.password-toggle{position:absolute;right:8px;top:50%;transform:translateY(-50%);width:38px;height:38px;border:0;border-radius:10px;background:#f2f4f7;color:#667085;cursor:pointer;display:grid;place-items:center;font-size:18px}.password-toggle:hover{background:#e9e7ff;color:#5146c8}.password-toggle:focus{outline:3px solid rgba(109,93,252,.14)}
#modal{backdrop-filter:blur(6px)!important}#modal>.card{border:0;box-shadow:0 30px 90px rgba(0,0,0,.28)}
@media(max-width:700px){.nav{padding:11px 4%;align-items:flex-start}.brand{font-size:17px}.brand:before{width:34px;height:34px}.navlinks{justify-content:flex-end;gap:5px}.navlinks a,.navlinks button{padding:8px 9px;font-size:12px}.wrap{padding:0 11px;margin:20px auto}.hero{padding:28px 22px;border-radius:20px}.card{padding:16px}.form{margin:28px auto}.form h2{font-size:26px}.toprow{align-items:flex-start;flex-direction:column}.toprow .muted{align-self:flex-start}.table th,.table td{padding:10px;font-size:13px}}

.eyebrow{font-size:11px;letter-spacing:1.6px;font-weight:900;color:#7c6cff;margin-bottom:5px}.service-count,.admin-chip{padding:9px 13px;border-radius:999px;background:#fff;border:1px solid var(--line);font-size:12px;font-weight:900;box-shadow:0 8px 20px rgba(16,24,40,.05)}.service-tools{background:rgba(255,255,255,.78);border:1px solid rgba(255,255,255,.95);padding:14px;border-radius:18px;box-shadow:var(--shadow);margin-bottom:18px}.subcategory-bar{display:flex;gap:8px;overflow-x:auto;padding:0 0 2px}.subcat{flex:0 0 auto;border:1px solid #e3e6ed;background:#f8f9fc;border-radius:10px;padding:8px 12px;font-size:12px;font-weight:800;cursor:pointer}.subcat.active{background:#141b34;color:#fff;border-color:#141b34}.soft{background:#f6f7fb;color:#667085}.service-meta{display:flex;gap:5px;flex-wrap:wrap}.service-card{min-height:190px}.modal-card{max-width:500px;margin:7vh auto;box-shadow:0 30px 90px rgba(0,0,0,.25)}.icon-btn{border:0;background:#f2f4f7;border-radius:10px;width:38px;height:38px;cursor:pointer}.order-total{display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,#f7f5ff,#eefcff);border:1px solid #e4e1ff;padding:14px;border-radius:14px;font-size:16px}.order-total b{font-size:22px}.empty-state{text-align:center;padding:50px 20px;grid-column:1/-1}.empty-icon{font-size:45px}.admin-grid{display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-bottom:24px}.price-form{display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end}.price-form label{grid-row:1}.price-form input{margin:0}.price-form button{height:48px}.inline-price-form{display:flex;gap:7px;align-items:center}.inline-price-form .input{margin:0;min-width:120px}.inline-price-form .btn{white-space:nowrap}.stat-number{font-size:42px;font-weight:950;margin-top:12px}.mini-note{margin-top:12px;padding:10px;border-radius:10px;background:#f8f9fc;font-size:12px;color:#667085}.section-title{margin-top:28px}@media(max-width:760px){.admin-grid{grid-template-columns:1fr}.price-form{grid-template-columns:1fr}.price-form label{display:none}.service-count{display:none}.toprow{align-items:flex-start}}
.app-bg{background-image:linear-gradient(rgba(9,12,30,.72),rgba(9,12,30,.76)),url('background.svg');background-size:cover;background-position:center;background-attachment:fixed}.app-bg .wrap{position:relative}.app-bg .card,.app-bg .service-tools,.app-bg .service-count,.app-bg .admin-chip{background:rgba(255,255,255,.94);backdrop-filter:blur(12px)}.profile-wrap{max-width:980px;margin:0 auto}.profile-hero{display:flex;align-items:center;gap:18px;padding:28px;border-radius:24px;color:#fff;background:linear-gradient(135deg,rgba(20,27,52,.94),rgba(38,32,75,.90),rgba(15,120,144,.88));box-shadow:0 24px 70px rgba(0,0,0,.25)}.profile-hero h2{margin:0;font-size:34px}.profile-hero p{margin:5px 0 0;color:rgba(255,255,255,.8)}.avatar{width:58px;height:58px;border-radius:18px;display:grid;place-items:center;flex:0 0 auto;background:linear-gradient(135deg,var(--primary),var(--accent));color:#fff;font-size:25px;font-weight:950;box-shadow:0 12px 30px rgba(109,93,252,.3)}.avatar.big{width:78px;height:78px;border-radius:24px;font-size:34px}.eyebrow.light{color:#c4b5fd}.profile-card{display:flex;gap:16px;align-items:center}.cat{display:flex;align-items:center;gap:8px}.cat-icon{display:grid;place-items:center;min-width:28px;height:28px;border-radius:8px;background:#f1efff;color:#5146c8;font-weight:950}.cat.active .cat-icon{background:rgba(255,255,255,.18);color:#fff}.service-title{display:flex;align-items:center;gap:10px;min-height:42px}.service-title strong{font-size:16px;line-height:1.4}.service-icon{width:38px;height:38px;border-radius:12px;display:grid;place-items:center;background:linear-gradient(135deg,#ede9fe,#cffafe);color:#5146c8;font-weight:950;font-size:18px;box-shadow:0 7px 18px rgba(109,93,252,.12)}.service-full-name{line-height:1.5}.service-card{background:rgba(255,255,255,.96)}@media(max-width:700px){.profile-hero{padding:22px}.profile-hero h2{font-size:27px}.profile-card{align-items:flex-start}.cat{padding:9px 11px}}
/* Rainbow customer/admin redesign */
body{background:#071126;overflow-x:hidden}
.app-bg{background-image:linear-gradient(rgba(7,13,36,.58),rgba(7,13,36,.64)),url('rainbow.svg');background-size:cover;background-position:center;background-attachment:fixed}
.nav{background:rgba(7,15,38,.78);border-bottom:1px solid rgba(255,255,255,.16);box-shadow:0 10px 35px rgba(0,0,0,.22)}
.brand{font-size:20px}.brand:before{content:'';background:linear-gradient(135deg,#ff3d9a,#7c4dff,#00d9ff,#00e5a8);background-size:220% 220%;animation:rainbowMove 7s ease infinite}
.wrap{max-width:1160px;margin:22px auto 50px}.app-bg .card,.app-bg .service-tools,.app-bg .service-count,.app-bg .admin-chip{background:rgba(255,255,255,.93);border-color:rgba(255,255,255,.55);box-shadow:0 18px 50px rgba(7,13,36,.16)}
.customer-shell{background:rgba(247,249,255,.92);border:1px solid rgba(255,255,255,.65);border-radius:28px;padding:18px;box-shadow:0 25px 80px rgba(0,0,0,.2);backdrop-filter:blur(14px)}
.customer-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:8px 4px 18px}.customer-brand{font-size:27px;font-weight:950;letter-spacing:-1px;color:#172033}.customer-brand b{color:#ef4b57}.customer-tag{font-size:11px;color:#667085;margin-top:-4px}.head-actions{display:flex;gap:9px;align-items:center}.wallet-pill{padding:12px 16px;border-radius:999px;color:#fff;font-weight:900;background:linear-gradient(135deg,#1265ff,#6b36ff);box-shadow:0 10px 22px rgba(45,87,255,.28)}
.profile-banner{display:grid;grid-template-columns:1.5fr 1fr;gap:14px;margin-bottom:16px}.profile-mini{background:linear-gradient(135deg,#fff,#f7f0ff);border-radius:22px;padding:20px;display:flex;align-items:center;gap:15px;border:1px solid #ebe8ff}.profile-mini .avatar{width:68px;height:68px;border-radius:50%;border:4px solid #fff;outline:3px solid #6b5cff}.profile-mini h3{margin:2px 0 3px;font-size:22px}.profile-mini .status{display:inline-flex;padding:5px 9px;border-radius:999px;background:#dff8e9;color:#0b8b4b;font-size:11px;font-weight:900}.profile-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:15px}.profile-stats>div{padding:10px;border-right:1px solid #eceef4}.profile-stats>div:last-child{border-right:0}.profile-stats b{display:block;font-size:18px}.profile-stats span{font-size:10px;color:#7b8494}.quick-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.quick-card{border-radius:17px;padding:18px 14px;color:#fff;min-height:100px;display:flex;flex-direction:column;justify-content:center;gap:6px;box-shadow:0 12px 28px rgba(35,64,150,.15)}.quick-card:nth-child(1){background:linear-gradient(135deg,#087dff,#1e54ff)}.quick-card:nth-child(2){background:linear-gradient(135deg,#1c74ff,#743bff)}.quick-card:nth-child(3){background:linear-gradient(135deg,#ff5b9c,#ff7b2f)}.quick-card strong{font-size:15px}.quick-card span{font-size:11px;opacity:.9}
.section-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:18px 2px 11px}.section-heading h3{margin:0;font-size:21px}.section-heading a{color:#5b4ee8;font-weight:900;font-size:12px}
.dashboard-cats{display:grid;grid-template-columns:repeat(4,1fr);gap:13px}.dash-cat{background:#fff;border-radius:18px;padding:13px 8px;text-align:center;border:1px solid #edf0f5;box-shadow:0 8px 22px rgba(16,24,40,.07);transition:.2s}.dash-cat:hover{transform:translateY(-3px)}.dash-cat .cat-img{width:62px;height:62px;margin:0 auto 7px;border-radius:16px;display:grid;place-items:center;color:#fff;font-size:28px;font-weight:950;background:linear-gradient(135deg,#1265ff,#8b35ff)}.dash-cat:nth-child(2n) .cat-img{background:linear-gradient(135deg,#08c98f,#0b83ff)}.dash-cat:nth-child(3n) .cat-img{background:linear-gradient(135deg,#ff4da6,#7d3cff)}.dash-cat strong{font-size:12px;display:block;line-height:1.35}.dash-cat span{font-size:10px;color:#8992a3}
.service-tools{border-radius:20px}.category-bar{padding-bottom:12px}.cat{background:#fff}.cat.active{background:linear-gradient(135deg,#1465ff,#8b3dff)}
.service-card{border-radius:19px;border:1px solid #e8eaf1;overflow:hidden}.service-card .service-icon{width:56px;height:56px;border-radius:16px;font-size:26px;background:linear-gradient(135deg,#0b72ff,#7b3cff);color:#fff}.service-card:nth-child(2n) .service-icon{background:linear-gradient(135deg,#00c98b,#008cff)}.service-card:nth-child(3n) .service-icon{background:linear-gradient(135deg,#ff3d9a,#7a35ff)}
.admin-shell{border-radius:28px;padding:20px;background:linear-gradient(145deg,rgba(8,17,45,.95),rgba(24,15,62,.94));box-shadow:0 30px 90px rgba(0,0,0,.32);color:#fff}.admin-shell .muted{color:rgba(255,255,255,.68)}.admin-hero{display:flex;justify-content:space-between;align-items:center;gap:15px;padding:6px 4px 20px}.admin-title{font-size:30px;font-weight:950}.admin-sub{font-size:12px;color:rgba(255,255,255,.68)}.admin-badge{padding:9px 13px;border-radius:999px;background:linear-gradient(135deg,#00d9a6,#147cff);font-weight:900;font-size:12px}.admin-shell .card{background:linear-gradient(145deg,rgba(255,255,255,.12),rgba(255,255,255,.06));border:1px solid rgba(255,255,255,.12);color:#fff;box-shadow:0 18px 50px rgba(0,0,0,.2)}.admin-shell .table{background:rgba(255,255,255,.98);color:#172033}.admin-shell .section-title{color:#fff}.admin-stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}.admin-stat{border-radius:18px;padding:17px;color:#fff;min-height:112px;box-shadow:0 15px 35px rgba(0,0,0,.18)}.admin-stat:nth-child(1){background:linear-gradient(135deg,#0b79ff,#513bff)}.admin-stat:nth-child(2){background:linear-gradient(135deg,#08c98f,#087cff)}.admin-stat:nth-child(3){background:linear-gradient(135deg,#ff3d9a,#8a39ff)}.admin-stat:nth-child(4){background:linear-gradient(135deg,#ff9d28,#ff3f67)}.admin-stat span{font-size:11px;opacity:.86}.admin-stat b{display:block;font-size:27px;margin-top:10px}.admin-actions{display:grid;grid-template-columns:repeat(4,1fr);gap:11px;margin-bottom:22px}.admin-action{padding:16px;border-radius:16px;color:#fff;font-weight:900;text-align:center;background:linear-gradient(135deg,#114cff,#7d3cff);border:1px solid rgba(255,255,255,.12)}.admin-action:nth-child(2){background:linear-gradient(135deg,#00a9ff,#00c98f)}.admin-action:nth-child(3){background:linear-gradient(135deg,#ff2f91,#ff7a2d)}.admin-action:nth-child(4){background:linear-gradient(135deg,#7540ff,#13b9ff)}
.mobile-bottom{display:none}
.telegram-help{position:fixed;right:16px;bottom:82px;z-index:70}.telegram-help a{display:inline-flex;align-items:center;gap:7px;padding:11px 14px;border-radius:999px;background:linear-gradient(135deg,#1687e8,#229ed9);color:#fff;font-weight:900;box-shadow:0 12px 28px rgba(22,135,232,.3);font-size:12px}.supercell-link-card{border:1px dashed #b8c0d4}
.settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.admin-user-table select{min-width:120px}.admin-note{padding:12px 14px;border-radius:14px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);color:rgba(255,255,255,.8);font-size:12px}@media(max-width:760px){.settings-grid{grid-template-columns:1fr}}
@keyframes rainbowMove{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
@media(max-width:760px){.wrap{margin:10px auto 86px}.customer-shell{padding:11px;border-radius:23px}.customer-brand{font-size:22px}.customer-head{padding:5px 3px 12px}.wallet-pill{padding:9px 12px;font-size:12px}.profile-banner{grid-template-columns:1fr}.quick-grid{grid-template-columns:repeat(3,1fr)}.quick-card{min-height:82px;padding:12px 8px}.dashboard-cats{grid-template-columns:repeat(3,1fr);gap:9px}.dash-cat{padding:10px 5px}.dash-cat .cat-img{width:54px;height:54px}.dash-cat strong{font-size:10px}.profile-mini{padding:15px}.admin-shell{padding:12px;border-radius:22px}.admin-stat-grid{grid-template-columns:repeat(2,1fr)}.admin-actions{grid-template-columns:repeat(2,1fr)}.admin-title{font-size:24px}.mobile-bottom{position:fixed;display:grid;grid-template-columns:repeat(5,1fr);left:8px;right:8px;bottom:8px;z-index:90;background:rgba(255,255,255,.95);border:1px solid #e6e9ef;border-radius:20px;padding:7px;box-shadow:0 16px 45px rgba(0,0,0,.22);backdrop-filter:blur(16px)}.mobile-bottom a{text-align:center;color:#7b8494;font-size:10px;font-weight:800;padding:6px 2px;border-radius:13px}.mobile-bottom a.active{background:linear-gradient(135deg,#1265ff,#713cff);color:#fff}.telegram-help{right:10px;bottom:84px}.telegram-help a{padding:9px 11px;font-size:11px}.mobile-bottom i{display:block;font-style:normal;font-size:19px;margin-bottom:2px}}

/* ===== Requested visual redesign: functionality/settings intentionally unchanged ===== */

/* Customer panel: white + blue + yellow */
.customer-shell{
  background:rgba(255,255,255,.98);
  border:1px solid #dbeafe;
  box-shadow:0 25px 80px rgba(30,64,175,.14);
}
.customer-head{border-bottom:1px solid #e5e7eb}
.customer-brand{color:#123a78}
.customer-brand b{color:#f2b705}
.customer-tag{color:#64748b}
.wallet-pill{
  background:linear-gradient(135deg,#0d6efd,#155eef);
  box-shadow:0 10px 22px rgba(13,110,253,.22);
}
.profile-banner .profile-mini{
  background:linear-gradient(135deg,#ffffff,#eff6ff);
  border:1px solid #bfdbfe;
}
.profile-mini .avatar{
  outline:3px solid #f2b705;
  background:linear-gradient(135deg,#0d6efd,#f2b705);
}
.profile-mini .status{background:#fff7cc;color:#8a6400}
.quick-card:nth-child(1){background:linear-gradient(135deg,#0d6efd,#2563eb)}
.quick-card:nth-child(2){background:linear-gradient(135deg,#2563eb,#0ea5e9)}
.quick-card:nth-child(3){background:linear-gradient(135deg,#f2b705,#f59e0b)}
.dashboard-cats .dash-cat{border-color:#dbeafe}
.dash-cat .cat-img,
.dash-cat:nth-child(2n) .cat-img,
.dash-cat:nth-child(3n) .cat-img{
  background:linear-gradient(135deg,#0d6efd,#f2b705);
}
.section-heading a{color:#0d6efd}
.cat.active{background:linear-gradient(135deg,#0d6efd,#f2b705);color:#fff}
.service-card .service-icon,
.service-card:nth-child(2n) .service-icon,
.service-card:nth-child(3n) .service-icon{
  background:linear-gradient(135deg,#0d6efd,#f2b705);
}
.btn{background:linear-gradient(135deg,#0d6efd,#155eef)}
.btn:hover{box-shadow:0 12px 24px rgba(13,110,253,.25)}
.password-toggle:hover{background:#fff7cc;color:#8a6400}
.input:focus,select:focus,textarea:focus{border-color:#0d6efd;box-shadow:0 0 0 4px rgba(13,110,253,.11)}

/* Admin panel: green + yellow */
.admin-shell{
  background:linear-gradient(145deg,#063b2b,#0b5d3f 52%,#173f32);
  border:1px solid rgba(242,183,5,.35);
  box-shadow:0 30px 90px rgba(4,56,38,.30);
}
.admin-title{color:#fff}
.admin-badge{background:linear-gradient(135deg,#0b8f57,#f2b705);color:#fff}
.admin-stat:nth-child(1),
.admin-stat:nth-child(2),
.admin-stat:nth-child(3),
.admin-stat:nth-child(4){
  background:linear-gradient(135deg,#087f4f,#f2b705);
}
.admin-action,
.admin-action:nth-child(2),
.admin-action:nth-child(3),
.admin-action:nth-child(4){
  background:linear-gradient(135deg,#087f4f,#f2b705);
  color:#fff;
}
.admin-shell .card{
  background:linear-gradient(145deg,rgba(8,143,87,.22),rgba(242,183,5,.10));
  border-color:rgba(242,183,5,.24);
}
.admin-shell .table th{background:#ecfdf5;color:#14532d}
.admin-shell .table{border-color:#bbf7d0}
.admin-note{background:rgba(242,183,5,.10);border-color:rgba(242,183,5,.28)}
.admin-shell .btn{background:linear-gradient(135deg,#087f4f,#f2b705)}
.admin-shell .btn.green{background:linear-gradient(135deg,#087f4f,#16a34a)}

/* Welcome screen shown on website entry; existing routes/settings remain unchanged. */
.welcome-screen{
  min-height:calc(100vh - 120px);
  display:grid;
  place-items:center;
  padding:24px 0;
}
.welcome-card{
  width:min(720px,100%);
  text-align:center;
  padding:48px 28px;
  border-radius:30px;
  background:rgba(255,255,255,.97);
  border:1px solid #dbeafe;
  box-shadow:0 30px 90px rgba(15,23,42,.22);
}
.welcome-logo{
  width:78px;height:78px;margin:0 auto 18px;border-radius:24px;
  display:grid;place-items:center;color:#fff;font-weight:950;font-size:20px;
  background:linear-gradient(135deg,#0d6efd,#f2b705);
  box-shadow:0 16px 35px rgba(13,110,253,.25);
}
.welcome-card h1{margin:0 0 10px;color:#123a78;font-size:clamp(30px,6vw,46px)}
.welcome-card p{margin:0 auto 24px;max-width:560px;color:#64748b;line-height:1.8}
.welcome-card .welcome-login{min-width:190px;background:linear-gradient(135deg,#0d6efd,#f2b705)}
.welcome-note{margin-top:14px;font-size:12px;color:#94a3b8}


/* ===== FINAL CUSTOMER UI: Bright Blue / White / Yellow ===== */
body{background:#f4f7fb;color:#172033}
.app-bg{background:#f4f7fb;background-image:none!important}
.nav{
  background:#fff!important;color:#172033!important;
  border-bottom:2px solid #0d6efd!important;
  box-shadow:0 5px 22px rgba(13,110,253,.10)!important;
  backdrop-filter:none!important;
}
.brand{color:#0d6efd!important;font-weight:950!important}
.brand:before{background:linear-gradient(135deg,#0d6efd,#f2b705)!important}
.navlinks a{color:#344054!important}
.navlinks a:hover{color:#0d6efd!important}
.wrap{max-width:1180px;margin:20px auto 70px}
.app-bg .card,.app-bg .service-tools,.app-bg .service-count,.app-bg .admin-chip{
  background:#fff;border-color:#dbeafe;box-shadow:0 12px 35px rgba(13,110,253,.08)
}
.btn{background:linear-gradient(135deg,#0d6efd,#155eef)!important;border:0}
.btn.secondary{background:#fff!important;color:#0d6efd!important;border:1px solid #0d6efd!important}

/* Customer dashboard */
.customer-shell{background:#fff!important;border:1px solid #dbeafe!important;box-shadow:0 20px 55px rgba(13,110,253,.10)!important;backdrop-filter:none!important}
.customer-head{border-bottom:1px solid #e5e7eb}
.customer-brand{color:#0d6efd!important}
.customer-brand b{color:#f05a28!important}
.customer-tag{color:#667085}
.wallet-pill{background:linear-gradient(135deg,#0d6efd,#155eef)!important}
.profile-mini{background:linear-gradient(135deg,#fff,#f5f9ff)!important;border-color:#bfdbfe!important}
.profile-mini .avatar{outline-color:#f2b705!important;background:linear-gradient(135deg,#0d6efd,#f2b705)!important}
.profile-mini .status{background:#fff7cc!important;color:#8a6400!important}
.quick-card:nth-child(1){background:linear-gradient(135deg,#0d6efd,#2563eb)!important}
.quick-card:nth-child(2){background:linear-gradient(135deg,#f2b705,#f59e0b)!important}
.quick-card:nth-child(3){background:linear-gradient(135deg,#087f5b,#0d6efd)!important}
.dashboard-cats{grid-template-columns:repeat(3,1fr)!important}
.dash-cat{background:#fff!important;border-color:#dbeafe!important}
.dash-cat:nth-child(4n+1) .cat-img{background:linear-gradient(135deg,#ff4fa3,#f97316)!important}
.dash-cat:nth-child(4n+2) .cat-img{background:linear-gradient(135deg,#7c3aed,#4f46e5)!important}
.dash-cat:nth-child(4n+3) .cat-img{background:linear-gradient(135deg,#0d6efd,#06b6d4)!important}
.dash-cat:nth-child(4n) .cat-img{background:linear-gradient(135deg,#84cc16,#eab308)!important}
.section-heading a{color:#0d6efd!important}
.cat.active{background:linear-gradient(135deg,#0d6efd,#155eef)!important;color:#fff!important}
.service-card{background:#fff!important;border-color:#dbeafe!important}
.service-card .service-icon{background:linear-gradient(135deg,#0d6efd,#f2b705)!important}
.service-card:nth-child(2n) .service-icon{background:linear-gradient(135deg,#ec4899,#8b5cf6)!important}
.service-card:nth-child(3n) .service-icon{background:linear-gradient(135deg,#0d6efd,#06b6d4)!important}
.service-card:nth-child(4n) .service-icon{background:linear-gradient(135deg,#84cc16,#eab308)!important}
.input:focus,select:focus,textarea:focus{border-color:#0d6efd!important;box-shadow:0 0 0 4px rgba(13,110,253,.10)!important}
.password-toggle:hover{background:#fff7cc!important;color:#8a6400!important}

/* Promo slider */
.promo-slider{position:relative;overflow:hidden;margin:0 0 18px;border-radius:24px;background:#0d6efd;box-shadow:0 18px 45px rgba(13,110,253,.18)}
.promo-track{display:flex;transition:transform .55s ease}
.promo-slide{min-width:100%;padding:25px 26px;display:flex;align-items:center;justify-content:space-between;gap:18px;color:#fff;box-sizing:border-box}
.promo-slide:nth-child(1){background:linear-gradient(135deg,#0d6efd,#155eef)}
.promo-slide:nth-child(2){background:linear-gradient(135deg,#f2b705,#f59e0b)}
.promo-slide:nth-child(3){background:linear-gradient(135deg,#087f5b,#0d6efd)}
.promo-copy h2{margin:0 0 6px;font-size:25px}.promo-copy p{margin:0;opacity:.9;font-size:12px}
.promo-icon{font-size:52px;filter:drop-shadow(0 8px 15px rgba(0,0,0,.15))}
.promo-dots{position:absolute;bottom:9px;left:0;right:0;text-align:center}
.promo-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:rgba(255,255,255,.5);margin:0 3px}
.promo-dot.active{background:#fff;width:18px;border-radius:9px}

/* Bottom app navigation */
.mobile-bottom{background:#fff!important;border:1px solid #dbeafe!important}
.mobile-bottom a{color:#64748b!important}
.mobile-bottom a.active{background:linear-gradient(135deg,#0d6efd,#155eef)!important;color:#fff!important}
.telegram-help a{background:linear-gradient(135deg,#16a34a,#0d6efd)!important}

/* Admin remains green/yellow */
.admin-shell{background:linear-gradient(145deg,#063b2b,#0b5d3f 52%,#173f32)!important;border-color:rgba(242,183,5,.35)!important}
.admin-badge,.admin-stat,.admin-action{background:linear-gradient(135deg,#087f4f,#f2b705)!important}
.admin-shell .card{background:linear-gradient(145deg,rgba(8,143,87,.22),rgba(242,183,5,.10))!important;border-color:rgba(242,183,5,.24)!important}

/* Responsive */
@media(max-width:760px){
  .dashboard-cats{grid-template-columns:repeat(3,1fr)!important}
  .promo-slide{padding:20px 18px}.promo-copy h2{font-size:20px}.promo-icon{font-size:40px}
}


/* ===== SINGLE-COLOR ICON / CARD THEME =====
   Each icon/card uses one solid color only. No mixed gradients on icons.
*/
.dash-cat:nth-child(4n+1) .cat-img{background:#ff4f81!important}
.dash-cat:nth-child(4n+2) .cat-img{background:#7c3aed!important}
.dash-cat:nth-child(4n+3) .cat-img{background:#0d6efd!important}
.dash-cat:nth-child(4n) .cat-img{background:#84cc16!important}

.service-card .service-icon{background:#0d6efd!important}
.service-card:nth-child(2n) .service-icon{background:#7c3aed!important}
.service-card:nth-child(3n) .service-icon{background:#16a34a!important}
.service-card:nth-child(4n) .service-icon{background:#f2b705!important}

.quick-card:nth-child(1){background:#0d6efd!important}
.quick-card:nth-child(2){background:#f2b705!important}
.quick-card:nth-child(3){background:#16a34a!important}

.wallet-pill{background:#0d6efd!important}
.profile-mini .avatar{background:#f2b705!important}
.promo-slide:nth-child(1){background:#0d6efd!important}
.promo-slide:nth-child(2){background:#f2b705!important}
.promo-slide:nth-child(3){background:#16a34a!important}

.btn{background:#0d6efd!important}
.welcome-logo{background:#0d6efd!important}
.admin-badge,.admin-stat,.admin-action{background:#087f4f!important}
.admin-shell .btn{background:#f2b705!important}
.admin-shell .btn.green{background:#16a34a!important}
.mobile-bottom a.active{background:#0d6efd!important}
.telegram-help a{background:#16a34a!important}

/* Prevent accidental gradient fills on the requested visual elements */
.dash-cat .cat-img,
.service-card .service-icon,
.quick-card,
.wallet-pill,
.profile-mini .avatar,
.promo-slide,
.btn,
.welcome-logo,
.admin-badge,
.admin-stat,
.admin-action,
.mobile-bottom a.active,
.telegram-help a{background-image:none!important}


/* Final customer/admin neutral theme: white background + black text */
html,body{background:#fff!important;color:#000!important}
body,.app-bg,.customer-shell,.admin-shell,.wrap{background:#fff!important;color:#000!important}
.app-bg{background-image:none!important}
.card,.service-tools,.service-count,.admin-chip,.service-card,.table,.table th,.table td,.customer-head,.admin-head,.profile-card,.profile-wrap{background:#fff!important;color:#000!important}
h1,h2,h3,h4,h5,h6,p,span,strong,b,label,small,li,td,th,.muted,.small,.service-full-name,.service-title strong,.section-title,.toprow h2{color:#000!important}
.service-card .service-icon{background:#fff!important;color:#000!important;box-shadow:none!important;border:1px solid #e5e7eb!important}
.service-card .service-icon img,.cat-img img,.cat-icon img{width:100%;height:100%;object-fit:contain;display:block}
.service-card .service-icon img{width:34px;height:34px}
.dash-cat .cat-img{background:#fff!important;color:#000!important;border:1px solid #e5e7eb!important}
.dash-cat .cat-img img{width:30px;height:30px}

/* ===== Final fix: clean white/black panels + working local brand icons ===== */
html,body{background:#fff!important;color:#000!important}
body,.app-bg,.wrap,.customer-shell,.admin-shell{background:#fff!important;color:#000!important;background-image:none!important}
.nav{background:#fff!important;color:#000!important;border-bottom:1px solid #e5e7eb!important;box-shadow:0 4px 16px rgba(0,0,0,.06)!important}
.navlinks a,.navlinks button{background:#fff!important;color:#000!important;border-color:#e5e7eb!important}
.brand{color:#000!important}.brand span{color:#000!important}.brand:before{background:#fff!important;color:#000!important;border:1px solid #e5e7eb!important;box-shadow:none!important}
.customer-shell{border:1px solid #e5e7eb!important;box-shadow:none!important;backdrop-filter:none!important}
.customer-brand,.customer-brand b,.customer-tag{color:#000!important}
.wallet-pill{background:#fff!important;color:#000!important;border:1px solid #d1d5db!important;box-shadow:none!important}
.profile-banner .profile-mini{background:#fff!important;color:#000!important;border:1px solid #e5e7eb!important}
.profile-mini .avatar{background:#fff!important;color:#000!important;outline:2px solid #d1d5db!important;box-shadow:none!important}
.quick-card{background:#fff!important;color:#000!important;border:1px solid #e5e7eb!important;box-shadow:none!important}
.quick-card *,.quick-card strong,.quick-card span{color:#000!important}
.dash-cat{background:#fff!important;color:#000!important;border:1px solid #e5e7eb!important;box-shadow:none!important}
.dash-cat .cat-img{background:#fff!important;color:#000!important;border:1px solid #e5e7eb!important;box-shadow:none!important}
.dash-cat .cat-img img{width:34px!important;height:34px!important;object-fit:contain!important}
.service-card,.service-tools,.service-count,.card{background:#fff!important;color:#000!important;border-color:#e5e7eb!important;box-shadow:none!important}
.service-card .service-icon{background:#fff!important;color:#000!important;border:1px solid #e5e7eb!important;box-shadow:none!important}
.service-card .service-icon img{width:36px!important;height:36px!important;object-fit:contain!important;display:block!important}
.brand-fallback{display:grid;place-items:center;width:34px;height:34px;border:1px solid #d1d5db;border-radius:8px;font-size:16px;font-weight:900;color:#000!important;background:#fff!important}
.brand-svg-wrap{width:38px;height:38px;display:grid;place-items:center;overflow:hidden}
.brand-svg{width:34px!important;height:34px!important;display:block!important;max-width:100%;max-height:100%;fill:currentColor!important}
.dash-cat .brand-svg-wrap{width:42px;height:42px}
.dash-cat .brand-svg{width:38px!important;height:38px!important}
.service-card .service-icon .brand-svg-wrap{width:42px;height:42px}
.service-card .service-icon .brand-svg{width:38px!important;height:38px!important}
.admin-shell{border:1px solid #e5e7eb!important;box-shadow:none!important}
.admin-shell,.admin-shell *,.admin-shell .muted,.admin-shell .admin-sub,.admin-shell .section-title{color:#000!important}
.admin-shell .card,.admin-shell .admin-stat,.admin-shell .admin-action,.admin-shell .admin-badge,.admin-shell .admin-note{background:#fff!important;color:#000!important;border:1px solid #e5e7eb!important;box-shadow:none!important}
.admin-shell .table,.admin-shell .table th,.admin-shell .table td{background:#fff!important;color:#000!important}
.admin-shell .table th{font-weight:800!important}
.btn,.admin-shell .btn{background:#fff!important;color:#000!important;border:1px solid #d1d5db!important;box-shadow:none!important}
.badge,.status,.status-badge{color:#000!important}
.section-heading a,.section-heading h3,.toprow h2,.section-title{color:#000!important}

/* FINAL: official brand-logo SVGs are embedded locally in the HTML. */
.brand-svg-wrap{width:44px;height:44px;display:grid;place-items:center;overflow:hidden;flex:0 0 auto}
.brand-svg-wrap .brand-svg{width:42px!important;height:42px!important;display:block!important;max-width:42px!important;max-height:42px!important;fill:#111!important}
.dash-cat .brand-svg-wrap{width:48px;height:48px}
.dash-cat .brand-svg-wrap .brand-svg{width:44px!important;height:44px!important;max-width:44px!important;max-height:44px!important}
.cat-icon .brand-svg-wrap{width:28px;height:28px}
.brand-icon-img{display:block;width:42px;height:42px;object-fit:contain}
.cat-icon .brand-icon-img{width:26px;height:26px}
.cat-icon .brand-svg-wrap .brand-svg{width:26px!important;height:26px!important;max-width:26px!important;max-height:26px!important}
.service-icon .brand-svg-wrap{width:44px;height:44px}
.service-icon .brand-svg-wrap .brand-svg{width:40px!important;height:40px!important;max-width:40px!important;max-height:40px!important}
.brand-fallback{display:grid!important;place-items:center;width:42px;height:42px;border:1px solid #d1d5db;border-radius:10px;background:#fff!important;color:#000!important;font-weight:900}

/* Final icon-only mode: no colored tile/background and no initials fallback. */
.dash-cat .cat-img,.service-card .service-icon,.cat-icon{background:transparent!important;border:0!important;box-shadow:none!important;color:#000!important}
.dash-cat .cat-img{width:62px!important;height:62px!important}
.service-card .service-icon{width:56px!important;height:56px!important}
.brand-fallback{display:none!important}
.dash-cat .brand-svg-wrap,.service-card .brand-svg-wrap,.cat-icon .brand-svg-wrap{background:transparent!important}
.language-switcher{display:flex;align-items:center;margin-left:8px}.language-select{border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.12);color:inherit;border-radius:9px;padding:7px 9px;font-weight:700;font-size:13px;cursor:pointer}.language-select option{color:#111;background:#fff} .tb-home-hero{width:100%;height:340px;background:linear-gradient(rgba(13,110,253,.6),rgba(25,135,84,.6)),url("https://images.unsplash.com/photo-1607083206869-4c7672e72a8a?auto=format&fit=crop&w=1200&q=80") no-repeat center center/cover;display:flex;align-items:center;justify-content:center;text-align:center;color:#fff;margin:0 0 30px;border-radius:0 0 20px 20px;box-shadow:0 4px 20px rgba(0,0,0,.1)} .tb-home-hero h1{font-size:2.5rem;margin:0 0 10px;font-weight:700}.tb-home-hero p{font-size:1.2rem;opacity:.95;margin:0}.tb-home-container{max-width:1100px;margin:0 auto;padding:0 20px 40px}.tb-section-title{font-size:1.6rem;font-weight:600;margin-bottom:20px;color:#2c3e50;border-left:5px solid #0d6efd;padding-left:12px}.tb-categories-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px}.tb-category-card{background:#fff;padding:30px 20px;text-align:center;border-radius:14px;box-shadow:0 4px 15px rgba(0,0,0,.05);transition:transform .3s ease,box-shadow .3s ease;cursor:pointer;border:1px solid rgba(0,0,0,.03)}.tb-category-card:hover{transform:translateY(-6px);box-shadow:0 10px 25px rgba(0,0,0,.08);border-color:#0d6efd}.tb-category-card i{font-size:2.8rem;color:#0d6efd;margin-bottom:15px}.tb-category-card h3{font-size:1.15rem;margin:0;color:#34495e;font-weight:600}@media(max-width:600px){.tb-home-hero{height:280px}.tb-home-hero h1{font-size:2rem}.tb-home-hero p{font-size:1rem}}@media(max-width:760px){.language-select{padding:6px 7px;font-size:12px}}</style></head><body class="app-bg"><nav class="nav"><a class="brand" href="?page=home">Trusted <span style="color:#f05a28">BAZAAR</span></a><div class="navlinks"><?php if($u):?><a href="?page=account">👤 Account</a><a href="?page=dashboard">Dashboard</a><a href="?page=services">Services</a><a href="?page=supercell">🎮 সুপারসেল গেম আইটেম</a><a href="?page=orders">Orders</a><a href="?page=deposit">Deposit</a><a href="https://t.me/Rayhanvai120" target="_blank" rel="noopener">💬 Help</a><?php if($u['role']==='admin'):?><a href="admin.php">Admin Panel</a><?php endif;?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="logout"><button>Logout</button></form><?php else:?><a href="?page=login">Login</a><a href="?page=register">Register</a><?php endif;?></div><div class="language-switcher"><select id="languageSelect" class="language-select" aria-label="Language"><option value="bn">বাংলা</option><option value="en">English</option><option value="hi">हिन्दी</option></select></div></nav><main class="wrap"><?php if($flashMessage):?><div class="alert"><?=e($flashMessage)?></div><?php endif;?>
<?php if($page==='home'):?>
<div class="tb-home-hero">
  <div class="hero-content">
    <h1>Trusted Bazaar-এ স্বাগতম</h1>
    <p>আপনার আস্থার সাথে সেরা সেবা ও ক্যাটাগরিগুলো উপভোগ করুন</p>
  </div>
</div>
<div class="tb-home-container">
  <div class="tb-section-title">অফিশিয়াল ক্যাটাগরি সমূহ</div>
  <div class="tb-categories-grid">
    <div class="tb-category-card"><i class="fa-solid fa-mobile-screen-button"></i><h3>মোবাইল রিচার্জ</h3></div>
    <div class="tb-category-card"><i class="fa-solid fa-gamepad"></i><h3>গেম টপ-আপ</h3></div>
    <div class="tb-category-card"><i class="fa-solid fa-graduation-cap"></i><h3>শিক্ষার্থী সেবা</h3></div>
    <div class="tb-category-card"><i class="fa-solid fa-shield-halved"></i><h3>নিরাপদ পেমেন্ট</h3></div>
  </div>
</div>
<?php elseif($page==='login'||$page==='register'):?><div class="card form"><div class="muted" style="margin-bottom:6px"><?= $page==='login'?'Welcome back 👋':'Start your journey ✨' ?></div><h2><?=$page==='login'?'Login':'Create account'?></h2><p class="muted" style="margin-top:0;margin-bottom:22px"><?=$page==='login'?'আপনার account-এ নিরাপদে Login করুন।':'নতুন account তৈরি করে panel ব্যবহার শুরু করুন।'?></p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="<?=$page==='login'?'login':'register'?>"><?php if($page==='register'):?><label>Name</label><input class="input" name="name" placeholder="আপনার নাম" autocomplete="name" required><?php endif;?><label>Email</label><input class="input" type="email" name="email" placeholder="you@example.com" autocomplete="email" required><label>Password</label><div class="password-wrap"><input class="input" id="passwordField" type="password" name="password" placeholder="আপনার password লিখুন" autocomplete="<?=$page==='login'?'current-password':'new-password'?>" required><button class="password-toggle" type="button" onclick="togglePassword('passwordField',this)" aria-label="Show password" title="Show password">👁️</button></div><?php if($page==='register'):?><p class="muted" style="margin-top:-5px">কমপক্ষে ৬ অক্ষর।</p><?php endif;?><button class="btn" style="width:100%;margin-top:5px"><?=$page==='login'?'Login →':'Create Account →'?></button></form><div style="text-align:center;margin-top:18px" class="muted"><?php if($page==='login'):?>নতুন account? <a href="?page=register" style="color:#5b4ee8;font-weight:800">Register করুন</a><?php else:?>আগেই account আছে? <a href="?page=login" style="color:#5b4ee8;font-weight:800">Login করুন</a><?php endif;?></div></div>
<?php elseif($page==='dashboard'): needLogin();
$dashPlatforms=[];foreach($services as $ds){$dp=platformOf($ds);if(!isset($dashPlatforms[$dp]))$dashPlatforms[$dp]=['icon'=>platformIcon($dp),'count'=>0];$dashPlatforms[$dp]['count']++;} $dashPlatforms=array_slice($dashPlatforms,0,8,true); $recent=$store->userOrders((int)$u['id']); $recent=array_slice($recent,0,3);
?><div class="promo-slider" id="promoSlider">
  <div class="promo-track" id="promoTrack">
    <div class="promo-slide"><div class="promo-copy"><h2>Trusted BAZAAR</h2><p>দ্রুত, সহজ ও নিরাপদে আপনার প্রয়োজনীয় সার্ভিস নিন।</p></div><div class="promo-icon">🛍️</div></div>
    <div class="promo-slide"><div class="promo-copy"><h2>Easy Add Fund</h2><p>Wallet-এ balance যোগ করে দ্রুত order করুন।</p></div><div class="promo-icon">💳</div></div>
    <div class="promo-slide"><div class="promo-copy"><h2>Get Your Service</h2><p>পছন্দের category থেকে service বেছে নিন।</p></div><div class="promo-icon">⚡</div></div>
  </div>
  <div class="promo-dots"><span class="promo-dot active"></span><span class="promo-dot"></span><span class="promo-dot"></span></div>
</div><div class="customer-shell"><div class="customer-head"><div><div class="customer-brand">Trusted <b style="color:#f05a28">BAZAAR</b></div><div class="customer-tag">All time active service</div></div><div class="head-actions"><a class="wallet-pill" href="?page=deposit">💳 <span data-bdt="<?=e((string)$u['balance'])?>">৳<?=number_format((float)$u['balance'],0)?></span></a><a class="avatar" href="?page=account">👤</a></div></div>
<div class="profile-banner"><div class="profile-mini"><div class="avatar"><?=e(mb_strtoupper(mb_substr((string)$u['name'],0,1)))?></div><div style="flex:1"><div class="muted">CUSTOMER PROFILE</div><h3><?=e($u['name'])?></h3><div class="muted"><?=e($u['email'])?></div><span class="status">● Active Customer</span><div class="profile-stats"><div><b data-bdt="<?=e((string)$u['balance'])?>">৳<?=number_format((float)$u['balance'],2)?></b><span>Wallet Balance</span></div><div><b><?=count($recent)?></b><span>Recent Orders</span></div><div><b>✓</b><span>Account</span></div></div></div><a href="?page=account" style="font-size:26px;color:#5965d8">›</a></div><div class="quick-grid"><a class="quick-card" href="?page=orders"><strong>🛍 My Orders</strong><span>Track all orders</span></a><a class="quick-card" href="?page=deposit"><strong>💰 Add Fund</strong><span>Recharge wallet</span></a><a class="quick-card" href="?page=services"><strong>⚡ Get Service</strong><span>Browse services</span></a></div></div>
<div class="section-heading"><h3>Service Category</h3><a href="?page=services">View All →</a></div><div class="dashboard-cats"><?php foreach($dashPlatforms as $dp=>$info):?><a class="dash-cat" href="?page=services"><div class="cat-img"><?=$info['icon']?></div><strong><?=e(ucwords($dp==='other'?'Other Services':$dp.' Services'))?></strong><span><?=e((string)$info['count'])?> services</span></a><?php endforeach;?></div>
<div class="section-heading"><h3>Recent Orders</h3><a href="?page=orders">View All →</a></div><div class="card" style="padding:8px 15px"><?php if(!$recent):?><div class="muted" style="padding:18px;text-align:center">এখনও কোনো order নেই। <a href="?page=services" style="color:#5b4ee8;font-weight:900">প্রথম order করুন →</a></div><?php else:foreach($recent as $rr):?><div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 4px;border-bottom:1px solid #eef0f4"><div><b><?=e($rr['service_name'])?></b><div class="small">#<?=e((string)$rr['id'])?> • <?=e($rr['created_at'])?></div></div><div style="text-align:right"><b data-bdt="<?=e((string)$rr['total'])?>">৳<?=number_format((float)$rr['total'],2)?></b><div class="small"><span class="badge"><?=e($rr['status'])?></span></div></div></div><?php endforeach;endif;?></div></div>
<?php elseif($page==='account'): needLogin();?><div class="profile-wrap"><div class="profile-hero"><div class="avatar big"><?=e(mb_strtoupper(mb_substr((string)$u['name'],0,1)))?></div><div><div class="eyebrow light">CUSTOMER PROFILE</div><h2><?=e($u['name'])?></h2><p><?=e($u['email'])?></p></div></div><div class="grid" style="margin-top:18px"><div class="card"><div class="muted">Full Name</div><h3><?=e($u['name'])?></h3><div class="muted">Customer account</div></div><div class="card"><div class="muted">Email Address</div><h3><?=e($u['email'])?></h3><div class="muted">Verified account login</div></div><div class="card"><div class="muted">Current Balance</div><div class="balance" data-bdt="<?=e((string)$u['balance'])?>">৳<?=number_format((float)$u['balance'],2)?></div></div><div class="card"><div class="muted">Account Type</div><h3><?=($u['role']??'customer')==='admin'?'Administrator':'Customer'?></h3><div class="muted">Secure panel access</div></div></div></div>
<?php elseif($page==='services'):?>
<div class="toprow"><div><div class="eyebrow">SERVICE MARKETPLACE</div><h2>Premium Services</h2><div class="muted">Platform → service type নির্বাচন করে দ্রুত আপনার প্রয়োজনের সার্ভিস খুঁজুন।</div></div><div class="service-count"><?=count($services)?> Services</div></div>
<?php if($apiError):?><div class="alert"><?=e($apiError)?></div><?php endif;?>
<div class="service-tools"><input class="input" id="search" placeholder="🔎 Search service, keyword or ID..." style="margin:0"><div class="category-bar" id="platformBar">
  <button class="cat active" data-cat="all"><span class="cat-icon">All</span><span>All Services</span></button><?php foreach(['youtube','instagram','tiktok','facebook','telegram','twitter','linkedin','discord','spotify','twitch','soundcloud'] as $cp): ?><button class="cat" data-cat="<?=e($cp)?>"><span class="cat-icon"><?=platformIcon($cp)?></span><span><?=e(ucwords($cp==='twitter'?'X / Twitter':$cp).' Services')?></span></button><?php endforeach; ?><button class="cat" data-cat="other"><span class="cat-icon">Other</span><span>Other Services</span></button>
</div><div class="subcategory-bar" id="subcategoryBar"></div></div>
<div class="grid" id="services">
<?php $priceMap=[];foreach($store->allPrices() as $pp)$priceMap[(string)$pp['service_id']] = (float)$pp['price']; foreach($services as $s):
  $sid=(string)($s['service']??''); $ov=$priceMap[$sid]??null;
  $p=unitPrice($s,$usd,$markup,$ov); $raw=strtolower(($s['name']??'').' '.($s['category']??'').' '.$sid); $platform=platformOf($s); $stype=serviceTypeOf($s,$platform);
?>
<div class="card service-card service-item" data-search="<?=e($raw)?>" data-platform="<?=e($platform)?>" data-type="<?=e(strtolower($stype))?>">
 <div class="service-title"><span class="service-icon"><?=serviceIcon($s)?></span><strong><?=e((string)($s['name']??'Service'))?></strong></div><div class="service-meta"><span class="badge"><?=e(strtoupper($platform))?></span><span class="badge soft"><?=e($stype)?></span><span class="badge soft">ID <?=e($sid)?></span></div>
 <div class="muted service-full-name">Service: <?=e((string)($s['name']??'Service'))?></div><div class="muted">Min <?=e((string)($s['min']??''))?> • Max <?=e((string)($s['max']??''))?></div>
 <div class="bottom"><div><div class="price" data-bdt="<?=e((string)$p)?>">৳<?=number_format($p,2)?></div><div class="muted">per 1,000<?= $ov!==null?' • custom price':''?></div></div><button class="btn" onclick='openOrder(<?=json_encode($sid)?>,<?=json_encode((string)($s['name']??''))?>,<?=json_encode((float)$p)?>,<?=json_encode((int)($s['min']??1))?>,<?=json_encode((int)($s['max']??1000000))?>)'>Order Now</button></div>
</div>
<?php endforeach;?></div>
<div id="emptyState" class="card empty-state" style="display:none"><div class="empty-icon">⌕</div><h3>No service found</h3><p class="muted">Search keyword বা অন্য category নির্বাচন করুন।</p></div>
<div id="modal" style="display:none;position:fixed;inset:0;background:rgba(7,12,25,.72);backdrop-filter:blur(7px);padding:20px;z-index:100"><div class="card modal-card"><div class="toprow"><div><div class="eyebrow">QUICK ORDER</div><h3>Place Order</h3></div><button class="icon-btn" onclick="closeOrder()">✕</button></div><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="order"><input type="hidden" name="service_id" id="sid"><input type="hidden" name="service_name" id="sname"><input type="hidden" name="unit_price" id="price"><label>Service</label><input class="input" id="slabel" disabled><label>Link</label><input class="input" name="link" placeholder="https://..." required><label>Quantity</label><input class="input" type="number" name="quantity" id="qty" required oninput="calc()"><div class="order-total"><span>Total</span><b id="total" data-bdt-total="1">৳0.00</b></div><button class="btn green" style="width:100%;margin-top:14px">Confirm Order</button></form></div></div>
<script>
const search=document.getElementById('search'), cards=[...document.querySelectorAll('.service-item')], subBar=document.getElementById('subcategoryBar'), empty=document.getElementById('emptyState');let activePlatform='all',activeType='all';
const typeSets={youtube:['Subscribers','Views','Likes','Comments','Watch Time','Other'],tiktok:['Followers','Views','Likes','Subscribers','Comments','Shares','Other'],instagram:['Followers','Likes','Views','Comments','Story Views','Saves','Other'],facebook:['Followers','Likes','Views','Comments','Shares','Reactions','Other'],telegram:['Members','Views','Reactions','Other'],twitter:['Followers','Likes','Views','Comments','Other'],linkedin:['Followers','Likes','Views','Comments','Other'],discord:['Members','Other'],spotify:['Followers','Plays','Saves','Other'],twitch:['Followers','Views','Other'],soundcloud:['Followers','Plays','Likes','Other'],other:['Other']};
function drawTypes(){subBar.innerHTML=''; if(activePlatform==='all')return; (typeSets[activePlatform]||['Other']).forEach(t=>{const b=document.createElement('button');b.className='subcat '+(activeType===t.toLowerCase()?'active':'');b.textContent=t;b.onclick=()=>{activeType=t.toLowerCase();drawTypes();renderServices()};subBar.appendChild(b)});}
function renderServices(){const q=(search?.value||'').toLowerCase().trim();let shown=0;cards.forEach(x=>{const okP=activePlatform==='all'||x.dataset.platform===activePlatform;const okT=activeType==='all'||x.dataset.type===activeType;const okQ=x.dataset.search.includes(q);const show=okP&&okT&&okQ;x.style.display=show?'flex':'none';if(show)shown++});if(empty)empty.style.display=shown?'none':'block'}
if(search)search.oninput=renderServices;document.querySelectorAll('#platformBar .cat').forEach(b=>b.onclick=()=>{document.querySelectorAll('#platformBar .cat').forEach(x=>x.classList.remove('active'));b.classList.add('active');activePlatform=b.dataset.cat;activeType='all';drawTypes();renderServices()});
function openOrder(id,name,p,min,max){document.getElementById('modal').style.display='block';document.getElementById('sid').value=id;document.getElementById('sname').value=name;document.getElementById('price').value=p;document.getElementById('slabel').value=name+' (#'+id+')';let q=document.getElementById('qty');q.min=min;q.max=max;q.value=min;calc()}
function closeOrder(){document.getElementById('modal').style.display='none'}function calc(){let q=+document.getElementById('qty').value||0,p=+document.getElementById('price').value||0;let b=q*p/1000;let l=localStorage.getItem('trustedBazaarLanguage')||'bn';let out=l==='en'?'$'+(b/130).toFixed(2):l==='hi'?'₹'+(b*102/130).toFixed(2):'৳'+b.toFixed(2);document.getElementById('total').innerText=out}drawTypes();renderServices();
</script>
<?php elseif($page==='deposit'):needLogin();?><div class="card form"><h2>Deposit Request</h2><div class="payment-box"><div><b>bKash</b><div class="payment-number"><?=e($paymentNumbers['bKash'])?></div></div><div><b>Nagad</b><div class="payment-number"><?=e($paymentNumbers['Nagad'])?></div></div><div><b>Binance UID</b><div class="payment-number"><?=e($paymentNumbers['Binance UID'])?></div><div class="muted">USDT রেট: <b>125৳ = $1 USDT</b></div></div><div class="muted">Payment করার পর Transaction ID দিন। সর্বনিম্ন Deposit: <b>৳50</b></div></div><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="deposit"><label>Payment Method</label><select name="method" id="paymentMethod" required><option value="bKash">bKash — <?=e($paymentNumbers['bKash'])?></option><option value="Nagad">Nagad — <?=e($paymentNumbers['Nagad'])?></option><option value="Binance UID">Binance UID — <?=e($paymentNumbers['Binance UID'])?></option></select><label>Amount (BDT)</label><input class="input" id="depositAmount" type="number" min="50" step="0.01" name="amount" required><div class="muted" id="usdtHint">Minimum deposit ৳50</div><label>Transaction ID</label><input class="input" name="trx_id" required><label>Note</label><textarea class="input" name="note"></textarea><button class="btn green">Submit Deposit</button></form></div><script>(function(){const m=document.getElementById('paymentMethod'),a=document.getElementById('depositAmount'),h=document.getElementById('usdtHint');function u(){const v=parseFloat(a.value||0);h.textContent=m.value==='Binance UID'?(v>=50?'USDT হিসাব: $'+(v/125).toFixed(2)+' • রেট 125৳ = $1 USDT':'USDT রেট: 125৳ = $1 USDT'):'Minimum deposit ৳50'}if(m)m.addEventListener('change',u);if(a)a.addEventListener('input',u);u()})();</script>
<?php elseif($page==='supercell'):needLogin();$products=$store->digitalProducts(true);$owned=$store->userDigitalPurchases((int)$u['id']);$ownedIds=[];foreach($owned as $op)$ownedIds[(int)$op['id']]=true;?><div class="toprow"><h2>🎮 সুপারসেল গেম আইটেম</h2><a class="btn" href="?page=deposit">💳 Add Fund</a></div>
<div class="grid"><?php foreach($products as $p): ?><div class="card service-card"><div style="position:relative"><img loading="lazy" src="<?=e($p['image_url'] ?: 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22600%22 height=%22300%22%3E%3Crect width=%22100%25%22 height=%22100%25%22 fill=%22%23101828%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 fill=%22white%22 font-size=%2228%22%3ESupercell Item%3C/text%3E%3C/svg%3E' )?>" alt="<?=e($p['name'])?>" style="width:100%;height:190px;object-fit:cover;border-radius:14px"></div><h3><?=e($p['name'])?></h3><?php if($p['description']): ?><p class="muted"><?=e($p['description'])?></p><?php endif; ?><div class="bottom"><b class="price" data-bdt="<?=e((string)$p['price'])?>">৳<?=number_format((float)$p['price'],2)?></b><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="buy_digital"><input type="hidden" name="product_id" value="<?=e((string)$p['id'])?>"><button class="btn" type="submit">💳 Buy & Get Link</button></form></div></div><?php endforeach; ?><?php if(!$products): ?><div class="card"><h3>এই মুহূর্তে কোনো item available নেই</h3><p class="muted">নতুন Supercell item যোগ হলে এখানে দেখা যাবে।</p></div><?php endif; ?></div>
<h3 class="section-title" id="purchase-history">👤 My Supercell Purchase History</h3><div class="grid"><?php if(!$owned): ?><div class="card"><p class="muted">আপনি এখনো কোনো Supercell item কিনেননি।</p></div><?php else: foreach($owned as $op): ?><div class="card service-card"><div class="muted">Purchased: <?=e($op['purchased_at'])?></div><h3><?=e($op['name'])?></h3><div class="bottom"><b class="price" data-bdt="<?=e((string)$op['price'])?>">৳<?=number_format((float)$op['price'],2)?></b><button class="btn green" type="button" onclick="copySupercellLink(this)" data-link="<?=e($op['access_link'])?>">📋 Copy Link</button></div><div class="mini-note">✅ Payment completed — এই protected link শুধু আপনার account-এর জন্য।</div></div><?php endforeach; endif; ?></div>
<div class="telegram-help"><a href="https://t.me/Rayhanvai120" target="_blank" rel="noopener">💬 Help: Telegram @Rayhanvai120</a></div>
<script>function copySupercellLink(btn){const link=btn.dataset.link||'';if(!link)return;const done=()=>{const old=btn.innerText;btn.innerText='✅ Copied';setTimeout(()=>btn.innerText=old,1500)};if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(link).then(done).catch(()=>prompt('লিংক কপি করুন:',link));}else{prompt('লিংক কপি করুন:',link);}}</script>
<?php elseif($page==='orders'):needLogin();$rows=$store->userOrders((int)$u['id']);if(isset($_GET['refresh'])&&$_GET['refresh']==='1'){syncProviderStatuses($store,$rows,10);$rows=$store->userOrders((int)$u['id']);}?><div class="toprow"><h2>My Orders</h2><div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn refresh-btn" href="?page=orders&refresh=1">🔄 Refresh Status</a><a class="btn" href="?page=services">New Order</a></div></div><div class="mini-note" style="margin-bottom:14px">🟢 <b>Completed ✓</b> মানে অর্ডার সম্পূর্ণ হয়েছে। 🔵 Processing মানে কাজ চলছে, 🟡 Pending মানে অপেক্ষায় আছে।</div><div style="overflow:auto"><table class="table"><tr><th>ID</th><th>Service</th><th>Qty</th><th>Total</th><th>Status</th><th>Date</th></tr><?php foreach($rows as $r):?><tr><td>#<?=e((string)$r['id'])?><?php if($r['provider_order_id']):?><div class="small">Provider: <?=e($r['provider_order_id'])?></div><?php endif;?></td><td><?=e($r['service_name'])?></td><td><?=e((string)$r['quantity'])?></td><td>৳<?=number_format((float)$r['total'],2)?></td><td><span class="status-badge <?=e(statusClass($r['status']))?>"><?=e(statusLabel($r['status']))?></span></td><td><?=e($r['created_at'])?></td></tr><?php endforeach;?></table></div>
<?php elseif($page==='admin'):needAdmin();$deps=$store->adminDeposits();$orders=$store->adminOrders();if(isset($_GET['refresh'])&&$_GET['refresh']==='1'){syncProviderStatuses($store,$orders,10);$orders=$store->adminOrders();}$customPrices=$store->allPrices();$customPriceMap=[];foreach($customPrices as $cp)$customPriceMap[(string)$cp['service_id']] = (float)$cp['price'];$stats=$store->counts();$digitalSales=$store->digitalSales();$digitalSalesTotal=$store->digitalSalesTotal();$editSupercellId=(int)($_GET['edit_supercell']??0);$editSupercell=$editSupercellId?$store->digitalProduct($editSupercellId):null;?>
<div class="admin-shell"><div class="admin-hero"><div><div class="admin-title">Trusted Bazaar Admin Panel</div><div class="admin-sub">Users, orders, services, pricing ও deposits এক জায়গা থেকে পরিচালনা করুন।</div></div><span class="admin-badge">● <?=e($adminEmail)?></span></div>
<div class="admin-stat-grid"><div class="admin-stat"><span>👥 Total Users</span><b><?=e((string)$stats['users'])?></b></div><div class="admin-stat"><span>🛍 Total Orders</span><b><?=e((string)$stats['orders'])?></b></div><div class="admin-stat"><span>💳 Pending Deposits</span><b><?=e((string)$stats['deposits_pending'])?></b></div><div class="admin-stat"><span>💰 Total Sales</span><b>৳<?=number_format((float)$stats['sales'],0)?></b></div></div>
<div class="admin-actions"><a class="admin-action" href="#payments">📱 Payment Number Change</a><a class="admin-action" href="#pricing">💰 Price Control</a><a class="admin-action" href="#supercell">🎮 Add Supercell Item</a><a class="admin-action" href="#deposit-requests">✅ Accept Payment Request</a><a class="admin-action" href="#supercell-sales">📊 Supercell Sales</a><a class="admin-action" href="#orders">🛍 SMM Orders</a></div>
<div class="admin-grid"><div class="card"><h3>📊 Store Overview</h3><div class="grid" style="margin-top:14px"><div><div class="muted">Services</div><div class="stat-number" style="font-size:30px"><?=count($services)?></div></div><div><div class="muted">Custom Prices</div><div class="stat-number" style="font-size:30px"><?=count($customPrices)?></div></div></div></div><div class="card"><h3>💰 Pricing Control</h3><div class="stat-number"><?=count($customPrices)?></div><div class="muted">Custom priced services</div><div class="mini-note">Custom price দিলে customer সেই দামেই order করবে। Reset করলে provider rate + markup ফিরে আসবে।</div></div></div>
<h3 class="section-title">Live Service Pricing</h3><div class="service-tools"><input class="input" id="adminPriceSearch" placeholder="🔎 Search service by name, platform or ID..." style="margin:0"></div><div style="overflow:auto"><table class="table" id="priceTable"><tr><th>ID</th><th>Service</th><th>Platform</th><th>Default</th><th>Customer Price / 1K</th><th>Action</th></tr><?php foreach($services as $as):$asid=(string)($as['service']??'');$custom=$customPriceMap[$asid]??null;$default=unitPrice($as,$usd,$markup);?><tr class="price-row" data-search="<?=e(strtolower(($as['name']??'').' '.platformOf($as).' '.$asid))?>"><td>#<?=e($asid)?></td><td><?=e((string)($as['name']??'Service'))?><div class="small">Min <?=e((string)($as['min']??''))?> • Max <?=e((string)($as['max']??''))?></div></td><td><span class="badge"><?=e(strtoupper(platformOf($as)))?></span></td><td>৳<?=number_format($default,2)?></td><td><form method="post" class="inline-price-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save_service_price"><input type="hidden" name="service_id" value="<?=e($asid)?>"><input class="input" type="number" name="price" min="0.01" step="0.01" value="<?=e(number_format($custom!==null?$custom:$default,2,'.',''))?>" required><button class="btn" type="submit">Save</button></form></td><td><?php if($custom!==null):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="delete_service_price"><input type="hidden" name="service_id" value="<?=e($asid)?>"><button class="btn secondary" type="submit">Reset</button></form><?php else:?><span class="muted">Default</span><?php endif;?></td></tr><?php endforeach;?></table></div>
<h3 class="section-title" id="payments">📱 Payment Number Settings</h3><div class="card"><div class="mini-note" style="margin-top:0;margin-bottom:14px">এখান থেকে bKash ও Nagad-এর Customer Deposit নম্বর পরিবর্তন করুন। Binance UID স্থিরভাবে <b>828400982</b> এবং USDT রেট <b>125৳ = $1</b> রাখা হয়েছে।</div><form method="post" class="settings-grid"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save_payment_settings"><div><label>bKash Payment Number</label><input class="input" name="bkash" inputmode="numeric" pattern="01[0-9]{9}" value="<?=e($paymentNumbers['bKash'])?>" required></div><div><label>Nagad Payment Number</label><input class="input" name="nagad" inputmode="numeric" pattern="01[0-9]{9}" value="<?=e($paymentNumbers['Nagad'])?>" required></div><div><label>Binance UID</label><input class="input" value="828400982" readonly></div><div><label>USDT Rate</label><input class="input" value="125৳ = $1 USDT" readonly></div><div style="grid-column:1/-1"><button class="btn green" type="submit">💾 Save Payment Numbers</button></div></form></div>
<h3 class="section-title" id="currency">💱 Currency Rate Settings</h3><div class="card"><div class="mini-note" style="margin-top:0;margin-bottom:14px">BDT মূল currency হিসেবে স্থির থাকবে। Customer English নির্বাচন করলে USD এবং Hindi নির্বাচন করলে INR display rate অনুযায়ী দেখাবে। এখানে rate পরিবর্তন করলে নতুন display price automatically আপডেট হবে।</div><form method="post" class="settings-grid"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save_currency_settings"><div><label>USD Rate</label><input class="input" type="number" name="usd_to_bdt" min="0.01" step="0.01" value="<?=e((string)$usdToBdt)?>" required><div class="small">1 USD = কত BDT</div></div><div><label>INR Rate</label><input class="input" type="number" name="inr_per_usd" min="0.01" step="0.01" value="<?=e((string)$inrPerUsd)?>" required><div class="small">1 USD = কত INR</div></div><div style="grid-column:1/-1"><button class="btn green" type="submit">💾 Save Currency Rates</button></div></form></div>
<h3 class="section-title" id="users">Admin & User Management</h3><div class="card"><div class="admin-note">এখান থেকে কোনো Customer-কে Admin করা বা Admin access সরিয়ে Customer করা যাবে। নিজের Admin access নিজে সরানো যাবে না।</div><div style="overflow:auto;margin-top:12px"><table class="table admin-user-table"><tr><th>User</th><th>Email</th><th>Role</th><th>Action</th></tr><?php foreach($store->users() as $usr):?><tr><td><?=e($usr['name'])?></td><td><?=e($usr['email'])?></td><td><span class="badge"><?=e($usr['role']??'customer')?></span></td><td><?php if((int)$usr['id']===(int)$u['id']):?><span class="muted">Current Admin</span><?php else:?><form method="post" style="display:flex;gap:7px;align-items:center"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="set_user_role"><input type="hidden" name="user_id" value="<?=e((string)$usr['id'])?>"><select class="input" name="role" style="margin:0"><option value="customer" <?=($usr['role']??'customer')==='customer'?'selected':''?>>Customer</option><option value="admin" <?=($usr['role']??'customer')==='admin'?'selected':''?>>Admin</option></select><button class="btn" type="submit">Update</button></form><?php endif;?></td></tr><?php endforeach;?></table></div></div>
<h3 class="section-title" id="bkash-deposits">📱 bKash Deposit Requests</h3><div class="card" style="margin-bottom:18px"><div class="mini-note" style="margin-top:0;margin-bottom:14px">bKash থেকে আসা pending deposit request এখানে Approve অথবা Reject করুন।</div><div style="overflow:auto"><table class="table"><tr><th>User</th><th>Amount</th><th>TRX</th><th>Status</th><th>Action</th></tr><?php $bkashDeps=array_values(array_filter($deps,fn($x)=>$x['method']==='bKash')); if(!$bkashDeps):?><tr><td colspan="5" style="text-align:center;padding:24px">কোনো bKash deposit request নেই।</td></tr><?php else: foreach($bkashDeps as $d):?><tr><td><?=e($d['name'])?><div class="small"><?=e($d['email'])?></div></td><td>৳<?=number_format((float)$d['amount'],2)?></td><td><?=e($d['trx_id'])?></td><td><span class="badge"><?=e($d['status'])?></span></td><td><?php if($d['status']==='pending'):?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="approve_deposit"><input type="hidden" name="id" value="<?=$d['id']?>"><button class="btn green">✅ Approve</button></form> <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="reject_deposit"><input type="hidden" name="id" value="<?=$d['id']?>"><button class="btn secondary">❌ Reject</button></form><?php endif;?></td></tr><?php endforeach; endif;?></table></div></div>
<h3 class="section-title" id="deposit-requests">💳 Payment Requests — Accept / Reject</h3><div style="overflow:auto"><table class="table"><tr><th>User</th><th>Method</th><th>Amount</th><th>TRX</th><th>Status</th><th>Action</th></tr><?php foreach($deps as $d):?><tr><td><?=e($d['name'])?><div class="small"><?=e($d['email'])?></div></td><td><?=e($d['method'])?></td><td>৳<?=number_format((float)$d['amount'],2)?></td><td><?=e($d['trx_id'])?></td><td><span class="badge"><?=e($d['status'])?></span></td><td><?php if($d['status']==='pending'):?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="approve_deposit"><input type="hidden" name="id" value="<?=$d['id']?>"><button class="btn green">Approve</button></form> <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="reject_deposit"><input type="hidden" name="id" value="<?=$d['id']?>"><button class="btn secondary">Reject</button></form><?php endif;?></td></tr><?php endforeach;?></table></div>
<h3 class="section-title" id="supercell">🎮 সুপারসেল গেম আইটেম</h3>
<div class="card" id="supercell-manager">
<div class="mini-note">একটি item বিক্রি হয়ে গেলে সেটি Customer Panel থেকে সঙ্গে সঙ্গে অদৃশ্য হবে। শুধু যে Customer কিনেছে তার Purchase History-তে item ও protected link থাকবে।</div>
<?php if($editSupercell): ?>
<form method="post" enctype="multipart/form-data" class="settings-grid" style="margin-top:14px"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save_digital"><input type="hidden" name="id" value="<?=e((string)$editSupercell['id'])?>">
<div><label>Item Name</label><input class="input" name="name" value="<?=e($editSupercell['name'])?>" required></div>
<div><label>Price ৳</label><input class="input" type="number" step="0.01" min="0.01" name="price" value="<?=e((string)$editSupercell['price'])?>" placeholder="৳" required></div>
<div><label>Price $</label><input class="input" type="number" step="0.01" min="0.01" name="price_usd" value="<?=e((string)($editSupercell['price_usd']??0))?>" placeholder="$" required></div>
<div><label>Item Image</label><input class="input" type="file" name="image_file" accept="image/jpeg,image/png,image/webp"><div class="muted">সরাসরি ছবি আপলোড করুন • JPG/PNG/WEBP • সর্বোচ্চ 5MB<?php if(!empty($editSupercell['image_url'])): ?> • বর্তমান ছবি রাখা হবে যদি নতুন ছবি না দেন<?php endif; ?></div></div>
<div><label>Paid Access Link</label><input class="input" name="access_link" value="<?=e($editSupercell['access_link'])?>" required></div>
<div style="grid-column:1/-1"><label>Description</label><textarea class="input" name="description" rows="2"><?=e($editSupercell['description'])?></textarea></div>
<div style="grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap"><button class="btn green" type="submit">💾 Save Changes</button><a class="btn secondary" href="?page=admin#supercell">Cancel</a></div></form>
<?php else: ?>
<form method="post" enctype="multipart/form-data" class="settings-grid" style="margin-top:14px"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save_digital"><input type="hidden" name="id" value="0">
<div><label>Item Name</label><input class="input" name="name" placeholder="যেমন: Clash of Clans Item" required></div>
<div><label>Price ৳</label><input class="input" type="number" step="0.01" min="0.01" name="price" placeholder="৳" required></div>
<div><label>Price $</label><input class="input" type="number" step="0.01" min="0.01" name="price_usd" placeholder="$" required></div>
<div><label>Item Image</label><input class="input" type="file" name="image_file" accept="image/jpeg,image/png,image/webp" required><div class="muted">সরাসরি ছবি আপলোড করুন • JPG/PNG/WEBP • সর্বোচ্চ 5MB</div></div>
<div><label>Paid Access Link</label><input class="input" name="access_link" placeholder="https://..." required></div>
<div style="grid-column:1/-1"><label>Description</label><textarea class="input" name="description" rows="2" placeholder="Item সম্পর্কে সংক্ষিপ্ত তথ্য"></textarea></div>
<div style="grid-column:1/-1"><button class="btn green" type="submit">➕ Add Supercell Item</button></div></form>
<?php endif; ?>
<div style="overflow:auto;margin-top:18px"><table class="table"><tr><th>Image</th><th>Item</th><th>Price</th><th>Status</th><th>Action</th></tr>
<?php foreach($store->digitalProducts(false) as $sp): ?><tr>
<td><?php if($sp['image_url']): ?><img loading="lazy" src="<?=e($sp['image_url'])?>" alt="" style="width:70px;height:50px;object-fit:cover;border-radius:8px"><?php else: ?>—<?php endif; ?></td>
<td><?=e($sp['name'])?><div class="small"><?=e($sp['description'])?></div></td><td>৳<?=number_format((float)$sp['price'],2)?><br>$<?=number_format((float)($sp['price_usd']??0),2)?></td>
<td><?php if(!empty($sp['active'])):?><span class="badge">Available</span><?php else:?><span class="badge" style="background:#fee2e2;color:#b91c1c">SOLD</span><?php endif;?></td>
<td><div style="display:flex;gap:6px;flex-wrap:wrap"><a class="btn" href="?page=admin&edit_supercell=<?=e((string)$sp['id'])?>#supercell">✏️ Edit</a><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="delete_digital"><input type="hidden" name="id" value="<?=e((string)$sp['id'])?>"><button class="btn secondary" type="submit">🗑 Delete</button></form></div></td>
</tr><?php endforeach; ?>
<?php if(!$store->digitalProducts(false)): ?><tr><td colspan="5" style="text-align:center;padding:22px">কোনো Supercell item নেই।</td></tr><?php endif; ?></table></div></div>
<h3 class="section-title" id="supercell-sales">📊 Supercell Sales / Purchase History</h3>
<div class="card"><div class="mini-note">মোট Supercell sales: <b>৳<?=number_format($digitalSalesTotal,2)?></b></div><div style="overflow:auto;margin-top:12px"><table class="table"><tr><th>ID</th><th>Customer</th><th>Item</th><th>Amount</th><th>Date</th></tr>
<?php if(!$digitalSales): ?><tr><td colspan="5" style="text-align:center;padding:22px">এখনো কোনো Supercell item বিক্রি হয়নি।</td></tr><?php else: foreach($digitalSales as $ds): ?><tr><td>#<?=e((string)$ds['id'])?></td><td><?=e($ds['name'])?><div class="small"><?=e($ds['email'])?></div></td><td><?=e($ds['product_name'])?></td><td>৳<?=number_format((float)$ds['amount'],2)?></td><td><?=e($ds['purchased_at'])?></td></tr><?php endforeach; endif; ?></table></div></div>
<h3 class="section-title" id="orders">Recent Orders</h3><div class="mini-note" style="margin-bottom:14px">🟢 <b>Completed ✓</b> = অর্ডার সম্পূর্ণ। 🔵 Processing = কাজ চলছে। 🟡 Pending = অপেক্ষায়। <a class="btn refresh-btn" style="float:right;padding:7px 11px" href="?page=admin&refresh=1#orders">🔄 Refresh</a></div><div style="overflow:auto"><table class="table"><tr><th>ID</th><th>User</th><th>Service</th><th>Qty</th><th>Total</th><th>Provider</th><th>Status</th></tr><?php foreach($orders as $o):?><tr><td>#<?=$o['id']?></td><td><?=e($o['name'])?></td><td><?=e($o['service_name'])?></td><td><?=e((string)$o['quantity'])?></td><td>৳<?=number_format((float)$o['total'],2)?></td><td><?=e($o['provider_order_id']?:'-')?></td><td><span class="status-badge <?=e(statusClass($o['status']))?>"><?=e(statusLabel($o['status']))?></span></td></tr><?php endforeach;?></table></div>
</div></div><script>const aps=document.getElementById('adminPriceSearch');if(aps)aps.oninput=()=>{const q=aps.value.toLowerCase().trim();document.querySelectorAll('.price-row').forEach(r=>r.style.display=r.dataset.search.includes(q)?'':'none')};</script>
<?php else:go('?page=home');endif;?><?php if($u && ($page!=='admin')):?><nav class="mobile-bottom"><a class="active" href="?page=dashboard"><i>⌂</i>Home</a><a href="?page=deposit"><i>▣</i>Add Fund</a><a href="?page=orders"><i>♧</i>My Orders</a><a href="?page=services"><i>▦</i>My Codes</a><a href="?page=account"><i>♙</i>Account</a></nav><?php endif;?><script>function togglePassword(id,btn){const input=document.getElementById(id);if(!input)return;const show=input.type==='password';input.type=show?'text':'password';btn.textContent=show?'🙈':'👁️';btn.setAttribute('aria-label',show?'Hide password':'Show password');btn.title=show?'Hide password':'Show password';}</script></main><script>
(function(){const D={
'Welcome to Trusted Bazaar':['আপনাকে Trusted Bazaar-এ স্বাগতম।','Welcome to Trusted Bazaar','Trusted Bazaar में आपका स्वागत है।'],'আপনাকে Trusted Bazaar-এ স্বাগতম। আপনার account ব্যবহার করতে Login করুন।':['আপনাকে Trusted Bazaar-এ স্বাগতম। আপনার account ব্যবহার করতে Login করুন।','Welcome to Trusted Bazaar. Login to use your account.','Trusted Bazaar में आपका स्वागत है। अपना account इस्तेमाल करने के लिए Login करें।'],'Login করুন →':['Login করুন →','Login →','Login करें →'],'Secure Customer & Admin Panel':['নিরাপদ Customer & Admin Panel','Secure Customer & Admin Panel','सुरक्षित Customer & Admin Panel'],
'Account':['অ্যাকাউন্ট','Account','अकाउंट'],'Dashboard':['ড্যাশবোর্ড','Dashboard','डैशबोर्ड'],'Services':['সার্ভিস','Services','सेवाएँ'],'🎮 সুপারসেল গেম আইটেম':['🎮 সুপারসেল গেম আইটেম','🎮 Supercell Game Items','🎮 सुपरसेल गेम आइटम'],'Orders':['অর্ডার','Orders','ऑर्डर'],'Deposit':['ডিপোজিট','Deposit','डिपॉज़िट'],'💬 Help':['💬 সাহায্য','💬 Help','💬 मदद'],'Admin Panel':['অ্যাডমিন প্যানেল','Admin Panel','एडमिन पैनल'],'Logout':['লগআউট','Logout','लॉगआउट'],'Login':['লগইন','Login','लॉगिन'],'Register':['রেজিস্টার','Register','रजिस्टर'],
'Welcome back 👋':['আবার স্বাগতম 👋','Welcome back 👋','फिर से स्वागत है 👋'],'Start your journey ✨':['আপনার যাত্রা শুরু করুন ✨','Start your journey ✨','अपनी यात्रा शुरू करें ✨'],'Create account':['অ্যাকাউন্ট তৈরি করুন','Create account','अकाउंट बनाएं'],'আপনার account-এ নিরাপদে Login করুন।':['আপনার account-এ নিরাপদে Login করুন।','Login securely to your account.','अपने account में सुरक्षित रूप से Login करें।'],'নতুন account তৈরি করে panel ব্যবহার শুরু করুন।':['নতুন account তৈরি করে panel ব্যবহার শুরু করুন।','Create a new account to start using the panel.','नया account बनाकर panel इस्तेमाल करना शुरू करें।'],'Name':['নাম','Name','नाम'],'Email':['ইমেইল','Email','ईमेल'],'Password':['পাসওয়ার্ড','Password','पासवर्ड'],'কমপক্ষে ৬ অক্ষর।':['কমপক্ষে ৬ অক্ষর।','At least 6 characters.','कम से कम 6 अक्षर।'],'Login →':['লগইন →','Login →','लॉगिन →'],'Create Account →':['অ্যাকাউন্ট তৈরি করুন →','Create Account →','अकाउंट बनाएं →'],'নতুন account?':['নতুন account?','New account?','नया account?'],'Register করুন':['রেজিস্টার করুন','Register','रजिस्टर करें'],'আগেই account আছে?':['আগেই account আছে?','Already have an account?','पहले से account है?'],'Login করুন':['লগইন করুন','Login','लॉगिन करें'],
'All time active service':['সবসময় সক্রিয় সার্ভিস','All time active service','हमेशा सक्रिय सेवा'],'CUSTOMER PROFILE':['কাস্টমার প্রোফাইল','CUSTOMER PROFILE','कस्टमर प्रोफ़ाइल'],'● Active Customer':['● সক্রিয় কাস্টমার','● Active Customer','● सक्रिय कस्टमर'],'Wallet Balance':['ওয়ালেট ব্যালেন্স','Wallet Balance','वॉलेट बैलेंस'],'Recent Orders':['সাম্প্রতিক অর্ডার','Recent Orders','हाल के ऑर्डर'],'Track all orders':['সব অর্ডার দেখুন','Track all orders','सभी ऑर्डर ट्रैक करें'],'Add Fund':['ফান্ড যোগ করুন','Add Fund','फंड जोड़ें'],'Recharge wallet':['ওয়ালেট রিচার্জ করুন','Recharge wallet','वॉलेट रिचार्ज करें'],'Get Service':['সার্ভিস নিন','Get Service','सेवा लें'],'Browse services':['সার্ভিস দেখুন','Browse services','सेवाएँ देखें'],'Service Category':['সার্ভিস ক্যাটাগরি','Service Category','सेवा श्रेणी'],'View All →':['সব দেখুন →','View All →','सभी देखें →'],'এখনও কোনো order নেই।':['এখনও কোনো order নেই।','No orders yet.','अभी तक कोई order नहीं है।'],'প্রথম order করুন →':['প্রথম order করুন →','Place your first order →','अपना पहला order करें →'],
'SERVICE MARKETPLACE':['সার্ভিস মার্কেটপ্লেস','SERVICE MARKETPLACE','सेवा मार्केटप्लेस'],'Premium Services':['প্রিমিয়াম সার্ভিস','Premium Services','प्रीमियम सेवाएँ'],'Platform → service type নির্বাচন করে দ্রুত আপনার প্রয়োজনের সার্ভিস খুঁজুন।':['Platform → service type নির্বাচন করে দ্রুত আপনার প্রয়োজনের সার্ভিস খুঁজুন।','Select a platform and service type to quickly find what you need.','Platform → service type चुनकर अपनी ज़रूरत की service जल्दी खोजें।'],'All':['সব','All','सभी'],'All Services':['সব সার্ভিস','All Services','सभी सेवाएँ'],'Other':['অন্যান্য','Other','अन्य'],'Other Services':['অন্যান্য সার্ভিস','Other Services','अन्य सेवाएँ'],'Order Now':['অর্ডার করুন','Order Now','ऑर्डर करें'],'No service found':['কোনো সার্ভিস পাওয়া যায়নি','No service found','कोई service नहीं मिली'],'QUICK ORDER':['দ্রুত অর্ডার','QUICK ORDER','त्वरित ऑर्डर'],'Place Order':['অর্ডার দিন','Place Order','ऑर्डर दें'],'Service':['সার্ভিস','Service','सेवा'],'Link':['লিংক','Link','लिंक'],'Quantity':['পরিমাণ','Quantity','मात्रा'],'Total':['মোট','Total','कुल'],'Confirm Order':['অর্ডার নিশ্চিত করুন','Confirm Order','ऑर्डर की पुष्टि करें'],'My Orders':['আমার অর্ডার','My Orders','मेरे ऑर्डर'],'🔄 Refresh Status':['🔄 স্ট্যাটাস রিফ্রেশ','🔄 Refresh Status','🔄 स्टेटस रिफ्रेश'],'New Order':['নতুন অর্ডার','New Order','नया ऑर्डर'],'Completed ✓':['সম্পূর্ণ ✓','Completed ✓','पूरा ✓'],'Processing':['প্রসেসিং','Processing','प्रोसेसिंग'],'Pending':['অপেক্ষায়','Pending','लंबित'],'Date':['তারিখ','Date','तारीख'],
'Full Name':['পুরো নাম','Full Name','पूरा नाम'],'Customer account':['কাস্টমার অ্যাকাউন্ট','Customer account','कस्टमर अकाउंट'],'Email Address':['ইমেইল ঠিকানা','Email Address','ईमेल पता'],'Verified account login':['ভেরিফায়েড অ্যাকাউন্ট লগইন','Verified account login','सत्यापित अकाउंट लॉगिन'],'Current Balance':['বর্তমান ব্যালেন্স','Current Balance','वर्तमान बैलेंस'],'Account Type':['অ্যাকাউন্টের ধরন','Account Type','अकाउंट प्रकार'],'Administrator':['অ্যাডমিনিস্ট্রেটর','Administrator','एडमिनिस्ट्रेटर'],'Customer':['কাস্টমার','Customer','कस्टमर'],'Secure panel access':['নিরাপদ প্যানেল অ্যাক্সেস','Secure panel access','सुरक्षित पैनल एक्सेस'],
'Save':['সেভ','Save','सेव'],'Reset':['রিসেট','Reset','रीसेट'],'Update':['আপডেট','Update','अपडेट'],'Cancel':['বাতিল','Cancel','रद्द करें'],'Delete':['ডিলিট','Delete','हटाएं'],'Available':['উপলভ্য','Available','उपलब्ध'],'SOLD':['বিক্রি হয়েছে','SOLD','बिक गया'],'Item Name':['আইটেমের নাম','Item Name','आइटम का नाम'],'Price ৳':['দাম ৳','Price ৳','कीमत ৳'],'Price $':['দাম $','Price $','कीमत $'],'Item Image':['আইটেমের ছবি','Item Image','आइटम की तस्वीर'],'Paid Access Link':['পেইড অ্যাক্সেস লিংক','Paid Access Link','पेड एक्सेस लिंक'],'Description':['বিবরণ','Description','विवरण'],'💾 Save Changes':['💾 পরিবর্তন সেভ করুন','💾 Save Changes','💾 बदलाव सेव करें'],'➕ Add Supercell Item':['➕ Supercell Item যোগ করুন','➕ Add Supercell Item','➕ Supercell Item जोड़ें'],
'Payment Number Settings':['পেমেন্ট নম্বর সেটিংস','Payment Number Settings','पेमेंट नंबर सेटिंग्स'],'Admin & User Management':['অ্যাডমিন ও ইউজার ম্যানেজমেন্ট','Admin & User Management','एडमिन और यूज़र प्रबंधन'],'Total Users':['মোট ইউজার','Total Users','कुल यूज़र'],'Total Orders':['মোট অর্ডার','Total Orders','कुल ऑर्डर'],'Pending Deposits':['অপেক্ষমাণ ডিপোজিট','Pending Deposits','लंबित डिपॉज़िट'],'Total Sales':['মোট বিক্রি','Total Sales','कुल बिक्री'],'Store Overview':['স্টোর ওভারভিউ','Store Overview','स्टोर ओवरव्यू'],'Pricing Control':['মূল্য নিয়ন্ত্রণ','Pricing Control','मूल्य नियंत्रण'],'Live Service Pricing':['লাইভ সার্ভিস মূল্য','Live Service Pricing','लाइव सेवा मूल्य'],'Payment Number Change':['পেমেন্ট নম্বর পরিবর্তন','Payment Number Change','पेमेंट नंबर बदलें'],'Price Control':['মূল্য নিয়ন্ত্রণ','Price Control','मूल्य नियंत्रण'],'Accept Payment Request':['পেমেন্ট রিকোয়েস্ট গ্রহণ','Accept Payment Request','पेमेंट अनुरोध स्वीकार करें'],'Supercell Sales':['Supercell বিক্রি','Supercell Sales','Supercell बिक्री'],'SMM Orders':['SMM অর্ডার','SMM Orders','SMM ऑर्डर']};
const L=['bn','en','hi']; function tr(s,l){return D[s]?D[s][L.indexOf(l)]:null}
function apply(l){document.documentElement.lang=l;document.querySelectorAll('body *:not(script):not(style)').forEach(e=>[...e.childNodes].forEach(n=>{if(n.nodeType!==3||!n.nodeValue.trim())return;let a=n.nodeValue,b=a.trim(),x=tr(b,l);if(x!==null)n.nodeValue=a.replace(b,x)}));document.querySelectorAll('[placeholder],[title],[aria-label]').forEach(e=>['placeholder','title','aria-label'].forEach(a=>{let v=e.getAttribute(a),x=v&&tr(v,l);if(x)e.setAttribute(a,x)}));document.querySelectorAll('.subcat').forEach(e=>{let x=tr(e.textContent.trim(),l);if(x)e.textContent=x});localStorage.setItem('trustedBazaarLanguage',l);let s=document.getElementById('languageSelect');if(s)s.value=l}
const CURRENCY={usdToBdt:<?=json_encode($usdToBdt)?>,inrPerUsd:<?=json_encode($inrPerUsd)?>}; function init(){let s=document.getElementById('languageSelect');if(!s)return;let l=localStorage.getItem('trustedBazaarLanguage')||'bn';s.value=L.includes(l)?l:'bn';s.onchange=()=>{localStorage.setItem('trustedBazaarLanguage',s.value);location.reload()};apply(s.value)}function formatMoneyBDT(v,l){v=Number(v)||0;if(l==='en')return '$'+(v/CURRENCY.usdToBdt).toFixed(2);if(l==='hi')return '₹'+(v/CURRENCY.usdToBdt*CURRENCY.inrPerUsd).toFixed(2);return '৳'+v.toFixed(2)} function applyCurrency(l){document.querySelectorAll('[data-bdt]').forEach(e=>{e.textContent=formatMoneyBDT(e.getAttribute('data-bdt'),l)});let t=document.getElementById('total');if(t&&t.getAttribute('data-bdt-total')==='1'){let q=Number(document.getElementById('qty')?.value||0),p=Number(document.getElementById('price')?.value||0);t.textContent=formatMoneyBDT(q*p/1000,l)}} document.addEventListener('DOMContentLoaded',init);window.addEventListener('load',()=>{let l=localStorage.getItem('trustedBazaarLanguage')||'bn';apply(l);applyCurrency(l)});window.addEventListener('storage',e=>{if(e.key==='trustedBazaarLanguage'){location.reload()}})})();
</script><script>
(function(){
 const track=document.getElementById('promoTrack');
 const dots=document.querySelectorAll('.promo-dot');
 if(!track||!dots.length)return;
 let i=0;
 setInterval(function(){
   i=(i+1)%dots.length;
   track.style.transform='translateX(-'+(i*100)+'%)';
   dots.forEach((d,n)=>d.classList.toggle('active',n===i));
 },3500);
})();
</script></body></html>
