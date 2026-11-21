<?php
declare(strict_types=1);
// Read-only post-deployment check for the shared Gateway runtime.
// No DB writes, no external provider requests, no customer data/secrets.
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/src/bootstrap.php';

use Iluma\Gateway\{CheckoutReturn,Config};
$order='IL'.str_repeat('A',24);
$checks=[];
$check=static function(string $name,bool $ok) use (&$checks):void {
 $checks[$name]=$ok;
 echo $name.': '.($ok?'PASS':'FAIL').PHP_EOL;
};
$check('GATEWAY_SHARED_RUNTIME',Config::liveAllowed());
$check('STAGING_TENANT_RUNTIME',Config::stagingTenantRuntimeAllowed());
$check('PRODUCTION_DIRECT_PLAY',Config::playProductionAllowed());
$check('LISTENER_MIT_NOT_AUTO_AUTHORIZED',!Config::productionMitExecutionAllowed());
$check('LEGACY_WEBHOOK_FORWARDING_DISABLED',!Config::legacyStageDeliveryToPlay());
$check('STAGING_RETURN',CheckoutReturn::url('staging','/checkout/cancel',$order,'B-PREM')
 ==='https://stage.iradios.gr/backoffice/pro-features?gateway_return=cancel&order='.$order);
$check('PRODUCTION_RETURN',CheckoutReturn::url('production','/checkout/cancel',$order,'B-PREM',true)
 ==='https://play.iradios.gr/backoffice/pro-features?gateway_return=cancel&order='.$order);
$check('PRODUCTION_ADVERTISING_RETURN',CheckoutReturn::url('production','/checkout/result',$order,'ADV-BUDGET',true)
 ==='https://play.iradios.gr/backoffice/agency/campaigns?gateway_return=result&order='.$order);
try{
 $check('STAGING_NEXI_SANDBOX',Config::base('staging')===
  'https://xpaysandbox.nexigroup.com/api/phoenix-0.0/psp');
}catch(Throwable){$check('STAGING_NEXI_SANDBOX',false);}
try{
 $check('PRODUCTION_NEXI_LIVE',Config::base('production')===
  'https://xpay.nexigroup.com/api/phoenix-0.0/psp');
}catch(Throwable){$check('PRODUCTION_NEXI_LIVE',false);}
try{
 $check('STAGING_WEBHOOK',Config::webhook('staging')===
  'https://stage.iradios.gr/api/gateway/webhook');
}catch(Throwable){$check('STAGING_WEBHOOK',false);}
try{
 $check('PRODUCTION_PLAY_WEBHOOK',Config::playProductionWebhook()===
  'https://play.iradios.gr/api/gateway/webhook');
}catch(Throwable){$check('PRODUCTION_PLAY_WEBHOOK',false);}
$ok=!in_array(false,$checks,true);
echo 'GATEWAY_RETURN_PREFLIGHT: '.($ok?'PASS':'FAIL').PHP_EOL;
echo "FINANCIAL_WRITES: 0; NEXI_REQUESTS: 0\n";
exit($ok?0:1);
