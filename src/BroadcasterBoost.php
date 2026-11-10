<?php
declare(strict_types=1);
namespace Iluma\Gateway;

/**
 * One-off station-scoped Boost: fixed price, no card token or MIT mandate.
 * Mapping is immutable and included in every signed outcome webhook.
 */
final class BroadcasterBoost {
 public const PRICE=[
  'BOOST-T-DAY'=>490,'BOOST-T-WEEK'=>2990,'BOOST-T-2W'=>5490,
  'BOOST-T-MONTH'=>9900,'BOOST-T-YEAR'=>89000,
  'BOOST-G-DAY'=>2900,'BOOST-G-WEEK'=>17900,'BOOST-G-2W'=>34900,
  'BOOST-G-MONTH'=>79900,'BOOST-G-YEAR'=>848900
 ];
 public static function choice(array $body,string $env,string $sku,string $slug,int $price):?string {
  if(!array_key_exists($sku,self::PRICE))return null;
  if($env!=='staging'||$slug!=='iradiosgr'||Config::get('APP_ENV')==='production')
   throw new HttpError(403,'BOOST_STAGING_ONLY');
  if($price!==self::PRICE[$sku])throw new HttpError(422,'BOOST_PRICE_MISMATCH');
  $station=$body['resource_id']??null;
  if(!is_string($station)||!preg_match('/^[a-f0-9]{24}$/D',$station))
   throw new HttpError(422,'BOOST_STATION_REQUIRED');
  if(($body['auto_renew']??false)!==false||
     array_key_exists('recurring_consent_version',$body)||
     !empty($body['save_card'])||!empty($body['use_saved_card']))
   throw new HttpError(422,'BOOST_RECURRING_NOT_ALLOWED');
  return $station;
 }
 public static function preflight(\PDO $db):void {
  $r=Db::one($db,"SELECT COUNT(*) AS n FROM information_schema.tables
   WHERE table_schema=DATABASE() AND table_name='broadcaster_boost_scopes'");
  if((int)($r['n']??0)!==1)throw new HttpError(422,'BOOST_MIGRATION_REQUIRED');
 }
 public static function record(\PDO $db,int $app,string $customer,string $payment,
  string $sku,string $station,int $price):void {
  Db::exec($db,"INSERT INTO broadcaster_boost_scopes
   (payment_id,application_id,customer_id,sku,resource_id,amount_cents)
   VALUES(?,?,?,?,?,?)",[$payment,$app,$customer,$sku,$station,$price]);
 }
 public static function eventScope(\PDO $db,string $paymentId):?array {
  $r=Db::one($db,"SELECT COUNT(*) AS n FROM information_schema.tables
   WHERE table_schema=DATABASE() AND table_name='broadcaster_boost_scopes'");
  if((int)($r['n']??0)!==1)return null;
  $scope=Db::one($db,"SELECT sku,resource_id FROM broadcaster_boost_scopes
    WHERE payment_id=? FOR UPDATE",[$paymentId]);
  return $scope?['sku'=>$scope['sku'],'resource_id'=>$scope['resource_id']]:null;
 }
}
