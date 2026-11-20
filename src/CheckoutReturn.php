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
  if($applicationSlug===AdminTestCenter::SLUG)
   return 'https://gateway.iluma.gr/ilm-admin/?p=test-center&order='.$orderId;
  if($applicationSlug!=='iradiosgr')
   return ApplicationRouting::returnUrl($environment,$applicationId,$orderId,$path==='/checkout/cancel'?'cancel':'result');
  $returnToStage=$environment==='staging'||($environment==='production'&&$stageRouted&&!Config::legacyStageDeliveryToPlay());
  $origin=$returnToStage?'https://stage.iradios.gr':'https://play.iradios.gr';
  $kind=$path==='/checkout/cancel'?'cancel':'result';
  // The caller looks up the product from the stored Gateway order; the
  // browser cannot choose or override the return destination.
  $route=$returnToStage&&$sku==='ADV-BUDGET'
   ?'/backoffice/agency/campaigns':
   (str_starts_with((string)$sku,'BOOST-')?'/backoffice/boost':
    (in_array($sku,['B-PREM','ADD-DSP','ADD-AN','ADD-ADS'],true)
     ?'/backoffice/pro-features':'/premium'));
  return $origin.$route.'?gateway_return='.$kind.'&order='.$orderId;
 }
}
