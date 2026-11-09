<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Iluma\Gateway\{Security,PaymentState,Config,NexiClient,EnvLoader,AdminContext};
putenv('MASTER_KEY_B64='.base64_encode(str_repeat('K',32)));
$tests=0;
function check(bool $ok,string $name):void {global $tests;$tests++;if(!$ok){fwrite(STDERR,"FAIL $name\n");exit(1);}echo "PASS $name\n";}
$encoded=Security::encrypt('sensitive provider key');check($encoded!=='sensitive provider key'&&Security::decrypt($encoded)==='sensitive provider key','Sodium secretbox roundtrip');
$canonical=Security::signature('secret','1783585200','00000000000000000000000000000000','POST','/api/v1/payments/checkout','{"id":1}');
check(strlen($canonical)===64,'HMAC length');check($canonical!==Security::signature('secret','1783585200','00000000000000000000000000000000','POST','/api/v1/payments/checkout','{"id":2}'),'payload modification invalidates HMAC');
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
putenv('NEXI_STAGING_BASE=https://xpaysandbox.nexigroup.com/api/phoenix-0.0/psp');putenv('NEXI_PRODUCTION_BASE=https://xpay.nexigroup.com/api/phoenix-0.0/psp');
check(str_contains(Config::base('staging'),'xpaysandbox'),'staging endpoint pinned');
putenv('NEXI_STAGING_BASE=https://xpay.nexigroup.com/api/phoenix-0.0/psp');
try{Config::base('staging');$bad=false;}catch(\RuntimeException){$bad=true;}check($bad,'no staging to live fallback');
$base=sys_get_temp_dir().'/iluma-env-test-'.bin2hex(random_bytes(6));
$project=$base.'/domains/example.test/public_html/site';
$private=$base.'/domains/example.test/private';
mkdir($project,0700,true);mkdir($private,0700,true);
$file=$private.'/iluma-gateway.env';
file_put_contents($file,"REGISTRY_USER=test_user\nSTAGING_PASSWORD='a=b#c'\n");chmod($file,0600);
putenv('REGISTRY_USER');putenv('STAGING_PASSWORD');
check(EnvLoader::privateFilePath($project)===$file,'Hostinger env path outside public_html');
check(EnvLoader::load($project),'Hostinger private env loaded');
check(getenv('STAGING_PASSWORD')==='a=b#c','private password special characters');
chmod($file,0644);
try{EnvLoader::load($project);$bad=false;}catch(\RuntimeException){$bad=true;}
check($bad,'unsafe environment permissions rejected');
unlink($file);rmdir($private);rmdir($project);rmdir(dirname($project));rmdir(dirname(dirname($project)));rmdir(dirname(dirname(dirname($project))));
putenv('REGISTRY_USER');putenv('STAGING_PASSWORD');
$routing=file_get_contents(dirname(__DIR__).'/.htaccess');
$adminSource=file_get_contents(dirname(__DIR__).'/public/admin.php');
check($routing!==false && str_contains($routing,'^ilm-admin/?$ public/admin.php'),'hostinger admin path rewrite');
check(str_contains($routing,'^ilm-admin/admin\.css$ public/admin.css'),'hostinger admin stylesheet rewrite');
check($adminSource!==false && str_contains($adminSource,'/ilm-admin/?p='),'admin navigation uses new path');
check(str_contains($adminSource,'/ilm-admin/admin.css'),'admin stylesheet uses new path');
check(!str_contains($adminSource,"Config::get('ADMIN_HOST')"),'admin uses gateway host');
check(!str_contains($adminSource,"header('Location: /')"),'admin redirects stay in protected path');
check(AdminContext::environment([])==='staging','admin login page uses staging without a session environment');
check(AdminContext::environment(['view_env'=>'staging'])==='staging','admin staging selection');
check(AdminContext::environment(['view_env'=>'production'])==='production','admin production selection');
check(AdminContext::environment(['view_env'=>'unexpected'])==='staging','unknown admin display environment falls back to staging');
$adminJs=file_get_contents(dirname(__DIR__).'/public/admin.js');
$adminCss=file_get_contents(dirname(__DIR__).'/public/admin.css');
check($adminJs!==false && str_contains($adminJs,'requestSubmit'),'step-up popup submits after approval');
check($adminCss!==false && str_contains($adminCss,'.mfa-dialog'),'MFA confirmation sheet styles present');
check(str_contains($routing,'^ilm-admin/admin\.js$ public/admin.js'),'admin script routed correctly');
check(str_contains($adminSource,"script-src 'self'"),'strict same-origin script CSP');
check(str_contains($adminSource,'password_verify($pw,$u[\'passhash\']);'),'login verifies password without TOTP');
check(str_contains($adminSource,'function requireStepUp('),'server-side MFA guard exists');
check(substr_count($adminSource,'requireStepUp($reg,')>=4,'sensitive actions and CSV export have MFA guard');
check(str_contains($adminSource,'MFA required for export'),'CSV downloads require verified POST');
check(str_contains($adminSource,'data-stepup='),'sensitive admin forms use action confirmation');
$nexiSource=file_get_contents(dirname(__DIR__).'/src/NexiClient.php');
check($nexiSource!==false && str_contains($nexiSource,"if(\$this->environment==='staging')\$session['captureType']='IMPLICIT';"),'Sandbox HPP explicitly requests implicit capture');
$recurrence=\Iluma\Gateway\SubscriptionPilot::recurrence('ILC'.str_repeat('A',24));
check($recurrence['action']==='CONTRACT_CREATION' && $recurrence['contractType']==='MIT_SCHEDULED' &&
 $recurrence['contractFrequency']==='28','Staging subscription pilot prepares Nexi contract');
check(\Iluma\Gateway\SubscriptionPilot::reference('listener-001')==='listener-001','Staging subscription test reference');
try{\Iluma\Gateway\SubscriptionPilot::reference('../bad');$bad=false;}catch(\InvalidArgumentException){$bad=true;}
check($bad,'Staging subscription rejects unsafe reference');
$pilotSource=file_get_contents(dirname(__DIR__).'/src/SubscriptionPilot.php');
check($pilotSource!==false && str_contains($pilotSource,"'renewal_enabled'=>false") &&
 str_contains($pilotSource,"Db::get('staging')"),'Subscription pilot is staging-only with renewals off');
check(str_contains($pilotSource,"'sandbox-subscription-'.\$ref") &&
 str_contains($pilotSource,'FOR UPDATE'),'Staging subscription uses stable idempotency key');
check(str_contains($pilotSource,'contractsForCustomer'),'Nexi contract provider lookup implemented');
check(str_contains($nexiSource,"'/api/v1/contracts/customers/'"),'Nexi contract lookup route');
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
echo "$tests isolated offline tests passed. No Nexi transaction was executed.\n";
