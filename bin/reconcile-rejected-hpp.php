<?php
declare(strict_types=1);
// Operator-only HPP repair. DRY_RUN by default; no new HPP, MIT, or refund.
// Execute inside the existing PHP container: php bin/reconcile-rejected-hpp.php production
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/src/bootstrap.php';

use Iluma\Gateway\{Config,Db,Payments,RejectedHppRepair};

try{
 $env=$argv[1]??'';
 if(!in_array($env,['staging','production'],true))throw new RuntimeException('INVALID_ENVIRONMENT');
 $limit=50;$apply=false;$confirmLive=false;$confirmStaging=false;
 $includeLegacy=false;$suffix=null;$seen=[];
 foreach(array_slice($argv,2) as $arg){
  $key=explode('=',$arg,2)[0];
  if(isset($seen[$key]))throw new RuntimeException('DUPLICATE_ARGUMENT');
  $seen[$key]=true;
  if(preg_match('/^--limit=([0-9]{1,3})$/D',$arg,$m))$limit=(int)$m[1];
  elseif(preg_match('/^--account-suffix=([a-f0-9]{8})$/D',$arg,$m))$suffix=$m[1];
  elseif($arg==='--apply')$apply=true;
  elseif($arg==='--confirm-live')$confirmLive=true;
  elseif($arg==='--confirm-staging')$confirmStaging=true;
  elseif($arg==='--include-legacy-stage-routed')$includeLegacy=true;
  else throw new RuntimeException('INVALID_ARGUMENT');
 }
 if($limit<1||$limit>100)throw new RuntimeException('INVALID_LIMIT');
 if(!RejectedHppRepair::permittedEnvironment($env))
  throw new RuntimeException('ENVIRONMENT_NOT_ALLOWED');
 // Write gates are checked before ANY DB/provider lookup.
 if($apply){
  if($env==='production' && (!$confirmLive || $confirmStaging ||
    !RejectedHppRepair::productionApplyAllowed()))
   throw new RuntimeException('PRODUCTION_APPLY_NOT_APPROVED');
  if($env==='staging' && (!$confirmStaging || $confirmLive))
   throw new RuntimeException('STAGING_APPLY_NOT_CONFIRMED');
  // No bulk live writes. One uniquely identified original checkout per invocation.
  if($suffix===null)throw new RuntimeException('APPLY_REQUIRES_ACCOUNT_SUFFIX');
 }
 if($confirmLive&&!$apply || $confirmStaging&&!$apply ||
    ($includeLegacy && ($env!=='production'||$suffix===null)))
  throw new RuntimeException('INVALID_CONFIRMATION_SCOPE');

 $db=Db::get($env);
 $rows=RejectedHppRepair::listCandidates($env,$limit,$suffix);
 if($apply && count($rows)!==1)throw new RuntimeException('APPLY_TARGET_NOT_UNIQUE');
 $summary=['environment'=>$env,'mode'=>$apply?'APPLY':'DRY_RUN',
  'inspected'=>0,'ready'=>0,'repaired'=>0,'review'=>0,'deferred'=>0,'errors'=>0];
 foreach($rows as $row){
  $summary['inspected']++;
  $reason=RejectedHppRepair::eligibility($db,$env,$row,$includeLegacy,false);
  $state='REVIEW';
  if($reason==='READY'){
   $provider=RejectedHppRepair::nexiOrderIsAbsent($env,(string)$row['order_id']);
   if($provider==='NEXI_404'){
    $reason='VERIFIED_HPP_400_AND_NEXI_404';
    $state='READY';
    if($apply){
     // Inside Payments, all DB/mandate/payment evidence is rechecked in one
     // transaction, with compare-and-set; no provider POST.
     if(Payments::reconcileRejectedHpp404(
       $env,(string)$row['order_id'],$includeLegacy)){
      $state='REPAIRED';
     }else{$state='DEFERRED';$reason='STATE_CHANGED_OR_LOCKED';}
    }
   }else{
    $reason=$provider;
    $state=$provider==='NEXI_LOOKUP_UNAVAILABLE'?'DEFERRED':'REVIEW';
   }
  }
  $summary[strtolower($state)]++;
  echo json_encode([
   'sku'=>(string)$row['sku'],
   'account_suffix'=>substr((string)$row['external_user_id'],-8),
   'order_suffix'=>substr((string)$row['order_id'],-6),
   'stage_routed'=>$env==='production' && !empty($row['iradios_stage_routed']),
   'state'=>$state,'reason'=>$reason
  ],JSON_UNESCAPED_SLASHES).PHP_EOL;
 }
 echo json_encode(['summary'=>$summary,'nexi'=>'GET_ONLY',
  'new_charges'=>0],JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch(Throwable $error){
 $reason=$error->getMessage();
 if(!preg_match('/^[A-Z0-9_]{3,80}$/D',$reason))$reason='REPAIR_UNAVAILABLE';
 fwrite(STDERR,'STOP: '.$reason.PHP_EOL);
 exit(1);
}
