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
  return ($credential['environment']??null)==='staging'
   &&($credential['slug']??null)==='iradiosgr'
   &&(int)($credential['application_id']??0)>0
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
  foreach($found as $sku=>$row) {
   if((int)$row['amount_cents']!==$expected[$sku]||$row['currency']!=='EUR'
      ||(int)$row['enabled']!==1)
    throw new HttpError(503,'BROADCASTER_STAGING_CATALOG_MISMATCH');
  }
  return array_diff_key($expected,$found);
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
  if(self::$ready)return;
  $app=(int)$credential['application_id'];
  try {
   $db=Db::get('staging');
   if(self::ready($db,$app)){self::$ready=true;return;}
   // Cross-worker exclusive lock prevents concurrent first-request migrations.
   $lock=Db::one($db,'SELECT GET_LOCK(?,5) AS acquired',[self::LOCK]);
   if((int)($lock['acquired']??0)!==1)
    throw new HttpError(503,'BROADCASTER_STAGING_SETUP_BUSY');
   try {
    if(!self::ready($db,$app)) {
     // Existing wrong prices are never silently changed.
     $missing=self::catalog($db,$app);
     self::installTables($db);
     foreach($missing as $sku=>$amount) {
      Db::exec($db,"INSERT INTO products
       (application_id,sku,title,amount_cents,currency,enabled)
       VALUES(?,?,?,?,'EUR',1) ON DUPLICATE KEY UPDATE id=id",
       [$app,$sku,$sku,$amount]);
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
