<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Iluma\Gateway\{Security,PaymentState,Config,NexiClient,EnvLoader,AdminContext};
// Offline tests never inherit operator production/MIT rollout flags.
foreach(['APP_ENV'=>'staging','PRODUCTION_ENABLED'=>'0','IRADIOS_STAGE_PRODUCTION_ENABLED'=>'0',
 'MIT_PRODUCTION_EXECUTION_ENABLED'=>'0','MIT_PRODUCTION_UAT_APPROVED'=>'0',
 'BROADCASTER_MIT_PRODUCTION_EXECUTION_ENABLED'=>'0','BROADCASTER_MIT_PRODUCTION_UAT_APPROVED'=>'0'] as $name=>$value)putenv($name.'='.$value);
putenv('MASTER_KEY_B64='.base64_encode(str_repeat('K',32)));
$tests=0;
function check(bool $ok,string $name):void {global $tests;$tests++;if(!$ok){fwrite(STDERR,"FAIL $name\n");exit(1);}echo "PASS $name\n";}
// Customer references never select a tenant: authenticated credentials do.
$legacyId='iradios-stage-'.str_repeat('a',24);
$playId='iradios-play-'.str_repeat('b',24);
check(Config::iradiosCustomerAllowed('staging',$legacyId)
 &&!Config::iradiosCustomerAllowed('staging',$playId)
 &&Config::iradiosCustomerAllowed('production',$legacyId)
 &&Config::iradiosCustomerAllowed('production',$playId)
 &&!Config::iradiosCustomerAllowed('other',$legacyId),
 'Customer references cannot select another tenant');
$sandboxVars=['APP_ENV','PRODUCTION_ENABLED','MIT_SANDBOX_EXECUTION_ENABLED',
 'MIT_SANDBOX_UAT_APPROVED','BROADCASTER_MIT_SANDBOX_EXECUTION_ENABLED',
 'BROADCASTER_MIT_SANDBOX_UAT_APPROVED'];
$savedSandbox=[];
foreach($sandboxVars as $k)$savedSandbox[$k]=getenv($k);
foreach(['APP_ENV'=>'production','PRODUCTION_ENABLED'=>'1',
 'MIT_SANDBOX_EXECUTION_ENABLED'=>'1','MIT_SANDBOX_UAT_APPROVED'=>'1',
 'BROADCASTER_MIT_SANDBOX_EXECUTION_ENABLED'=>'1',
 'BROADCASTER_MIT_SANDBOX_UAT_APPROVED'=>'1'] as $k=>$v)putenv($k.'='.$v);
check(Config::stagingTenantRuntimeAllowed()
 &&\Iluma\Gateway\RecurringAdmin::executionEnabled('staging')
 &&\Iluma\Gateway\BroadcasterRecurringAdmin::executionEnabled('staging'),
 'Shared production runtime permits approved Sandbox tenant MIT');
putenv('MIT_SANDBOX_UAT_APPROVED=0');
check(!\Iluma\Gateway\RecurringAdmin::executionEnabled('staging')
 &&\Iluma\Gateway\BroadcasterRecurringAdmin::executionEnabled('staging'),
 'Listener Sandbox MIT stays independently locked');
foreach($savedSandbox as $k=>$v){if($v===false)putenv($k);else putenv($k.'='.$v);}
// The Admin view must not confuse a verified active contract with permission
// to execute a real MIT payment. These checks do not connect to a database.
$mitEnv=['APP_ENV','PRODUCTION_ENABLED','IRADIOS_STAGE_PRODUCTION_ENABLED',
 'MIT_PRODUCTION_EXECUTION_ENABLED','MIT_PRODUCTION_UAT_APPROVED',
 'BROADCASTER_MIT_PRODUCTION_EXECUTION_ENABLED','BROADCASTER_MIT_PRODUCTION_UAT_APPROVED'];
$mitBefore=[];
foreach($mitEnv as $key)$mitBefore[$key]=getenv($key);
check(!\Iluma\Gateway\RecurringAdmin::executionEnabled('production')
 &&!\Iluma\Gateway\BroadcasterRecurringAdmin::executionEnabled('production'),
 'Production MIT admin indicators remain disabled by default');
foreach(['APP_ENV'=>'production','PRODUCTION_ENABLED'=>'1','IRADIOS_STAGE_PRODUCTION_ENABLED'=>'1',
 'MIT_PRODUCTION_EXECUTION_ENABLED'=>'1','MIT_PRODUCTION_UAT_APPROVED'=>'1',
 'BROADCASTER_MIT_PRODUCTION_EXECUTION_ENABLED'=>'1',
 'BROADCASTER_MIT_PRODUCTION_UAT_APPROVED'=>'1'] as $key=>$value)putenv($key.'='.$value);
check(\Iluma\Gateway\RecurringAdmin::executionEnabled('production')
 &&\Iluma\Gateway\BroadcasterRecurringAdmin::executionEnabled('production'),
 'Production MIT admin indicators reflect independently approved execution');
putenv('MIT_PRODUCTION_UAT_APPROVED=0');
check(!\Iluma\Gateway\RecurringAdmin::executionEnabled('production')
 &&\Iluma\Gateway\BroadcasterRecurringAdmin::executionEnabled('production'),
 'Listener MIT approval does not change Broadcaster execution status');
putenv('IRADIOS_STAGE_PRODUCTION_ENABLED=0');
check(!\Iluma\Gateway\RecurringAdmin::executionEnabled('production')
 &&!\Iluma\Gateway\BroadcasterRecurringAdmin::executionEnabled('production'),
 'Production routing gate blocks both MIT execution indicators');
foreach($mitBefore as $key=>$value){
 if($value===false)putenv($key);else putenv($key.'='.$value);
}
// Direct-play renewals must NEVER inherit prior stage-route MIT approval.
// These offline checks have no Nexi/provider or database calls.
putenv('APP_ENV=production');putenv('PRODUCTION_ENABLED=1');
putenv('IRADIOS_STAGE_PRODUCTION_ENABLED=1');
putenv('IRADIOS_PRODUCTION_PLAY_ENABLED=0');
putenv('IRADIOS_PLAY_MIT_EXECUTION_APPROVED=0');
check(Config::productionMitExecutionAllowed()&&Config::productionMitRoutingValue()===1,
 'Original stage-routed MIT scope retained until direct-play cutover');
putenv('IRADIOS_PRODUCTION_PLAY_ENABLED=1');
check(Config::productionRecurringAllowed()&&!Config::productionMitExecutionAllowed()
 &&Config::productionMitRoutingValue()===0,
 'Play may accept explicit recurring consent, but old stage MIT approval cannot charge');
putenv('IRADIOS_PLAY_MIT_EXECUTION_APPROVED=1');
check(Config::productionMitExecutionAllowed()&&Config::productionMitRoutingValue()===0,
 'Independent play MIT approval selects only direct-play mandates');

 // Provider MIT methods must use the same direct-play execution gate as the
 // due-date selectors. Invalid order IDs stop before any Nexi HTTP request.
 putenv('NEXI_PRODUCTION_BASE=https://xpay.nexigroup.com/api/phoenix-0.0/psp');
 putenv('IRADIOS_STAGE_PRODUCTION_ENABLED=0');
 putenv('MIT_PRODUCTION_EXECUTION_ENABLED=1');
 putenv('MIT_PRODUCTION_UAT_APPROVED=1');
 putenv('BROADCASTER_MIT_PRODUCTION_EXECUTION_ENABLED=1');
 putenv('BROADCASTER_MIT_PRODUCTION_UAT_APPROVED=1');
 $offlineProvider=new NexiClient('production','offline-test-api-key');
 $badOrder='invalid-order';
 $providerCid='IR'.str_repeat('A',24);
 $providerContract='ILM'.str_repeat('A',15);
 try{$offlineProvider->startMit($badOrder,$providerCid,$providerContract,399);
   $listenerPassesGate=false;
 }catch(\InvalidArgumentException $e){$listenerPassesGate=true;}
  catch(\RuntimeException $e){$listenerPassesGate=false;}
 check($listenerPassesGate,'Direct-play Listener MIT reaches argument validation without provider request');
 try{$offlineProvider->startBroadcasterMit($badOrder,$providerCid,$providerContract,9900);
   $broadcasterPassesGate=false;
 }catch(\InvalidArgumentException $e){$broadcasterPassesGate=true;}
  catch(\RuntimeException $e){$broadcasterPassesGate=false;}
 check($broadcasterPassesGate,'Direct-play Broadcaster MIT reaches argument validation without provider request');
 putenv('IRADIOS_PLAY_MIT_EXECUTION_APPROVED=0');
 try{$offlineProvider->startMit($badOrder,$providerCid,$providerContract,399);
   $listenerLocked=false;
 }catch(\RuntimeException $e){$listenerLocked=true;}
 check($listenerLocked,'Listener MIT cannot inherit old stage approval when play approval is off');
 try{$offlineProvider->startBroadcasterMit($badOrder,$providerCid,$providerContract,9900);
   $broadcasterLocked=false;
 }catch(\RuntimeException $e){$broadcasterLocked=true;}
 check($broadcasterLocked,'Broadcaster MIT cannot inherit old stage approval when play approval is off');
 putenv('MIT_PRODUCTION_EXECUTION_ENABLED=0');
 putenv('MIT_PRODUCTION_UAT_APPROVED=0');
 putenv('BROADCASTER_MIT_PRODUCTION_EXECUTION_ENABLED=0');
 putenv('BROADCASTER_MIT_PRODUCTION_UAT_APPROVED=0');
 putenv('IRADIOS_STAGE_PRODUCTION_ENABLED=1');
 putenv('IRADIOS_PLAY_MIT_EXECUTION_APPROVED=1');

check(Config::productionIradiosAllowed(),
 'Direct-play checkout admits verified iRadios product scopes');
check(\Iluma\Gateway\BroadcasterStagingBootstrap::permitted([
 'environment'=>'production','slug'=>'iradiosgr','application_id'=>1]),
 'Play production broadcaster/Boost/advertising catalogue uses existing readiness gates');
check(\Iluma\Gateway\BroadcasterBoost::choice([
 'resource_id'=>str_repeat('a',24)],'production','BOOST-T-DAY','iradiosgr',490)
 ===str_repeat('a',24), 'Direct-play one-off station boost is scoped');

putenv('IRADIOS_PLAY_MIT_EXECUTION_APPROVED=0');
putenv('IRADIOS_PRODUCTION_PLAY_ENABLED=0');
putenv('IRADIOS_STAGE_PRODUCTION_ENABLED=0');
putenv('APP_ENV=staging');putenv('PRODUCTION_ENABLED=0');

