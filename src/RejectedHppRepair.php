<?php
declare(strict_types=1);
namespace Iluma\Gateway;

use PDO;
use Throwable;

/**
 * Strictly scoped HPP-rejection evidence for one original iRadios checkout.
 * This class never creates payments, contracts, refunds or provider POSTs.
 * The Nexi order GET must return a fresh 404 before Payments can close it.
 */
final class RejectedHppRepair {
 public const APPLY_FLAG='IRADIOS_PRODUCTION_REJECTED_HPP_REPAIR_ENABLED';
 public const AUTO_FLAG='IRADIOS_PRODUCTION_REJECTED_HPP_AUTO_ENABLED';

 public static function productionApplyAllowed():bool {
  return Config::liveAllowed() && Config::get(self::APPLY_FLAG)==='1';
 }
 public static function productionAutoAllowed():bool {
  return self::productionApplyAllowed() && Config::get(self::AUTO_FLAG)==='1';
 }
 public static function permittedEnvironment(string $env):bool {
  return ($env==='staging' && Config::stagingTenantRuntimeAllowed())
   || ($env==='production' && Config::liveAllowed());
 }
 public static function provider404(Throwable $error):bool {
  return str_starts_with($error->getMessage(),'Nexi status 404 ');
 }
 private static function exists(PDO $db,string $sql,array $args):bool {
  return (bool)Db::one($db,$sql,$args);
 }
 private static function tableExists(PDO $db,string $name):bool {
  if(!in_array($name,['broadcaster_mandates','broadcaster_cycles',
   'recurring_mandates','recurring_cycles','saved_card_agreements'],true))
   return false;
  return self::exists($db,'SELECT 1 FROM information_schema.tables
    WHERE table_schema=DATABASE() AND table_name=? LIMIT 1',[$name]);
 }

 /**
  * Return a stable reason code, never payment/customer details.
  * May be called inside the existing payment-row FOR UPDATE transaction.
  */
 public static function eligibility(PDO $db,string $env,array $p,bool $allowLegacy=false,bool $forApply=false):string {
  if(!self::permittedEnvironment($env))return 'ENVIRONMENT_LOCKED';
  if($forApply && $env==='production' && !self::productionApplyAllowed())
   return 'PRODUCTION_APPLY_LOCKED';
  if(!preg_match('/^IL[A-F0-9]{24}$/D',(string)($p['order_id']??'')))
   return 'INVALID_ORDER';
  if(($p['status']??null)!=='unknown')return 'NOT_UNKNOWN';
  if(($p['operation_id']??null)!==null || ($p['hosted_page']??null)!==null ||
    ($p['security_token_box']??null)!==null || ($p['confirmed_at']??null)!==null ||
    ($p['subscription_id']??null)!==null || ($p['billing_cycle_id']??null)!==null)
   return 'PAYMENT_FINANCIAL_EVIDENCE';
  $created=strtotime((string)($p['created_at']??'').' UTC');
  if($created===false || $created>time()-120)return 'QUARANTINE';
  if(($p['currency']??null)!=='EUR' || !ctype_digit((string)($p['amount_cents']??'')) ||
    (int)$p['amount_cents']<1)return 'INVALID_AMOUNT';
  if($env==='production' && !empty($p['iradios_stage_routed']) && !$allowLegacy)
   return 'LEGACY_STAGE_ROUTED_REVIEW';

  $application=(int)($p['application_id']??0);
  if($application<=0)return 'INVALID_APPLICATION';
  $app=Db::one(Db::get('registry'),
   "SELECT id FROM applications WHERE id=? AND slug='iradiosgr' LIMIT 1",[$application]);
  if(!$app)return 'APPLICATION_NOT_IRADIOS';

  $customer=Db::one($db,'SELECT id,external_user_id FROM customers
    WHERE id=? AND application_id=? LIMIT 1',
    [$p['customer_id']??null,$application]);
  if(!$customer || !preg_match('/^iradios-stage-[a-f0-9]{24}$/D',
    (string)$customer['external_user_id']))return 'CUSTOMER_SCOPE_MISMATCH';

  $product=Db::one($db,'SELECT sku,amount_cents,currency FROM products
    WHERE id=? AND application_id=? LIMIT 1',[$p['product_id']??null,$application]);
  if(!$product || $product['currency']!=='EUR')return 'PRODUCT_SCOPE_MISMATCH';
  $sku=(string)$product['sku'];
  $amount=(int)$p['amount_cents'];
  $broadcaster=array_key_exists($sku,BroadcasterMandates::PRICE);
  $listener=in_array($sku,['L-PREM','L-WINBACK-199'],true);
  if(!$broadcaster && !$listener)return 'PRODUCT_NOT_SUPPORTED';
  if($broadcaster && !BroadcasterMandates::validContractAmount($sku,$amount))
   return 'BROADCASTER_PRICE_MISMATCH';
  if($listener && !in_array($amount, $sku==='L-PREM'?[399]:[199],true))
   return 'LISTENER_PRICE_MISMATCH';
  if($broadcaster && !preg_match('/^iradios-b(?:stage|play)-[a-f0-9]{24}-[a-f0-9-]{36}$/iD',
   (string)($p['request_key']??'')))return 'REQUEST_SCOPE_MISMATCH';
  if($listener && !preg_match('/^iradios-(?:stage|play)-[a-f0-9]{24}-[a-f0-9-]{36}$/iD',
   (string)($p['request_key']??'')))return 'REQUEST_SCOPE_MISMATCH';
  if(!str_contains((string)$p['request_key'],(string)substr($customer['external_user_id'],-24)))
   return 'REQUEST_CUSTOMER_MISMATCH';

  $pid=$p['id'];$order=$p['order_id'];
  if(!self::exists($db,"SELECT 1 FROM payment_logs WHERE payment_id=?
      AND (event_type='hpp_rejected_400' OR
        (event_type='unknown' AND
        message_gr LIKE 'Η Nexi απέρριψε τη δημιουργία checkout (HTTP 400)%'))
      LIMIT 1",[$pid]))return 'NO_DOCUMENTED_HPP_400';

  foreach([
   ['SELECT 1 FROM payment_attempts WHERE payment_id=? LIMIT 1',[$pid]],
   ['SELECT 1 FROM outgoing_events WHERE payment_id=? LIMIT 1',[$pid]],
   ['SELECT 1 FROM webhook_events WHERE order_id=? LIMIT 1',[$order]],
   ['SELECT 1 FROM refunds WHERE payment_id=? LIMIT 1',[$pid]]
  ] as [$sql,$args])if(self::exists($db,$sql,$args))return 'FINANCIAL_EVENT_EXISTS';

  if($broadcaster){
   if(!self::tableExists($db,'broadcaster_mandates') ||
      !self::tableExists($db,'broadcaster_cycles'))
    return 'BROADCASTER_SCHEMA_MISSING';
   $mandates=Db::all($db,'SELECT id,status,customer_id,sku,amount_cents,
      paid_through_at,next_due_at,verified_at,cancelled_at,deactivated_at
      FROM broadcaster_mandates
      WHERE initial_payment_id=? AND application_id=? LIMIT 2',[$pid,$application]);
   if(count($mandates)!==1)return 'BROADCASTER_MANDATE_MISSING_OR_AMBIGUOUS';
   $m=$mandates[0];
   if($m['customer_id']!==$p['customer_id'] || $m['sku']!==$sku ||
      (int)$m['amount_cents']!==$amount || $m['status']!=='pending' ||
      $m['paid_through_at']!==null || $m['next_due_at']!==null ||
      $m['verified_at']!==null || $m['cancelled_at']!==null ||
      $m['deactivated_at']!==null)return 'BROADCASTER_MANDATE_HAS_EVIDENCE';
   if(self::exists($db,'SELECT 1 FROM broadcaster_cycles WHERE mandate_id=? LIMIT 1',[$m['id']]))
    return 'BROADCASTER_CYCLE_EXISTS';
  } elseif(self::tableExists($db,'recurring_mandates')){
   $mandates=Db::all($db,'SELECT id,status,customer_id,paid_through_at,
      next_due_at,verified_at,cancelled_at,deactivated_at
      FROM recurring_mandates WHERE initial_payment_id=? AND application_id=? LIMIT 2',
      [$pid,$application]);
   if(count($mandates)>1)return 'LISTENER_MANDATE_AMBIGUOUS';
   if($mandates){
    $m=$mandates[0];
    if($m['customer_id']!==$p['customer_id'] || $m['status']!=='pending' ||
       $m['paid_through_at']!==null || $m['next_due_at']!==null ||
       $m['verified_at']!==null || $m['cancelled_at']!==null ||
       $m['deactivated_at']!==null)return 'LISTENER_MANDATE_HAS_EVIDENCE';
    if(!self::tableExists($db,'recurring_cycles'))return 'LISTENER_CYCLE_SCHEMA_MISSING';
    if(self::exists($db,'SELECT 1 FROM recurring_cycles WHERE mandate_id=? LIMIT 1',[$m['id']]))
     return 'LISTENER_CYCLE_EXISTS';
   }
  }
  if(self::tableExists($db,'saved_card_agreements')){
   $cards=Db::all($db,'SELECT status,verified_at,deactivated_at,customer_id
     FROM saved_card_agreements WHERE payment_id=? AND application_id=? LIMIT 2',
     [$pid,$application]);
   if(count($cards)>1)return 'CARD_AGREEMENT_AMBIGUOUS';
   if($cards && ($cards[0]['status']!=='pending' || $cards[0]['verified_at']!==null ||
       $cards[0]['deactivated_at']!==null || $cards[0]['customer_id']!==$p['customer_id']))
    return 'CARD_AGREEMENT_HAS_EVIDENCE';
  }
  return 'READY';
 }

 public static function listCandidates(string $env,int $limit=100,?string $accountSuffix=null):array {
  if(!self::permittedEnvironment($env))throw new \RuntimeException('REPAIR_ENVIRONMENT_LOCKED');
  if($limit<1||$limit>500)throw new \InvalidArgumentException('INVALID_REPAIR_LIMIT');
  if($accountSuffix!==null && !preg_match('/^[a-f0-9]{8}$/D',$accountSuffix))
   throw new \InvalidArgumentException('INVALID_ACCOUNT_SUFFIX');
  $db=Db::get($env);
  $app=Db::one(Db::get('registry'),
   "SELECT id FROM applications WHERE slug='iradiosgr' LIMIT 1");
  if(!$app)throw new \RuntimeException('IRADIOS_APP_NOT_FOUND');
  $args=[(int)$app['id'],(int)$app['id'],(int)$app['id']];
  $suffix='';
  if($accountSuffix!==null){$suffix=' AND RIGHT(c.external_user_id,8)=?';$args[]=$accountSuffix;}
  return Db::all($db,"SELECT p.*,pr.sku,c.external_user_id
    FROM payments p
    JOIN products pr ON pr.id=p.product_id AND pr.application_id=p.application_id
    JOIN customers c ON c.id=p.customer_id AND c.application_id=p.application_id
    WHERE p.application_id=? AND pr.application_id=? AND c.application_id=?
      AND p.status='unknown' AND p.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE)
      AND (c.external_user_id LIKE 'iradios-stage-%'".
       ($env==='production'?" OR c.external_user_id LIKE 'iradios-play-%'":"").")".$suffix."
      AND EXISTS (SELECT 1 FROM payment_logs l WHERE l.payment_id=p.id AND
       (l.event_type='hpp_rejected_400' OR
        (l.event_type='unknown' AND l.message_gr LIKE
         'Η Nexi απέρριψε τη δημιουργία checkout (HTTP 400)%')))
    ORDER BY p.created_at ASC LIMIT ".$limit,$args);
 }

 /** Strictly a read-only GET; 200 is NEVER taken as proof of nonpayment. */
 public static function nexiOrderIsAbsent(string $env,string $orderId):string {
  try {
   NexiClient::forEnvironment($env)->order($orderId);
   return 'NEXI_ORDER_EXISTS_REVIEW';
  } catch(Throwable $error) {
   if(self::provider404($error))return 'NEXI_404';
   return 'NEXI_LOOKUP_UNAVAILABLE';
  }
 }
}
