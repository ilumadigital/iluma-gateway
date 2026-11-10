<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Iluma\Gateway\{Worker,Db,Config};
if(PHP_SAPI!=='cli')exit(1);
$env=$argv[1]??'';if(!in_array($env,['staging','production'],true))die("Usage: php bin/work.php staging|production\n");
if($env==='production'&&!Config::liveAllowed())die("Production locked\n");
$lock=fopen(sys_get_temp_dir().'/iluma-worker-'.$env.'.lock','c');
if(!flock($lock,LOCK_EX|LOCK_NB))die("Worker already running\n");
// Flush already-confirmed payment events FIRST. Slow reconciliation of old unknown
// orders must never prevent the iRadios subscriber from receiving a paid event.
Worker::deliver($env);
Worker::reconcile($env);
// Dispatch newly confirmed events discovered during reconciliation as well.
Worker::deliver($env);
// Tokenization verification/revocation has NO ability to create a payment.
\Iluma\Gateway\RecurringMandates::pendingVerification($env,10);
\Iluma\Gateway\RecurringMandates::pendingDeactivation($env,10);
\Iluma\Gateway\BroadcasterMandates::pendingVerification($env,10);
\Iluma\Gateway\BroadcasterMandates::pendingDeactivation($env,10);
// One-shot provider MIT is fully disabled unless BOTH staging rollout flags
// have been explicitly enabled after real sandbox UAT. No live MIT path.
\Iluma\Gateway\RecurringMandates::chargeDue($env,5);
\Iluma\Gateway\BroadcasterMandates::chargeDue($env,5);
Worker::deliver($env);
\Iluma\Gateway\SavedCards::syncPending($env,10);
\Iluma\Gateway\SavedCards::processRevocations($env,10);
Db::exec(Db::get('registry'),'DELETE FROM api_nonces WHERE expires_at<UTC_TIMESTAMP()');