// Production-to-play routing and legacy webhook forwarding are separate
// default-OFF gates. These tests have no provider or database calls.
check(!Config::playProductionAllowed()&&!Config::legacyStageDeliveryToPlay(),
 'Play production routing defaults OFF');
putenv('APP_ENV=production');putenv('PRODUCTION_ENABLED=1');
putenv('IRADIOS_PRODUCTION_PLAY_ENABLED=0');
putenv('IRADIOS_LEGACY_STAGE_TO_PLAY_ENABLED=0');
$order='IL'.str_repeat('A',24);
check(\Iluma\Gateway\CheckoutReturn::url('production','/checkout/result',$order,'L-PREM',true)
  ==='https://play.iradios.gr/premium?gateway_return=result&order='.$order,
 'Even legacy stage-routed live checkout returns to play with play cutover still disabled');
putenv('IRADIOS_PRODUCTION_PLAY_ENABLED=1');
check(Config::playProductionAllowed()&&!Config::legacyStageDeliveryToPlay(),
 'New play routing and legacy delivery toggles are independent');
putenv('IRADIOS_PRODUCTION_WEBHOOK=https://play.iradios.gr/api/gateway/webhook');
check(Config::playProductionWebhook()==='https://play.iradios.gr/api/gateway/webhook',
 'Play production webhook exact target');
putenv('IRADIOS_LEGACY_STAGE_TO_PLAY_ENABLED=1');
check(Config::legacyStageDeliveryToPlay()&&
 \Iluma\Gateway\CheckoutReturn::url('production','/checkout/result',$order,'L-PREM',true)
 ==='https://play.iradios.gr/premium?gateway_return=result&order='.$order,
 'Old real production return may move to play without new Nexi checkout');
putenv('IRADIOS_LEGACY_STAGE_TO_PLAY_ENABLED=0');
putenv('IRADIOS_PRODUCTION_PLAY_ENABLED=0');
putenv('APP_ENV=staging');putenv('PRODUCTION_ENABLED=0');
putenv('IRADIOS_PRODUCTION_WEBHOOK');
$adminMitSource=file_get_contents(dirname(__DIR__).'/src/AdminApplication.php');
check($adminMitSource!==false
 &&preg_match('/RecurringAdmin::index\([^;]+,\$env\);/', $adminMitSource)===1
 &&preg_match('/BroadcasterRecurringAdmin::index\([^;]+,\$env\);/', $adminMitSource)===1
 &&str_contains($adminMitSource,"in_array(\$env,['staging','production'],true)")
 &&!str_contains($adminMitSource,'Η Nexi MIT recurring υπηρεσία δεν είναι ενεργή στο production.'),
 'Canonical admin exposes read-only production MIT listing and detail');

$encoded=Security::encrypt('sensitive provider key');check($encoded!=='sensitive provider key'&&Security::decrypt($encoded)==='sensitive provider key','Sodium secretbox roundtrip');
$canonical=Security::signature('secret','1783585200','00000000000000000000000000000000','POST','/api/v1/payments/checkout','{"id":1}');
check(strlen($canonical)===64,'HMAC length');check($canonical!==Security::signature('secret','1783585200','00000000000000000000000000000000','POST','/api/v1/payments/checkout','{"id":2}'),'payload modification invalidates HMAC');

$originalAppEnv=getenv('APP_ENV');
putenv('APP_ENV=staging');
$originalProductionEnabled=getenv('PRODUCTION_ENABLED');
putenv('PRODUCTION_ENABLED=0');
$stageCredential=['environment'=>'staging','slug'=>'iradiosgr','application_id'=>1];
check(\Iluma\Gateway\BroadcasterStagingBootstrap::permitted($stageCredential),
 'Signed staging credential can initialize broadcaster schema');
putenv('APP_ENV=development');
check(\Iluma\Gateway\BroadcasterStagingBootstrap::permitted($stageCredential),
 'Hostinger development runtime accepts authenticated staging-only bootstrap');
putenv('PRODUCTION_ENABLED=1');
check(!\Iluma\Gateway\BroadcasterStagingBootstrap::permitted($stageCredential),
 'Contradictory development/live flags cannot open staging');
putenv('PRODUCTION_ENABLED=0');
putenv('APP_ENV=production');
check(!\Iluma\Gateway\BroadcasterStagingBootstrap::permitted($stageCredential),
 'Incomplete production runtime flags cannot open staging');
putenv('PRODUCTION_ENABLED=1');
check(\Iluma\Gateway\BroadcasterStagingBootstrap::permitted($stageCredential)
 &&\Iluma\Gateway\Config::stagingTenantRuntimeAllowed()
 &&\Iluma\Gateway\Config::liveAllowed(),
 'Signed staging credential can use isolated sandbox tenant in common production runtime');
$stageConsent=['auto_renew'=>true,
 'recurring_consent_version'=>\Iluma\Gateway\BroadcasterMandates::CONSENT];
check(\Iluma\Gateway\BroadcasterMandates::choice(
 $stageConsent,'staging','B-PREM','iradiosgr',9900)==='B-PREM',
 'Broadcaster Premium checkout keeps staging tenant under global production');
check(\Iluma\Gateway\BroadcasterBoost::choice([
 'resource_id'=>str_repeat('a',24),'auto_renew'=>false],
 'staging','BOOST-G-DAY','iradiosgr',2900)===str_repeat('a',24),
 'One-time Boost checkout keeps staging tenant under global production');
check(!\Iluma\Gateway\BroadcasterStagingBootstrap::permitted(
 ['environment'=>'staging','slug'=>'another-app','application_id'=>1])
 &&!\Iluma\Gateway\BroadcasterStagingBootstrap::permitted(
 ['environment'=>'staging','slug'=>'iradiosgr','application_id'=>0]),
 'Wrong staging application or missing app ID cannot initialize Broadcaster tenant');
putenv('IRADIOS_PRODUCTION_PLAY_ENABLED=0');
putenv('IRADIOS_STAGE_PRODUCTION_ENABLED=0');
check(!\Iluma\Gateway\BroadcasterStagingBootstrap::permitted(
 ['environment'=>'production','slug'=>'iradiosgr','application_id'=>1]),
 'Production credential cannot inherit signed sandbox access');
check(!\Iluma\Gateway\Config::productionRecurringAllowed(),
 'Staging checkout does not enable unapproved production recurring');
putenv('NEXI_STAGING_BASE=https://xpaysandbox.nexigroup.com/api/phoenix-0.0/psp');
$stageProvider=new NexiClient('staging','offline-test-api-key');
putenv('BROADCASTER_MIT_SANDBOX_EXECUTION_ENABLED=0');
putenv('BROADCASTER_MIT_SANDBOX_UAT_APPROVED=0');
try{$stageProvider->startBroadcasterMit('INVALID','INVALID','INVALID',9900);
 $sandboxMitLocked=false;
}catch(\RuntimeException $e){$sandboxMitLocked=true;}
check($sandboxMitLocked,'Sandbox MIT remains disabled in shared production runtime without approval');
putenv('BROADCASTER_MIT_SANDBOX_EXECUTION_ENABLED=1');
putenv('BROADCASTER_MIT_SANDBOX_UAT_APPROVED=1');
try{$stageProvider->startBroadcasterMit('INVALID','INVALID','INVALID',9900);
 $sandboxMitGuarded=false;
}catch(\InvalidArgumentException $e){$sandboxMitGuarded=true;}
 catch(\RuntimeException $e){$sandboxMitGuarded=false;}
check($sandboxMitGuarded,'Approved sandbox MIT selects provider input validation, not Nexi Live');
putenv('BROADCASTER_MIT_SANDBOX_EXECUTION_ENABLED=0');
putenv('BROADCASTER_MIT_SANDBOX_UAT_APPROVED=0');
putenv('APP_ENV=staging');putenv('PRODUCTION_ENABLED=0');
check(!\Iluma\Gateway\Config::liveAllowed(),
 'Staging global runtime cannot enter Nexi Live');
if($originalProductionEnabled===false)putenv('PRODUCTION_ENABLED');
else putenv('PRODUCTION_ENABLED='.$originalProductionEnabled);
// Account deletion is a signed, staging-only financial operation; invalid
// callers must be rejected BEFORE any database connection is attempted.
$rejected=false;
try{\Iluma\Gateway\AccountDeletion::cancelAll(
 ['environment'=>'production','slug'=>'iradiosgr','application_id'=>1],
 'iradios-stage-'.str_repeat('a',24));}
catch(\Iluma\Gateway\HttpError){$rejected=true;}
check($rejected,'Account-wide billing cancellation rejects production credentials');
$rejected=false;
try{\Iluma\Gateway\AccountDeletion::cancelAll($stageCredential,'not-an-iradios-user');}
catch(\Iluma\Gateway\HttpError){$rejected=true;}
check($rejected,'Account-wide billing cancellation rejects unscoped customer IDs');
$stationRef=str_repeat('a',24);
$boostOrder=['resource_id'=>$stationRef,'auto_renew'=>false];
check(\Iluma\Gateway\BroadcasterBoost::choice($boostOrder,'staging','BOOST-G-DAY','iradiosgr',2900)===$stationRef,
 'One-off Boost verifies fixed price and canonical station scope');
$boostRejected=false;
try{\Iluma\Gateway\BroadcasterBoost::choice(
 ['resource_id'=>$stationRef,'auto_renew'=>true],'staging','BOOST-G-DAY','iradiosgr',2900);}
catch(\Iluma\Gateway\HttpError){$boostRejected=true;}
check($boostRejected,'One-off Boost refuses recurring MIT consent');
$boostRejected=false;
try{\Iluma\Gateway\BroadcasterBoost::choice($boostOrder,'staging','BOOST-G-DAY','iradiosgr',2990);}
catch(\Iluma\Gateway\HttpError){$boostRejected=true;}
check($boostRejected,'One-off Boost cannot be purchased at another catalog price');
check(\Iluma\Gateway\BroadcasterMandates::PRICE===
 ['B-PREM'=>9900,'ADD-DSP'=>2790,'ADD-AN'=>690,'ADD-ADS'=>890],
 'New broadcaster account/station catalog prices are exact VAT-inclusive cents');
check(\Iluma\Gateway\BroadcasterMandates::validContractAmount('B-PREM',11900)&&
 \Iluma\Gateway\BroadcasterMandates::validContractAmount('B-PREM',9900)&&
 \Iluma\Gateway\BroadcasterMandates::validContractAmount('ADD-AN',790)&&
 \Iluma\Gateway\BroadcasterMandates::validContractAmount('ADD-AN',690)&&
 !\Iluma\Gateway\BroadcasterMandates::validContractAmount('ADD-AN',500),
 'Signed legacy and current MIT amounts are allowed, unrelated prices refused');
