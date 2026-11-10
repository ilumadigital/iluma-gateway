<?php
declare(strict_types=1);
namespace Iluma\Gateway;
use PDO;
final class Payments {
 public static function log(PDO $db,string $payment,string $type,string $message):void {
  Db::exec($db,'INSERT INTO payment_logs(payment_id,event_type,message_gr) VALUES(?,?,?)',[$payment,$type,$message]);
 }
 public static function checkout(array $credential,array $request,string $key):array {
  $env=$credential['environment'];$db=Db::get($env);$application=(int)$credential['application_id'];
  $external=(string)($request['customer_id']??'');$sku=(string)($request['product_sku']??'');
  $email=(string)($request['email']??'');$name=(string)($request['name']??'');
  if(strlen($external)<1||strlen($external)>190||strlen($sku)<1||strlen($sku)>80||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>250||strlen($name)>250)
   throw new HttpError(422,'Customer/product validation failed');
  if(!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D',$key)) throw new HttpError(422,'Idempotency-Key required');
  $product=Db::one($db,'SELECT * FROM products WHERE application_id=? AND sku=? AND enabled=1',[$application,$sku]);
  if(!$product) throw new HttpError(422,'Unknown product');
  if(isset($request['amount_cents']) && (int)$request['amount_cents']!==(int)$product['amount_cents'])throw new HttpError(422,'Price mismatch');
  if(isset($request['currency']) && $request['currency']!=='EUR')throw new HttpError(422,'Unsupported currency');
  $choice=SavedCards::choice($request,$env,$sku,(string)$credential['slug']);
  $canonical=['customer_id'=>$external,'product_sku'=>$sku,'email'=>$email,'name'=>$name];
  if($choice['save']){$canonical['save_card']=true;$canonical['card_consent_version']=$choice['version'];}
  if($choice['reuse'])$canonical['use_saved_card']=true;
  $hash=hash('sha256',Security::json($canonical));
  $recurrence=null;
  $db->beginTransaction();
  try{
   $existing=Db::one($db,'SELECT * FROM payments WHERE application_id=? AND request_key=? FOR UPDATE',[$application,$key]);
   if($existing){$db->commit();if(!hash_equals($existing['request_hash'],$hash))throw new HttpError(409,'Idempotency payload mismatch');return self::publicPayment($existing);}
   // Preserve idempotency for an already-created payment before validating
   // current stored-card state. New requests fail closed before any payment insert.
   SavedCards::preflight($db,$application,$external,$choice);
   $customer=Db::one($db,'SELECT id FROM customers WHERE application_id=? AND external_user_id=? FOR UPDATE',[$application,$external]);
   if($customer){$cid=$customer['id'];Db::exec($db,'UPDATE customers SET email=?,name=? WHERE id=?',[$email,$name,$cid]);}
   else { $cid=Security::id();Db::exec($db,'INSERT INTO customers(id,application_id,external_user_id,email,name) VALUES(?,?,?,?,?)',[$cid,$application,$external,$email,$name]); }
   $pid=Security::id();$order='IL'.strtoupper(substr($pid,0,24));
   Db::exec($db,"INSERT INTO payments(id,application_id,customer_id,product_id,order_id,request_key,request_hash,amount_cents,status) VALUES(?,?,?,?,?,?,?,?,'pending')",[$pid,$application,$cid,$product['id'],$order,$key,$hash,$product['amount_cents']]);
   if($choice['save']||$choice['reuse'])
    $recurrence=SavedCards::prepare($db,$application,$cid,$pid,
     NexiClient::providerCustomerId($external),$choice);
   self::log($db,$pid,'created','Το iRadios ζήτησε πληρωμή.');$db->commit();
  }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  // Exactly one initial provider call. No automatic replay after error/timeout.
  try {
   $res=NexiClient::forEnvironment($env)->startCheckout($order,(int)$product['amount_cents'],$external,$recurrence);
   $page=(string)($res['hostedPage']??'');$token=(string)($res['securityToken']??'');
   if(!filter_var($page,FILTER_VALIDATE_URL)||parse_url($page,PHP_URL_SCHEME)!=='https'||$token==='')throw new \RuntimeException('Unexpected provider HPP response');
   // Domain of checkout page must be whitelisted after merchant UAT; never expose an arbitrary URL.
   $host=strtolower((string)parse_url($page,PHP_URL_HOST));
   if(!($host==='nexigroup.com'||str_ends_with($host,'.nexigroup.com')))throw new \RuntimeException('Nexi page outside approved domain');
   Db::exec($db,"UPDATE payments SET status='redirect',hosted_page=?,security_token_box=? WHERE id=? AND status='pending'",[$page,Security::encrypt($token),$pid]);
   self::log($db,$pid,'redirect','Δημιουργήθηκε ασφαλής σελίδα πληρωμής Nexi.');
  } catch(\Throwable $e){
   // Persist only the HTTP status class in the operator-visible audit trail.
   // Detailed, already scrubbed validation/correlation data stays in private
   // server logs. Never expose a provider response, token or API key in admin.
   error_log('ILUMA HPP initialization failed for order '.$order.': '.$e->getMessage());
   Db::exec($db,"UPDATE payments SET status='unknown' WHERE id=? AND status='pending'",[$pid]);
   $reason='Δεν δημιουργήθηκε επιβεβαιωμένο Nexi checkout. Χρειάζεται διασταύρωση.';
   if(preg_match('/^Nexi status ([45][0-9]{2})\b/',$e->getMessage(),$matches)){
    $reason='Η Nexi απέρριψε τη δημιουργία checkout (HTTP '.$matches[1].'). Ελέγξτε το αίτημα και τα ιδιωτικά server logs.';
   }
   self::log($db,$pid,'unknown',$reason);
  }
  $p=Db::one($db,'SELECT * FROM payments WHERE id=?',[$pid]);return self::publicPayment($p);
 }
 public static function publicPayment(array $p):array {
  return ['payment_id'=>$p['id'],'order_id'=>$p['order_id'],'status'=>$p['status'],'amount_cents'=>(int)$p['amount_cents'],'currency'=>'EUR',
   'checkout_url'=>$p['status']==='redirect'?$p['hosted_page']:null];
 }
 public static function find(array $credential,string $id):array {
  $p=Db::one(Db::get($credential['environment']),'SELECT * FROM payments WHERE id=? AND application_id=?',[$id,(int)$credential['application_id']]);
  if(!$p)throw new HttpError(404,'Payment not found');return self::publicPayment($p);
 }
 // Recover an EXISTING payment without creating a checkout or calling Nexi.
 // Credential application and environment are mandatory query boundaries.
 public static function findByRequestKey(array $credential,string $requestKey):array {
  if(!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D',$requestKey))throw new HttpError(400,'Invalid request key');
  $p=Db::one(Db::get($credential['environment']),
   'SELECT * FROM payments WHERE application_id=? AND request_key=?',
   [(int)$credential['application_id'],$requestKey]);
  if(!$p)throw new HttpError(404,'Payment not found');
  return self::publicPayment($p);
 }
 public static function reconcile(string $env,string $order): string {
  $db=Db::get($env);
  $p=Db::one($db,'SELECT * FROM payments WHERE order_id=?',[$order]);if(!$p) throw new HttpError(404,'Order not found');
  $reply=NexiClient::forEnvironment($env)->order($order);
  [$status,$operation]=PaymentState::fromNexi($reply,(int)$p['amount_cents'],$order);
  if($status==='unknown')return 'unknown';
  $db->beginTransaction();
  try {
   $locked=Db::one($db,'SELECT * FROM payments WHERE id=? FOR UPDATE',[$p['id']]);
   if($locked['status']==='paid' || $locked['status']===$status) { $db->commit();return $locked['status']; }
   if($status==='paid' || !in_array($locked['status'],['paid','refunded'],true)) {
    Db::exec($db,'UPDATE payments SET status=?,operation_id=?,confirmed_at=UTC_TIMESTAMP() WHERE id=?',[$status,$operation,$p['id']]);
    // The pilot first cycle activates only after Nexi confirms exact capture.
    // Renewal remains disabled; no MIT calls are made.
    if($env==='staging' && $locked['subscription_id']!==null && $locked['billing_cycle_id']!==null){
     if($status==='paid'){
      Db::exec($db,"UPDATE subscriptions SET state='active',
       period_end_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 MONTH),next_billing_at=NULL
       WHERE id=? AND state='pending' AND auto_renew=0",[$locked['subscription_id']]);
      Db::exec($db,"UPDATE billing_cycles SET state='paid' WHERE id=? AND state='pending'",[$locked['billing_cycle_id']]);
     }elseif(in_array($status,['failed','canceled'],true)){
      Db::exec($db,"UPDATE subscriptions SET state=? WHERE id=? AND state='pending'",[$status,$locked['subscription_id']]);
      Db::exec($db,"UPDATE billing_cycles SET state=? WHERE id=? AND state='pending'",[$status,$locked['billing_cycle_id']]);
     }
    }
    self::log($db,$p['id'],$status,$status==='paid'?'Η Nexi επιβεβαίωσε επιτυχημένη πληρωμή.':($status==='failed'?'Η Nexi απέρριψε την πληρωμή.':'Η συναλλαγή ακυρώθηκε.'));
    Db::exec($db,'INSERT INTO payment_attempts(payment_id,provider_status,operation_id) VALUES(?,?,?)',[$p['id'],$status,$operation]);
    $eventId=Security::id();$body=['event_id'=>$eventId,'event_type'=>'payment.'.$status,'payment_id'=>$p['id'],'order_id'=>$order,'customer_id'=>$p['customer_id'],'amount_cents'=>(int)$p['amount_cents'],'currency'=>'EUR','environment'=>$env,'occurred_at'=>gmdate('c')];
    Db::exec($db,"INSERT IGNORE INTO outgoing_events(id,payment_id,application_id,event_type,body_json) VALUES(?,?,?,?,?)",[$eventId,$p['id'],$p['application_id'],'payment.'.$status,Security::json($body)]);
   }
   $db->commit();
   if($status==='paid'){
    try{SavedCards::verifyPaid($env,$p['id']);}
    catch(\Throwable $e){error_log('Saved-card verification deferred; payment remains paid');}
   }
   return $status;
  }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
}