<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Iluma\Gateway\{Worker,Db,Config};
if(PHP_SAPI!=='cli')exit(1);
$env=$argv[1]??'';if(!in_array($env,['staging','production'],true))die("Usage: php bin/work.php staging|production\n");
if($env==='production'&&!Config::liveAllowed())die("Production locked\n");
$lock=fopen(sys_get_temp_dir().'/iluma-worker-'.$env.'.lock','c');
if(!flock($lock,LOCK_EX|LOCK_NB))die("Worker already running\n");
Worker::reconcile($env);Worker::deliver($env);
Db::exec(Db::get('registry'),'DELETE FROM api_nonces WHERE expires_at<UTC_TIMESTAMP()');
