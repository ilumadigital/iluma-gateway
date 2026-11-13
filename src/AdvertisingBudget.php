<?php
declare(strict_types=1);
namespace Iluma\Gateway;

use PDO;

/**
 * Staging-only variable-price Advertising Budget checkout. Only the signed
 * iRadios staging application can authorize an amount and a Mongo campaign
 * + quote pair. The existing Premium/Broadcaster catalog is never modified.
 * A checkout is NOT a financial entitlement: only Nexi reconciliation emits
 * a signed webhook, and the iRadios ledger credits it idempotently.
 */
final class AdvertisingBudget {
 public const SKU='ADV-BUDGET';
 private const LOCK='iluma_iradios_advertising_budget_staging_v1';
 private static bool $ready=false;

 public static function permitted(array $credential):bool {
  return BroadcasterStagingBootstrap::permitted($credential)
   &&Config::get('ADVERTISING_SANDBOX_ENABLED')==='1';
 }
 private static function isolated():bool {
  $staging=Config::get('STAGING_DSN');
  $production=Config::get('PRODUCTION_DSN');
  if($staging===''||$production===''||hash_equals($staging,$production))return false;
  if(!preg_match('/(?:^|;)dbname=([^;]+)/i',$staging,$a)||
     !preg_match('/(?:^|;)dbname=([^;]+)/i',$production,$b))return false;
  return $a[1]!==''&&$b[1]!==''&&strcasecmp($a[1],$b[1])!==0;
 }

 /**
  * Never accept caller-selected prices for fixed-price products. Only
  * ADV-BUDGET accepts amount_cents from a signed, staging-scoped server.
  */
 public static function choice(array $request,array $credential):?array {
  if(($request['product_sku']??null)!==self::SKU)return null;
  if(!self::permitted($credential))throw new HttpError(503,'ADVERTISING_SANDBOX_NOT_ENABLED');
  $allowed=['customer_id','product_sku','email','name','campaign_id',
   'quote_id','amount_cents','currency'];
  foreach(array_keys($request) as $key)
   if(!in_array($key,$allowed,true))
    throw new HttpError(422,'Advertising checkout does not accept recurring or card scope');
  $external=$request['customer_id']??null;
  $campaign=$request['campaign_id']??null;
  $quote=$request['quote_id']??null;
  $cents=$request['amount_cents']??null;
  if(!is_string($external)||!preg_match('/^iradios-stage-[a-f0-9]{24}$/D',$external)||
     !is_string($campaign)||!preg_match('/^[a-f0-9]{24}$/D',$campaign)||
     !is_string($quote)||!preg_match('/^[a-f0-9]{24}$/D',$quote)||
     !is_int($cents)||$cents<100||$cents>100000000||
     ($request['currency']??'EUR')!=='EUR')
   throw new HttpError(422,'Invalid advertising quote correlation or amount');
  return ['external_user_id'=>$external,'campaign_id'=>$campaign,
   'quote_id'=>$quote,'amount_cents'=>$cents];
 }

