<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Iluma\Gateway\AdvertisingBudget;
use Iluma\Gateway\CheckoutReturn;
use Iluma\Gateway\HttpError;
function assertCase(bool $value,string $name):void{
 if(!$value){fwrite(STDERR,"FAIL $name\n");exit(1);}
 echo "PASS $name\n";
}
putenv('APP_ENV=development');
putenv('PRODUCTION_ENABLED=0');
$credential=['environment'=>'staging','slug'=>'iradiosgr','application_id'=>1];
$base=['product_sku'=>'ADV-BUDGET','customer_id'=>'iradios-stage-'.str_repeat('a',24),
 'campaign_id'=>str_repeat('b',24),'quote_id'=>str_repeat('c',24),
 'amount_cents'=>12500,'currency'=>'EUR'];
assertCase(AdvertisingBudget::choice($credential,$base,'ADV-BUDGET')['amount_cents']===12500,
 'Signed staging variable budget is accepted');
assertCase(AdvertisingBudget::choice($credential,$base,'L-PREM')===null,
 'Existing products stay on fixed-price path');
foreach([
 ['amount_cents'=>'12500'],['amount_cents'=>-100],['amount_cents'=>0],
 ['currency'=>'USD'],['auto_renew'=>true],['save_card'=>true],
 ['quote_id'=>'bad'],['campaign_id'=>'bad']
] as $patch) {
 $rejected=false;
 try{AdvertisingBudget::choice($credential,array_merge($base,$patch),'ADV-BUDGET');}
 catch(HttpError){$rejected=true;}
 assertCase($rejected,'Invalid advertising budget or consent rejected');
}
$rejected=false;
try{AdvertisingBudget::choice(['environment'=>'production','slug'=>'iradiosgr'],
 $base,'ADV-BUDGET');}catch(HttpError){$rejected=true;}
assertCase($rejected,'Production advertising budget is disabled');
$order='IL'.str_repeat('A',24);
assertCase(str_contains(CheckoutReturn::url('staging','/checkout/result',$order,'ADV-BUDGET'),
 '/agency/v2/campaigns?gateway_return=result'),'Advertising return is agency-scoped');

$sql=file_get_contents(dirname(__DIR__).'/database/migrations/2026-11-staging-advertising-budget.sql');
assertCase(is_string($sql)&&
 str_contains($sql,'SET @iradios_staging_application_id = NULL;')&&
 str_contains($sql,'p.application_id=@iradios_staging_application_id')&&
 !str_contains($sql,"FROM products WHERE sku='L-PREM' AND enabled=1"),
 'Staging ADV-BUDGET product migration requires a verified app ID');
