<?php
declare(strict_types=1);
namespace Iluma\Gateway;
use PDO;

/**
 * Single-use Listener Premium win-back promotion.
 * The signing iRadios backend is the source of paid-through / entitlement
 * eligibility. The Gateway independently checks the customer's verified
 * historic paid payment and controls the one-time irreversible redemption.
 */
final class Winback {
 public const SKU='L-WINBACK-199';
 public const AMOUNT=199;
 public const DAYS=30;
 public static function ready(PDO $db):bool{
  $r=Db::one($db,"SELECT COUNT(*) n FROM information_schema.tables
    WHERE table_schema=DATABASE() AND table_name='winback_claims'");
  return (int)($r['n']??0)===1;
 }
 public static function expiredAt(array $body,string $environment,string $sku,string $slug):?string{
  $proof=$body['winback_expired_at']??null;
  if($sku!==self::SKU){
   if($proof!==null)throw new HttpError(422,'UNEXPECTED_WINBACK_PROOF');
   return null;
  }
  if($environment!=='staging'||$slug!=='iradiosgr')
   throw new HttpError(403,'WINBACK_STAGING_ONLY');
  if(!is_string($proof)||!preg_match('/^20[2-9][0-9]-[0-1][0-9]-[0-3][0-9]T[0-2][0-9]:[0-5][0-9]:[0-5][.]?[0-9]{0,3}Z$/D',$proof))
   throw new HttpError(422,'WINBACK_EXPIRED_AT_INVALID');
  try{$date=new \DateTimeImmutable($proof,new \DateTimeZone('UTC'));}
  catch(\Throwable){throw new HttpError(422,'WINBACK_EXPIRED_AT_INVALID');}
  $expiry=$date->getTimestamp();$now=time();
  if($expiry>$now||$now-$expiry>=self::DAYS*86400)
   throw new HttpError(422,'WINBACK_WINDOW_EXPIRED');
  return gmdate('Y-m-d H:i:s',$expiry);
 }
 public static function preflight(PDO $db,int $app,string $customer,string $expiredAt):void{
  if(!self::ready($db))throw new HttpError(422,'WINBACK_MIGRATION_REQUIRED');
  // Verify at least one provider-confirmed regular Premium payment for the
  // exact Gateway account, before the iRadios-authenticated expiry.
  $paid=Db::one($db,"SELECT MAX(p.confirmed_at) latest
    FROM payments p JOIN products pr ON pr.id=p.product_id
    WHERE p.application_id=? AND p.customer_id=? AND p.status='paid'
    AND pr.sku IN ('L-PREM','L-WINBACK-199')",[$app,$customer]);
  $last=$paid['latest']??null;
  if(!$last||strtotime($last.' UTC')>strtotime($expiredAt.' UTC')||
     strtotime($expiredAt.' UTC')-strtotime($last.' UTC')>65*86400)
   throw new HttpError(422,'WINBACK_PREVIOUS_PAID_PERIOD_REQUIRED');
  // No overlapping signed automatic renewal or pending provider contract.
  $mandate=Db::one($db,"SELECT id FROM recurring_mandates
    WHERE application_id=? AND customer_id=?
    AND status IN ('pending','verification_pending','active','past_due')
    LIMIT 1",[$app,$customer]);
  if($mandate)throw new HttpError(422,'WINBACK_REQUIRES_RECURRING_CANCELLATION');
  $claim=Db::one($db,"SELECT w.redeemed_at,p.status AS payment_status
    FROM winback_claims w JOIN payments p ON p.id=w.payment_id
    WHERE w.application_id=? AND w.customer_id=? FOR UPDATE",[$app,$customer]);
  if($claim && ($claim['redeemed_at']!==null||
    !in_array($claim['payment_status'],['failed','canceled'],true)))
   throw new HttpError(422,'WINBACK_ALREADY_USED_OR_PENDING');
 }
 public static function reserve(PDO $db,int $app,string $customer,string $payment,string $expiredAt):void{
  $prior=Db::one($db,"SELECT w.redeemed_at,p.status payment_status
    FROM winback_claims w JOIN payments p ON p.id=w.payment_id
    WHERE w.application_id=? AND w.customer_id=? FOR UPDATE",[$app,$customer]);
  if(!$prior){
   Db::exec($db,'INSERT INTO winback_claims(id,application_id,customer_id,payment_id,expired_at) VALUES(?,?,?,?,?)',
    [Security::id(),$app,$customer,$payment,$expiredAt]);
  }elseif($prior['redeemed_at']===null&&
      in_array($prior['payment_status'],['failed','canceled'],true)){
   Db::exec($db,'UPDATE winback_claims SET payment_id=?,expired_at=? WHERE application_id=? AND customer_id=? AND redeemed_at IS NULL',
    [$payment,$expiredAt,$app,$customer]);
  }else throw new HttpError(422,'WINBACK_ALREADY_USED_OR_PENDING');
 }
 /** Executed inside the exact paid financial reconciliation transaction. */
 public static function captured(PDO $db,string $paymentId):void{
  if(!self::ready($db))return;
  Db::exec($db,'UPDATE winback_claims SET redeemed_at=UTC_TIMESTAMP()
   WHERE payment_id=? AND redeemed_at IS NULL',[$paymentId]);
 }
 /** Account scoped, HMAC-authenticated, read-only provider ledger state. */
 public static function status(array $credential,string $external):array{
  if($credential['environment']!=='staging'||($credential['slug']??'')!=='iradiosgr'||
     !preg_match('/^iradios-stage-[a-f0-9]{24}$/D',$external))
   throw new HttpError(403,'WINBACK_STAGING_ONLY');
  $db=Db::get('staging');
  if(!self::ready($db))return ['state'=>'unavailable','used'=>false];
  $c=Db::one($db,"SELECT id FROM customers WHERE application_id=? AND external_user_id=?",
    [(int)$credential['application_id'],$external]);
  if(!$c)return ['state'=>'none','used'=>false];
  $row=Db::one($db,"SELECT w.redeemed_at,p.status payment_status
    FROM winback_claims w JOIN payments p ON p.id=w.payment_id
    WHERE w.application_id=? AND w.customer_id=?",[(int)$credential['application_id'],$c['id']]);
  if(!$row||($row['redeemed_at']===null&&in_array($row['payment_status'],['failed','canceled'],true)))
   return ['state'=>'none','used'=>false];
  if($row['redeemed_at']!==null||$row['payment_status']==='paid')
   return ['state'=>'redeemed','used'=>true];
  return ['state'=>'pending','used'=>false];
 }
}
