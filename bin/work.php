<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Iluma\Gateway\{Worker,Db,Config};
if(PHP_SAPI!=='cli')exit(1);
$env=$argv[1]??'';if(!in_array($env,['staging','production'],true))die("Usage: php bin/work.php staging|production\n");
if($env==='production'&&!Config::liveAllowed())die("Production locked\n");
// An independent, read-only fingerprint records unexpected PHP source edits.
// Do not auto-restore code: that would destroy forensic evidence. The worker
// must continue to deliver already confirmed, signed payment events.
$projectRoot=dirname(__DIR__);
\Iluma\Gateway\SourceIntegrity::logIfDrift($projectRoot,'worker-start');
register_shutdown_function(static function () use ($projectRoot): void {
    \Iluma\Gateway\SourceIntegrity::logIfDrift($projectRoot,'worker-shutdown');
});

// This worker signs outgoing events using libsodium. Fail clearly instead of
// silently leaving queued payment events pending on the wrong Hostinger PHP CLI.
if (!extension_loaded('sodium')) {
    error_log('ILUMA WORKER: PHP sodium extension missing; staging webhook delivery unavailable in this CLI');
    fwrite(STDERR,"FAIL: PHP sodium extension missing in worker CLI; select a PHP binary with sodium enabled\n");
    exit(1);
}
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