$accountConsent=['auto_renew'=>true,
 'recurring_consent_version'=>\Iluma\Gateway\BroadcasterMandates::CONSENT];
check(\Iluma\Gateway\BroadcasterMandates::choice($accountConsent,'staging','B-PREM','iradiosgr',9900)==='B-PREM',
 'Broadcaster account Premium accepts explicit monthly consent');
$addonConsent=$accountConsent+['resource_id'=>$stationRef];
check(\Iluma\Gateway\BroadcasterMandates::choice($addonConsent,'staging','ADD-AN','iradiosgr',690)==='ADD-AN',
 'Station Analytics accepts independent recurring consent');
$addonRejected=false;
try{\Iluma\Gateway\BroadcasterMandates::choice(
 ['auto_renew'=>true,'recurring_consent_version'=>\Iluma\Gateway\BroadcasterMandates::CONSENT],
 'staging','ADD-AN','iradiosgr',690);}
catch(\Iluma\Gateway\HttpError){$addonRejected=true;}
check($addonRejected,'Station add-on rejects missing station ID');
$addonRejected=false;
try{\Iluma\Gateway\BroadcasterMandates::choice(
 $addonConsent,'staging','ADD-AN','iradiosgr',399);}
catch(\Iluma\Gateway\HttpError){$addonRejected=true;}
check($addonRejected,'Broadcaster recurring price cannot fall back to Listener Premium 3.99');
check(\Iluma\Gateway\CheckoutReturn::url('staging','/checkout/result','IL'.str_repeat('A',24),'BOOST-G-DAY')===
 'https://stage.iradios.gr/backoffice/boost?gateway_return=result&order=IL'.str_repeat('A',24),
 'Boost HPP return targets Backoffice rather than Listener Premium');
check(\Iluma\Gateway\CheckoutReturn::url('staging','/checkout/result','IL'.str_repeat('A',24),'B-PREM')===
 'https://stage.iradios.gr/backoffice/pro-features?gateway_return=result&order=IL'.str_repeat('A',24),
 'Broadcaster subscription HPP return targets Pro Features');
$manageAuditSource=file_get_contents(dirname(__DIR__).'/bin/manage.php');
check($manageAuditSource!==false&&
 str_contains($manageAuditSource,"if(\$command==='audit-staging-broadcaster')")&&
 str_contains($manageAuditSource,'BROADCASTER STAGING READINESS')&&
 str_contains($manageAuditSource,"Config::get('APP_ENV')!=='staging'"),
 'Staging broadcaster migration and read-only readiness audit require explicit APP_ENV=staging');
$autoSetupSource=file_get_contents(dirname(__DIR__).'/src/BroadcasterStagingBootstrap.php');
$checkoutSource=file_get_contents(dirname(__DIR__).'/src/Payments.php');
$statusSource=file_get_contents(dirname(__DIR__).'/src/BroadcasterMandates.php');
check($autoSetupSource!==false&&
 str_contains($autoSetupSource,"Db::get('staging')")&&
 str_contains($autoSetupSource,"if($". "credential['environment']==='production')")&&
 str_contains($autoSetupSource,'BROADCASTER_PRODUCTION_SCHEMA_OR_CATALOG_NOT_READY')&&
 str_contains($autoSetupSource,'GET_LOCK(')&&
 str_contains($autoSetupSource,'BROADCASTER_STAGING_CATALOG_MISMATCH'),
 'Auto-bootstrap modifies staging only; production catalog is read-only and preflighted');
check($checkoutSource!==false&&
 strpos($checkoutSource,'BroadcasterStagingBootstrap::ensure($credential)')<
 strpos($checkoutSource,'SELECT * FROM products WHERE application_id=? AND sku=? AND enabled=1'),
 'Broadcaster setup precedes product lookup and provider checkout');
check($statusSource!==false&&
 str_contains($statusSource,'BroadcasterStagingBootstrap::ensure($credential)'),
 'Signed recurring-status request prepares staging without a payment');
check(\Iluma\Gateway\RecurringMandates::monthAfter('2027-02-28 15:04:05',31)==='2027-03-31 15:04:05',
 'MIT renewals preserve January 31 billing anchor after February');
check(\Iluma\Gateway\RecurringMandates::monthAfter('2028-02-29 15:04:05',31)==='2028-03-31 15:04:05',
 'MIT renewals preserve original day across leap February');
$paymentSource=file_get_contents(dirname(__DIR__).'/src/Payments.php');
check($paymentSource!==false&&
 str_contains($paymentSource,"last_error_code='HPP_REJECTED_400'")&&
 str_contains($paymentSource,"UPDATE broadcaster_mandates")&&
 str_contains($paymentSource,"initial_payment_id=? AND status IN ('pending','verification_pending')"),
 'Confirmed Nexi HPP 400 + read-only 404 releases only the pending Broadcaster mandate');
$bmSource=file_get_contents(dirname(__DIR__).'/src/BroadcasterMandates.php');
$paySource=file_get_contents(dirname(__DIR__).'/src/Payments.php');
check($bmSource!==false&&$paySource!==false&&
 str_contains($bmSource,'function markInitialFailed(')&&
 str_contains($paySource,'BroadcasterMandates::markInitialFailed($db,$p[\'id\'])')&&
 str_contains($bmSource,"status IN ('pending','verification_pending')"),
 'Provider-confirmed failed initial HPP releases only pending broadcaster MIT mandates');
check(str_contains($bmSource,'period_ends_at')&&str_contains($bmSource,'anchor_day')&&
 str_contains($paySource,"$"."body['period_ends_at']=$"."bScope['period_ends_at']"),
 'Signed MIT outbox contains immutable original anchor and next expiry');

if($originalAppEnv===false)putenv('APP_ENV');else putenv('APP_ENV='.$originalAppEnv);

$valid=['orderStatus'=>['order'=>['orderId'=>'ILABC','amount'=>'499','currency'=>'EUR'],'capturedAmount'=>'499'], 'operations'=>[['orderId'=>'ILABC','operationId'=>'O123','operationType'=>'CAPTURE','operationAmount'=>'499','operationCurrency'=>'EUR','operationResult'=>'EXECUTED']]];
check(PaymentState::fromNexi($valid,499,'ILABC')===['paid','O123'],'paid when captured and verified');
check(PaymentState::fromNexi($valid,500,'ILABC')===['unknown',null],'mismatched amount is not paid');
check(PaymentState::fromNexi($valid,499,'DIFFERENT')===['unknown',null],'mismatched order is not paid');
$valid['orderStatus']['capturedAmount']='0';check(PaymentState::fromNexi($valid,499,'ILABC')===['unknown',null],'no capture means no paid');
$valid['operations']=[['operationResult'=>'DECLINED','operationType'=>'AUTHORIZATION','operationCurrency'=>'EUR']];check(PaymentState::fromNexi($valid,499,'ILABC')===['failed',null],'decline');
$valid['operations']=[['operationResult'=>'PENDING','operationType'=>'AUTHORIZATION','operationCurrency'=>'EUR']];check(PaymentState::fromNexi($valid,499,'ILABC')===['unknown',null],'pending never assumes success');
// Regression: actual implicit capture response from XPay Greece Sandbox.
$implicit=[
 'orderStatus'=>[
  'order'=>['orderId'=>'ILIMPLICIT100','amount'=>'100','currency'=>'EUR'],
  'authorizedAmount'=>'100','capturedAmount'=>'100','lastOperationType'=>'AUTHORIZATION'
 ],
 'operations'=>[[
  'orderId'=>'ILIMPLICIT100','operationId'=>'OP-AUTH-EXEC-1',
  'operationType'=>'AUTHORIZATION','operationResult'=>'EXECUTED',
  'operationAmount'=>'100','operationCurrency'=>'EUR'
 ]]
];
check(PaymentState::fromNexi($implicit,100,'ILIMPLICIT100')===['paid','OP-AUTH-EXEC-1'],'implicit Nexi captured amount and EXECUTED authorization means paid');
$noCapture=$implicit;$noCapture['orderStatus']['capturedAmount']='0';
check(PaymentState::fromNexi($noCapture,100,'ILIMPLICIT100')===['unknown',null],'authorization without captured amount is never paid');
$onlyAuthorized=$implicit;$onlyAuthorized['operations'][0]['operationResult']='AUTHORIZED';
check(PaymentState::fromNexi($onlyAuthorized,100,'ILIMPLICIT100')===['unknown',null],'AUTHORIZED without EXECUTED cannot claim implicit payment');
$wrongAmount=$implicit;$wrongAmount['operations'][0]['operationAmount']='99';
check(PaymentState::fromNexi($wrongAmount,100,'ILIMPLICIT100')===['unknown',null],'implicit capture operation amount must match');
$wrongCurrency=$implicit;$wrongCurrency['operations'][0]['operationCurrency']='USD';
check(PaymentState::fromNexi($wrongCurrency,100,'ILIMPLICIT100')===['unknown',null],'implicit capture currency mismatch rejected');
$wrongOrder=$implicit;$wrongOrder['operations'][0]['orderId']='ILOTHER';
check(PaymentState::fromNexi($wrongOrder,100,'ILIMPLICIT100')===['unknown',null],'implicit capture operation order mismatch rejected');
$overcaptured=$implicit;$overcaptured['orderStatus']['capturedAmount']='101';
check(PaymentState::fromNexi($overcaptured,100,'ILIMPLICIT100')===['unknown',null],'implicit capture mismatch is not paid');
$malformedCapture=$implicit;$malformedCapture['orderStatus']['capturedAmount']='100wat';
check(PaymentState::fromNexi($malformedCapture,100,'ILIMPLICIT100')===['unknown',null],'malformed captured amount rejected');
$refunded=$implicit;$refunded['orderStatus']['lastOperationType']='REFUND';
check(PaymentState::fromNexi($refunded,100,'ILIMPLICIT100')===['unknown',null],'refunded last operation is not newly paid');
check(\Iluma\Gateway\CheckoutReturn::url('staging','/checkout/result','IL'.str_repeat('A',24)) ===
 'https://stage.iradios.gr/premium?gateway_return=result&order=IL'.str_repeat('A',24),'Gateway returns to iRadios staging after Nexi');
check(\Iluma\Gateway\CheckoutReturn::url('production','/checkout/cancel','IL'.str_repeat('B',24)) ===
 'https://play.iradios.gr/premium?gateway_return=cancel&order=IL'.str_repeat('B',24),'Production callback targets production iRadios only');

