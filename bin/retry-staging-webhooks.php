<?php
declare(strict_types=1);
// Staging-only recovery for an outbox retry delayed by an old HMAC 401.
// DRY_RUN by default. No Nexi, checkout, refund, production DB or MIT calls.
// Apply only AFTER comparing real stage receiver + Gateway signer proofs.
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/src/bootstrap.php';
use Iluma\Gateway\{Config,Db,Worker};
$mode=$argv[1]??'';
if(!in_array($mode,['--dry-run','--apply-after-stage-hmac-pass'],true)||
 count($argv)!==2){
 fwrite(STDERR,"Usage: php bin/retry-staging-webhooks.php --dry-run|--apply-after-stage-hmac-pass\n");
 exit(2);
}
if(!Config::stagingTenantRuntimeAllowed()){
 fwrite(STDERR,"STOP: staging tenant runtime not available\n");exit(1);
}
$db=Db::get('staging');
$row=Db::one($db,"SELECT COUNT(*) AS n FROM outgoing_events WHERE status='pending'");
echo "STAGING_PENDING=".(int)($row['n']??0)."\n";
if($mode==='--dry-run'){
 echo "DRY_RUN: no modifications; no external requests.\n";exit(0);
}
// Staging only: re-arm existing failed deliveries, never fabricate a new event.
$due=Db::exec($db,"UPDATE outgoing_events SET next_attempt_at=UTC_TIMESTAMP()
 WHERE status='pending' AND attempts>0 AND next_attempt_at>UTC_TIMESTAMP()
 ORDER BY created_at ASC LIMIT 30");
echo "STAGING_REARMED=".$due."\n";
Worker::deliver('staging',30);
$after=Db::one($db,"SELECT COUNT(*) AS n FROM outgoing_events WHERE status='pending'");
echo "STAGING_PENDING_AFTER=".(int)($after['n']??0)."\n";
echo "NEXI_REQUESTS=0; PRODUCTION_WRITES=0; MIT_CHARGES=0\n";
