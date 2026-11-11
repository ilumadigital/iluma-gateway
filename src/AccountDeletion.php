<?php
declare(strict_types=1);
namespace Iluma\Gateway;

/**
 * Signed, staging-only billing shutdown before iRadios admin account deletion.
 * Financial records remain for audit; no future MIT may be scheduled.
 * A customer tombstone also rejects late/replayed checkout requests.
 */
final class AccountDeletion {
 public static function cancelAll(array $credential,string $external):array {
  if(($credential['environment']??'')!=='staging'
     ||($credential['slug']??'')!=='iradiosgr'
     ||Config::get('APP_ENV')==='production'
     ||Config::get('PRODUCTION_ENABLED')==='1')
   throw new HttpError(403,'ACCOUNT_DELETION_STAGING_ONLY');
  if(!preg_match('/^iradios-stage-[a-f0-9]{24}$/D',$external))
   throw new HttpError(422,'CUSTOMER_SCOPE_INVALID');

  $db=Db::get('staging');$app=(int)$credential['application_id'];
  // Never acknowledge deletion if a ledger/migration cannot be inspected.
  $ready=Db::one($db,"SELECT COUNT(*) AS n FROM information_schema.tables
   WHERE table_schema=DATABASE() AND table_name IN
   ('customers','subscriptions','nexi_contracts','recurring_mandates',
    'recurring_cycles','broadcaster_mandates','broadcaster_cycles')");
  if((int)($ready['n']??0)!==7)
   throw new HttpError(503,'ACCOUNT_DELETION_BILLING_SCHEMA_UNAVAILABLE');

  $db->beginTransaction();
  try{
   // Same row lock/order as checkout(): serialize with new mandate creation.
   $customer=Db::one($db,"SELECT id,type FROM customers
    WHERE application_id=? AND external_user_id=? FOR UPDATE",[$app,$external]);
   if($customer){
    $customerId=$customer['id'];
   }else{
    // Even a free user gets a non-PII tombstone so the deleted ID cannot bill.
    $customerId=Security::id();
    Db::exec($db,"INSERT INTO customers
      (id,application_id,external_user_id,email,name,type)
      VALUES(?,?,?,'','','account_deleted')",[$customerId,$app,$external]);
   }

   // Older legacy Nexi contracts are not covered by the MIT deactivation
   // worker. Refuse deletion rather than claim to have stopped such a token.
   $legacyActive=Db::one($db,"SELECT COUNT(*) AS n FROM nexi_contracts n
    JOIN subscriptions s ON s.id=n.subscription_id
    WHERE s.application_id=? AND s.customer_id=? AND n.active=1",
    [$app,$customerId]);
   if((int)($legacyActive['n']??0)>0)
    throw new HttpError(409,'LEGACY_NEXI_CONTRACT_REQUIRES_REVIEW');

   // Cancel ALL mandates, across all roles, SKUs and stations (not only latest).
   // Worker locks these same rows before MIT requests and later deactivates
   // their provider tokens; historical payments and paid periods are retained.
   $listener=Db::exec($db,"UPDATE recurring_mandates
    SET status='cancelled',cancelled_at=COALESCE(cancelled_at,UTC_TIMESTAMP()),
        next_due_at=NULL
    WHERE application_id=? AND customer_id=?
      AND (status<>'cancelled' OR next_due_at IS NOT NULL)",[$app,$customerId]);
   $broadcaster=Db::exec($db,"UPDATE broadcaster_mandates
    SET status='cancelled',cancelled_at=COALESCE(cancelled_at,UTC_TIMESTAMP()),
        next_due_at=NULL
    WHERE application_id=? AND customer_id=?
      AND (status<>'cancelled' OR next_due_at IS NOT NULL)",[$app,$customerId]);
   $legacy=Db::exec($db,"UPDATE subscriptions
    SET state='cancelled',auto_renew=0,next_billing_at=NULL
    WHERE application_id=? AND customer_id=?
      AND (state<>'cancelled' OR auto_renew<>0 OR next_billing_at IS NOT NULL)",
    [$app,$customerId]);

   // Terminal marker blocks checkout, including reuse of an old request key.
   Db::exec($db,"UPDATE customers SET type='account_deleted'
    WHERE id=? AND application_id=?",[$customerId,$app]);

   $remainingListener=Db::one($db,"SELECT COUNT(*) AS n FROM recurring_mandates
    WHERE application_id=? AND customer_id=?
      AND (status<>'cancelled' OR next_due_at IS NOT NULL)",[$app,$customerId]);
   $remainingBroadcaster=Db::one($db,"SELECT COUNT(*) AS n FROM broadcaster_mandates
    WHERE application_id=? AND customer_id=?
      AND (status<>'cancelled' OR next_due_at IS NOT NULL)",[$app,$customerId]);
   $remainingLegacy=Db::one($db,"SELECT COUNT(*) AS n FROM subscriptions
    WHERE application_id=? AND customer_id=?
      AND (auto_renew<>0 OR next_billing_at IS NOT NULL)",[$app,$customerId]);
   if((int)$remainingListener['n']||(int)$remainingBroadcaster['n']||
      (int)$remainingLegacy['n'])
    throw new \RuntimeException('Account billing shutdown incomplete');

   $db->commit();
   return ['state'=>'cancelled','billing_blocked'=>true,
    'listener_cancelled'=>$listener,'broadcaster_cancelled'=>$broadcaster,
    'subscriptions_cancelled'=>$legacy];
  }catch(\Throwable $e){
   if($db->inTransaction())$db->rollBack();
   throw $e;
  }
 }
}