check(\Iluma\Gateway\CheckoutReturn::url('production','/checkout/result','IL'.str_repeat('C',24),'ADV-BUDGET',true) ===
 'https://play.iradios.gr/backoffice/agency/campaigns?gateway_return=result&order=IL'.str_repeat('C',24),
 'Live Advertising always returns to play agency campaigns, including legacy stage-routed orders');
check(\Iluma\Gateway\CheckoutReturn::url('production','/checkout/result','IL'.str_repeat('D',24),'B-PREM',true) ===
 'https://play.iradios.gr/backoffice/pro-features?gateway_return=result&order=IL'.str_repeat('D',24),
 'Live broadcaster always returns to play pro features, including legacy stage-routed orders');
// Strong tenant isolation: browser return is ALWAYS chosen by the Gateway
// order's authenticated tenant. Legacy routing markers and financial webhook
// flags must never override the customer-facing return host.
$checkoutReturnOrder='IL'.str_repeat('E',24);
$returnTargets=[
 'L-PREM'=>'/premium',
 'L-WINBACK-199'=>'/premium',
 'B-PREM'=>'/backoffice/pro-features',
 'ADD-DSP'=>'/backoffice/pro-features',
 'ADD-AN'=>'/backoffice/pro-features',
 'ADD-ADS'=>'/backoffice/pro-features',
 'BOOST-T-DAY'=>'/backoffice/boost',
 'BOOST-T-WEEK'=>'/backoffice/boost',
 'BOOST-T-MONTH'=>'/backoffice/boost',
 'ADV-BUDGET'=>'/backoffice/agency/campaigns'
];
$tenantFlags=['APP_ENV','PRODUCTION_ENABLED','IRADIOS_PRODUCTION_PLAY_ENABLED',
 'IRADIOS_LEGACY_STAGE_TO_PLAY_ENABLED'];
$tenantBefore=[];
foreach($tenantFlags as $name)$tenantBefore[$name]=getenv($name);
foreach([false,true] as $playEnabled){
 foreach([false,true] as $legacyForwarding){
  putenv('APP_ENV=production');putenv('PRODUCTION_ENABLED=1');
  putenv('IRADIOS_PRODUCTION_PLAY_ENABLED='.($playEnabled?'1':'0'));
  putenv('IRADIOS_LEGACY_STAGE_TO_PLAY_ENABLED='.($legacyForwarding?'1':'0'));
  foreach(['staging'=>'https://stage.iradios.gr',
           'production'=>'https://play.iradios.gr'] as $tenant=>$host){
   foreach([false,true] as $stageRouted){
    foreach(['result','cancel'] as $kind){
     foreach($returnTargets as $sku=>$route){
      $actual=\Iluma\Gateway\CheckoutReturn::url($tenant,
       '/checkout/'.$kind,$checkoutReturnOrder,$sku,$stageRouted);
      $expected=$host.$route.'?gateway_return='.$kind.'&order='.$checkoutReturnOrder;
      check($actual===$expected,
       'Tenant-isolated Nexi browser return '.$tenant.'/'.$kind.'/'.$sku.
       '/legacy:'.(int)$stageRouted.'/flags:'.(int)$playEnabled.(int)$legacyForwarding);
     }
    }
   }
  }
 }
}
foreach($tenantBefore as $name=>$value){
 if($value===false)putenv($name);else putenv($name.'='.$value);
}
// The HTTP route looks up the order from Gateway DB and derives tenant
// internally. No browser-supplied environment, redirect URL or paid state.
$httpReturnSource=file_get_contents(dirname(__DIR__).'/public/index.php');
check(str_contains($httpReturnSource,"foreach(['staging','production'] as ". '$candidate)') &&
 str_contains($httpReturnSource,'CheckoutReturn::url($foundEnv,$path,$order,') &&
 str_contains($httpReturnSource,'Location: '),
 'Nexi return route resolves tenant from stored Gateway order, not query parameters');
$paymentGatewaySource=file_get_contents(dirname(__DIR__).'/src/NexiClient.php');
check(str_contains($paymentGatewaySource,"'/checkout/result?order='") &&
 str_contains($paymentGatewaySource,"'/checkout/cancel?order='"),
 'Nexi HPP resultUrl and cancelUrl always return through Gateway order lookup');
$legacyWorkerSource=file_get_contents(dirname(__DIR__).'/src/Worker.php');
check(str_contains($legacyWorkerSource,'Config::legacyStageDeliveryToPlay()') &&
 str_contains($legacyWorkerSource,'Config::stagePaymentWebhook()'),
 'Legacy financial webhook forwarding remains a separate, independently controlled operation');
// A production API credential alone must not enable routing or charge real cards.
$stageProdPrevious=[];
foreach(['APP_ENV','PRODUCTION_ENABLED','IRADIOS_STAGE_PRODUCTION_ENABLED',
  'IRADIOS_STAGE_PRODUCTION_WEBHOOK'] as $flag)$stageProdPrevious[$flag]=getenv($flag);
putenv('APP_ENV=production');putenv('PRODUCTION_ENABLED=1');
putenv('IRADIOS_STAGE_PRODUCTION_ENABLED=0');
check(!\Iluma\Gateway\Config::stageProductionAllowed(),
 'New iRadios production is disabled until its explicit route flag is approved');
putenv('IRADIOS_STAGE_PRODUCTION_ENABLED=1');
check(\Iluma\Gateway\Config::stageProductionAllowed(),
 'Explicit commercial iRadios production gate requires production runtime');
putenv('IRADIOS_STAGE_PRODUCTION_WEBHOOK=https://stage.iradios.gr/api/gateway/webhook');
check(\Iluma\Gateway\Config::stagePaymentWebhook()==='https://stage.iradios.gr/api/gateway/webhook',
 'New iRadios commercial HMAC outbox is pinned to stage origin');
putenv('IRADIOS_STAGE_PRODUCTION_WEBHOOK=https://play.iradios.gr/api/gateway/webhook');
try{\Iluma\Gateway\Config::stagePaymentWebhook();$badRouteAccepted=true;}
catch(\RuntimeException){$badRouteAccepted=false;}
check(!$badRouteAccepted,'The legacy origin can never replace the new stage commercial callback');
foreach($stageProdPrevious as $flag=>$value){
 if($value===false)putenv($flag);else putenv($flag.'='.$value);
}
$commercialWorkerSource=file_get_contents(dirname(__DIR__).'/src/Worker.php');
$commercialPaymentsSource=file_get_contents(dirname(__DIR__).'/src/Payments.php');
$commercialSql=file_get_contents(dirname(__DIR__).'/database/migrations/20261116_iradios_stage_production_routing.sql');
check(str_contains($commercialWorkerSource,'origin_credential_id')&&
 str_contains($commercialWorkerSource,'Config::stagePaymentWebhook()')&&
 str_contains($commercialPaymentsSource,'iradios_stage_routed')&&
 str_contains($commercialSql,'DEFAULT 0'),
 'New payment records pin the original credential; all historical production records retain their route');

foreach(['https://evil.test/','/checkout/unexpected'] as $invalidPath){
 try{\Iluma\Gateway\CheckoutReturn::url('staging',$invalidPath,'IL'.str_repeat('A',24));$returnAccepted=true;}
 catch(\InvalidArgumentException){$returnAccepted=false;}
 check(!$returnAccepted,'Arbitrary return path refused');
}
try{\Iluma\Gateway\CheckoutReturn::url('staging','/checkout/result','../bad');$badOrderAllowed=true;}
catch(\InvalidArgumentException){$badOrderAllowed=false;}
check(!$badOrderAllowed,'Checkout return requires a valid provider order ID');
putenv('NEXI_STAGING_BASE=https://xpaysandbox.nexigroup.com/api/phoenix-0.0/psp');putenv('NEXI_PRODUCTION_BASE=https://xpay.nexigroup.com/api/phoenix-0.0/psp');
check(str_contains(Config::base('staging'),'xpaysandbox'),'staging endpoint pinned');
putenv('NEXI_STAGING_BASE=https://xpay.nexigroup.com/api/phoenix-0.0/psp');
try{Config::base('staging');$bad=false;}catch(\RuntimeException){$bad=true;}check($bad,'no staging to live fallback');
$base=sys_get_temp_dir().'/iluma-env-test-'.bin2hex(random_bytes(6));
$project=$base.'/domains/example.test/public_html/site';
$private=$base.'/domains/example.test/private';
mkdir($project,0700,true);mkdir($private,0700,true);
$file=$private.'/iluma-gateway.env';
file_put_contents($file,"REGISTRY_USER=test_user\nSTAGING_PASSWORD='a=b#c'\nBROADCASTER_MIT_SANDBOX_EXECUTION_ENABLED=0\nBROADCASTER_MIT_SANDBOX_UAT_APPROVED=0\nMIT_SANDBOX_EXECUTION_ENABLED=0\nMIT_SANDBOX_UAT_APPROVED=0\nIRADIOS_PRODUCTION_PLAY_ENABLED=1\nIRADIOS_PLAY_MIT_EXECUTION_APPROVED=0\nIRADIOS_LEGACY_STAGE_TO_PLAY_ENABLED=0\n");chmod($file,0600);
putenv('REGISTRY_USER');putenv('STAGING_PASSWORD');
foreach(['MIT_SANDBOX_EXECUTION_ENABLED','MIT_SANDBOX_UAT_APPROVED',
 'BROADCASTER_MIT_SANDBOX_EXECUTION_ENABLED','BROADCASTER_MIT_SANDBOX_UAT_APPROVED',
 'IRADIOS_PRODUCTION_PLAY_ENABLED','IRADIOS_PLAY_MIT_EXECUTION_APPROVED',
 'IRADIOS_LEGACY_STAGE_TO_PLAY_ENABLED'] as $flag)putenv($flag);
check(EnvLoader::privateFilePath($project)===$file,'Hostinger env path outside public_html');
check(EnvLoader::load($project),'Hostinger private env loaded');
check(getenv('STAGING_PASSWORD')==='a=b#c','private password special characters');
check(Config::get('IRADIOS_PRODUCTION_PLAY_ENABLED')==='1'
 && Config::get('IRADIOS_PLAY_MIT_EXECUTION_APPROVED')==='0'
 && Config::get('IRADIOS_LEGACY_STAGE_TO_PLAY_ENABLED')==='0',
 'Private ENV accepts direct-play routing with MIT and legacy migration still off');
