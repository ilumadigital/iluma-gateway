<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Iluma\Gateway\{Security,PaymentState,Config,NexiClient,EnvLoader};
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
echo "$tests isolated offline tests passed. No Nexi transaction was executed.\n";
