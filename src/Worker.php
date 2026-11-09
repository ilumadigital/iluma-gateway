<?php
declare(strict_types=1);
namespace Iluma\Gateway;
use PDO;
final class Worker {
 public static function reconcile(string $env,int $limit=30):void{
  $db=Db::get($env);
  $orders=Db::all($db,"SELECT order_id FROM payments WHERE status IN ('unknown','redirect','pending') AND created_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) ORDER BY created_at LIMIT ".max(1,min($limit,100)));
  foreach($orders as $row){try {Payments::reconcile($env,$row['order_id']);}catch(\Throwable $e){error_log('Reconcile error: '.$e->getMessage());}}
 }
 public static function deliver(string $env,int $limit=30):void{
  $db=Db::get($env);
  // Recover workers that crashed after claiming a delivery.
  Db::exec($db,"UPDATE outgoing_events SET status='pending' WHERE status='sending' AND next_attempt_at<=UTC_TIMESTAMP()");
  $records=Db::all($db,"SELECT * FROM outgoing_events WHERE status='pending' AND next_attempt_at<=UTC_TIMESTAMP() ORDER BY created_at LIMIT ".max(1,min($limit,100)));
  foreach($records as $event){
   // Atomic claim for multi-worker execution.
   if(!Db::exec($db,"UPDATE outgoing_events SET status='sending',next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 MINUTE) WHERE id=? AND status='pending'",[$event['id']]))continue;
   $code=null;try {
    $credential=Db::one(Db::get('registry'),"SELECT secret_box FROM application_credentials WHERE application_id=? AND environment=? AND enabled=1 ORDER BY created_at DESC LIMIT 1",[$event['application_id'],$env]);
    if(!$credential)throw new \RuntimeException('No outbound credential');
    $secret=Security::decrypt($credential['secret_box']);$body=(string)$event['body_json'];$time=(string)time();$nonce=Security::id();
    $path=(string)parse_url(Config::webhook($env),PHP_URL_PATH);
    $sig=Security::signature($secret,$time,$nonce,'POST',$path,$body);
    if(!function_exists('curl_init'))throw new \RuntimeException('cURL missing');
    $ch=curl_init(Config::webhook($env));
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,
     CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Iluma-Timestamp: '.$time,'X-Iluma-Nonce: '.$nonce,'X-Iluma-Signature: '.$sig,'X-Iluma-Event-Id: '.$event['id']],
     CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
     CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>0]);
    $result=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($result===false || $code<200 || $code>=300) throw new \RuntimeException('Webhook delivery HTTP '.$code);
    $status='delivered';$note='acknowledged';
   }catch(\Throwable $e){$status='pending';$note='retry_required';$code=$code??null;error_log('Webhook delivery failed: '.$e->getMessage());}
   $attempt=(int)$event['attempts']+1;
   Db::exec($db,"UPDATE outgoing_events SET status=?,attempts=?,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND) WHERE id=?",[$status,$attempt,min(86400,60*(2**min($attempt,10))),$event['id']]);
   Db::exec($db,'INSERT INTO event_deliveries(event_id,http_status,result) VALUES(?,?,?)',[$event['id'],$code??null,$note]);
  }
 }
}