check(Config::get('BROADCASTER_MIT_SANDBOX_EXECUTION_ENABLED')==='0'
 && Config::get('BROADCASTER_MIT_SANDBOX_UAT_APPROVED')==='0'
 && Config::get('MIT_SANDBOX_EXECUTION_ENABLED')==='0'
 && Config::get('MIT_SANDBOX_UAT_APPROVED')==='0',
 'Hostinger private env accepts sandbox MIT flags but execution stays disabled');
chmod($file,0644);
try{EnvLoader::load($project);$bad=false;}catch(\RuntimeException){$bad=true;}
check($bad,'unsafe environment permissions rejected');
unlink($file);rmdir($private);rmdir($project);rmdir(dirname($project));rmdir(dirname(dirname($project)));rmdir(dirname(dirname(dirname($project))));
putenv('REGISTRY_USER');putenv('STAGING_PASSWORD');
foreach(['IRADIOS_PRODUCTION_PLAY_ENABLED','IRADIOS_PLAY_MIT_EXECUTION_APPROVED',
 'IRADIOS_LEGACY_STAGE_TO_PLAY_ENABLED'] as $flag)putenv($flag);
foreach(['MIT_SANDBOX_EXECUTION_ENABLED','MIT_SANDBOX_UAT_APPROVED',
 'BROADCASTER_MIT_SANDBOX_EXECUTION_ENABLED','BROADCASTER_MIT_SANDBOX_UAT_APPROVED'] as $flag)putenv($flag);
$routing=file_get_contents(dirname(__DIR__).'/.htaccess');
$adminEntrypoint=file_get_contents(dirname(__DIR__).'/public/admin.php');
$adminSource=file_get_contents(dirname(__DIR__).'/src/AdminApplication.php');
check($adminEntrypoint!==false &&
 str_contains($adminEntrypoint,"require dirname(__DIR__) . '/src/AdminApplication.php';") &&
 !str_contains($adminEntrypoint,'class AdminPageView') &&
 !str_contains($adminEntrypoint,'function requireStepUp(') &&
 strlen($adminEntrypoint)<1024,
 'One short public Admin entrypoint forwards to a single private controller');
check($adminSource!==false &&
 str_contains($adminSource,'session_start();') &&
 str_contains($adminSource,'function requireStepUp(') &&
 str_contains($adminSource,'final class AdminPageView') &&
 str_contains($adminSource,'AdminPageView::render('),
 'Admin session, MFA and HTML renderer stay together in the private controller');
check($routing!==false && str_contains($routing,'^ilm-admin/?$ public/admin.php'),'hostinger admin path rewrite');
check(str_contains($routing,'^ilm-admin/admin\.css$ public/admin.css'),'hostinger admin stylesheet rewrite');
check(str_contains($adminSource,'X-ILUMA-Admin-Entry: canonical'), 'Canonical admin entrypoint exposes build marker');
check($adminSource!==false && str_contains($adminSource,'/ilm-admin/?p='),'admin navigation uses new path');
check(str_contains($adminSource,'/ilm-admin/admin.css'),'admin stylesheet uses new path');
check(str_contains($adminSource,'final class AdminPageView') &&
 str_contains($adminSource,'AdminPageView::render(') &&
 !str_contains($adminSource,'$renderPage(') &&
 !str_contains($adminSource,'function page('),
 'Admin login/error paths use static view method, not a mutable callable');
$dbSource=file_get_contents(dirname(__DIR__).'/src/Db.php');
check($dbSource!==false &&
 str_contains($dbSource,"PDO::ATTR_PERSISTENT=>PHP_SAPI!=='cli'") &&
 str_contains($dbSource,"if(PHP_SAPI!=='cli')\$db->exec('ROLLBACK')"),
 'Hostinger web PDO reuse includes rollback of inherited transaction while CLI remains isolated');

check(!str_contains($adminSource,"Config::get('ADMIN_HOST')"),'admin uses gateway host');
check(!str_contains($adminSource,"header('Location: /')"),'admin redirects stay in protected path');
check(AdminContext::environment([])==='production','admin login page defaults to production without a session environment');
check(AdminContext::environment(['view_env'=>'staging'])==='staging','admin staging selection');
check(AdminContext::environment(['view_env'=>'production'])==='production','admin production selection');
check(AdminContext::environment(['view_env'=>'unexpected'])==='production','unknown admin display environment falls back to production');
$adminJs=file_get_contents(dirname(__DIR__).'/public/admin.js');
$adminCss=file_get_contents(dirname(__DIR__).'/public/admin.css');
check($adminJs!==false && str_contains($adminJs,'requestSubmit'),'step-up popup submits after approval');
check(str_contains($adminJs,'matchMedia("(max-width: 420px)")')
 && str_contains($adminSource,'data-size="flexible"'),
 'Turnstile login widget switches to compact size on narrow screens');
check($adminCss!==false && str_contains($adminCss,'.mfa-dialog'),'MFA confirmation sheet styles present');

check($adminCss!==false
 && str_contains($adminCss,'.brand-tile')
 && str_contains($adminCss,'border-radius:20px')
 && str_contains($adminCss,'.is-login .brand-tile')
 && str_contains($adminCss,'@media(max-width:760px)')
 && str_contains($adminCss,'@media(max-width:480px)'),
 'Branded logo tile has 20px radius and responsive light-mode layouts');
check(str_contains($adminSource,'https://iluma.gr/press/ilumadigitalagencywh.png')
 && str_contains($adminSource,"img-src 'self' https://iluma.gr")
 && str_contains($adminSource,'referrerpolicy="no-referrer"'),
 'Official ILUMA logo is displayed under a narrow admin image CSP');
check(str_contains($adminSource,'aria-current="page"')
 && str_contains($adminCss,'aside a[aria-current="page"]'),
 'Sidebar marks active workspace navigation accessibly');
check(str_contains($adminSource,"connect-src 'self' https://challenges.cloudflare.com"),
 'Cloudflare Turnstile remains permitted under strict script, frame and connect CSP');

check(str_contains($routing,'^ilm-admin/admin\.js$ public/admin.js'),'admin script routed correctly');
check(str_contains($adminSource,"script-src 'self' https://challenges.cloudflare.com")
 && str_contains($adminSource,'frame-src https://challenges.cloudflare.com'),
 'strict CSP permits Cloudflare Turnstile scripts and frames');
check(str_contains($adminSource,'password_verify($pw,$u[\'passhash\']);')
 && str_contains($adminSource,'AdminLoginState::begin(')
 && str_contains($adminSource,'AdminLoginState::current(')
 && str_contains($adminSource,'Security::otpVerify(Security::decrypt($u[\'totp_secret_box\']),$otp)')
 && str_contains($adminSource,'AdminTurnstile::verify($token,$ip)'),
 'login verifies password, Turnstile and TOTP before session');
check(str_contains($adminSource,'function requireStepUp('),'server-side MFA guard exists');
check(substr_count($adminSource,'requireStepUp($reg,')>=4,'sensitive actions and CSV export have MFA guard');
check(str_contains($adminSource,'MFA required for export'),'CSV downloads require verified POST');
check(str_contains($adminSource,'data-stepup='),'sensitive admin forms use action confirmation');
$nexiSource=file_get_contents(dirname(__DIR__).'/src/NexiClient.php');
check($nexiSource!==false && str_contains($nexiSource,"if(\$this->environment==='staging')\$session['captureType']='IMPLICIT';"),'Sandbox HPP explicitly requests implicit capture');
check(str_contains($nexiSource,"'language'=>'ELL'"),'Nexi HPP includes required ISO 639-2 session language');
$reference='iradios-stage-'.str_repeat('a',24);
$providerCustomer=NexiClient::providerCustomerId($reference);
check(strlen($reference)>27,'Application customer reference reproduces XPay customerId overflow');
check((bool)preg_match('/^[A-Za-z0-9_-]{1,27}$/D',$providerCustomer),'Nexi HPP customerId meets official 27-character format');
check(NexiClient::providerCustomerId($reference)===$providerCustomer,'Same Gateway customer always maps to same Nexi customerId');
check(NexiClient::providerCustomerId('iradios-stage-'.str_repeat('b',24))!==$providerCustomer,'Different customers cannot accidentally reuse the same test customerId');
check(NexiClient::providerCustomerId('listener-002')!=='','Gateway CLI pilot uses same compliant mapper');
check(str_contains($nexiSource,"'customerId'=>self::providerCustomerId(\$customerRef)"),'All standard HPP calls convert the Gateway customer reference to provider format');
try{NexiClient::providerCustomerId('');$invalidCustomerAccepted=true;}catch(\InvalidArgumentException){$invalidCustomerAccepted=false;}
check(!$invalidCustomerAccepted,'Empty Nexi customer reference is refused');

check(\Iluma\Gateway\SubscriptionPilot::reference('listener-001')==='listener-001','Staging subscription test reference');
try{\Iluma\Gateway\SubscriptionPilot::reference('../bad');$bad=false;}catch(\InvalidArgumentException){$bad=true;}
check($bad,'Staging subscription rejects unsafe reference');
$pilotSource=file_get_contents(dirname(__DIR__).'/src/SubscriptionPilot.php');
check($pilotSource!==false && str_contains($pilotSource,"'renewal_enabled'=>false") &&
 str_contains($pilotSource,"Db::get('staging')"),'Subscription pilot is staging-only with renewals off');
check(str_contains($pilotSource,"'sandbox-subscription-'.\$ref") &&
 str_contains($pilotSource,'FOR UPDATE'),'Staging subscription uses stable idempotency key');
check(str_contains($pilotSource,"'payment_mode'=>'one_time_hosted_checkout'") &&
 !str_contains($pilotSource,'CONTRACT_CREATION') &&
 !str_contains($pilotSource,'MIT_SCHEDULED'),'Staging plan pilot does not require Nexi recurring contract');
check(str_contains($pilotSource,'$orderId,self::CENTS,$external') &&
 !str_contains($pilotSource,'self::recurrence('),'First month is standard HPP checkout');
check(str_contains($pilotSource,'JOIN customers c ON c.id=s.customer_id') &&
 !str_contains($pilotSource,'JOIN nexi_contracts nc'),'Subscription status belongs to Gateway ledger and needs no Nexi contract');
$paymentSource=file_get_contents(dirname(__DIR__).'/src/Payments.php');
check(str_contains($paymentSource,"period_end_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 MONTH)") &&
 str_contains($paymentSource,"auto_renew=0"),'Subscription pilot activates after paid without renewal');
$manageSource=file_get_contents(dirname(__DIR__).'/bin/manage.php');
check(str_contains($manageSource,'sandbox-subscription-status') &&
 str_contains($manageSource,"(\$argv[2]??'')!=='staging'"),'Staging CLI guard on subscription pilot');
