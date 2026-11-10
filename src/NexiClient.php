<?php
declare(strict_types=1);
namespace Iluma\Gateway;
final class NexiClient {
 private string $base;
 public function __construct(private readonly string $environment,private readonly string $apiKey) { $this->base=Config::base($environment); if($apiKey==='') throw new \RuntimeException('Nexi credentials absent'); }
 public function request(string $method,string $route,?array $body=null): array {
  if(!str_starts_with($route,'/api/v1/') || !preg_match('#^/api/v1/[a-z0-9/{}._-]+$#i',$route)) throw new \LogicException('Invalid Nexi route');
  if($this->environment==='production' && !Config::liveAllowed() && $method!=='GET') throw new \RuntimeException('Live write operations disabled');
  if(!function_exists('curl_init')) throw new \RuntimeException('ext-curl required');
  $ch=curl_init($this->base.$route);
  $correlation=self::uuid();
  $headers=['X-API-KEY: '.$this->apiKey,'Correlation-Id: '.$correlation,'Accept: application/json'];
  if($body!==null) $headers[]='Content-Type: application/json';
  $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,
   CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
   CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>0,CURLOPT_USERAGENT=>'ILUMA-Gateway/0.1'];
  if($body!==null)$options[CURLOPT_POSTFIELDS]=Security::json($body);
  curl_setopt_array($ch,$options);
  $response=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
  if($response===false) throw new \RuntimeException('Nexi transport uncertainty: '.$err);
  if($code<200||$code>=300) {
   $summary=self::errorSummary((string)$response,$this->apiKey);
   throw new \RuntimeException('Nexi status '.$code.
    ($summary!==''?' ['.$summary.']':'').
    ' (correlation '.$correlation.'; check provider state before retry)');
  }
  $decoded=json_decode((string)$response,true);
  if(!is_array($decoded)) throw new \RuntimeException('Invalid Nexi response');return $decoded;
 }
 /** Return only a short, scrubbed provider validation code and description.
  * Never log arbitrary provider response payloads, card data, API keys or tokens.
  */
 public static function errorSummary(string $response,string $apiKey):string {
  $obj=json_decode($response,true);
  if(!is_array($obj)||!isset($obj['errors'])||!is_array($obj['errors']))return '';
  $out=[];
  foreach(array_slice($obj['errors'],0,2) as $err){
   if(!is_array($err))continue;
   $code=is_string($err['code']??null)?$err['code']:'';
   if(!preg_match('/^[a-z0-9_-]{1,40}$/iD',$code))$code='';
   $description=is_string($err['description']??null)?$err['description']:'';
   if($description!==''){
    // No full URLs, query parameters, angle brackets or multi-line data in logs.
    $description=preg_replace('~https?://[^\\s]+~i','[url]',$description);
    $description=str_replace($apiKey,'[redacted]',$description);
    $description=preg_replace('/\\b(?:[0-9a-f]{32,}|ilg_[0-9a-f]{12,})\\b/i','[redacted]',$description);
    $description=preg_replace('/[^\\p{L}\\p{N} .,:;_()\\[\\]\\/-]+/u',' ',$description);
    $description=trim((string)preg_replace('/\\s+/u',' ',$description));
    $description=substr($description,0,160);
   }
   if($code!==''||$description!=='')$out[]=trim($code.' '.$description);
  }
  return implode('; ',$out);
 }
 public static function uuid(): string { $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
 public static function forEnvironment(string $env): self {
  $r=Db::one(Db::get('registry'),'SELECT secret_box FROM nexi_credentials WHERE environment=?',[$env]);
  if(!$r) throw new HttpError(503,'Nexi credentials not configured');
  return new self($env,Security::decrypt($r['secret_box']));
 }
 public function startCheckout(string $orderId,int $amount,string $customerRef,?array $recurrence=null): array {
  $session=['actionType'=>'PAY','amount'=>(string)$amount,'paymentService'=>'CARDS',
   // Required by XPay Greece POST /orders/hpp (ISO 639-2); without it the
   // provider may reject HPP creation before a card form is issued.
   'language'=>'ELL',
   'resultUrl'=>'https://'.Config::get('GATEWAY_HOST').'/checkout/result?order='.$orderId,
   'cancelUrl'=>'https://'.Config::get('GATEWAY_HOST').'/checkout/cancel?order='.$orderId,
   'notificationUrl'=>'https://'.Config::get('GATEWAY_HOST').'/nexi/'.$this->environment.'/notification'];
  // Sandbox checkout must capture on approval even if the demo terminal
  // defaults to EXPLICIT. No change to Production capture behavior.
  if($this->environment==='staging')$session['captureType']='IMPLICIT';
  if($recurrence!==null) $session['recurrence']=$recurrence;
  return $this->request('POST','/api/v1/orders/hpp',['order'=>['orderId'=>$orderId,'amount'=>(string)$amount,'currency'=>'EUR','customerId'=>$customerRef],'paymentSession'=>$session]);
 }
 public function contractsForCustomer(string $customerId):array {
  if(!preg_match('/^[A-Za-z0-9._-]{1,190}$/D',$customerId))throw new \InvalidArgumentException('Invalid customer id');
  return $this->request('GET','/api/v1/contracts/customers/'.$customerId);
 }
 public function order(string $orderId): array {if(!preg_match('/^[A-Za-z0-9_-]{1,27}$/D',$orderId)) throw new \InvalidArgumentException('Order id');return $this->request('GET','/api/v1/orders/'.$orderId);}
 public function testConnection(): bool { $this->request('GET','/api/v1/payment_methods');return true; }
}