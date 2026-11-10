<?php
declare(strict_types=1);
namespace Iluma\Gateway;

/**
 * Browser landing page after Nexi. Never accepts a caller-controlled URL or
 * payment status; the iRadios backend verifies via signed Gateway events.
 */
final class CheckoutReturn {
 public static function url(string $environment,string $path,string $orderId):string {
  if(!in_array($environment,['staging','production'],true) ||
     !in_array($path,['/checkout/result','/checkout/cancel'],true) ||
     !preg_match('/^IL[A-F0-9]{24}$/D',$orderId)){
   throw new \InvalidArgumentException('Invalid checkout return');
  }
  $origin=$environment==='staging'?'https://stage.iradios.gr':'https://play.iradios.gr';
  $kind=$path==='/checkout/cancel'?'cancel':'result';
  return $origin.'/premium?gateway_return='.$kind.'&order='.$orderId;
 }
}
