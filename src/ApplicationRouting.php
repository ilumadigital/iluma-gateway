<?php
declare(strict_types=1);
namespace Iluma\Gateway;

use PDO;

/**
 * Routing for newly connected applications. Uses the existing environment-
 * isolated settings table; iRadios legacy routes stay unchanged.
 * All endpoints are administrator-approved, HTTPS, same-origin and audited.
 */
final class ApplicationRouting {
 private static function key(int $applicationId):string {
  if($applicationId<1)throw new HttpError(422,'Invalid application');
  return 'iluma.application.route.'.$applicationId;
 }
 public static function read(string $env,int $applicationId):array {
  $row=Db::one(Db::get($env),'SELECT setting_value FROM settings WHERE setting_key=?',[self::key($applicationId)]);
  if(!$row)return ['enabled'=>false,'webhook_url'=>'','return_path'=>''];
  $json=json_decode((string)$row['setting_value'],true);
  if(!is_array($json))throw new \RuntimeException('Invalid application routing record');
  return [
   'enabled'=>($json['enabled']??false)===true,
   'webhook_url'=>is_string($json['webhook_url']??null)?$json['webhook_url']:'',
   'return_path'=>is_string($json['return_path']??null)?$json['return_path']:''
  ];
 }
 private static function app(PDO $registry,int $applicationId):array {
  $app=Db::one($registry,'SELECT id,slug,enabled,staging_origin,production_origin FROM applications WHERE id=?',[$applicationId]);
  if(!$app)throw new HttpError(404,'Application not found');
  return $app;
 }
 private static function validReturn(string $path):string {
  if(!preg_match('#^/[a-zA-Z0-9/_-]{1,120}$#D',$path)||str_contains($path,'//'))
   throw new HttpError(422,'Το return path πρέπει να ξεκινά με / χωρίς query ή traversal.');
  return $path;
 }
 private static function validWebhook(string $url,string $origin):string {
  if(strlen($url)>255||str_contains($url,'#')||str_contains($url,'?')||
     str_contains($url,'@')||str_contains($url,'%'))
   throw new HttpError(422,'Μη έγκυρο Webhook URL.');
  $parts=parse_url($url);
  if(!is_array($parts) || ($parts['scheme']??'')!=='https' ||
    isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])||
    empty($parts['host'])||!preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/Di',$parts['host']))
   throw new HttpError(422,'Το Webhook URL πρέπει να είναι HTTPS στο domain της εφαρμογής.');
  $normalized='https://'.strtolower($parts['host']);
  if(!hash_equals(strtolower(rtrim($origin,'/')),$normalized))
   throw new HttpError(422,'Το webhook πρέπει να ανήκει στο αντίστοιχο Stage/Live domain.');
  $path=$parts['path']??'';
  if(!preg_match('#^/[a-zA-Z0-9/_-]{1,180}$#D',$path)||str_contains($path,'//'))
   throw new HttpError(422,'Χρησιμοποίησε συγκεκριμένο ασφαλές webhook path.');
  return $normalized.$path;
 }
 public static function save(PDO $registry,string $env,int $appId,array $post):string {
  if(!in_array($env,['staging','production'],true))throw new HttpError(422,'Invalid environment');
  $app=self::app($registry,$appId);
  if(in_array($app['slug'],['iradiosgr','iluma-internal'],true))
   throw new HttpError(403,'Η υπάρχουσα διαδρομή αυτής της εφαρμογής είναι προστατευμένη.');
  $enabled=isset($post['route_enabled']);
  $url=trim((string)($post['webhook_url']??''));
  $return=trim((string)($post['return_path']??''));
  $origin=(string)$app[$env==='staging'?'staging_origin':'production_origin'];
  $url=$url!==''?self::validWebhook($url,$origin):'';
  $return=$return!==''?self::validReturn($return):'';
  if($enabled&&($url===''||$return===''))
   throw new HttpError(422,'Απαιτείται Webhook URL και Return Path για ενεργοποίηση.');
  if($enabled){
   $active=Db::one($registry,"SELECT COUNT(*) AS n FROM application_credentials
      WHERE application_id=? AND environment=? AND enabled=1",[$appId,$env]);
   if((int)($active['n']??0)<1)
    throw new HttpError(422,'Δημιούργησε πρώτα ένα ενεργό API Key για το περιβάλλον.');
   // Validate and pin only publicly routable callback DNS.
   self::publicDnsPin($url);
  }
  $value=Security::json(['enabled'=>$enabled,'webhook_url'=>$url,'return_path'=>$return]);
  Db::exec(Db::get($env),'INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',
    [self::key($appId),$value]);
  return $enabled?'Η δρομολόγηση ενεργοποιήθηκε μόνο στο '.strtoupper($env).'.':'Η δημιουργία νέων πληρωμών απενεργοποιήθηκε στο '.strtoupper($env).'.';
 }
 public static function assertCheckout(array $credential):void {
  $slug=(string)($credential['slug']??'');
  if($slug==='iluma-internal')throw new HttpError(403,'Retired application checkout is disabled');
   if($slug==='iradiosgr')return;
  $env=(string)($credential['environment']??'');
  $id=(int)($credential['application_id']??0);
  if($env==='production'){
   // The worker uses these columns to deliver every production payment.
   // Reject new checkouts before Nexi POST if production migration is absent.
   $columns=Db::one(Db::get('production'),"SELECT COUNT(*) AS n FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='payments'
      AND column_name IN ('iradios_stage_routed','origin_credential_id')");
   if((int)($columns['n']??0)!==2)
    throw new HttpError(503,'PRODUCTION_PAYMENT_ROUTE_SCHEMA_NOT_READY');
  }
  $route=self::read($env,$id);
  if(!$route['enabled']||$route['webhook_url']===''||$route['return_path']==='')
   throw new HttpError(503,'APPLICATION_PAYMENT_ROUTING_NOT_CONFIGURED');
  $app=self::app(Db::get('registry'),$id);
  $origin=(string)$app[$env==='staging'?'staging_origin':'production_origin'];
  self::validWebhook($route['webhook_url'],$origin);
  self::validReturn($route['return_path']);
 }
 public static function webhook(string $env,int $appId):string {
  $route=self::read($env,$appId);
  $app=self::app(Db::get('registry'),$appId);
  $origin=(string)$app[$env==='staging'?'staging_origin':'production_origin'];
  if($route['webhook_url']==='')throw new \RuntimeException('No webhook route');
  return self::validWebhook($route['webhook_url'],$origin);
 }
 public static function returnUrl(string $env,int $appId,string $orderId,string $kind):string {
  $route=self::read($env,$appId);
  $app=self::app(Db::get('registry'),$appId);
  $origin=(string)$app[$env==='staging'?'staging_origin':'production_origin'];
  if($route['return_path']==='')throw new \RuntimeException('No return route');
  $path=self::validReturn($route['return_path']);
  return rtrim($origin,'/').$path.'?gateway_return='.$kind.'&order='.$orderId;
 }
 /**
  * Pin a public DNS address before cURL: no local/private or link-local hops.
  * The calling code must also disallow redirects and verify TLS/SNI.
  */
 public static function publicDnsPin(string $url):array {
  $host=(string)parse_url($url,PHP_URL_HOST);
  if($host===''||!function_exists('dns_get_record'))throw new \RuntimeException('DNS unavailable');
  $dns=dns_get_record($host,DNS_A|DNS_AAAA);
  if(!is_array($dns))throw new \RuntimeException('Webhook DNS lookup failed');
  foreach($dns as $record){
   $ip=(string)($record['ip']??$record['ipv6']??'');
   if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))continue;
   return [$host.':443:'.(str_contains($ip,':')?'['.$ip.']':$ip)];
  }
  throw new \RuntimeException('Webhook has no public DNS address');
 }
}
