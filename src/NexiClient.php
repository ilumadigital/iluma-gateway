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
  if(trim((string)$response)==='' && $method==='POST' &&
     str_ends_with($route,'/deactivation'))return ['deactivated'=>true];
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
 /**
  * iRadios customer references (e.g. "iradios-stage-" + Mongo ObjectId)
  * exceed XPay's 27-character order.customerId limit. Keep the exact
  * reference in the Gateway customer ledger; send only a stable, opaque,
  * 26-character merchant identifier to the provider. Hex + letters is
  * accepted by XPay and avoids embedding account IDs or emails in HPP.
  */
 public static function providerCustomerId(string $customerRef): string {
  if($customerRef==='' || strlen($customerRef)>190)throw new \InvalidArgumentException('Invalid customer reference');
  return 'IR'.strtoupper(substr(hash('sha256',$customerRef),0,24));
 }
 public static function oneClickRecurrence(string $action,string $contractId):array {
  if(!in_array($action,['CONTRACT_CREATION','SUBSEQUENT_PAYMENT'],true) ||
     !preg_match('/^ILC(?:[A-F0-9]{15}|[A-F0-9]{24})$/D',$contractId))
   throw new \InvalidArgumentException('Invalid OneClick contract');
  return ['action'=>$action,'contractId'=>$contractId,'contractType'=>'CIT'];
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
  return $this->request('POST','/api/v1/orders/hpp',['order'=>['orderId'=>$orderId,'amount'=>(string)$amount,'currency'=>'EUR','customerId'=>self::providerCustomerId($customerRef)],'paymentSession'=>$session]);
 }
 public function deactivateContract(string $contractId):array {
  if(!preg_match('/^IL[CM](?:[A-F0-9]{15}|[A-F0-9]{24})$/D',$contractId))
   throw new \InvalidArgumentException('Invalid provider contract');
  return $this->request('POST','/api/v1/contracts/'.$contractId.'/deactivation');
 }
 /**
  * One irreversible provider attempt per Gateway cycle. The caller must
  * first commit the unique payment + recurring_cycle ledger to MySQL.
  * Never retry POST after a timeout: reconcile GET /orders/{orderId} instead.
  */
 public function startMit(string $orderId,string $providerCustomerId,
   string $contractId,int $amountCents):array {
  if($this->environment!=='staging'||Config::get('MIT_SANDBOX_EXECUTION_ENABLED')!=='1'||
     Config::get('MIT_SANDBOX_UAT_APPROVED')!=='1')
   throw new \RuntimeException('MIT sandbox execution is locked');
  if(!preg_match('/^IL[A-F0-9]{24}$/D',$orderId)||
     !preg_match('/^ILM(?:[A-F0-9]{15}|[A-F0-9]{24})$/D',$contractId)||
     !preg_match('/^[A-Z0-9]{1,27}$/D',$providerCustomerId)||
     $amountCents!==399)throw new \InvalidArgumentException('Invalid MIT parameters');
  return $this->request('POST','/api/v1/orders/mit',[
    'captureType'=>'IMPLICIT',
    'contractId'=>$contractId,
    'order'=>[
      'orderId'=>$orderId,'customerId'=>$providerCustomerId,
      'amount'=>(string)$amountCents,'currency'=>'EUR'
    ]
  ]);
 }
 /**
  * Scoped broadcaster MIT, isolated from Listener Premium's 399-cent guard.
  * Both staging approval flags must be explicitly set after UAT.
  */
 public function startBroadcasterMit(string $orderId,string $customerId,
   string $contractId,int $amountCents):array {
  if($this->environment!=='staging'||
    Config::get('APP_ENV')==='production'||
    Config::get('BROADCASTER_MIT_SANDBOX_EXECUTION_ENABLED')!=='1'||
    Config::get('BROADCASTER_MIT_SANDBOX_UAT_APPROVED')!=='1')
   throw new \RuntimeException('Broadcaster MIT execution locked');
  if(!preg_match('/^IL[A-F0-9]{24}$/D',$orderId)||
     !preg_match('/^ILM(?:[A-F0-9]{15}|[A-F0-9]{24})$/D',$contractId)||
     !preg_match('/^[A-Z0-9]{1,27}$/D',$customerId)||
     !in_array($amountCents,array_values(BroadcasterMandates::PRICE),true))
   throw new \InvalidArgumentException('Invalid broadcaster MIT parameters');
  return $this->request('POST','/api/v1/orders/mit',[
   'captureType'=>'IMPLICIT','contractId'=>$contractId,
   'order'=>['orderId'=>$orderId,'customerId'=>$customerId,
     'amount'=>(string)$amountCents,'currency'=>'EUR']
  ]);
 }
 public function contractsForCustomer(string $customerId):array {
  if(!preg_match('/^[A-Za-z0-9._-]{1,190}$/D',$customerId))throw new \InvalidArgumentException('Invalid customer id');
  return $this->request('GET','/api/v1/contracts/customers/'.$customerId);
 }
 public function order(string $orderId): array {if(!preg_match('/^[A-Za-z0-9_-]{1,27}$/D',$orderId)) throw new \InvalidArgumentException('Order id');return $this->request('GET','/api/v1/orders/'.$orderId);}
 public function testConnection(): bool { $this->request('GET','/api/v1/payment_methods');return true; }
}