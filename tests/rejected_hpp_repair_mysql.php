<?php
declare(strict_types=1);
// Synthetic disposable MySQL CI fixture only. This directly exercises the
// transaction after SIMULATING a previously verified Nexi 404. No provider I/O.
// Never invoke on the VPS. CI-specific environment and database are mandatory.
if(PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
   getenv('BILLING_MYSQL_CONTAINER')===false)
 exit(1);
require dirname(__DIR__).'/src/bootstrap.php';

use Iluma\Gateway\{Db,Payments,RejectedHppRepair};

function checkRepair(bool $ok,string $label):void {
 if(!$ok)throw new RuntimeException('FIXTURE_FAILED_'.$label);
 echo 'PASS: '.$label.PHP_EOL;
}
if(getenv('APP_ENV')!=='production'||getenv('PRODUCTION_ENABLED')!=='1')
 throw new RuntimeException('CI_ENV_GUARD');
$db=Db::get('production');
if($db->query('SELECT DATABASE()')->fetchColumn()!=='iluma_production')
 throw new RuntimeException('CI_DATABASE_GUARD');
$registry=Db::get('registry');
$app=Db::one($registry,"SELECT id FROM applications WHERE slug='iradiosgr'");
if(!$app)throw new RuntimeException('APP_NOT_FOUND');
$appId=(int)$app['id'];
$product=Db::one($db,"SELECT id FROM products WHERE application_id=? AND sku='B-PREM'",[$appId]);
if(!$product)throw new RuntimeException('BPREM_NOT_FOUND');

$customerId=str_repeat('1',32);
$account='iradios-stage-'.str_repeat('a',24);
$paymentId=str_repeat('2',32);
$mandateId=str_repeat('3',32);
$order='IL'.str_repeat('A',24);
$request='iradios-bstage-'.str_repeat('a',24).'-11111111-1111-4111-8111-111111111111';
Db::exec($db,'INSERT INTO customers(id,application_id,external_user_id,email,name)
 VALUES(?,?,?, ?,?)',[$customerId,$appId,$account,'fixture@example.invalid','Fixture']);
Db::exec($db,"INSERT INTO payments(id,application_id,customer_id,product_id,order_id,
 request_key,request_hash,amount_cents,currency,status,created_at,iradios_stage_routed)
 VALUES(?,?,?,?,?,?,?,9900,'EUR','unknown',
 DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),0)",
 [$paymentId,$appId,$customerId,$product['id'],$order,$request,str_repeat('f',64)]);
Db::exec($db,"INSERT INTO broadcaster_mandates(
 id,application_id,customer_id,initial_payment_id,provider_customer_id,
 contract_id,sku,amount_cents,consent_version,consent_at,status)
 VALUES(?,?,?,?,?,?,'B-PREM',9900,'fixture',UTC_TIMESTAMP(),'pending')",
 [$mandateId,$appId,$customerId,$paymentId,'IR'.str_repeat('D',24),'ILM'.str_repeat('C',15)]);
Db::exec($db,"INSERT INTO payment_logs(payment_id,event_type,message_gr)
 VALUES(?,'hpp_rejected_400','Synthetic offline HPP 400')",[$paymentId]);

$row=static fn():array=>Db::one($db,'SELECT * FROM payments WHERE id=?',[$paymentId]);
putenv('IRADIOS_PRODUCTION_REJECTED_HPP_REPAIR_ENABLED=0');
putenv('IRADIOS_PRODUCTION_REJECTED_HPP_AUTO_ENABLED=1');
checkRepair(!RejectedHppRepair::productionApplyAllowed() &&
 !RejectedHppRepair::productionAutoAllowed(),'write and auto off without independent approval');
checkRepair(RejectedHppRepair::eligibility($db,'production',$row())==='READY',
 'read-only candidate is inspectable with writes disabled');
checkRepair(RejectedHppRepair::eligibility($db,'production',$row(),false,true)==='PRODUCTION_APPLY_LOCKED',
 'production CAS rejects write while approval is absent');
checkRepair(!Payments::reconcileRejectedHpp404('production',$order),
 'disabled apply cannot mutate even with synthetic 404 proof');

putenv('IRADIOS_PRODUCTION_REJECTED_HPP_REPAIR_ENABLED=1');
checkRepair(RejectedHppRepair::productionApplyAllowed() &&
 RejectedHppRepair::productionAutoAllowed(),'two independent worker gates recognized');
putenv('IRADIOS_PRODUCTION_REJECTED_HPP_AUTO_ENABLED=0');
checkRepair(!RejectedHppRepair::productionAutoAllowed(),
 'manual approval does not activate automatic worker');

Db::exec($db,'UPDATE payments SET confirmed_at=UTC_TIMESTAMP() WHERE id=?',[$paymentId]);
checkRepair(RejectedHppRepair::eligibility($db,'production',$row())==='PAYMENT_FINANCIAL_EVIDENCE',
 'payment evidence blocks release');
Db::exec($db,'UPDATE payments SET confirmed_at=NULL WHERE id=?',[$paymentId]);

Db::exec($db,"INSERT INTO outgoing_events(id,payment_id,application_id,event_type,body_json)
 VALUES(?,?,?,'payment.paid','{}')",[str_repeat('4',32),$paymentId,$appId]);
checkRepair(RejectedHppRepair::eligibility($db,'production',$row())==='FINANCIAL_EVENT_EXISTS',
 'paid outbox event blocks release');
Db::exec($db,'DELETE FROM outgoing_events WHERE payment_id=?',[$paymentId]);

Db::exec($db,"UPDATE broadcaster_mandates SET status='active' WHERE id=?",[$mandateId]);
checkRepair(RejectedHppRepair::eligibility($db,'production',$row())==='BROADCASTER_MANDATE_HAS_EVIDENCE',
 'active mandate is never released');
Db::exec($db,"UPDATE broadcaster_mandates SET status='pending' WHERE id=?",[$mandateId]);

Db::exec($db,'UPDATE payments SET iradios_stage_routed=1 WHERE id=?',[$paymentId]);
checkRepair(RejectedHppRepair::eligibility($db,'production',$row())==='LEGACY_STAGE_ROUTED_REVIEW',
 'legacy stage-routed history excluded by default');
checkRepair(RejectedHppRepair::eligibility($db,'production',$row(),true)==='READY',
 'explicit legacy flag allows verified manual evaluation');
Db::exec($db,'UPDATE payments SET iradios_stage_routed=0 WHERE id=?',[$paymentId]);

checkRepair(RejectedHppRepair::eligibility($db,'production',$row())==='READY',
 'synthetic rejected original checkout meets all strict checks');
checkRepair(Payments::reconcileRejectedHpp404('production',$order),
 'atomic CAS closes rejected original checkout after synthetic provider proof');
$p=Db::one($db,'SELECT status FROM payments WHERE id=?',[$paymentId]);
$m=Db::one($db,'SELECT status,deactivated_at,last_error_code FROM broadcaster_mandates WHERE id=?',
 [$mandateId]);
checkRepair($p['status']==='failed'&&$m['status']==='cancelled'&&
 $m['deactivated_at']!==null&&$m['last_error_code']==='HPP_REJECTED_400',
 'failed payment and cancelled mandate persist without deletion');
checkRepair(!Payments::reconcileRejectedHpp404('production',$order),
 'second repair is idempotent');
echo "PASS: disposable production-schema repair guard and transaction tests; NO NEXI REQUESTS\n";
