<?php
declare(strict_types=1);
namespace Iluma\Gateway;
final class PaymentState {
 // No confirmed captured amount + matching successful operation = no paid status.
 // For implicit capture, some XPay Greece sandbox orders report a confirmed
 // AUTHORIZATION/EXECUTED while orderStatus.capturedAmount is fully captured.
 public static function fromNexi(array $result,int $expectedCents,string $expectedOrder): array {
  $s=$result['orderStatus']??[];
  if(!is_array($s) || !isset($s['order']['orderId'],$s['order']['amount'],$s['order']['currency']) || $expectedCents<1) return ['unknown',null];
  if((string)$s['order']['orderId']!==$expectedOrder || (string)$s['order']['amount']!==(string)$expectedCents || $s['order']['currency']!=='EUR') return ['unknown',null];
  $rawCaptured=$s['capturedAmount']??'0';
  if(!is_string($rawCaptured) && !is_int($rawCaptured))return ['unknown',null];
  if(!ctype_digit((string)$rawCaptured))return ['unknown',null];
  $captured=(int)$rawCaptured;
  $operations=$result['operations']??[];
  if(!is_array($operations))return ['unknown',null];
  $lastOperation=(string)($s['lastOperationType']??'');
  // An order that was subsequently refunded, cancelled or voided requires
  // separate reconciliation rather than being treated as newly paid.
  if(in_array($lastOperation,['REFUND','CANCEL','VOID'],true))return ['unknown',null];
  $latest=null;$success=false;$failed=false;$canceled=false;
  foreach($operations as $op){
   if(!is_array($op)||($op['orderId']??$expectedOrder)!==$expectedOrder)continue;
   if(($op['operationCurrency']??'EUR')!=='EUR')continue;
   $kind=(string)($op['operationType']??'');$res=(string)($op['operationResult']??'');
   $opAmount=(string)($op['operationAmount']??'');
   $captureOperation=$kind==='CAPTURE' && in_array($res,['EXECUTED','AUTHORIZED'],true);
   $implicitOperation=$kind==='AUTHORIZATION' && $res==='EXECUTED'
     && $lastOperation==='AUTHORIZATION' && (string)($s['authorizedAmount']??'')===(string)$expectedCents;
   if(($captureOperation || $implicitOperation) && $opAmount===(string)$expectedCents){
    $success=true;$latest=$op['operationId']??null;
   }
   if(in_array($res,['DECLINED','DENIED','DENIED_BY_RISK','FAILED','THREEDS_FAILED'],true))$failed=true;
   if(in_array($res,['CANCELED','VOIDED'],true))$canceled=true;
  }
  if($success && $captured===$expectedCents)return ['paid',$latest];
  if($captured>0)return ['unknown',null];
  if($canceled)return ['canceled',null];
  if($failed)return ['failed',null];
  return ['unknown',null];
 }
}