$errBody=Security::json(['errors'=>[['code'=>'PS0000','description'=>'Missing paymentSession.recurrence.contractFrequency']]]);
check(NexiClient::errorSummary($errBody,'test-private-key')==='PS0000 Missing paymentSession.recurrence.contractFrequency','Provider validation error details retained without raw response');
$errBody=Security::json(['errors'=>[['code'=>'GW0001','description'=>'Incorrect url https://example.net/?secret=abc test-private-key']]]);
$errSummary=NexiClient::errorSummary($errBody,'test-private-key');
check(!str_contains($errSummary,'example.net')&&!str_contains($errSummary,'test-private-key') && str_contains($errSummary,'GW0001'),'Provider errors redact urls and configured key');
check(NexiClient::errorSummary('not a json','key')==='','Malformed provider error is never dumped');
$paymentsSource=file_get_contents(dirname(__DIR__).'/src/Payments.php');
$routerSource=file_get_contents(dirname(__DIR__).'/public/index.php');
check(str_contains($paymentsSource,'function findByRequestKey(') &&
 str_contains($paymentsSource,'WHERE application_id=? AND request_key=?') &&
 !str_contains(substr($paymentsSource,strpos($paymentsSource,'function findByRequestKey('),500),'startCheckout('),
 'Signed recovery uses scoped SELECT and never creates provider checkout');
check(str_contains($routerSource,'Payments::findByRequestKey($credential,') &&
 str_contains($routerSource,"/api/v1/payments/by-request-key/"),
 'Recovery route uses the already authenticated gateway credential');

$workerSource=file_get_contents(dirname(__DIR__).'/src/Worker.php');
check($workerSource!==false && str_contains($workerSource,'function deliverPayment(') &&
 str_contains($workerSource,'self::deliver($env,1,$paymentId)'),
 'Verified paid events use targeted immediate delivery through the durable outbox');
check(str_contains($routerSource,"if(\$state==='paid')") &&
 str_contains($routerSource,'Worker::deliverPayment($env,') &&
 str_contains($routerSource,'Worker::deliverPayment($foundEnv,$foundPaymentId)'),
 'Both provider notification and verified return dispatch the existing paid event');
check(str_contains($workerSource,'AND payment_id=?') &&
 str_contains($workerSource,'AND next_attempt_at<=UTC_TIMESTAMP()') &&
 str_contains($workerSource,"status='sending'"),
 'Immediate delivery uses existing idempotent, due-time, atomic outbox claim');
try{
 \Iluma\Gateway\Worker::deliverPayment('staging','not-valid-id');
 $invalidDeliveryAllowed=true;
}catch(\InvalidArgumentException){$invalidDeliveryAllowed=false;}
check(!$invalidDeliveryAllowed,'Targeted webhook delivery rejects invalid payment ID before database access');
$adminSource=file_get_contents(dirname(__DIR__).'/src/AdminApplication.php');
check(str_contains($adminSource,'Τελευταίο HTTP webhook:') &&
 str_contains($adminSource,'Δεν έχει πραγματοποιηθεί αποστολή προς το iRadios'),
 'Admin exposes redacted pending delivery and last HTTP status for diagnosis');


$choice=\Iluma\Gateway\SavedCards::choice([
 'save_card'=>true,'card_consent_version'=>\Iluma\Gateway\SavedCards::CONSENT_VERSION
 ],'staging','L-PREM','iradiosgr');
check($choice['save']===true && $choice['reuse']===false,'Stored-card opt-in requires explicit consent version');
$plain=\Iluma\Gateway\SavedCards::choice([],'staging','L-PREM','iradiosgr');
check($plain['save']===false && $plain['reuse']===false,'No card tokenization by default');
$reuse=\Iluma\Gateway\SavedCards::choice(['use_saved_card'=>true],'staging','L-PREM','iradiosgr');
check($reuse['reuse']===true,'Subsequent CIT requires explicit one-click selection');
foreach([
 ['save_card'=>true],
 ['save_card'=>true,'use_saved_card'=>true,'card_consent_version'=>\Iluma\Gateway\SavedCards::CONSENT_VERSION],
 ['save_card'=>'yes','card_consent_version'=>\Iluma\Gateway\SavedCards::CONSENT_VERSION]
] as $invalid){
 try{\Iluma\Gateway\SavedCards::choice($invalid,'staging','L-PREM','iradiosgr');$accepted=true;}
 catch(\Iluma\Gateway\HttpError){$accepted=false;}
 check(!$accepted,'Invalid or ambiguous stored-card consent rejected');
}
try{\Iluma\Gateway\SavedCards::choice([
 'save_card'=>true,'card_consent_version'=>\Iluma\Gateway\SavedCards::CONSENT_VERSION
 ],'production','L-PREM','iradiosgr');$prodAllowed=true;}
catch(\Iluma\Gateway\HttpError){$prodAllowed=false;}
check(!$prodAllowed,'Provider tokenization locked in production');
$contract=\Iluma\Gateway\SavedCards::contractId();
check((bool)preg_match('/^ILC[A-F0-9]{15}$/D',$contract),'No PAN data; contract ID is safe and unique shaped');
$first=NexiClient::oneClickRecurrence('CONTRACT_CREATION',$contract);
$repeat=NexiClient::oneClickRecurrence('SUBSEQUENT_PAYMENT',$contract);
check($first['contractType']==='CIT' && $first['action']==='CONTRACT_CREATION',
 'First opt-in HPP creates CIT contract only');
check($repeat['contractType']==='CIT' && $repeat['action']==='SUBSEQUENT_PAYMENT',
 'Saved-card manual renewal requests Nexi 3DS again');
try{NexiClient::oneClickRecurrence('MIT_SCHEDULED',$contract);$mitAllowed=true;}
catch(\InvalidArgumentException){$mitAllowed=false;}
check(!$mitAllowed,'Automatic merchant-initiated charge remains unsupported');
$cardsSource=file_get_contents(dirname(__DIR__).'/src/SavedCards.php');
check(str_contains($cardsSource,"p.status='paid'") &&
 str_contains($cardsSource,"(\$contract['contractType']??null)==='CIT'"), 'Card contract is verified only after paid provider settlement');
check(str_contains($cardsSource,'revocation_pending') &&
 str_contains($cardsSource,'deactivateContract(') &&
 !str_contains($cardsSource,'POST /orders/mit'),'Card removal is server-side, no auto charges');
check(str_contains($routerSource,"/api/v1/payment-methods/status") &&
 str_contains($routerSource,"/api/v1/payment-methods/revoke"),
 'Authenticated Gateway card status and revoke endpoints exist');
$cardMigration=file_get_contents(dirname(__DIR__).'/database/migrations/20261110_staging_saved_cards.sql');
check(str_contains($cardMigration,'CREATE TABLE IF NOT EXISTS saved_card_agreements') &&
 !preg_match('/\\b(?:card_number|security_code)\\s+(?:VARCHAR|TEXT|CHAR)\\b/i',$cardMigration),
 'SQL schema stores provider contracts and consent, never raw card details');
$paymentsSource=file_get_contents(dirname(__DIR__).'/src/Payments.php');
$savedCardsSource=file_get_contents(dirname(__DIR__).'/src/SavedCards.php');
$existingPos=strpos($paymentsSource,"if(\$existing){\$db->commit()");
$preflightPos=strpos($paymentsSource,'SavedCards::preflight(');
$insertPos=strpos($paymentsSource,"INSERT INTO payments(id,application_id,customer_id");
check($existingPos!==false&&$preflightPos!==false&&$insertPos!==false
 && $existingPos<$preflightPos&&$preflightPos<$insertPos,
 'OneClick preflight happens after idempotency lookup but before payment ledger creation');
check(str_contains($savedCardsSource,"table_name='saved_card_agreements'") &&
 str_contains($savedCardsSource,"throw new HttpError(422,'ONECLICK_STAGING_MIGRATION_REQUIRED')") &&
 str_contains($savedCardsSource,"throw new HttpError(422,'SAVED_CARD_NOT_AVAILABLE')"),
 'Missing OneClick migration or absent card fails 422 before any Nexi request');
check(str_contains($savedCardsSource,"SAVED_CARD_EXISTS_OR_PENDING") &&
 str_contains($savedCardsSource,'if(!$choice[\'save\']&&!$choice[\'reuse\'])return;'),
 'Ordinary card checkout remains available without OneClick migration');

$mitChoice=\Iluma\Gateway\RecurringMandates::choice([
 'auto_renew'=>true,'recurring_consent_version'=>\Iluma\Gateway\RecurringMandates::CONSENT
 ],'staging','L-PREM','iradiosgr');
check($mitChoice===true,'Explicit listener consent creates eligible recurring mandate');
check(\Iluma\Gateway\RecurringMandates::choice([],'staging','L-PREM','iradiosgr')===false,
 'No automatic renewal by default; opt-in is separate from OneClick');
foreach([
 ['auto_renew'=>true],
 ['auto_renew'=>true,'recurring_consent_version'=>'wrong'],
 ['auto_renew'=>true,'save_card'=>true,'recurring_consent_version'=>\Iluma\Gateway\RecurringMandates::CONSENT],
 ['auto_renew'=>'1','recurring_consent_version'=>\Iluma\Gateway\RecurringMandates::CONSENT]
] as $invalidMit){
 try{\Iluma\Gateway\RecurringMandates::choice($invalidMit,'staging','L-PREM','iradiosgr');$badMitAllowed=true;}
 catch(\Iluma\Gateway\HttpError){$badMitAllowed=false;}
 check(!$badMitAllowed,'Missing/ambiguous MIT consent rejected before payment creation');
}
try{\Iluma\Gateway\RecurringMandates::choice([
 'auto_renew'=>true,'recurring_consent_version'=>\Iluma\Gateway\RecurringMandates::CONSENT
 ],'production','L-PREM','iradiosgr');$prodMitAllowed=true;}
catch(\Iluma\Gateway\HttpError){$prodMitAllowed=false;}
check(!$prodMitAllowed,'Production MIT enrollment is locked');
$mitContract=\Iluma\Gateway\RecurringMandates::contractId();
$mitRecurrence=\Iluma\Gateway\RecurringMandates::recurrence($mitContract);
check((bool)preg_match('/^ILM[A-F0-9]{15}$/D',$mitContract) &&
 $mitRecurrence['action']==='CONTRACT_CREATION' &&
 $mitRecurrence['contractType']==='MIT_SCHEDULED' &&
 $mitRecurrence['contractFrequency']==='28',
 'Nexi scheduled MIT contract has valid ID and 28-day minimum monthly frequency');
