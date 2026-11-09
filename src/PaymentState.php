<?php
declare(strict_types=1);
namespace Iluma\Gateway;
final class PaymentState {
 // Conservative: no capture == no paid status. A refund is recorded separately.
 public static function fromNexi(array $result,int $expectedCents,string $expectedOrder): array {
  $s=$result['orderStatus']??[];
  if(!is_array($s) || !isset($s['order']['orderId'],$s['order']['amount'],$s['order']['currency'])) return ['unknown',null];
  if((string)$s['order']['orderId']!==$expectedOrder || (string)$s['order']['amount']!==(string)$expectedCents || $s['order']['currency']!=='EUR') return ['unknown',null];
  $captured=(int)($s['capturedAmount']??0);
  $operations=$result['operations']??[];
  if(!is_array($operations))return ['unknown',null];
  $latest=null;$success=false;$failed=false;$canceled=false;
  foreach($operations as $op){
   if(!is_array($op)||($op['orderId']??$expectedOrder)!==$expectedOrder)continue;
   if(($op['operationCurrency']??'EUR')!=='EUR')continue;
   $kind=(string)($op['operationType']??'');$res=(string)($op['operationResult']??'');
   if($kind==='CAPTURE' && in_array($res,['EXECUTED','AUTHORIZED'],true) && (int)($op['operationAmount']??0)===$expectedCents){$success=true;$latest=$op['operationId']??null;}
   if(in_array($res,['DECLINED','DENIED','DENIED_BY_RISK','FAILED','THREEDS_FAILED'],true))$failed=true;
   if(in_array($res,['CANCELED','VOIDED'],true))$canceled=true;
  }
  if($success && $captured>=$expectedCents)return ['paid',$latest];
  if($captured>0)return ['unknown',null];
  if($canceled)return ['canceled',null];
  if($failed)return ['failed',null];
  return ['unknown',null];
 }
}