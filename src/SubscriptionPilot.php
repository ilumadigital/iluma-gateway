<?php
declare(strict_types=1);
namespace Iluma\Gateway;

/** CLI-only first-cycle sandbox subscription test. No production or automatic renewals. */
final class SubscriptionPilot {
 private const SKU='L-PREM';
 private const CENTS=399;
 public static function reference(string $value):string {
  if(!preg_match('/^[a-z0-9][a-z0-9._-]{2,39}$/D',$value)) throw new \InvalidArgumentException('Use a unique 3-40 character lowercase test reference');
  return $value;
 }
 public static function recurrence(string $contractId):array {
  if(!preg_match('/^ILC[A-F0-9]{24}$/D',$contractId)) throw new \InvalidArgumentException('Invalid contract id');
  return [
   'action'=>'CONTRACT_CREATION',
   'contractType'=>'MIT_SCHEDULED',
   'contractId'=>$contractId,
   // Provider minimum interval; actual calendar-month renewal is NOT implemented.
   'contractFrequency'=>'28',
   'contractExpiryDate'=>gmdate('Y-m-d\TH:i:s\Z',strtotime('+2 years')),
  ];
 }
 private static function output(array $payment):array {
  return array_merge(Payments::publicPayment($payment),[
   'subscription_id'=>$payment['subscription_id'],
   'renewal_enabled'=>false,
   'scope'=>'staging_first_cycle_only',
  ]);
 }
 public static function start(string $reference):array {
  $ref=self::reference($reference);
  $reg=Db::get('registry');
  $app=Db::one($reg,"SELECT id FROM applications WHERE slug='iradiosgr' AND enabled=1");
  if(!$app)throw new \RuntimeException('iRadios application not configured');
  $appId=(int)$app['id'];
  $db=Db::get('staging');
  // Seed only the missing Staging plan; an existing catalog price is never overwritten.
  Db::exec($db,"INSERT INTO products(application_id,sku,title,amount_cents,currency,period_days)
   VALUES(?,?,?,?,'EUR',30) ON DUPLICATE KEY UPDATE sku=VALUES(sku)",
   [$appId,self::SKU,'Listener Premium',self::CENTS]);
  $product=Db::one($db,'SELECT * FROM products WHERE application_id=? AND sku=?',[$appId,self::SKU]);
  if(!$product || (int)$product['amount_cents']!==self::CENTS || $product['currency']!=='EUR' || !(int)$product['enabled'])
   throw new \RuntimeException('Staging L-PREM must be enabled at 399 cents EUR');

  $external='sandbox-sub-'.$ref;
  $requestKey='sandbox-subscription-'.$ref;
  $email='sandbox@example.com';$name='Sandbox Listener';
  $requestHash=hash('sha256',Security::json([
   'customer_id'=>$external,'product_sku'=>self::SKU,'email'=>$email,'name'=>$name,
  ]));
  $db->beginTransaction();
  try {
   $payment=Db::one($db,'SELECT * FROM payments WHERE application_id=? AND request_key=? FOR UPDATE',[$appId,$requestKey]);
   if($payment){
    if(!$payment['subscription_id']||!hash_equals($payment['request_hash'],$requestHash))
     throw new \RuntimeException('Reference already assigned to another payment');
    $db->commit();
    return self::output($payment);
   }
   $customer=Db::one($db,'SELECT id FROM customers WHERE application_id=? AND external_user_id=? FOR UPDATE',[$appId,$external]);
   if($customer)$customerId=$customer['id'];
   else {
    $customerId=Security::id();
    Db::exec($db,'INSERT INTO customers(id,application_id,external_user_id,email,name) VALUES(?,?,?,?,?)',[$customerId,$appId,$external,$email,$name]);
   }
   $subId=Security::id();$contractId='ILC'.strtoupper(substr($subId,0,24));
   $cycleId=Security::id();$paymentId=Security::id();$orderId='IL'.strtoupper(substr($paymentId,0,24));
   Db::exec($db,"INSERT INTO subscriptions(id,application_id,customer_id,product_id,external_subscription_id,state,auto_renew,contract_id)
    VALUES(?,?,?,?,?,'pending',0,?)",[$subId,$appId,$customerId,$product['id'],$ref,$contractId]);
   Db::exec($db,"INSERT INTO nexi_contracts(subscription_id,provider_contract_ref,contract_type,active)
    VALUES(?,?,'MIT_SCHEDULED',0)",[$subId,$contractId]);
   Db::exec($db,'INSERT INTO billing_cycles(id,subscription_id,cycle_key) VALUES(?,?,?)',[$cycleId,$subId,'initial']);
   Db::exec($db,"INSERT INTO payments(id,application_id,customer_id,product_id,subscription_id,billing_cycle_id,order_id,request_key,request_hash,amount_cents,status)
    VALUES(?,?,?,?,?,?,?,?,?,?,'pending')",
    [$paymentId,$appId,$customerId,$product['id'],$subId,$cycleId,$orderId,$requestKey,$requestHash,self::CENTS]);
   Payments::log($db,$paymentId,'created','Δοκιμαστική αρχική πληρωμή συνδρομής Listener Premium.');
   $db->commit();
  }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  // Only one provider checkout attempt; an uncertain result is NOT retried automatically.
  try {
   $result=NexiClient::forEnvironment('staging')->startCheckout(
    $orderId,self::CENTS,$external,self::recurrence($contractId)
   );
   $url=(string)($result['hostedPage']??'');$token=(string)($result['securityToken']??'');
   $host=strtolower((string)parse_url($url,PHP_URL_HOST));
   if(!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https'||
      !($host==='nexigroup.com'||str_ends_with($host,'.nexigroup.com'))||$token==='')
    throw new \RuntimeException('Unexpected Nexi hosted checkout response');
   Db::exec($db,"UPDATE payments SET status='redirect',hosted_page=?,security_token_box=? WHERE id=? AND status='pending'",
    [$url,Security::encrypt($token),$paymentId]);
   Payments::log($db,$paymentId,'redirect','Δημιουργήθηκε Nexi Sandbox checkout με αίτημα MIT συμβολαίου.');
  }catch(\Throwable $e){
   error_log('ILUMA sandbox subscription checkout uncertain: '.$e->getMessage());
   Db::exec($db,"UPDATE payments SET status='unknown' WHERE id=? AND status='pending'",[$paymentId]);
   Payments::log($db,$paymentId,'unknown','Χρειάζεται έλεγχος Nexi προτού γίνει νέα προσπάθεια.');
  }
  return self::output(Db::one($db,'SELECT * FROM payments WHERE id=?',[$paymentId]));
 }
 public static function status(string $reference):array {
  $ref=self::reference($reference);
  $row=Db::one(Db::get('staging'),"SELECT s.id AS subscription_id,s.state AS subscription_state,
   s.contract_id,s.auto_renew,s.period_end_at,p.order_id,p.status AS payment_status,
   nc.active AS provider_contract_verified,c.external_user_id
   FROM subscriptions s JOIN payments p ON p.subscription_id=s.id
   JOIN customers c ON c.id=s.customer_id
   JOIN nexi_contracts nc ON nc.subscription_id=s.id
   WHERE s.external_subscription_id=? AND p.request_key=?",
   [$ref,'sandbox-subscription-'.$ref]);
  if(!$row)throw new \RuntimeException('No subscription for that Staging reference');
  return $row;
 }
 public static function verifyProviderContract(string $reference):array {
  $row=self::status($reference);
  $response=NexiClient::forEnvironment('staging')->contractsForCustomer((string)$row['external_user_id']);
  $found=false;
  if(($response['customerId']??null)===$row['external_user_id'] && is_array($response['contracts']??null)){
   foreach($response['contracts'] as $contract){
    if(is_array($contract)&&($contract['contractId']??null)===$row['contract_id']&&
       ($contract['contractType']??null)==='MIT_SCHEDULED'){$found=true;break;}
   }
  }
  if($found && $row['payment_status']==='paid' && $row['subscription_state']==='active'){
   Db::exec(Db::get('staging'),'UPDATE nexi_contracts SET active=1 WHERE subscription_id=?',[$row['subscription_id']]);
  }
  $row['provider_contract_verified']=$found?1:0;
  return $row;
 }
}
