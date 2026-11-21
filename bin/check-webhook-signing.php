<?php
declare(strict_types=1);
// Safe read-only HMAC alignment check for the signing key used by the outbox.
// A proof is NOT a key, password or payment identifier. No Nexi calls/writes.
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/src/bootstrap.php';

use Iluma\Gateway\{Db,Security};
$env=$argv[1]??'';
if(!in_array($env,['staging','production'],true)||count($argv)!==2){
 fwrite(STDERR,"Usage: php bin/check-webhook-signing.php staging|production\n");exit(2);
}
$record=Db::one(Db::get('registry'),"SELECT c.secret_box
  FROM application_credentials c
  JOIN applications a ON a.id=c.application_id
  WHERE a.slug='iradiosgr' AND a.enabled=1
    AND c.environment=? AND c.enabled=1
  ORDER BY c.created_at DESC LIMIT 1",[$env]);
if(!$record){fwrite(STDERR,"FAIL: no enabled Gateway signing credential for $env\n");exit(1);}
$proof=substr(hash_hmac('sha256','iradios-webhook-key-alignment-v1',
 Security::decrypt($record['secret_box'])),0,24);
echo strtoupper($env)." GATEWAY_OUTBOX_HMAC_PROOF=$proof\n";
echo "READ_ONLY: compare with iRadios receiver proof for the SAME environment.\n";
echo "PRODUCTION LEGACY ROUTE: only review existing records; never copy live credentials to staging.\n";