check(\Iluma\Gateway\RecurringMandates::monthAfter('2026-01-31 15:14:00')==='2026-02-28 15:14:00' &&
 \Iluma\Gateway\RecurringMandates::monthAfter('2026-02-28 15:14:00',31)==='2026-03-31 15:14:00' &&
 \Iluma\Gateway\RecurringMandates::monthAfter('2027-02-28 15:14:00')==='2027-03-28 15:14:00',
 'Calendar-month clamp preserves subscriber anniversary through February');
putenv('NEXI_STAGING_BASE=https://xpaysandbox.nexigroup.com/api/phoenix-0.0/psp');
putenv('MIT_SANDBOX_EXECUTION_ENABLED=0');
putenv('MIT_SANDBOX_UAT_APPROVED=0');
try{
 (new NexiClient('staging','offline-test-api-key'))->startMit('IL'.str_repeat('A',24),
  'IR'.str_repeat('A',24),$mitContract,399);
 $mitGateFailed=false;
}catch(\RuntimeException $e){$mitGateFailed=str_contains($e->getMessage(),'MIT sandbox execution is locked');}
check($mitGateFailed,'No MIT charge is possible without staged execution and UAT approval flags');
check(str_contains(file_get_contents(dirname(__DIR__).'/src/NexiClient.php'),"IL[CM](?:[A-F0-9]{15}|[A-F0-9]{24})"),
 'Both CIT and MIT contracts may be deactivated on cancellation');
check(str_contains(file_get_contents(dirname(__DIR__).'/src/RecurringMandates.php'),
 "WHERE id=? FOR UPDATE") &&
 str_contains(file_get_contents(dirname(__DIR__).'/src/RecurringMandates.php'),
 "SELECT status,cancelled_at FROM recurring_mandates"),
 'Charge initiation serializes with cancellation acknowledgement');

$mitSource=file_get_contents(dirname(__DIR__).'/src/RecurringMandates.php');
$mitMigration=file_get_contents(dirname(__DIR__).'/database/migrations/20261110_staging_recurring_mit.sql');
check(str_contains($mitSource,'UNIQUE')===false &&
 str_contains($mitMigration,'UNIQUE KEY one_cycle(mandate_id,cycle_due_at)') &&
 str_contains($mitSource,"status='active'") &&
 str_contains($mitSource,'chargeDue('),
 'Unique monthly renewal cycle and fail-closed scheduler are present');
$workerSource=file_get_contents(dirname(__DIR__).'/bin/work.php');
check(str_contains($workerSource,'RecurringMandates::chargeDue(') &&
 str_contains($workerSource,'RecurringMandates::pendingVerification('),
 'MIT scheduler is isolated behind staging worker/provider readback');
$routerSource=file_get_contents(dirname(__DIR__).'/public/index.php');
check(str_contains($routerSource,"/api/v1/recurring/cancel") &&
 str_contains($routerSource,"/api/v1/billing/history"),
 'HMAC-account scoped recurring cancellation and payment history APIs available');
// HPP HTTP400 with structured Nexi validation is not a "paid" event.
// Show only the scrubbed error in operator logs; no automatic retry/charge.
$providerError=NexiClient::errorSummary(json_encode(['errors'=>[
 ['code'=>'GW0001','description'=>'Invalid recurring contractFrequency']
]]), 'test-secret');
check(str_contains($providerError,'GW0001') &&
 str_contains($providerError,'contractFrequency'),'Nexi structured HPP rejection is diagnosable without card data');
$paymentSource=file_get_contents(dirname(__DIR__).'/src/Payments.php');
$workerSource=file_get_contents(dirname(__DIR__).'/src/Worker.php');
check(str_contains($paymentSource,"'hpp_rejected_400'") &&
 str_contains($paymentSource,'reconcileRejectedHpp404(') &&
 str_contains($paymentSource,"event_type='hpp_rejected_400'") &&
 str_contains($paymentSource,"message_gr LIKE 'Η Nexi απέρριψε τη δημιουργία checkout (HTTP 400)%'") &&
 str_contains($paymentSource,"$" . "p['status']!=='unknown'"),
 'Gateway never releases a rejected checkout without the exact 400 marker and unknown status');
check(str_contains($workerSource,'RejectedHppRepair::provider404(') &&
 str_contains($workerSource,'Payments::reconcileRejectedHpp404('),
 'Only a read-only Nexi GET 404 allows the worker to reconcile a rejected HPP');

// Rejected-HPP repair is fail closed and has separate manual/automatic gates.
$repairSource=file_get_contents(dirname(__DIR__).'/src/RejectedHppRepair.php');
$repairCli=file_get_contents(dirname(__DIR__).'/bin/reconcile-rejected-hpp.php');
check(\Iluma\Gateway\RejectedHppRepair::provider404(new \RuntimeException('Nexi status 404 (correlation synthetic)'))
 && !\Iluma\Gateway\RejectedHppRepair::provider404(new \RuntimeException('Nexi status 503 unavailable')),
 'Only a true provider 404 is acceptable for HPP rejection proof');
$repairFlags=['APP_ENV','PRODUCTION_ENABLED','IRADIOS_PRODUCTION_REJECTED_HPP_REPAIR_ENABLED',
 'IRADIOS_PRODUCTION_REJECTED_HPP_AUTO_ENABLED'];
$previousFlags=[];
foreach($repairFlags as $flag)$previousFlags[$flag]=getenv($flag);
putenv('APP_ENV=production');putenv('PRODUCTION_ENABLED=1');
putenv('IRADIOS_PRODUCTION_REJECTED_HPP_REPAIR_ENABLED=0');
putenv('IRADIOS_PRODUCTION_REJECTED_HPP_AUTO_ENABLED=1');
check(!\Iluma\Gateway\RejectedHppRepair::productionApplyAllowed()
 && !\Iluma\Gateway\RejectedHppRepair::productionAutoAllowed(),
 'Production repair stays locked without independent financial opt-in');
putenv('IRADIOS_PRODUCTION_REJECTED_HPP_REPAIR_ENABLED=1');
putenv('IRADIOS_PRODUCTION_REJECTED_HPP_AUTO_ENABLED=0');
check(\Iluma\Gateway\RejectedHppRepair::productionApplyAllowed()
 && !\Iluma\Gateway\RejectedHppRepair::productionAutoAllowed(),
 'Manual repair approval does not activate automatic worker');
foreach($previousFlags as $flag=>$value){
 if($value===false)putenv($flag);else putenv($flag.'='.$value);
}
check(str_contains($repairSource,'FINANCIAL_EVENT_EXISTS') &&
 str_contains($repairSource,'LEGACY_STAGE_ROUTED_REVIEW') &&
 str_contains($repairSource,'BROADCASTER_MANDATE_HAS_EVIDENCE') &&
 str_contains($repairSource,'APPLICATION_NOT_IRADIOS') &&
 str_contains($repairSource,'NEXI_404'),
 'Rejected-HPP evaluation guards financial evidence and tenant provenance');
check(str_contains($repairCli,'--confirm-live') &&
 str_contains($repairCli,'APPLY_TARGET_NOT_UNIQUE') &&
 str_contains($repairCli,'--include-legacy-stage-routed') &&
 str_contains($repairCli,'nexiOrderIsAbsent(') &&
 str_contains($repairCli,'GET_ONLY'),
 'Production repair CLI requires a confirmed single-target scope');
check(str_contains($workerSource,'RejectedHppRepair::productionAutoAllowed()'),
 'Production worker auto-repair requires independent approval');

check(str_contains($paymentSource,"strtotime((string)$" . "p['created_at'].' UTC')>time()-120") &&
 str_contains($paymentSource,"$" . "p['operation_id']!==null") &&
 str_contains($paymentSource,"status='failed'"),
 'Rejected checkout closure requires a 2 minute quarantine and no known operation');
check(str_contains($paymentSource,"status='cancelled',cancelled_at=UTC_TIMESTAMP()") &&
 str_contains($paymentSource,"last_error_code='HPP_REJECTED_400'"),
 'Rejected pending MIT agreement is disabled only after documented safe provider reconciliation');

check(\Iluma\Gateway\RecurringMandates::hasProviderSpacing(
  '2026-11-10 18:20:00','2026-12-08 18:20:00'),
 'MIT spacing is satisfied exactly 28 full days after capture');
check(!\Iluma\Gateway\RecurringMandates::hasProviderSpacing(
  '2026-11-10 18:20:00','2026-11-10 20:20:00') &&
 !\Iluma\Gateway\RecurringMandates::hasProviderSpacing(
  '2026-11-10 18:20:00','2026-12-08 18:19:59'),
 'Backdating renewal due cannot trigger an early scheduled MIT debit');
$adminSource=file_get_contents(dirname(__DIR__).'/src/AdminApplication.php');
$subscriptionView=file_get_contents(dirname(__DIR__).'/src/RecurringAdmin.php');
check(str_contains($adminSource,'RecurringAdmin::index(') &&
 str_contains($adminSource,'RecurringAdmin::detail(') &&
 str_contains($subscriptionView,'FROM recurring_mandates m') &&
 str_contains($subscriptionView,'FROM recurring_cycles cy') &&
 str_contains($subscriptionView,'JOIN customers c'),
 'Gateway subscriptions admin reads actual Nexi MIT mandates and payment cycles');
check(str_contains($subscriptionView,'ENT_QUOTES|ENT_SUBSTITUTE') &&
 str_contains($subscriptionView,'substr((string)$m[\'contract_id\'],-6)') &&
 !str_contains($subscriptionView,'NexiClient::'),
 'Admin escapes customer data, masks Nexi contract IDs and never calls provider');
$managementSource=file_get_contents(dirname(__DIR__).'/bin/manage.php');
$previewStart=strpos($managementSource,"if(\$command==='recurring-preview-staging')");
$previewEnd=strpos($managementSource,"if(\$command==='recurring-doctor-staging')");
check($previewStart!==false && $previewEnd!==false && $previewEnd>$previewStart,
 'Read-only future-date MIT staging preview CLI exists');
$preview=substr($managementSource,$previewStart,$previewEnd-$previewStart);
check(str_contains($preview,'READ-ONLY MIT PREVIEW (NO CHARGE)') &&
 str_contains($preview,'CHARGES EXECUTED BY THIS COMMAND: ZERO') &&
 !preg_match('/\\b(?:UPDATE|INSERT|DELETE|startMit|chargeDue|->request)\\s*\\(/i',$preview),
 'Virtual-time preview performs no DB writes or Nexi POST');
