<?php
declare(strict_types=1);
namespace Iluma\Gateway;

/**
 * Browser landing page after Nexi. Never accepts a caller-controlled URL or
 * payment status; the iRadios backend verifies via signed Gateway events.
 */
final class CheckoutReturn {
 public static function url(string $environment,string $path,string $orderId,?string $sku=null,bool $stageRouted=false,string $applicationSlug='iradiosgr',int $applicationId=0):string {
  if(!in_array($environment,['staging','production'],true) ||
     !in_array($path,['/checkout/result','/checkout/cancel'],true) ||
     !preg_match('/^IL[A-F0-9]{24}$/D',$orderId)){
   throw new \InvalidArgumentException('Invalid checkout return');
  }
  // Historical retired operator payments must never be redirected to iRadios.
  // This is only a legacy browser-return destination; no test checkout remains.
  if($applicationSlug==='iluma-internal')
   return 'https://gateway.iluma.gr/ilm-admin/?p=payments';
  if($applicationSlug!=='iradiosgr')
   return ApplicationRouting::returnUrl($environment,$applicationId,$orderId,$path==='/checkout/cancel'?'cancel':'result');
  // A browser return is NOT a financial webhook. Only the authenticated
  // Gateway tenant that owns the order decides the iRadios destination.
  // Historical stage-routed production orders still belong to Nexi Live and
  // must return to play; retain $stageRouted for call-site compatibility.
  $origin=$environment==='staging'?'https://stage.iradios.gr':'https://play.iradios.gr';
  $kind=$path==='/checkout/cancel'?'cancel':'result';
  // The caller looks up the product from the stored Gateway order; the
  // browser cannot choose or override the return destination.
  $route=$sku==='ADV-BUDGET'
   ?'/backoffice/agency/campaigns':
   (str_starts_with((string)$sku,'BOOST-')?'/backoffice/boost':
    (in_array($sku,['B-PREM','ADD-DSP','ADD-AN','ADD-ADS'],true)
     ?'/backoffice/pro-features':'/premium'));
  return $origin.$route.'?gateway_return='.$kind.'&order='.$orderId;
 }
}
