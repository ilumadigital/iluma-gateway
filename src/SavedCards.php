<?php
declare(strict_types=1);
namespace Iluma\Gateway;

/**
 * XPay Greece OneClick (CIT) only. The card and CVV are NEVER handled here.
 * Each agreement is created with opt-in before checkout, but becomes active
 * only after (1) a verified captured payment and (2) Nexi confirms the
 * exact merchant contractId for the expected provider customer.
 * No MIT, automatic renewal, background charges or card data.
 */
final class SavedCards {
 public const CONSENT_VERSION='iradios-stored-card-v1';
 public static function choice(array $request,string $env,string $sku,string $slug):array {
  $save=$request['save_card']??false;
  $reuse=$request['use_saved_card']??false;
  if(!is_bool($save)||!is_bool($reuse)||($save&&$reuse))
   throw new HttpError(422,'Invalid stored-card choice');
  $version=$request['card_consent_version']??null;
  if($save && $version!==self::CONSENT_VERSION)
   throw new HttpError(422,'Explicit stored-card consent required');
  if(!$save && $version!==null)
   throw new HttpError(422,'Unexpected card consent');
  if(($save||$reuse)&&($env!=='staging'||$sku!=='L-PREM'||$slug!=='iradiosgr'))
   throw new HttpError(403,'Stored cards are staging-only');
  return ['save'=>$save,'reuse'=>$reuse,'version'=>$save?self::CONSENT_VERSION:null];
 }
 public static function contractId():string {
  return 'ILC'.strtoupper(substr(Security::id(16),0,24));
 }
 // Called within the existing checkout transaction after payment/customer exist.
 public static function prepare(\PDO $db,int $application,string $customer,string $payment,
  string $providerCustomer,array $choice):?array {
  if($choice['save']){
   $existing=Db::one($db,"SELECT id FROM saved_card_agreements WHERE application_id=? AND customer_id=? AND status IN ('active','pending','revocation_pending') LIMIT 1 FOR UPDATE",[$application,$customer]);
   if($existing)throw new HttpError(409,'Saved-card agreement already exists or is pending');
   $contract=self::contractId();
   Db::exec($db,"INSERT INTO saved_card_agreements
    (id,payment_id,application_id,customer_id,provider_customer_id,contract_id,contract_type,consent_version,consent_at,status)
    VALUES(?,?,?,?,?,?,'CIT',?,UTC_TIMESTAMP(),'pending')",
    [Security::id(),$payment,$application,$customer,$providerCustomer,$contract,$choice['version']]);
   return NexiClient::oneClickRecurrence('CONTRACT_CREATION',$contract);
  }
  if($choice['reuse']){
   $card=Db::one($db,"SELECT contract_id FROM saved_card_agreements
    WHERE application_id=? AND customer_id=? AND status='active'
    ORDER BY verified_at DESC LIMIT 1 FOR UPDATE",[$application,$customer]);
   if(!$card)throw new HttpError(409,'No verified saved card');
   return NexiClient::oneClickRecurrence('SUBSEQUENT_PAYMENT',$card['contract_id']);
  }
  return null;
 }
 public static function publicForCustomer(array $credential,string $external):array {
  if($credential['environment']!=='staging'||$credential['slug']!=='iradiosgr')
   throw new HttpError(403,'Stored cards staging only');
  if(!preg_match('/^iradios-stage-[a-f0-9]{24}$/D',$external))
   throw new HttpError(422,'Invalid customer scope');
  $db=Db::get('staging');
  $customer=Db::one($db,'SELECT id FROM customers WHERE application_id=? AND external_user_id=?',
   [(int)$credential['application_id'],$external]);
  if(!$customer)return ['state'=>'none','type'=>null,'automatic_renewal'=>false];
  $card=Db::one($db,"SELECT status,contract_type FROM saved_card_agreements
    WHERE application_id=? AND customer_id=?
    ORDER BY created_at DESC LIMIT 1",[(int)$credential['application_id'],$customer['id']]);
  return ['state'=>$card?$card['status']:'none','type'=>$card?$card['contract_type']:null,
   'automatic_renewal'=>false];
 }
 public static function verifyPaid(string $env,string $paymentId):void {
  if($env!=='staging')return;
  $db=Db::get('staging');
  $card=Db::one($db,"SELECT a.* FROM saved_card_agreements a
   JOIN payments p ON p.id=a.payment_id
   WHERE a.payment_id=? AND p.status='paid' AND a.status='pending'",[$paymentId]);
  if(!$card)return;
  $reply=NexiClient::forEnvironment('staging')->contractsForCustomer($card['provider_customer_id']);
  $found=false;
  foreach(($reply['contracts']??[]) as $contract){
   if(is_array($contract) && ($contract['contractId']??null)===$card['contract_id'] &&
      ($contract['contractType']??null)==='CIT'){
    $found=true;break;
   }
  }
  Db::exec($db,"UPDATE saved_card_agreements SET last_checked_at=UTC_TIMESTAMP() WHERE id=?",[$card['id']]);
  if($found)Db::exec($db,"UPDATE saved_card_agreements
   SET status='active',verified_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'",[$card['id']]);
 }
 // Customer may revoke at any time; immediately disable future reuse, then
 // deactivate at Nexi. A failed provider call stays pending for cron retry.
 public static function revoke(array $credential,string $external):array {
  self::publicForCustomer($credential,$external);
  $db=Db::get('staging');
  $customer=Db::one($db,'SELECT id FROM customers WHERE application_id=? AND external_user_id=?',
   [(int)$credential['application_id'],$external]);
  if(!$customer)return ['state'=>'none','automatic_renewal'=>false];
  Db::exec($db,"UPDATE saved_card_agreements SET status='revocation_pending'
    WHERE application_id=? AND customer_id=? AND status IN ('active','pending')",
    [(int)$credential['application_id'],$customer['id']]);
  // A bounded attempt; the regular staging worker retries any failure.
  self::processRevocations('staging',5);
  return self::publicForCustomer($credential,$external);
 }
 public static function processRevocations(string $env,int $limit=20):void {
  if($env!=='staging')return;
  $db=Db::get('staging');
  $rows=Db::all($db,"SELECT id,contract_id FROM saved_card_agreements
    WHERE status='revocation_pending' ORDER BY updated_at ASC LIMIT ".max(1,min($limit,50)));
  foreach($rows as $card){
   try {
    $reply=NexiClient::forEnvironment('staging')->deactivateContract($card['contract_id']);
    Db::exec($db,"UPDATE saved_card_agreements SET status='revoked',deactivated_at=UTC_TIMESTAMP()
      WHERE id=? AND status='revocation_pending'",[$card['id']]);
   }catch(\Throwable $e){
    // Never restore active status following user revocation request.
    error_log('Nexi saved-card deactivation pending for '.substr($card['id'],0,8));
   }
  }
 }
 public static function syncPending(string $env,int $limit=15):void {
  if($env!=='staging')return;
  $rows=Db::all(Db::get('staging'),"SELECT a.payment_id FROM saved_card_agreements a
    JOIN payments p ON p.id=a.payment_id
    WHERE a.status='pending' AND p.status='paid' ORDER BY a.created_at
    LIMIT ".max(1,min($limit,40)));
  foreach($rows as $row){
   try{self::verifyPaid('staging',$row['payment_id']);}
   catch(\Throwable $e){error_log('Nexi CIT contract verification deferred');}
  }
 }
}
