<?php
declare(strict_types=1);
namespace Iluma\Gateway;

/** Signed deployment preflight: no customer records, provider calls or business writes. */
final class ProductionBillingReadiness {
 public static function manifest():array {
  return json_decode((string)file_get_contents(dirname(__DIR__).'/database/production-billing.json'),true,512,JSON_THROW_ON_ERROR);
 }
 private static function type(string $value):string {
  return (string)preg_replace('/\b(tinyint|smallint|int|bigint)\(\d+\)/','$1',strtolower($value));
 }
 public static function inspect(array $credential):array {
  if(($credential['environment']??'')!=='production'||($credential['slug']??'')!=='iradiosgr'||
     (int)($credential['application_id']??0)<1)throw new HttpError(403,'PRODUCTION_READINESS_SCOPE_INVALID');
  $checks=['production_route'=>Config::stageProductionAllowed()];
  try{$checks['stage_webhook']=Config::stagePaymentWebhook()==='https://stage.iradios.gr/api/gateway/webhook';}
  catch(\RuntimeException){$checks['stage_webhook']=false;}
  try{Config::base('production');$checks['provider_endpoint']=true;}
  catch(\RuntimeException){$checks['provider_endpoint']=false;}
  $manifest=self::manifest();$db=Db::get('production');
  $checks['production_database']=Db::one($db,'SELECT DATABASE() AS name')['name']==='iluma_production';
  $actual=[];
  foreach(Db::all($db,'SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE
   FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()') as $row){
   $actual[$row['TABLE_NAME']][$row['COLUMN_NAME']]=[
    'type'=>self::type($row['COLUMN_TYPE']),'nullable'=>$row['IS_NULLABLE']==='YES'];
  }
  $schema=$manifest['tables'];
  $schema['payments']=['columns'=>$manifest['routing']];
  foreach($schema as $table=>$spec){
   $ok=true;
   foreach($spec['columns'] as $name=>$expected){
    $expected['type']=self::type($expected['type']);
    if(($actual[$table][$name]??null)!==$expected)$ok=false;
   }
   $checks['schema:'.$table]=$ok;
  }
  $catalog=[];
  foreach(Db::all($db,'SELECT sku,amount_cents,currency,enabled FROM products WHERE application_id=?',
    [(int)$credential['application_id']]) as $row)$catalog[$row['sku']]=$row;
  foreach($manifest['catalog'] as $sku=>$amount){
   $row=$catalog[$sku]??null;
   $checks['catalog:'.$sku]=$row!==null&&(int)$row['amount_cents']===$amount&&
    $row['currency']==='EUR'&&(int)$row['enabled']===1;
  }
  // The VPS migration provisions a schema-scoped user. Do not claim SELECT-only
  // readiness is enough for a ledger; no probe INSERT or rollback is needed.
  $privileges=array_column(Db::all($db,"SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES
   WHERE TABLE_SCHEMA=DATABASE() AND GRANTEE=CONCAT(CHAR(39),
    SUBSTRING_INDEX(CURRENT_USER(),'@',1),CHAR(39),'@',CHAR(39),
    SUBSTRING_INDEX(CURRENT_USER(),'@',-1),CHAR(39))"),'PRIVILEGE_TYPE');
  $checks['runtime_dml']=array_diff(['SELECT','INSERT','UPDATE','DELETE'],$privileges)===[];
  $provider=Db::one(Db::get('registry'),"SELECT secret_box FROM nexi_credentials WHERE environment='production'");
  $checks['provider_credential']=false;
  try{$checks['provider_credential']=$provider!==false&&Security::decrypt($provider['secret_box'])!=='';}
  catch(\Throwable){/* Only readiness is returned, never ciphertext or plaintext. */}
  return ['environment'=>'production','ready'=>!in_array(false,$checks,true),'checks'=>$checks,
   'automatic_charges_enabled'=>[
    'listener'=>Config::stageProductionAllowed()&&Config::get('MIT_PRODUCTION_EXECUTION_ENABLED')==='1'&&Config::get('MIT_PRODUCTION_UAT_APPROVED')==='1',
    'broadcaster'=>Config::stageProductionAllowed()&&Config::get('BROADCASTER_MIT_PRODUCTION_EXECUTION_ENABLED')==='1'&&Config::get('BROADCASTER_MIT_PRODUCTION_UAT_APPROVED')==='1'
   ]];
 }
 public static function assertReady(array $credential):void {
  if(!self::inspect($credential)['ready'])throw new HttpError(503,'BILLING_PRODUCTION_NOT_READY');
 }
}
