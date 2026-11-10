<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Iluma\Gateway\{Config,Db,Security,Http,HttpError,Payments};
header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
try {
 $host=strtolower(explode(':',$_SERVER['HTTP_HOST']??'')[0]);
 if($host!==Config::get('GATEWAY_HOST'))throw new HttpError(404,'Not found');
 $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD']??'GET';
 if($method==='POST' && preg_match('#^/nexi/(staging|production)/notification$#D',$path,$m)) {
  $env=$m[1];if($env==='production'&&!Config::liveAllowed())throw new HttpError(503,'Production locked');
  $raw=file_get_contents('php://input');$body=Security::jsonRequest($raw);
  $order=(string)($body['operation']['orderId']??'');$token=(string)($body['securityToken']??'');
  if(!preg_match('/^IL[A-F0-9]{24}$/D',$order) || $token==='')throw new HttpError(400,'Invalid notification');
  $db=Db::get($env);$payment=Db::one($db,'SELECT security_token_box FROM payments WHERE order_id=?',[$order]);
  if(!$payment||!$payment['security_token_box']||!hash_equals(Security::decrypt($payment['security_token_box']),$token)) throw new HttpError(401,'Notification not verified');
  $eventId=hash('sha256',$raw);
  Db::exec($db,'INSERT IGNORE INTO webhook_events(id,order_id,verified) VALUES(?,?,1)',[$eventId,$order]);
  // Provider lookup, not browser claim or notification body, determines financial state.
  $state=Payments::reconcile($env,$order);
  Http::respond(['received'=>true,'state'=>$state]);
 }
 if($method==='GET' && in_array($path,['/checkout/result','/checkout/cancel'],true)) {
  // The browser return is navigation only, NEVER payment evidence.
  // Nexi does not need access to the iRadios session or its JWT.
  $order=(string)($_GET['order']??'');
  if(!preg_match('/^IL[A-F0-9]{24}$/D',$order))throw new HttpError(400,'Invalid order');
  $foundEnv=null;
  foreach(['staging','production'] as $candidate){
   if($candidate==='production'&&!Config::liveAllowed())continue;
   $found=Db::one(Db::get($candidate),
    'SELECT id FROM payments WHERE order_id=?',[$order]);
   if($found){$foundEnv=$candidate;break;}
  }
  if($foundEnv===null)throw new HttpError(404,'Order not found');
  // Server-to-server verification only; provider return itself cannot mark paid.
  try{Payments::reconcile($foundEnv,$order);}
  catch(\\Throwable $e){error_log('Gateway hosted return reconciliation deferred');}
  header('Location: '.\\Iluma\\Gateway\\CheckoutReturn::url($foundEnv,$path,$order),true,303);
  exit;
 }
 if(str_starts_with($path,'/api/v1/')){
  $raw=file_get_contents('php://input');$headers=Security::headers();
  $credential=Security::credentialFromHeaders($headers,$raw,$method,$path);
  $environment=$credential['environment'];$body=$method==='POST'?Security::jsonRequest($raw):[];
  if($method==='POST' && $path==='/api/v1/payments/checkout'){
   $result=Payments::checkout($credential,$body,$headers['idempotency-key']??'');
   Http::respond($result,$result['status']==='redirect'?201:202);
  }
  // Signed, read-only recovery for a lost initial checkout response.
  // Never starts a second provider payment; scoped to the calling application.
  if($method==='GET' && preg_match('#^/api/v1/payments/by-request-key/([A-Za-z0-9._:-]{1,128})$#D',$path,$m)){
   Http::respond(Payments::findByRequestKey($credential,$m[1]));
  }
  if($method==='GET' && preg_match('#^/api/v1/payments/([a-f0-9]{32})$#D',$path,$m)){
   Http::respond(Payments::find($credential,$m[1]));
  }
  if($method==='GET' && preg_match('#^/api/v1/customers/([a-f0-9]{32})$#D',$path,$m)){
   $customer=Db::one(Db::get($environment),'SELECT id,external_user_id,email,name,type FROM customers WHERE id=? AND application_id=?',[$m[1],$credential['application_id']]);
   if(!$customer) throw new HttpError(404,'Customer not found');Http::respond(['customer'=>$customer]);
  }
  if($method==='GET' && preg_match('#^/api/v1/subscriptions/([a-f0-9]{32})$#D',$path,$m)){
   $subscription=Db::one(Db::get($environment),'SELECT id,state,auto_renew,period_end_at,next_billing_at FROM subscriptions WHERE id=? AND application_id=?',[$m[1],$credential['application_id']]);
   if(!$subscription)throw new HttpError(404,'Subscription not found');Http::respond(['subscription'=>$subscription]);
  }
  if(in_array($path,['/api/v1/subscriptions/renew','/api/v1/subscriptions/cancel','/api/v1/subscriptions/sync','/api/v1/payment-methods/replace'],true))
   throw new HttpError(501,'Feature locked pending provider integration and authorization review');
 }
 throw new HttpError(404,'Not found');
} catch(\Throwable $e){Http::error($e);}
