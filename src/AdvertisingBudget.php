<?php
declare(strict_types=1);
namespace Iluma\Gateway;
use PDO;

/**
 * Isolated, one-off staging advertising budget checkout.
 * This does not create subscriptions, stored-card mandates, or change prices
 * for any other SKU. The caller MUST be the HMAC-authenticated iRadios backend.
 */
final class AdvertisingBudget {
 public const SKU='ADV-BUDGET';
 public static function choice(array $credential,array $request,string $sku):?array {
  if($sku!==self::SKU)return null;
  if(($credential['environment']??'')!=='staging'||
     ($credential['slug']??'')!=='iradiosgr'||
     Config::get('APP_ENV')==='production'||Config::liveAllowed())
   throw new HttpError(403,'ADVERTISING_STAGING_ONLY');
  $customer=$request['customer_id']??null;
  $amount=$request['amount_cents']??null;
  $campaign=$request['campaign_id']??null;
  $quote=$request['quote_id']??null;
  if(!is_string($customer)||!preg_match('/^iradios-stage-[a-f0-9]{24}$/D',$customer))
   throw new HttpError(422,'ADVERTISING_CUSTOMER_INVALID');
  // A server-signed request still cannot create zero/negative or enormous charges.
  if(!is_int($amount)||$amount<100||$amount>100000000)
   throw new HttpError(422,'ADVERTISING_AMOUNT_INVALID');
  if(!is_string($campaign)||!preg_match('/^[a-f0-9]{24}$/D',$campaign)||
     !is_string($quote)||!preg_match('/^[a-f0-9]{24}$/D',$quote))
   throw new HttpError(422,'ADVERTISING_QUOTE_INVALID');
  if(($request['currency']??null)!=='EUR'||
     ($request['auto_renew']??false)!==false||
     ($request['save_card']??false)!==false||
     ($request['use_saved_card']??false)!==false||
     isset($request['recurring_consent_version'])||
     isset($request['card_consent_version'])||
     isset($request['winback_expired_at'])||
     isset($request['resource_id']))
   throw new HttpError(422,'ADVERTISING_RECURRING_FORBIDDEN');
  return ['campaign_id'=>$campaign,'quote_id'=>$quote,'amount_cents'=>$amount];
 }
 public static function record(PDO $db,int $application,string $payment,array $choice):void {
  Db::exec($db,'INSERT INTO advertising_budget_payments
   (payment_id,application_id,campaign_id,quote_id,amount_cents)
   VALUES(?,?,?,?,?)',[
    $payment,$application,$choice['campaign_id'],$choice['quote_id'],$choice['amount_cents']
   ]);
 }
 public static function eventScope(PDO $db,string $payment):?array {
  $result=Db::one($db,'SELECT a.campaign_id,a.quote_id,a.amount_cents,
    c.external_user_id FROM advertising_budget_payments a
    JOIN payments p ON p.id=a.payment_id AND p.application_id=a.application_id
    JOIN customers c ON c.id=p.customer_id
    WHERE a.payment_id=?',[$payment]);
  return $result ?: null;
 }
}
