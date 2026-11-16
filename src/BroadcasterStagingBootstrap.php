<?php
declare(strict_types=1);
namespace Iluma\Gateway;

use PDO;

/**
 * Hostinger deploys main under APP_ENV=development. A signed iRadios staging
 * request may prepare ONLY the staging tenant; no manual SSH migration needed.
 * This class never invokes Nexi, creates payment attempts or enables MIT.
 */
final class BroadcasterStagingBootstrap {
 private const LOCK='iluma_iradios_broadcaster_staging_schema_v1';
 private const TABLES=['broadcaster_mandates','broadcaster_cycles','broadcaster_boost_scopes'];
 private static bool $ready=false;

 public static function permitted(array $credential):bool {
  if(($credential['slug']??null)!=='iradiosgr'||(int)($credential['application_id']??0)<=0)return false;
  if(($credential['environment']??null)==='production')
   return Config::stageProductionAllowed();
  return ($credential['environment']??null)==='staging'
   &&in_array(Config::get('APP_ENV'),['development','staging'],true)
   &&Config::get('PRODUCTION_ENABLED')!=='1';
 }

 private static function isolated():bool {
  $staging=Config::get('STAGING_DSN');
  $production=Config::get('PRODUCTION_DSN');
  if($staging===''||$production===''||hash_equals($staging,$production))return false;
  if(!preg_match('/(?:^|;)dbname=([^;]+)/i',$staging,$a)
    ||!preg_match('/(?:^|;)dbname=([^;]+)/i',$production,$b))return false;
  // Fail closed even when different hosts carry the same database name.
  return $a[1]!==''&&$b[1]!==''&&strcasecmp($a[1],$b[1])!==0;
 }

 private static function catalog(PDO $db,int $app):array {
  $expected=BroadcasterMandates::PRICE+BroadcasterBoost::PRICE;
  $placeholders=implode(',',array_fill(0,count($expected),'?'));
  $rows=Db::all($db,"SELECT sku,amount_cents,currency,enabled FROM products
   WHERE application_id=? AND sku IN ($placeholders)",array_merge([$app],array_keys($expected)));
  $found=[];
  foreach($rows as $row)$found[(string)$row['sku']]=$row;
  $changes=[];
  foreach($expected as $sku=>$price) {
   $row=$found[$sku]??null;
   if(!$row){$changes[$sku]=['from'=>null,'to'=>$price];continue;}
   if($row['currency']!=='EUR'||(int)$row['enabled']!==1)
    throw new HttpError(503,'BROADCASTER_STAGING_CATALOG_MISMATCH');
   $old=(int)$row['amount_cents'];
   if($old===$price)continue;
   // Only the four explicitly approved 2026-11-15 broadcaster transitions.
   $legacy=BroadcasterMandates::LEGACY_PRICE[$sku]??null;
   if($legacy===null||$old!==$legacy)
    throw new HttpError(503,'BROADCASTER_STAGING_CATALOG_MISMATCH');
   $changes[$sku]=['from'=>$old,'to'=>$price];
  }
  return $changes;
 }
 private static function ready(PDO $db,int $app):bool {
  $tables=Db::one($db,"SELECT COUNT(*) AS n FROM information_schema.tables
   WHERE table_schema=DATABASE() AND table_name IN
    ('broadcaster_mandates','broadcaster_cycles','broadcaster_boost_scopes')");
  return (int)($tables['n']??0)===3&&self::catalog($db,$app)===[];
 }

 private static function installTables(PDO $db):void {
  $file=dirname(__DIR__).'/database/migrations/20261111_broadcaster_recurring.sql';
  $sql=file_get_contents($file);
  if($sql===false)throw new \RuntimeException('Broadcaster migration file unavailable');
  $sql=preg_replace('/^\s*--[^\r\n]*$/m','',$sql);
  if($sql===null)throw new \RuntimeException('Broadcaster migration parse failed');
  $statements=array_values(array_filter(array_map('trim',explode(';',$sql))));
  if(count($statements)!==count(self::TABLES))
   throw new \RuntimeException('Unexpected broadcaster schema statements');
  foreach($statements as $i=>$statement) {
   if(!preg_match('/^CREATE TABLE IF NOT EXISTS\s+([a-z_]+)\s*\(/i',$statement,$match)
     ||$match[1]!==self::TABLES[$i])
    throw new \RuntimeException('Unexpected broadcaster migration statement');
   $db->exec($statement);
  }
 }

 public static function ensure(array $credential):void {
  if(!self::permitted($credential))throw new HttpError(403,'BROADCASTER_STAGING_ONLY');
  if(!self::isolated())throw new HttpError(503,'BROADCASTER_STAGING_DATABASE_NOT_ISOLATED');
  $app=(int)$credential['application_id'];
  if($credential['environment']==='production'){
   // Live product catalog and tables must be manually audited first.
   if(!self::ready(Db::get('production'),$app))
    throw new HttpError(503,'BROADCASTER_PRODUCTION_SCHEMA_OR_CATALOG_NOT_READY');
   return;
  }
  if(self::$ready)return;
  try {
   $db=Db::get('staging');
   if(self::ready($db,$app)){self::$ready=true;return;}
   // Cross-worker exclusive lock prevents concurrent first-request migrations.
   $lock=Db::one($db,'SELECT GET_LOCK(?,5) AS acquired',[self::LOCK]);
   if((int)($lock['acquired']??0)!==1)
    throw new HttpError(503,'BROADCASTER_STAGING_SETUP_BUSY');
   try {
    if(!self::ready($db,$app)) {
     // Approved staging-only migration, guarded by old price and tenant.
     // Existing mandates and paid transactions retain their signed amounts.
     $missing=self::catalog($db,$app);
     self::installTables($db);
     foreach($missing as $sku=>$change) {
      if($change['from']!==null) {
       $updated=Db::exec($db,"UPDATE products SET amount_cents=?
        WHERE application_id=? AND sku=? AND amount_cents=?
         AND currency='EUR' AND enabled=1",
         [$change['to'],$app,$sku,$change['from']]);
       if($updated!==1)
        throw new HttpError(503,'BROADCASTER_STAGING_CATALOG_MISMATCH');
      }else{
       Db::exec($db,"INSERT INTO products
        (application_id,sku,title,amount_cents,currency,enabled)
        VALUES(?,?,?,?,'EUR',1) ON DUPLICATE KEY UPDATE id=id",
        [$app,$sku,$sku,$change['to']]);
      }
     }
    }
    if(!self::ready($db,$app))
     throw new HttpError(503,'BROADCASTER_STAGING_SETUP_INCOMPLETE');
    self::$ready=true;
   } finally {
    try{Db::one($db,'SELECT RELEASE_LOCK(?) AS released',[self::LOCK]);}
    catch(\Throwable $e){error_log('Broadcaster staging setup lock release deferred');}
   }
  } catch(\Throwable $e) {
   if($e instanceof HttpError)throw $e;
   error_log('Broadcaster staging setup failed: '.get_class($e));
   throw new HttpError(503,'BROADCASTER_STAGING_SETUP_UNAVAILABLE');
  }
 }
}