check(str_contains(file_get_contents(dirname(__DIR__).'/src/RecurringMandates.php'),
 'self::hasProviderSpacing('),
 'Scheduler enforces provider minimum spacing independently of admin virtual time');

$oneDayAgo=gmdate('Y-m-d\\TH:i:s.000\\Z',time()-86400);
$thirtyDaysAgo=gmdate('Y-m-d\\TH:i:s.000\\Z',time()-30*86400-60);
check(\Iluma\Gateway\Winback::expiredAt(
 ['winback_expired_at'=>$oneDayAgo],'staging','L-WINBACK-199','iradiosgr')!=='',
 'Signed iRadios one-time winback can enter within 30 days of expired paid period');
try{\Iluma\Gateway\Winback::expiredAt(
 ['winback_expired_at'=>$thirtyDaysAgo],'staging','L-WINBACK-199','iradiosgr');
 $expiredAccepted=true;}catch(\Iluma\Gateway\HttpError){$expiredAccepted=false;}
check(!$expiredAccepted,'Winback expires permanently 30 days after paid through');
try{\Iluma\Gateway\Winback::expiredAt(
 ['winback_expired_at'=>$oneDayAgo],'production','L-WINBACK-199','iradiosgr');
 $prodAccepted=true;}catch(\Iluma\Gateway\HttpError){$prodAccepted=false;}
check(!$prodAccepted,'No production discount checkout via staging offer');
try{\Iluma\Gateway\Winback::expiredAt(
 ['winback_expired_at'=>$oneDayAgo],'staging','L-PREM','iradiosgr');
 $spoofAccepted=true;}catch(\Iluma\Gateway\HttpError){$spoofAccepted=false;}
check(!$spoofAccepted,'Regular Premium checkout forbids forged promotional expiry');
check(\Iluma\Gateway\RecurringMandates::choice([
 'auto_renew'=>true,
 'recurring_consent_version'=>\Iluma\Gateway\RecurringMandates::CONSENT
 ],'staging','L-WINBACK-199','iradiosgr'),
 'Explicit MIT consent supports discounted first HPP and later normal 399-cent renewals');
$winbackSource=file_get_contents(dirname(__DIR__).'/src/Winback.php');
$claimSchema=file_get_contents(dirname(__DIR__).'/database/migrations/20261110_staging_winback_199.sql');
check(str_contains($claimSchema,'UNIQUE KEY one_winback_per_account(application_id,customer_id)') &&
 str_contains($winbackSource,'redeemed_at=UTC_TIMESTAMP()') &&
 str_contains($winbackSource,'payment_status') &&
 str_contains($winbackSource,'\'failed\',\'canceled\''),
 'Discount redemption is unique per account and consumed only on provider-confirmed capture');
$paymentSource=file_get_contents(dirname(__DIR__).'/src/Payments.php');
check(str_contains($paymentSource,'Winback::captured($db,$p[\'id\'])') &&
 str_contains($paymentSource,'Winback::reserve(') &&
 str_contains($paymentSource,'Winback::preflight('),
 'Payment ledger atomically reserves one offer and marks it used on paid reconciliation');
$cli=file_get_contents(dirname(__DIR__).'/bin/manage.php');
check(str_contains($cli,"if(\$command==='migrate-staging-winback')") &&
 str_contains($cli,'L-WINBACK-199') &&
 str_contains($cli,'199'),
 'Staging-only migration installs exactly 199-cent product and claim ledger');
$mitSource=file_get_contents(dirname(__DIR__).'/src/RecurringMandates.php');
check(str_contains($mitSource,"sku='L-PREM' AND amount_cents=399") &&
 str_contains($mitSource,'$regular[\'id\']') &&
 str_contains($mitSource,'One irreversible provider attempt'),
 'After first 199-cent HPP, every MIT renewal uses normal L-PREM at 399 cents');
check(str_contains($paymentSource,'INITIAL_PAYMENT_FAILED') &&
 str_contains($paymentSource,"status IN ('pending','verification_pending')"),
 'Failed first MIT HPP releases pending consent but not unknown payment attempts');
// Advertising has a distinct, staging-only, variable-price checkout contract.
$oldAdvertisingFlag=getenv('ADVERTISING_SANDBOX_ENABLED');
$oldAppEnv=getenv('APP_ENV');$oldProdFlag=getenv('PRODUCTION_ENABLED');
putenv('APP_ENV=development');putenv('PRODUCTION_ENABLED=0');
putenv('ADVERTISING_SANDBOX_ENABLED=1');
$adCredential=['environment'=>'staging','slug'=>'iradiosgr','application_id'=>1];
$advertising=['product_sku'=>'ADV-BUDGET',
 'customer_id'=>'iradios-stage-'.str_repeat('a',24),
 'campaign_id'=>str_repeat('b',24),'quote_id'=>str_repeat('c',24),
 'amount_cents'=>12345,'currency'=>'EUR'];
check(\Iluma\Gateway\AdvertisingBudget::permitted($adCredential),
 'Only signed iRadios staging customer may use variable advertising budget');
$scope=\Iluma\Gateway\AdvertisingBudget::choice($advertising,$adCredential);
check(($scope['amount_cents']??null)===12345&&
 ($scope['campaign_id']??null)===str_repeat('b',24)&&
 ($scope['quote_id']??null)===str_repeat('c',24),
 'Quote, campaign and exact payable cents are bound to checkout');
check(\Iluma\Gateway\AdvertisingBudget::choice([
 'product_sku'=>'L-PREM'],$adCredential)===null,
 'Fixed-price Listener SKU never enters advertising budget flow');
foreach([
 ['amount_cents'=>0],['amount_cents'=>100000001],
 ['amount_cents'=>123.45],['campaign_id'=>'bad'],['quote_id'=>'bad'],
 ['customer_id'=>'another-app-account'],['auto_renew'=>true],
 ['use_saved_card'=>true],['currency'=>'USD']
] as $patch){
 $refused=false;
 try{\Iluma\Gateway\AdvertisingBudget::choice(array_replace($advertising,$patch),$adCredential);}
 catch(\Iluma\Gateway\HttpError){$refused=true;}
 check($refused,'Advertising rejects invalid amount, ID, currency or recurring checkout');
}
check(!\Iluma\Gateway\AdvertisingBudget::permitted([
 'environment'=>'production','slug'=>'iradiosgr','application_id'=>1]),
 'Variable Advertising checkout is impossible on production credentials');
check(!\Iluma\Gateway\AdvertisingBudget::permitted([
 'environment'=>'staging','slug'=>'other-app','application_id'=>1]),
 'Variable Advertising checkout does not authorize other applications');
putenv('ADVERTISING_SANDBOX_ENABLED=0');
check(!\Iluma\Gateway\AdvertisingBudget::permitted($adCredential),
 'Advertising checkout remains disabled unless staging flag is explicit');
check(str_contains(\Iluma\Gateway\CheckoutReturn::url('staging','/checkout/result',
 'IL'.str_repeat('A',24),'ADV-BUDGET'),'/backoffice/agency/campaigns?gateway_return=result'),
 'Nexi Advertising return goes into the Agency Backoffice without marking paid');
$adPaymentSource=file_get_contents(dirname(__DIR__).'/src/Payments.php');
check(str_contains($adPaymentSource,'AdvertisingBudget::record(')&&
 str_contains($adPaymentSource,'AdvertisingBudget::eventScope(')&&
 str_contains($adPaymentSource,'$checkoutAmount'),
 'Payment ledger preserves immutable advertising quote scope and signed event');
$adMigration=file_get_contents(dirname(__DIR__).'/database/migrations/20261113_advertising_budget.sql');
check(str_contains($adMigration,'UNIQUE KEY one_advertising_payment_per_quote(application_id,quote_id)'),
 'A second request key cannot create another Nexi attempt for the same quote');
if($oldAdvertisingFlag===false)putenv('ADVERTISING_SANDBOX_ENABLED');
else putenv('ADVERTISING_SANDBOX_ENABLED='.$oldAdvertisingFlag);
if($oldAppEnv===false)putenv('APP_ENV');else putenv('APP_ENV='.$oldAppEnv);
if($oldProdFlag===false)putenv('PRODUCTION_ENABLED');else putenv('PRODUCTION_ENABLED='.$oldProdFlag);
// The retired one-euro test checkout must never be executable again.
// Preserve browser-return isolation for old receipts and keep historical
// database records intact (neither a new payment nor an entitlement).
$internalOrder='IL'.str_repeat('A',24);
$adminWithoutTest=file_get_contents(dirname(__DIR__).'/src/AdminApplication.php');
check(!is_file(dirname(__DIR__).'/src/AdminTestCenter.php') &&
  $adminWithoutTest!==false &&
  !str_contains($adminWithoutTest,'test-center') &&
  !str_contains($adminWithoutTest,'test-prepare') &&
  !str_contains($adminWithoutTest,'test-start') &&
  !str_contains($adminWithoutTest,'test-new'),
  'Retired operator checkout code and Admin route are absent');
check(\Iluma\Gateway\CheckoutReturn::url('production','/checkout/result',$internalOrder,
  'ILUMA-TEST-100',false,'iluma-internal')
  ==='https://gateway.iluma.gr/ilm-admin/?p=payments',
  'Historical internal return stays in private Gateway payments, never iRadios');
$internalCheckoutDenied=false;
try {
 \Iluma\Gateway\ApplicationRouting::assertCheckout([
  'environment'=>'staging','application_id'=>1,'slug'=>'iluma-internal'
 ]);
} catch (\Iluma\Gateway\HttpError $e) {
 $internalCheckoutDenied=$e->status===403;
}
check($internalCheckoutDenied,
 'Retired internal application cannot create checkout, even via privileged credentials');
// Audit ALL active PHP entrypoints, not just known references. Missing class
// references are otherwise invisible to php -l until that branch executes.
$legacyReferenceFound=false;
foreach(['src','public','bin'] as $activeDir){
 foreach(glob(dirname(__DIR__).'/'.$activeDir.'/*.php')?:[] as $file){
  $source=file_get_contents($file);
  if($source===false || str_contains($source,'AdminTestCenter::')){
   $legacyReferenceFound=true;break 2;
  }
 }
}
check(!$legacyReferenceFound,
 'All active PHP entrypoints are free of removed internal checkout class references');

check(\Iluma\Gateway\CheckoutReturn::url('production','/checkout/result',$internalOrder,
  'L-PREM',false,'iradiosgr')==='https://play.iradios.gr/premium?gateway_return=result&order='.$internalOrder,
  'iRadios production return remains unchanged');

echo "$tests isolated offline tests passed. No Nexi transaction was executed.\n";
