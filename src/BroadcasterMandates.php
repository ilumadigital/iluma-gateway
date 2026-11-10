<?php
declare(strict_types=1);
namespace Iluma\Gateway;

/**
 * Separate broadcaster MIT ledger. Listener mandates/renewals remain untouched.
 * Every subscriber can have B-PREM plus independent ADD-* station mandates.
 */
final class BroadcasterMandates {
 public const CONSENT='iradios-broadcaster-monthly-v1';
 public const PRICE=['B-PREM'=>11900,'ADD-DSP'=>4990,'ADD-AN'=>790,'ADD-ADS'=>2990];
 private static function isStaging(array $credential):bool {
  return $credential['environment']==='staging'&&($credential['slug']??'')==='iradiosgr'
   &&Config::get('APP_ENV')!=='production';
 }
 public static function resource(string $sku,mixed $value):?string {
  if(!array_key_exists($sku,self::PRICE))throw new HttpError(422,'BROADCASTER_SKU_INVALID');
  if($sku==='B-PREM'){
   if($value!==null)throw new HttpError(422,'ACCOUNT_SCOPE_MUST_BE_EMPTY');
   return null;
  }
  if(!is_string($value)||!preg_match('/^[a-f0-9]{24}$/D',$value))
   throw new HttpError(422,'STATION_RESOURCE_REQUIRED');
  return $value;
 }
 public static function choice(array $body,string $env,string $sku,string $slug,int $price):?string {
  if(!array_key_exists($sku,self::PRICE))return null;
  if($env!=='staging'||$slug!=='iradiosgr'||Config::get('APP_ENV')==='production')
   throw new HttpError(403,'BROADCASTER_STAGING_ONLY');
  if($price!==self::PRICE[$sku])throw new HttpError(422,'BROADCASTER_PRICE_MISMATCH');
  if(($body['auto_renew']??null)!==true||
    ($body['recurring_consent_version']??null)!==self::CONSENT)
   throw new HttpError(422,'BROADCASTER_RECURRING_CONSENT_REQUIRED');
  if(!empty($body['save_card'])||!empty($body['use_saved_card']))
   throw new HttpError(422,'BROADCASTER_CANNOT_COMBINE_CIT_MIT');
  self::resource($sku,$body['resource_id']??null);
  return $sku;
 }
 public static function schemaReady(\PDO $db):bool {
  $found=Db::one($db,"SELECT COUNT(*) AS n FROM information_schema.tables
   WHERE table_schema=DATABASE() AND table_name IN ('broadcaster_mandates','broadcaster_cycles')");
  return (int)($found['n']??0)===2;
 }
 public static function preflight(\PDO $db,int $app,string $customer,string $sku,?string $resource):void {
  if(!self::schemaReady($db))throw new HttpError(422,'BROADCASTER_MIGRATION_REQUIRED');
  // The caller holds the existing customer row lock in checkout().
  $active=Db::one($db,"SELECT id FROM broadcaster_mandates
   WHERE application_id=? AND customer_id=? AND sku=?
    AND resource_id <=> ? AND status IN ('pending','active','past_due','verification_pending')
   LIMIT 1 FOR UPDATE",[$app,$customer,$sku,$resource]);
  if($active)throw new HttpError(422,'BROADCASTER_AGREEMENT_EXISTS');
 }
 public static function prepare(\PDO $db,int $app,string $customer,string $payment,
  string $external,string $sku,?string $resource,int $price):array {
  self::preflight($db,$app,$customer,$sku,$resource);
  $contract=RecurringMandates::contractId();
  Db::exec($db,"INSERT INTO broadcaster_mandates
   (id,application_id,customer_id,initial_payment_id,provider_customer_id,
    contract_id,sku,resource_id,amount_cents,consent_version,consent_at,status)
   VALUES(?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),'pending')",
    [Security::id(),$app,$customer,$payment,NexiClient::providerCustomerId($external),
     $contract,$sku,$resource,$price,self::CONSENT]);
  return RecurringMandates::recurrence($contract);
 }
 private static function target(array $credential,array $body):array {
  if(!self::isStaging($credential))throw new HttpError(403,'BROADCASTER_STAGING_ONLY');
  $external=$body['customer_id']??null;$sku=$body['product_sku']??null;
  if(!is_string($external)||!preg_match('/^iradios-stage-[a-f0-9]{24}$/D',$external)||
    !is_string($sku)||!array_key_exists($sku,self::PRICE))
   throw new HttpError(422,'BROADCASTER_TARGET_INVALID');
  $resource=self::resource($sku,$body['resource_id']??null);
  return [Db::get('staging'),(int)$credential['application_id'],$external,$sku,$resource];
 }
 public static function status(array $credential,array $body):array {
  [$db,$app,$external,$sku,$resource]=self::target($credential,$body);
  $base=['sku'=>$sku,'resource_id'=>$resource,
   'amount_cents'=>self::PRICE[$sku],'currency'=>'EUR','interval'=>'calendar_month',
   'state'=>'unavailable','auto_renew'=>false,'execution_enabled'=>false,
   'next_charge_at'=>null,'paid_through_at'=>null,'cancelled_at'=>null];
  if(!self::schemaReady($db))return $base;
  $row=Db::one($db,"SELECT m.status,m.next_due_at,m.paid_through_at,m.cancelled_at
   FROM broadcaster_mandates m JOIN customers c ON c.id=m.customer_id
   WHERE m.application_id=? AND c.application_id=? AND c.external_user_id=?
    AND m.sku=? AND m.resource_id <=> ?
   ORDER BY m.created_at DESC LIMIT 1",[$app,$app,$external,$sku,$resource]);
  return array_merge($base,[
   'state'=>$row['status']??'none',
   'auto_renew'=>($row['status']??null)==='active',
   'execution_enabled'=>Config::get('BROADCASTER_MIT_SANDBOX_EXECUTION_ENABLED')==='1'
    &&Config::get('BROADCASTER_MIT_SANDBOX_UAT_APPROVED')==='1',
   'next_charge_at'=>!empty($row['next_due_at'])?str_replace(' ','T',$row['next_due_at']).'Z':null,
   'paid_through_at'=>!empty($row['paid_through_at'])?str_replace(' ','T',$row['paid_through_at']).'Z':null,
   'cancelled_at'=>!empty($row['cancelled_at'])?str_replace(' ','T',$row['cancelled_at']).'Z':null
  ]);
 }
 public static function cancel(array $credential,array $body):array {
  [$db,$app,$external,$sku,$resource]=self::target($credential,$body);
  if(!self::schemaReady($db))throw new HttpError(503,'Broadcaster recurring schema unavailable');
  $db->beginTransaction();
  try {
   $row=Db::one($db,"SELECT m.id FROM broadcaster_mandates m
    JOIN customers c ON c.id=m.customer_id
    WHERE m.application_id=? AND c.application_id=? AND c.external_user_id=?
    AND m.sku=? AND m.resource_id <=> ?
    AND m.status IN ('pending','active','past_due','verification_pending')
    ORDER BY m.created_at DESC LIMIT 1 FOR UPDATE",
    [$app,$app,$external,$sku,$resource]);
   if($row)Db::exec($db,"UPDATE broadcaster_mandates SET status='cancelled',
    cancelled_at=UTC_TIMESTAMP(),next_due_at=NULL WHERE id=?",[$row['id']]);
   $db->commit();
  }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  return self::status($credential,$body);
 }
 /**
  * A provider-confirmed failed/cancelled INITIAL HPP must release this one
  * product/station mandate. Do not touch other station contracts, and never
  * infer a failure from a timeout or an unknown provider status.
  */
 public static function markInitialFailed(\PDO $db,string $paymentId):void {
  if(!self::schemaReady($db))return;
  Db::exec($db,"UPDATE broadcaster_mandates
    SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),next_due_at=NULL,
      last_error_code='INITIAL_PAYMENT_FAILED'
    WHERE initial_payment_id=?
      AND status IN ('pending','verification_pending')",[$paymentId]);
 }
 public static function verifyPaid(string $env,string $paymentId):void {
  if($env!=='staging')return;
  $db=Db::get($env);
  if(!self::schemaReady($db))return;
  $m=Db::one($db,"SELECT m.* FROM broadcaster_mandates m
   JOIN payments p ON p.id=m.initial_payment_id
   WHERE p.id=? AND p.status='paid'
    AND m.status IN ('pending','verification_pending')",[$paymentId]);
  if(!$m)return;
  $response=NexiClient::forEnvironment('staging')->contractsForCustomer($m['provider_customer_id']);
  $found=false;
  foreach(($response['contracts']??[]) as $contract){
   if(is_array($contract)&&($contract['contractId']??null)===$m['contract_id']
    &&($contract['contractType']??null)==='MIT_SCHEDULED'){$found=true;break;}
  }
  if(!$found){
   Db::exec($db,"UPDATE broadcaster_mandates SET status='verification_pending'
    WHERE id=? AND status='pending'",[$m['id']]);return;
  }
  $payment=Db::one($db,'SELECT confirmed_at FROM payments WHERE id=?',[$paymentId]);
  if(!$payment||!$payment['confirmed_at'])return;
  $day=(int)(new \DateTimeImmutable((string)$payment['confirmed_at'],new \DateTimeZone('UTC')))->format('j');
  $end=RecurringMandates::monthAfter((string)$payment['confirmed_at'],$day);
  Db::exec($db,"UPDATE broadcaster_mandates SET status='active',
   verified_at=UTC_TIMESTAMP(),paid_through_at=?,next_due_at=?,anchor_day=?
   WHERE id=? AND status IN ('pending','verification_pending')",
   [$end,$end,$day,$m['id']]);
 }
 public static function eventScope(\PDO $db,string $paymentId):?array {
  if(!self::schemaReady($db))return null;
  $cycle=Db::one($db,"SELECT c.cycle_due_at,m.sku,m.resource_id,u.external_user_id
   FROM broadcaster_cycles c
   JOIN broadcaster_mandates m ON m.id=c.mandate_id
   JOIN customers u ON u.id=m.customer_id
   WHERE c.payment_id=? FOR UPDATE",[$paymentId]);
  if($cycle)return ['sku'=>$cycle['sku'],'resource_id'=>$cycle['resource_id'],
    'external_user_id'=>$cycle['external_user_id'],
    'billing_origin'=>'automatic_renewal','cycle_due_at'=>gmdate('c',strtotime($cycle['cycle_due_at'].' UTC'))];
  $initial=Db::one($db,"SELECT m.sku,m.resource_id
   FROM broadcaster_mandates m WHERE m.initial_payment_id=? FOR UPDATE",[$paymentId]);
  return $initial?['sku'=>$initial['sku'],'resource_id'=>$initial['resource_id']]:null;
 }
 public static function settleCycle(\PDO $db,string $paymentId,string $status):void {
  if(!self::schemaReady($db))return;
  $cycle=Db::one($db,"SELECT c.id,c.cycle_due_at,c.mandate_id,m.anchor_day,m.status
   FROM broadcaster_cycles c JOIN broadcaster_mandates m ON m.id=c.mandate_id
   WHERE c.payment_id=? FOR UPDATE",[$paymentId]);
  if(!$cycle)return;
  if($status==='paid'){
   Db::exec($db,"UPDATE broadcaster_cycles SET status='paid' WHERE id=? AND status<>'paid'",[$cycle['id']]);
   if($cycle['status']==='active'){
    $end=RecurringMandates::monthAfter((string)$cycle['cycle_due_at'],(int)$cycle['anchor_day']);
    Db::exec($db,"UPDATE broadcaster_mandates SET paid_through_at=?,next_due_at=?
     WHERE id=? AND status='active'",[$end,$end,$cycle['mandate_id']]);
   }
  }elseif(in_array($status,['failed','canceled'],true)){
   Db::exec($db,'UPDATE broadcaster_cycles SET status=? WHERE id=?',[$status,$cycle['id']]);
   if($cycle['status']==='active')Db::exec($db,"UPDATE broadcaster_mandates
    SET status='past_due',next_due_at=NULL,last_error_code='CHARGE_FAILED'
    WHERE id=? AND status='active'",[$cycle['mandate_id']]);
  }
 }
 public static function chargeDue(string $env,int $limit=5):void {
  if($env!=='staging'||Config::get('APP_ENV')==='production'
   ||Config::get('BROADCASTER_MIT_SANDBOX_EXECUTION_ENABLED')!=='1'
   ||Config::get('BROADCASTER_MIT_SANDBOX_UAT_APPROVED')!=='1')return;
  $db=Db::get('staging');if(!self::schemaReady($db))return;
  $rows=Db::all($db,"SELECT id FROM broadcaster_mandates
   WHERE status='active' AND verified_at IS NOT NULL AND cancelled_at IS NULL
    AND next_due_at<=UTC_TIMESTAMP() ORDER BY next_due_at LIMIT ".max(1,min(5,$limit)));
  foreach($rows as $row){
   $db->beginTransaction();$payment=null;
   try{
    $m=Db::one($db,"SELECT * FROM broadcaster_mandates WHERE id=? FOR UPDATE",[$row['id']]);
    if(!$m||$m['status']!=='active'||!$m['verified_at']||$m['cancelled_at']
      ||!$m['next_due_at']||$m['next_due_at']!==$m['paid_through_at']
      ||strtotime($m['next_due_at'].' UTC')>time()){$db->rollBack();continue;}
    $last=Db::one($db,"SELECT MAX(confirmed_at) AS paid
     FROM payments WHERE status='paid' AND
      (id=? OR id IN (SELECT payment_id FROM broadcaster_cycles WHERE mandate_id=?))",
      [$m['initial_payment_id'],$m['id']]);
    if(!$last||!$last['paid']||!RecurringMandates::hasProviderSpacing($last['paid'],gmdate('Y-m-d H:i:s'))){
     $db->rollBack();continue;
    }
    $existing=Db::one($db,"SELECT id FROM broadcaster_cycles WHERE mandate_id=?
     AND cycle_due_at=? FOR UPDATE",[$m['id'],$m['next_due_at']]);
    if($existing){$db->rollBack();continue;}
    $product=Db::one($db,"SELECT id FROM products WHERE application_id=? AND sku=?
      AND amount_cents=? AND currency='EUR' AND enabled=1",
      [$m['application_id'],$m['sku'],$m['amount_cents']]);
    if(!$product||self::PRICE[$m['sku']]!==(int)$m['amount_cents'])
     throw new \RuntimeException('Broadcaster catalog mismatch');
    $pid=Security::id();$order='IL'.strtoupper(substr($pid,0,24));
    $request='bmit-'.$m['id'].'-'.gmdate('YmdHis',strtotime($m['next_due_at'].' UTC'));
    $hash=hash('sha256',Security::json([$m['id'],$m['next_due_at'],$m['amount_cents'],'EUR']));
    Db::exec($db,"INSERT INTO payments
     (id,application_id,customer_id,product_id,order_id,request_key,request_hash,amount_cents,currency,status)
     VALUES(?,?,?,?,?,?,?,?,'EUR','pending')",
     [$pid,$m['application_id'],$m['customer_id'],$product['id'],$order,$request,$hash,$m['amount_cents']]);
    Db::exec($db,"INSERT INTO broadcaster_cycles
     (id,mandate_id,cycle_due_at,payment_id,status)
     VALUES(?,?,?,?, 'pending')",[Security::id(),$m['id'],$m['next_due_at'],$pid]);
    Payments::log($db,$pid,'mit_reserved','Broadcaster monthly cycle reserved; one provider attempt.');
    $db->commit();$payment=['id'=>$pid,'order'=>$order,'mandate'=>$m['id']];
   }catch(\Throwable $e){
    if($db->inTransaction())$db->rollBack();
    error_log('Broadcaster MIT reservation failed');continue;
   }
   // Serializes cancellation with the outbound provider attempt.
   $db->beginTransaction();
   try {
    $m=Db::one($db,"SELECT * FROM broadcaster_mandates WHERE id=? FOR UPDATE",[$payment['mandate']]);
    if(!$m||$m['status']!=='active'||$m['cancelled_at']){
     Db::exec($db,"UPDATE payments SET status='canceled' WHERE id=? AND status='pending'",[$payment['id']]);
     Db::exec($db,"UPDATE broadcaster_cycles SET status='cancelled'
      WHERE payment_id=? AND status='pending'",[$payment['id']]);
     $db->commit();continue;
    }
    try{
     NexiClient::forEnvironment('staging')->startBroadcasterMit(
      $payment['order'],$m['provider_customer_id'],$m['contract_id'],(int)$m['amount_cents']);
     Payments::log($db,$payment['id'],'mit_submitted','Broadcaster MIT submitted, confirmation pending.');
    }catch(\Throwable $e){
     Db::exec($db,"UPDATE payments SET status='unknown' WHERE id=? AND status='pending'",[$payment['id']]);
     Db::exec($db,"UPDATE broadcaster_cycles SET status='unknown'
      WHERE payment_id=? AND status='pending'",[$payment['id']]);
     Payments::log($db,$payment['id'],'unknown','MIT outcome unknown; read-only reconciliation required.');
     error_log('Broadcaster MIT provider unknown; no retry');
    }
    $db->commit();
   }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();error_log('Broadcaster MIT requires reconciliation');}
   try{Payments::reconcile('staging',$payment['order']);}
   catch(\Throwable $e){error_log('Broadcaster MIT reconciliation pending');}
  }
 }
 public static function pendingVerification(string $env,int $limit=10):void {
  if($env!=='staging'||!self::schemaReady(Db::get($env)))return;
  $rows=Db::all(Db::get($env),"SELECT m.initial_payment_id FROM broadcaster_mandates m
   JOIN payments p ON p.id=m.initial_payment_id
   WHERE m.status IN ('pending','verification_pending') AND p.status='paid'
   ORDER BY m.created_at LIMIT ".max(1,min($limit,30)));
  foreach($rows as $row)try{self::verifyPaid($env,$row['initial_payment_id']);}
   catch(\Throwable $e){error_log('Broadcaster contract verification deferred');}
 }
 public static function pendingDeactivation(string $env,int $limit=10):void {
  if($env!=='staging'||!self::schemaReady(Db::get($env)))return;
  $rows=Db::all(Db::get($env),"SELECT id,contract_id FROM broadcaster_mandates
   WHERE status='cancelled' AND deactivated_at IS NULL ORDER BY cancelled_at
   LIMIT ".max(1,min($limit,30)));
  foreach($rows as $row)try{
   NexiClient::forEnvironment('staging')->deactivateContract($row['contract_id']);
   Db::exec(Db::get($env),"UPDATE broadcaster_mandates
    SET deactivated_at=UTC_TIMESTAMP() WHERE id=? AND status='cancelled'",[$row['id']]);
  }catch(\Throwable $e){error_log('Broadcaster contract deactivation deferred');}
 }
}
