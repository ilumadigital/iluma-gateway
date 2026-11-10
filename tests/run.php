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
check(\Iluma\Gateway\CheckoutReturn::url('staging','/checkout/result','IL'.str_repeat('A',24)) ===
 'https://stage.iradios.gr/premium?gateway_return=result&order=IL'.str_repeat('A',24),'Gateway returns to iRadios staging after Nexi');
check(\Iluma\Gateway\CheckoutReturn::url('production','/checkout/cancel','IL'.str_repeat('B',24)) ===
 'https://play.iradios.gr/premium?gateway_return=cancel&order=IL'.str_repeat('B',24),'Production callback targets production iRadios only');
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
$adminSource=file_get_contents(dirname(__DIR__).'/public/admin.php');
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
check((bool)preg_match('/^ILC[A-F0-9]{24}$/D',$contract),'No PAN data; contract ID is safe and unique shaped');
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
check((bool)preg_match('/^ILM[A-F0-9]{24}$/D',$mitContract) &&
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
check(str_contains(file_get_contents(dirname(__DIR__).'/src/NexiClient.php'),"IL[CM][A-F0-9]{24}"),
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
echo "$tests isolated offline tests passed. No Nexi transaction was executed.\n";
