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
  // A signed staging Broadcaster request prepares only its isolated sandbox.
  if(isset(BroadcasterMandates::PRICE[$sku])||isset(BroadcasterBoost::PRICE[$sku]))
   BroadcasterStagingBootstrap::ensure($credential);
  $product=Db::one($db,'SELECT * FROM products WHERE application_id=? AND sku=? AND enabled=1',[$application,$sku]);
  if(!$product) throw new HttpError(422,'Unknown product');
  if(isset($request['amount_cents']) && (int)$request['amount_cents']!==(int)$product['amount_cents'])throw new HttpError(422,'Price mismatch');
  if(isset($request['currency']) && $request['currency']!=='EUR')throw new HttpError(422,'Unsupported currency');
  $choice=SavedCards::choice($request,$env,$sku,(string)$credential['slug']);
  // Broadcaster mandates are isolated from the single-Listener contract.
  $boostStation=BroadcasterBoost::choice($request,$env,$sku,(string)$credential['slug'],(int)$product['amount_cents']);
  $bSku=BroadcasterMandates::choice($request,$env,$sku,(string)$credential['slug'],(int)$product['amount_cents']);
  $bResource=$bSku!==null?BroadcasterMandates::resource($sku,$request['resource_id']??null):null;
  $recurring=$bSku===null
    ?RecurringMandates::choice($request,$env,$sku,(string)$credential['slug'])
    :false;
  $winbackExpiredAt=Winback::expiredAt($request,$env,$sku,(string)$credential['slug']);
  if($winbackExpiredAt!==null&&($choice['save']||$choice['reuse']))
   throw new HttpError(422,'WINBACK_CANNOT_COMBINE_CIT');
  $canonical=['customer_id'=>$external,'product_sku'=>$sku,'email'=>$email,'name'=>$name];
  if($choice['save']){$canonical['save_card']=true;$canonical['card_consent_version']=$choice['version'];}
  if($choice['reuse'])$canonical['use_saved_card']=true;
  if($recurring){$canonical['auto_renew']=true;
    $canonical['recurring_consent_version']=RecurringMandates::CONSENT;}
  if($boostStation!==null)$canonical['resource_id']=$boostStation;
  if($bSku!==null){
   $canonical['auto_renew']=true;
   $canonical['recurring_consent_version']=BroadcasterMandates::CONSENT;
   $canonical['resource_id']=$bResource;
  }
  if($winbackExpiredAt!==null)$canonical['winback_expired_at']=$winbackExpiredAt;
  $hash=hash('sha256',Security::json($canonical));
  $recurrence=null;
  $db->beginTransaction();
  try{
   $existing=Db::one($db,'SELECT * FROM payments WHERE application_id=? AND request_key=? FOR UPDATE',[$application,$key]);
   // The admin deletion flow marks this customer as permanently non-billable.
   // Check even for idempotent replay: never return an old checkout link.
   $customer=Db::one($db,'SELECT id,type FROM customers WHERE application_id=? AND external_user_id=? FOR UPDATE',[$application,$external]);
   if($customer && $customer['type']==='account_deleted')
    throw new HttpError(410,'ACCOUNT_DELETED_BILLING_BLOCKED');
   if($existing){$db->commit();if(!hash_equals($existing['request_hash'],$hash))throw new HttpError(409,'Idempotency payload mismatch');return self::publicPayment($existing);}
   // Preserve idempotency for an already-created payment before validating
   // current stored-card state. New requests fail closed before any payment insert.
   SavedCards::preflight($db,$application,$external,$choice);
   RecurringMandates::preflight($db,$application,$external,$recurring);
   if($boostStation!==null)BroadcasterBoost::preflight($db);
   if($customer){$cid=$customer['id'];Db::exec($db,'UPDATE customers SET email=?,name=? WHERE id=?',[$email,$name,$cid]);}
   else { $cid=Security::id();Db::exec($db,'INSERT INTO customers(id,application_id,external_user_id,email,name) VALUES(?,?,?,?,?)',[$cid,$application,$external,$email,$name]); }
   if($winbackExpiredAt!==null)
    Winback::preflight($db,$application,$cid,$winbackExpiredAt);
   $pid=Security::id();$order='IL'.strtoupper(substr($pid,0,24));
   Db::exec($db,"INSERT INTO payments(id,application_id,customer_id,product_id,order_id,request_key,request_hash,amount_cents,status) VALUES(?,?,?,?,?,?,?,?,'pending')",[$pid,$application,$cid,$product['id'],$order,$key,$hash,$product['amount_cents']]);
   if($choice['save']||$choice['reuse'])
    $recurrence=SavedCards::prepare($db,$application,$cid,$pid,
     NexiClient::providerCustomerId($external),$choice);
   if($recurring)$recurrence=RecurringMandates::prepare(
     $db,$application,$cid,$pid,NexiClient::providerCustomerId($external));
   if($bSku!==null)$recurrence=BroadcasterMandates::prepare(
     $db,$application,$cid,$pid,$external,$bSku,$bResource,(int)$product['amount_cents']);
   if($boostStation!==null)BroadcasterBoost::record(
     $db,$application,$cid,$pid,$sku,$boostStation,(int)$product['amount_cents']);
   if($winbackExpiredAt!==null)
    Winback::reserve($db,$application,$cid,$pid,$winbackExpiredAt);
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
   if(preg_match('/^Nexi status ([45][0-9]{2})\b(?: \[([^\]]{1,190})\])?/u',$e->getMessage(),$matches)){
    // The HTTP code and provider errorSummary() have already been scrubbed
    // of API keys, long IDs, URLs and multiline payloads by NexiClient.
    // Store ONLY bounded, allowlisted validation text in operator audit.
    $code=$matches[1];$description=(string)($matches[2]??'');
    if(!preg_match('/^[\p{L}\p{N} .,:;_()\/\[\]-]{1,190}$/uD',$description))
     $description='';
    $reason='Η Nexi απέρριψε τη δημιουργία checkout (HTTP '.$code.')'.
     ($description!==''?' — '.$description:'').
     '. Δεν έγινε redirect. Ελέγξτε το Nexi Order με GET πριν από νέα δοκιμή.';
    if($code==='400'){
     self::log($db,$pid,'hpp_rejected_400',$reason);
     $reason='Απόρριψη HPP HTTP 400. Αναμένουμε read-only επαλήθευση Nexi πριν αποδεσμεύσουμε την απόπειρα.';
    }
   }
   self::log($db,$pid,'unknown',$reason);
  }
  $p=Db::one($db,'SELECT * FROM payments WHERE id=?',[$pid]);return self::publicPayment($p);
 }
 public static function publicPayment(array $p):array {
  return ['payment_id'=>$p['id'],'order_id'=>$p['order_id'],'status'=>$p['status'],'amount_cents'=>(int)$p['amount_cents'],'currency'=>'EUR',
   'checkout_url'=>$p['status']==='redirect'?$p['hosted_page']:null];
 }
 /**
  * Release only a provider-rejected HPP order where Nexi responds 404
  * to a fresh read-only GET and at least 2 minutes have elapsed.
  * Never applies to arbitrary unknown, redirect, pending, MIT renewal
  * cycles or anything with a provider transaction/verified webhook.
  */
 public static function reconcileRejectedHpp404(string $env,string $orderId):bool {
  if($env!=='staging')return false;
  $db=Db::get('staging');
  $db->beginTransaction();
  try{
   $p=Db::one($db,"SELECT id,status,operation_id,hosted_page,security_token_box,
     created_at FROM payments WHERE order_id=? FOR UPDATE",[$orderId]);
   if(!$p || $p['status']!=='unknown' || $p['operation_id']!==null ||
      $p['hosted_page']!==null || $p['security_token_box']!==null ||
      strtotime((string)$p['created_at'].' UTC')>time()-120) {
    $db->rollBack();return false;
   }
   $rejected=Db::one($db,"SELECT id FROM payment_logs
     WHERE payment_id=?
       AND (event_type='hpp_rejected_400'
        OR (event_type='unknown' AND
          message_gr LIKE 'Η Nexi απέρριψε τη δημιουργία checkout (HTTP 400)%'))
     LIMIT 1",[$p['id']]);
   if(!$rejected){$db->rollBack();return false;}
   // Requiring a positive documented 404 from Nexi before calling this
   // is the worker's responsibility; no retry provider POST is ever made.
   Db::exec($db,"UPDATE payments SET status='failed'
     WHERE id=? AND status='unknown'",[$p['id']]);
   // The first MIT mandate never became a provider contract.
   // Mark it cancelled and deactivated so it cannot block a later, new
   // opt-in. Financial event history is preserved for the rejected order.
   // This reconciliation also supports older non-tokenized staging
   // installs where the optional OneClick/MIT tables do not yet exist.
   $mitTable=Db::one($db,"SELECT COUNT(*) AS n FROM information_schema.tables
     WHERE table_schema=DATABASE() AND table_name='recurring_mandates'");
   if((int)($mitTable['n']??0)===1)
    Db::exec($db,"UPDATE recurring_mandates
      SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),
          deactivated_at=UTC_TIMESTAMP(),next_due_at=NULL,
          last_error_code='HPP_REJECTED_400'
      WHERE initial_payment_id=? AND status='pending'",[$p['id']]);
   // The same provider-verified rejected initial HPP must release the
   // independent Broadcaster contract reservation. No Nexi contract was
   // created (positive GET 404), so mark it cancelled + deactivated, without
   // affecting any other product/station mandate.
   if(BroadcasterMandates::schemaReady($db))
    Db::exec($db,"UPDATE broadcaster_mandates
      SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),
          deactivated_at=UTC_TIMESTAMP(),next_due_at=NULL,
          last_error_code='HPP_REJECTED_400'
      WHERE initial_payment_id=? AND status IN ('pending','verification_pending')",
      [$p['id']]);
   $citTable=Db::one($db,"SELECT COUNT(*) AS n FROM information_schema.tables
     WHERE table_schema=DATABASE() AND table_name='saved_card_agreements'");
   if((int)($citTable['n']??0)===1)
    Db::exec($db,"UPDATE saved_card_agreements
      SET status='revoked',deactivated_at=UTC_TIMESTAMP()
      WHERE payment_id=? AND status='pending'",[$p['id']]);
   self::log($db,$p['id'],'failed','Η Nexi επέστρεψε HTTP 400 στο HPP και επιβεβαιώθηκε ότι το Order δεν υπάρχει (GET 404). Καμία νέα χρέωση.');
   $db->commit();return true;
  }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 public static function history(array $credential,string $external):array {
  if($credential['environment']!=='staging'||($credential['slug']??'')!=='iradiosgr'||
     !preg_match('/^iradios-stage-[a-f0-9]{24}$/D',$external))
   throw new HttpError(403,'STAGING_ONLY');
  $db=Db::get('staging');
  $rows=Db::all($db,"SELECT p.id AS payment_id,p.order_id,p.status,p.amount_cents,p.currency,
    p.created_at,p.confirmed_at,pr.sku,pr.title
    FROM payments p JOIN customers c ON c.id=p.customer_id
    JOIN products pr ON pr.id=p.product_id
    WHERE p.application_id=? AND c.application_id=? AND c.external_user_id=?
    ORDER BY p.created_at DESC,p.id DESC LIMIT 60",
    [(int)$credential['application_id'],(int)$credential['application_id'],$external]);
  return ['payments'=>array_map(static fn($r)=>[
   'id'=>(string)$r['payment_id'],'orderId'=>(string)$r['order_id'],
   'sku'=>(string)$r['sku'],'title'=>(string)$r['title'],
   'status'=>(string)$r['status'],'amountCents'=>(int)$r['amount_cents'],
   'currency'=>(string)$r['currency'],'createdAt'=>str_replace(' ','T',(string)$r['created_at']).'Z',
   'paidAt'=>$r['confirmed_at']?str_replace(' ','T',(string)$r['confirmed_at']).'Z':null
  ],$rows)];
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
    if($env==='staging'){
     if($status==='paid')Winback::captured($db,$p['id']);
     // A provider-confirmed declined/cancelled *initial* HPP must not
     // permanently lock the listener out of a new consent or winback retry.
     // Unknown status remains held for reconciliation, never released.
     if(in_array($status,['failed','canceled'],true)){
      // Do not strand a separate broadcaster SKU/station mandate after a
      // provider-confirmed rejected initial HPP. Unknown outcomes stay locked.
      BroadcasterMandates::markInitialFailed($db,$p['id']);
      Db::exec($db,"UPDATE recurring_mandates SET status='cancelled',
       cancelled_at=UTC_TIMESTAMP(),next_due_at=NULL,
       last_error_code='INITIAL_PAYMENT_FAILED'
       WHERE initial_payment_id=? AND status IN ('pending','verification_pending')",
       [$p['id']]);
     }
    }
    // For an automated cycle, correlate the exact Gateway account and
    // immutable provider payment before sending a signed iRadios event.
    // The migration is optional until staged rollout; old payments remain safe.
    $mitCycle=false;
    if($env==='staging'){
     $schema=Db::one($db,"SELECT COUNT(*) AS n FROM information_schema.tables
       WHERE table_schema=DATABASE() AND table_name='recurring_cycles'");
     if((int)($schema['n']??0)===1){
      $mitCycle=Db::one($db,"SELECT c.cycle_due_at,u.external_user_id
        FROM recurring_cycles c
        JOIN recurring_mandates m ON m.id=c.mandate_id
        JOIN customers u ON u.id=m.customer_id
        WHERE c.payment_id=? FOR UPDATE",[$p['id']]);
      if($mitCycle)RecurringMandates::settleCycle($db,$p['id'],$status);
     }
    }
    $bScope=$env==='staging'?BroadcasterMandates::eventScope($db,$p['id']):null;
    if(!$bScope && $env==='staging')$bScope=BroadcasterBoost::eventScope($db,$p['id']);
    if($bScope&&($bScope['billing_origin']??'')==='automatic_renewal')
      BroadcasterMandates::settleCycle($db,$p['id'],$status);
    self::log($db,$p['id'],$status,$status==='paid'?'Η Nexi επιβεβαίωσε επιτυχημένη πληρωμή.':($status==='failed'?'Η Nexi απέρριψε την πληρωμή.':'Η συναλλαγή ακυρώθηκε.'));
    Db::exec($db,'INSERT INTO payment_attempts(payment_id,provider_status,operation_id) VALUES(?,?,?)',[$p['id'],$status,$operation]);
    $product=Db::one($db,'SELECT sku FROM products WHERE id=? AND application_id=?',
      [$locked['product_id'],$locked['application_id']]);
    $eventId=Security::id();$body=['event_id'=>$eventId,'event_type'=>'payment.'.$status,'payment_id'=>$p['id'],'order_id'=>$order,'customer_id'=>$p['customer_id'],'amount_cents'=>(int)$p['amount_cents'],'currency'=>'EUR',
      'product_sku'=>$product['sku']??'',
      'environment'=>$env,'occurred_at'=>gmdate('c')];
    if($bScope){
      $body['resource_id']=$bScope['resource_id'];
      if(isset($bScope['billing_origin'])){
        $body['billing_origin']='automatic_renewal';
        $body['external_user_id']=$bScope['external_user_id'];
        $body['cycle_due_at']=$bScope['cycle_due_at'];
        $body['anchor_day']=$bScope['anchor_day'];
        $body['period_ends_at']=$bScope['period_ends_at'];
      }
    }
    // The initial paid period starts at the provider-confirmation instant,
    // not at the eventual outbox delivery time.
    if($bScope && $status==='paid'){
     $confirmed=Db::one($db,'SELECT confirmed_at FROM payments WHERE id=?',[$p['id']]);
     if(!empty($confirmed['confirmed_at']))
      $body['occurred_at']=gmdate('c',strtotime((string)$confirmed['confirmed_at'].' UTC'));
    }
    if($mitCycle){
     $body['billing_origin']='automatic_renewal';
     $body['external_user_id']=(string)$mitCycle['external_user_id'];
     $body['cycle_due_at']=gmdate('c',strtotime((string)$mitCycle['cycle_due_at'].' UTC'));
    }
    Db::exec($db,"INSERT IGNORE INTO outgoing_events(id,payment_id,application_id,event_type,body_json) VALUES(?,?,?,?,?)",[$eventId,$p['id'],$p['application_id'],'payment.'.$status,Security::json($body)]);
   }
   $db->commit();
   if($status==='paid'){
    try{SavedCards::verifyPaid($env,$p['id']);}
    catch(\Throwable $e){error_log('Saved-card verification deferred; payment remains paid');}
    try{RecurringMandates::verifyPaid($env,$p['id']);}
    catch(\Throwable $e){error_log('MIT contract verification deferred; payment remains paid');}
    try{BroadcasterMandates::verifyPaid($env,$p['id']);}
    catch(\Throwable $e){error_log('Broadcaster MIT verification deferred; payment remains paid');}
   }
   return $status;
  }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
}