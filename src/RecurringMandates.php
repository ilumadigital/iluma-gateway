<?php
declare(strict_types=1);
namespace Iluma\Gateway;

/**
 * Explicit subscription mandate for XPay Greece MIT_SCHEDULED.
 * Staging-only, and independently gated from OneClick/CIT.
 * Each provider request uses a unique gateway order, financial reconciliation
 * and the durable HMAC outbox. No PAN/CVV is stored in this service.
 */
final class RecurringMandates {
 public const CONSENT='iradios-mit-monthly-v1';
 public static function choice(array $body,string $env,string $sku,string $slug):bool {
  $enable=$body['auto_renew']??false;
  if(!is_bool($enable))throw new HttpError(422,'AUTO_RENEW_BOOL_REQUIRED');
  $version=$body['recurring_consent_version']??null;
  if($enable&&$version!==self::CONSENT)
   throw new HttpError(422,'EXPLICIT_RECURRING_CONSENT_REQUIRED');
  if(!$enable && $version!==null)throw new HttpError(422,'INVALID_RECURRING_CONSENT');
  if($enable&&($env!=='staging'||$sku!=='L-PREM'||$slug!=='iradiosgr'))
   throw new HttpError(403,'RECURRING_STAGING_ONLY');
  if($enable&&(!empty($body['save_card'])||!empty($body['use_saved_card'])))
   throw new HttpError(422,'CANNOT_COMBINE_CIT_AND_MIT');
  return $enable;
 }
 public static function contractId():string {
  return 'ILM'.strtoupper(substr(Security::id(),0,24));
 }
 public static function recurrence(string $contractId):array {
  if(!preg_match('/^ILM[A-F0-9]{24}$/D',$contractId))throw new \InvalidArgumentException('Contract id');
  // Calendar months can be 28 days apart. Nexi's scheduled minimum interval
  // is therefore 28 days; merchant schedule remains calendar monthly in UTC.
  return [
   'action'=>'CONTRACT_CREATION','contractId'=>$contractId,
   'contractType'=>'MIT_SCHEDULED','contractFrequency'=>'28',
   'contractExpiryDate'=>gmdate('Y-m-d\TH:i:s.000\Z',strtotime('+10 years'))
  ];
 }
 public static function preflight(\PDO $db,int $application,string $external,bool $auto):void {
  if(!$auto)return;
  $table=Db::one($db,"SELECT COUNT(*) AS n FROM information_schema.tables
   WHERE table_schema=DATABASE() AND table_name='recurring_mandates'");
  if((int)($table['n']??0)!==1)
   throw new HttpError(422,'RECURRING_MIGRATION_REQUIRED');
  $customer=Db::one($db,'SELECT id FROM customers WHERE application_id=? AND external_user_id=?',
   [$application,$external]);
  if(!$customer)return;
  $active=Db::one($db,"SELECT id FROM recurring_mandates
   WHERE application_id=? AND customer_id=? AND status IN ('pending','active','past_due','verification_pending')
   LIMIT 1",[$application,$customer['id']]);
  if($active)throw new HttpError(422,'RECURRING_AGREEMENT_EXISTS');
 }
 public static function prepare(\PDO $db,int $app,string $customer,string $payment,string $providerId):array {
  $id=self::contractId();
  Db::exec($db,"INSERT INTO recurring_mandates
   (id,application_id,customer_id,initial_payment_id,provider_customer_id,contract_id,
    consent_version,consent_at,status)
   VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(),'pending')",
   [Security::id(),$app,$customer,$payment,$providerId,$id,self::CONSENT]);
  return self::recurrence($id);
 }
 private static function credentialCustomer(array $credential,string $external):array {
  if($credential['environment']!=='staging'||($credential['slug']??'')!=='iradiosgr')
   throw new HttpError(403,'STAGING_ONLY');
  if(!preg_match('/^iradios-stage-[a-f0-9]{24}$/D',$external))
   throw new HttpError(422,'CUSTOMER_SCOPE_INVALID');
  return [Db::get('staging'),(int)$credential['application_id']];
 }
 private static function schemaReady(\PDO $db):bool {
  $r=Db::one($db,"SELECT COUNT(*) AS n FROM information_schema.tables
   WHERE table_schema=DATABASE() AND table_name='recurring_mandates'");
  return (int)($r['n']??0)===1;
 }
 public static function status(array $credential,string $external):array {
  [$db,$app]=self::credentialCustomer($credential,$external);
  if(!self::schemaReady($db))return [
   'state'=>'unavailable','auto_renew'=>false,'amount_cents'=>399,
   'currency'=>'EUR','interval'=>'calendar_month','next_charge_at'=>null,
   'paid_through_at'=>null,'cancelled_at'=>null,'execution_enabled'=>false];
  $row=Db::one($db,"SELECT r.status,r.consent_at,r.next_due_at,r.paid_through_at,r.cancelled_at
   FROM recurring_mandates r JOIN customers c ON c.id=r.customer_id
   WHERE r.application_id=? AND c.application_id=? AND c.external_user_id=?
   ORDER BY r.created_at DESC LIMIT 1",[$app,$app,$external]);
  return [
   'state'=>$row['status']??'none',
   'auto_renew'=>isset($row['status'])&&$row['status']==='active',
   'amount_cents'=>399,'currency'=>'EUR','interval'=>'calendar_month',
   'next_charge_at'=>!empty($row['next_due_at'])?str_replace(' ','T',$row['next_due_at']).'Z':null,
   'paid_through_at'=>!empty($row['paid_through_at'])?str_replace(' ','T',$row['paid_through_at']).'Z':null,
   'cancelled_at'=>!empty($row['cancelled_at'])?str_replace(' ','T',$row['cancelled_at']).'Z':null,
   'execution_enabled'=>Config::get('MIT_SANDBOX_EXECUTION_ENABLED')==='1'
      && Config::get('MIT_SANDBOX_UAT_APPROVED')==='1'
      && Config::get('APP_ENV')==='staging'
  ];
 }
 public static function cancel(array $credential,string $external):array {
  [$db,$app]=self::credentialCustomer($credential,$external);
  if(!self::schemaReady($db))throw new HttpError(503,'Recurring schema unavailable');
  $db->beginTransaction();
  try{
   $row=Db::one($db,"SELECT r.id FROM recurring_mandates r
    JOIN customers c ON c.id=r.customer_id
    WHERE r.application_id=? AND c.application_id=? AND c.external_user_id=?
      AND r.status IN ('pending','active','verification_pending','past_due')
    ORDER BY r.created_at DESC LIMIT 1 FOR UPDATE",[$app,$app,$external]);
   if($row)Db::exec($db,"UPDATE recurring_mandates SET status='cancelled',
     cancelled_at=UTC_TIMESTAMP(),next_due_at=NULL WHERE id=?",[$row['id']]);
   $db->commit();
  }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  return self::status($credential,$external);
 }
 public static function verifyPaid(string $env,string $paymentId):void {
  if($env!=='staging')return;
  $db=Db::get('staging');
  $m=Db::one($db,"SELECT m.* FROM recurring_mandates m
   JOIN payments p ON p.id=m.initial_payment_id
   WHERE p.id=? AND p.status='paid' AND m.status IN ('pending','verification_pending')",[$paymentId]);
  if(!$m)return;
  $response=NexiClient::forEnvironment('staging')->contractsForCustomer($m['provider_customer_id']);
  $confirmed=false;
  foreach(($response['contracts']??[]) as $c){
   if(is_array($c)&&($c['contractId']??null)===$m['contract_id']
      &&($c['contractType']??null)==='MIT_SCHEDULED'){$confirmed=true;break;}
  }
  if(!$confirmed){
   Db::exec($db,"UPDATE recurring_mandates SET status='verification_pending'
     WHERE id=? AND status='pending'",[$m['id']]);
   return;
  }
  $paid=Db::one($db,'SELECT confirmed_at FROM payments WHERE id=?',[$paymentId]);
  if(!$paid||!$paid['confirmed_at'])return;
  $end=self::monthAfter((string)$paid['confirmed_at']);
  Db::exec($db,"UPDATE recurring_mandates SET status='active',verified_at=UTC_TIMESTAMP(),
   paid_through_at=?,next_due_at=? WHERE id=? AND status IN ('pending','verification_pending')",
   [$end,$end,$m['id']]);
 }
 /** Calendar-month arithmetic with correct February/31st clamping in UTC. */
 public static function monthAfter(string $value):string {
  $d=new \DateTimeImmutable($value,new \DateTimeZone('UTC'));
  $next=$d->modify('first day of next month');
  $day=min((int)$d->format('j'),(int)$next->format('t'));
  return $next->setDate((int)$next->format('Y'),(int)$next->format('n'),$day)
    ->setTime((int)$d->format('H'),(int)$d->format('i'),(int)$d->format('s'))
    ->format('Y-m-d H:i:s');
 }
 /**
  * Reserve exactly ONE payment per subscribed calendar-month cycle in an
  * atomic MySQL transaction, BEFORE POST /orders/mit. No retries of a
  * provider POST after an error/timeout, and never run outside staging.
  */
 public static function chargeDue(string $env,int $limit=5):void {
  if($env!=='staging'||Config::get('APP_ENV')==='production'||
     Config::get('MIT_SANDBOX_EXECUTION_ENABLED')!=='1'||
     Config::get('MIT_SANDBOX_UAT_APPROVED')!=='1')return;
  $db=Db::get('staging');
  if(!self::schemaReady($db))return;
  $rows=Db::all($db,"SELECT id FROM recurring_mandates
   WHERE status='active' AND verified_at IS NOT NULL AND cancelled_at IS NULL
    AND next_due_at<=UTC_TIMESTAMP() ORDER BY next_due_at LIMIT ".max(1,min(5,$limit)));
  foreach($rows as $row){
   $mandateId=$row['id'];$payment=null;$mandate=null;
   $db->beginTransaction();
   try{
    $mandate=Db::one($db,"SELECT m.*,p.product_id FROM recurring_mandates m
     JOIN payments p ON p.id=m.initial_payment_id
     WHERE m.id=? FOR UPDATE",[$mandateId]);
    if(!$mandate||$mandate['status']!=='active'||$mandate['cancelled_at']!==null||
       !$mandate['verified_at']||!$mandate['next_due_at']||
       strtotime($mandate['next_due_at'].' UTC')>time()){$db->rollBack();continue;}
    $existing=Db::one($db,'SELECT id FROM recurring_cycles WHERE mandate_id=? AND cycle_due_at=? FOR UPDATE',
       [$mandateId,$mandate['next_due_at']]);
    if($existing){$db->rollBack();continue;}
    $pid=Security::id();$order='IL'.strtoupper(substr($pid,0,24));
    $req='mit-'.$mandateId.'-'.gmdate('YmdHis',strtotime($mandate['next_due_at'].' UTC'));
    $hash=hash('sha256',Security::json([$mandateId,$mandate['next_due_at'],399,'EUR']));
    Db::exec($db,"INSERT INTO payments
     (id,application_id,customer_id,product_id,order_id,request_key,request_hash,
      amount_cents,currency,status)
     VALUES(?,?,?,?,?,?,?,399,'EUR','pending')",
     [$pid,$mandate['application_id'],$mandate['customer_id'],$mandate['product_id'],$order,$req,$hash]);
    Db::exec($db,"INSERT INTO recurring_cycles
     (id,mandate_id,cycle_due_at,payment_id,status)
     VALUES(?,?,?,?, 'pending')",[Security::id(),$mandateId,$mandate['next_due_at'],$pid]);
    Payments::log($db,$pid,'mit_reserved','Προγραμματισμένη ανανέωση. Ακριβώς μία προσπάθεια Nexi.');
    $db->commit();$payment=['id'=>$pid,'order'=>$order];
   }catch(\Throwable $e){
    if($db->inTransaction())$db->rollBack();
    error_log('MIT reservation failed (no provider attempt)');continue;
   }
   // If the user cancelled after reservation but before outbound request,
   // abort without touching the provider; the cancelled mandate stays stopped.
   $check=Db::one($db,'SELECT status,cancelled_at FROM recurring_mandates WHERE id=?',[$mandateId]);
   if(!$check||$check['status']!=='active'||$check['cancelled_at']!==null){
    Db::exec($db,"UPDATE payments SET status='canceled' WHERE id=? AND status='pending'",[$payment['id']]);
    Db::exec($db,"UPDATE recurring_cycles SET status='cancelled' WHERE payment_id=?",
      [$payment['id']]);continue;
   }
   try{
    // Provider may have accepted the POST even if transport times out. Do not
    // repeat it; the worker reconciles this exact immutable order via GET.
    NexiClient::forEnvironment($env)->startMit(
     $payment['order'],$mandate['provider_customer_id'],$mandate['contract_id'],399);
    Payments::log($db,$payment['id'],'mit_submitted','Η Nexi έλαβε το αίτημα ανανέωσης. Αναμονή επιβεβαίωσης.');
   }catch(\Throwable $e){
    Db::exec($db,"UPDATE payments SET status='unknown'
      WHERE id=? AND status='pending'",[$payment['id']]);
    Db::exec($db,"UPDATE recurring_cycles SET status='unknown' WHERE payment_id=?",[$payment['id']]);
    Payments::log($db,$payment['id'],'unknown','Μη επιβεβαιωμένη MIT προσπάθεια. Μόνο provider GET, χωρίς δεύτερη χρέωση.');
    error_log('MIT outcome requires reconciliation; never re-POST');
   }
   try{Payments::reconcile($env,$payment['order']);}
   catch(\Throwable $e){error_log('MIT immediate read-only reconciliation pending');}
  }
 }
 /** Called inside Payments::reconcile transaction on a genuine captured order. */
 public static function settleCycle(\PDO $db,string $paymentId,string $status):void {
  $cycle=Db::one($db,"SELECT c.id,c.cycle_due_at,c.mandate_id,m.status AS mandate_status
    FROM recurring_cycles c JOIN recurring_mandates m ON m.id=c.mandate_id
    WHERE c.payment_id=? FOR UPDATE",[$paymentId]);
  if(!$cycle)return;
  if($status==='paid'){
   Db::exec($db,"UPDATE recurring_cycles SET status='paid' WHERE id=? AND status<>'paid'",[$cycle['id']]);
   if($cycle['mandate_status']==='active'){
    $newEnd=self::monthAfter((string)$cycle['cycle_due_at']);
    Db::exec($db,"UPDATE recurring_mandates SET
      paid_through_at=?,next_due_at=? WHERE id=? AND status='active'",
     [$newEnd,$newEnd,$cycle['mandate_id']]);
   }
  }elseif(in_array($status,['failed','canceled'],true)){
   Db::exec($db,'UPDATE recurring_cycles SET status=? WHERE id=?',[$status,$cycle['id']]);
   if($cycle['mandate_status']==='active')
    Db::exec($db,"UPDATE recurring_mandates SET status='past_due',
     next_due_at=NULL,last_error_code='CHARGE_FAILED' WHERE id=? AND status='active'",
     [$cycle['mandate_id']]);
  }
 }
 public static function pendingVerification(string $env,int $limit=10):void {
  if($env!=='staging')return;
  if(!self::schemaReady(Db::get($env)))return;
  $rows=Db::all(Db::get($env),"SELECT m.initial_payment_id FROM recurring_mandates m
   JOIN payments p ON p.id=m.initial_payment_id WHERE m.status IN ('pending','verification_pending')
   AND p.status='paid' ORDER BY m.created_at LIMIT ".max(1,min(30,$limit)));
  foreach($rows as $row)try{self::verifyPaid($env,$row['initial_payment_id']);}
   catch(\Throwable $e){error_log('MIT mandate verification deferred');}
 }
 public static function pendingDeactivation(string $env,int $limit=10):void {
  if($env!=='staging')return;
  if(!self::schemaReady(Db::get($env)))return;
  $rows=Db::all(Db::get($env),"SELECT id,contract_id FROM recurring_mandates
   WHERE status='cancelled' AND deactivated_at IS NULL
   ORDER BY cancelled_at LIMIT ".max(1,min(30,$limit)));
  foreach($rows as $r)try{
   NexiClient::forEnvironment($env)->deactivateContract($r['contract_id']);
   Db::exec(Db::get($env),"UPDATE recurring_mandates
    SET deactivated_at=UTC_TIMESTAMP() WHERE id=? AND status='cancelled'",[$r['id']]);
  }catch(\Throwable $e){error_log('MIT contract deactivation deferred');}
 }
}