 private static function catalog(PDO $db,int $app):bool {
  $row=Db::one($db,'SELECT amount_cents,currency,enabled FROM products WHERE application_id=? AND sku=?',
   [$app,self::SKU]);
  if(!$row)return false;
  // Nonzero sentinel only; each signed ad order stores its actual amount.
  if((int)$row['amount_cents']!==100||$row['currency']!=='EUR'||
     (int)$row['enabled']!==1)
   throw new HttpError(503,'ADVERTISING_STAGING_CATALOG_MISMATCH');
  return true;
 }
 private static function ready(PDO $db,int $app):bool {
  $table=Db::one($db,"SELECT COUNT(*) AS n FROM information_schema.tables
   WHERE table_schema=DATABASE() AND table_name='advertising_budget_scopes'");
  return (int)($table['n']??0)===1&&self::catalog($db,$app);
 }
 public static function ensure(array $credential):void {
  if(!self::permitted($credential))throw new HttpError(503,'ADVERTISING_STAGING_ONLY');
  if(!self::isolated())throw new HttpError(503,'ADVERTISING_DATABASE_NOT_ISOLATED');
  if(self::$ready)return;
  $db=Db::get('staging');$app=(int)$credential['application_id'];
  try {
   if(self::ready($db,$app)){self::$ready=true;return;}
   $lock=Db::one($db,'SELECT GET_LOCK(?,5) AS acquired',[self::LOCK]);
   if((int)($lock['acquired']??0)!==1)
    throw new HttpError(503,'ADVERTISING_SCHEMA_BUSY');
   try {
    if(!self::ready($db,$app)){
     $filename=dirname(__DIR__).'/database/migrations/20261113_advertising_budget.sql';
     $sql=file_get_contents($filename);
     if($sql===false)throw new \RuntimeException('Advertising migration missing');
     $sql=preg_replace('/^\s*--[^\r\n]*$/m','',$sql);
     if($sql===null||!preg_match('/^\s*CREATE TABLE IF NOT EXISTS advertising_budget_scopes\s*\(/i',$sql))
      throw new \RuntimeException('Advertising migration invalid');
     // File contains exactly one CREATE TABLE, and no shell or DML statements.
     $sql=trim($sql);
     if(substr_count($sql,';')!==1||!str_ends_with($sql,';'))
      throw new \RuntimeException('Unexpected advertising schema statements');
     $db->exec(substr($sql,0,-1));
     if(!self::catalog($db,$app)){
      Db::exec($db,"INSERT INTO products
       (application_id,sku,title,amount_cents,currency,enabled)
       VALUES(?,?,?,100,'EUR',1) ON DUPLICATE KEY UPDATE id=id",
       [$app,self::SKU,'Advertising Budget (variable EUR)']);
     }
    }
    if(!self::ready($db,$app))
     throw new HttpError(503,'ADVERTISING_STAGING_SCHEMA_INCOMPLETE');
    self::$ready=true;
   }finally{
    try{Db::one($db,'SELECT RELEASE_LOCK(?) AS released',[self::LOCK]);}
    catch(\Throwable $e){error_log('Advertising staging setup lock release deferred');}
   }
  }catch(\Throwable $e){
   if($e instanceof HttpError)throw $e;
   error_log('Advertising staging schema setup failed: '.get_class($e));
   throw new HttpError(503,'ADVERTISING_STAGING_SCHEMA_UNAVAILABLE');
  }
 }

 /** Called in the *same* DB transaction that inserts the payment record. */
 public static function record(PDO $db,int $app,string $customer,
  string $payment,array $choice):void {
  if(!preg_match('/^[a-f0-9]{32}$/D',$payment))throw new HttpError(422,'Invalid payment');
  try{
   Db::exec($db,'INSERT INTO advertising_budget_scopes
    (payment_id,application_id,customer_id,campaign_id,quote_id,amount_cents)
    VALUES(?,?,?,?,?,?)',[$payment,$app,$customer,
     $choice['campaign_id'],$choice['quote_id'],$choice['amount_cents']]);
  }catch(\PDOException $e){
   if($e->getCode()==='23000')throw new HttpError(409,'Advertising quote already has a payment');
   throw $e;
  }
 }

 /** The scope originates from the immutable, recorded signed checkout. */
 public static function eventScope(PDO $db,string $payment):?array {
  $row=Db::one($db,'SELECT s.campaign_id,s.quote_id,s.amount_cents,
   c.external_user_id,p.amount_cents AS paid_amount
   FROM advertising_budget_scopes s
   JOIN payments p ON p.id=s.payment_id AND p.application_id=s.application_id
   JOIN customers c ON c.id=s.customer_id AND c.id=p.customer_id
   WHERE s.payment_id=?',[$payment]);
  if(!$row)return null;
  if((int)$row['amount_cents']!==(int)$row['paid_amount'])
   throw new \RuntimeException('Advertising payment scope mismatch');
  return ['campaign_id'=>(string)$row['campaign_id'],
   'quote_id'=>(string)$row['quote_id'],
   'external_user_id'=>(string)$row['external_user_id']];
 }
}
