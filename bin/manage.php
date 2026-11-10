<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Iluma\Gateway\{Db,Security};
if(PHP_SAPI!=='cli')exit(1);
$command=$argv[1]??'';
if($command==='recurring-preview-staging'){
 if(\Iluma\Gateway\Config::get('APP_ENV')==='production')
  die("STAGING ONLY\n");
 // This command is deliberately READ ONLY. It has no Nexi client, no UPDATE,
 // no payment creation, and never changes the scheduler's real system clock.
 $at=(string)($argv[2]??gmdate('Y-m-d\TH:i:s\Z'));
 $dt=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$at,
  new \DateTimeZone('UTC'));
 if(!$dt||$dt->format('Y-m-d\TH:i:s\Z')!==$at)
  die("Usage: php bin/manage.php recurring-preview-staging YYYY-MM-DDTHH:MM:SSZ [email]\n");
 if(abs($dt->getTimestamp()-time())>366*86400)die("Preview date outside 1 year\n");
 $email=(string)($argv[3]??'');
 if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))die("Invalid email filter\n");
 $db=Db::get('staging');
 $sql="SELECT m.status,m.verified_at,m.next_due_at,m.paid_through_at,
   m.amount_cents,m.contract_type,m.cancelled_at,
   c.email,
   (SELECT MAX(px.confirmed_at) FROM payments px WHERE px.status='paid'
     AND (px.id=m.initial_payment_id OR
      px.id IN (SELECT cy.payment_id FROM recurring_cycles cy
       WHERE cy.mandate_id=m.id))) AS last_paid_at
   FROM recurring_mandates m
   JOIN customers c ON c.id=m.customer_id";
 $args=[];
 if($email!==''){$sql.=" WHERE c.email=?";$args=[$email];}
 $sql.=" ORDER BY m.created_at DESC LIMIT 25";
 $rows=Db::all($db,$sql,$args);
 $asOf=$dt->getTimestamp();
 echo "READ-ONLY MIT PREVIEW (NO CHARGE): ".$at."\n";
 $enabled=\Iluma\Gateway\Config::get('MIT_SANDBOX_EXECUTION_ENABLED')==='1' &&
  \Iluma\Gateway\Config::get('MIT_SANDBOX_UAT_APPROVED')==='1';
 echo "SANDBOX EXECUTION: ".($enabled?'APPROVED':'LOCKED')."\n";
 foreach($rows as $r){
  $next=$r['next_due_at']?strtotime($r['next_due_at'].' UTC'):null;
  $paid=$r['last_paid_at']?strtotime($r['last_paid_at'].' UTC'):null;
  $earliest=$paid!==null?$paid+28*86400:null;
  $paidThrough=$r['paid_through_at']?strtotime($r['paid_through_at'].' UTC'):null;
  $due=$r['status']==='active'&&$r['verified_at']!==null&&
    $r['cancelled_at']===null&&$next!==null&&$next<=$asOf&&
    $paidThrough!==null&&$next===$paidThrough;
  $spacing=$earliest!==null&&$asOf>=$earliest;
  echo "ACCOUNT: ".$r['email']."\n";
  echo "MIT STATE: ".$r['status']."\n";
  echo "NEXT DUE UTC: ".($r['next_due_at']??'—')."\n";
  echo "PROVIDER EARLIEST (28 days): ".($earliest?gmdate('Y-m-d H:i:s',$earliest).' UTC':'unknown')."\n";
  echo "SCHEDULE DUE IN VIRTUAL CLOCK: ".($due?'YES':'NO')."\n";
  echo "PROVIDER MIN-SPACING IN VIRTUAL CLOCK: ".($spacing?'SATISFIED':'NOT YET')."\n";
  echo "WOULD BE ELIGIBLE IF SANDBOX CHARGES WERE ENABLED: ".
   ($due&&$spacing?'YES':'NO')."\n";
  echo "CHARGES EXECUTED BY THIS COMMAND: ZERO\n";
 }
 if(!$rows)echo "NO MATCHING MANDATES\n";
 exit;
}
if($command==='recurring-doctor-staging'){
 if(\Iluma\Gateway\Config::get('APP_ENV')==='production')die("STAGING ONLY\n");
 $db=Db::get('staging');
 $table=Db::one($db,"SELECT COUNT(*) AS n FROM information_schema.tables
   WHERE table_schema=DATABASE() AND table_name='recurring_mandates'");
 $cycles=Db::one($db,"SELECT COUNT(*) AS n FROM information_schema.tables
   WHERE table_schema=DATABASE() AND table_name='recurring_cycles'");
 $schema=(int)($table['n']??0)===1&&(int)($cycles['n']??0)===1;
 $enabled=\Iluma\Gateway\Config::get('MIT_SANDBOX_EXECUTION_ENABLED')==='1';
 $approved=\Iluma\Gateway\Config::get('MIT_SANDBOX_UAT_APPROVED')==='1';
 echo "RECURRING MIT SCHEMA: ".($schema?'READY':'MISSING - run migration')."\n";
 echo "MIT AUTOMATIC CHARGES: ".($enabled&&$approved?'ENABLED - SANDBOX ONLY':'DISABLED')."\n";
 echo "SANDBOX UAT APPROVAL: ".($approved?'YES':'NO')."\n";
 if($schema){
  $statuses=Db::all($db,'SELECT status,COUNT(*) AS n FROM recurring_mandates GROUP BY status');
  foreach($statuses as $entry)
   echo 'MANDATES '.strtoupper((string)$entry['status']).': '.(int)$entry['n']."\n";
  $due=Db::one($db,"SELECT COUNT(*) AS n FROM recurring_mandates
    WHERE status='active' AND verified_at IS NOT NULL AND
    cancelled_at IS NULL AND next_due_at<=UTC_TIMESTAMP()");
  echo 'DUE MANDATES: '.(int)($due['n']??0)."\n";
 }
 echo "Read-only check. No card details or provider contract IDs printed.\n";
 exit;
}
if($command==='migrate-staging-recurring'){
 if(\Iluma\Gateway\Config::get('APP_ENV')==='production')die("STAGING ONLY\n");
 $db=Db::get('staging');
 $sql=file_get_contents(dirname(__DIR__).'/database/migrations/20261110_staging_recurring_mit.sql');
 if($sql===false)die("Missing migration\n");
 // Strip SQL line comments before splitting statements (comments may contain
 // semicolons). Never execute migration fragments as arbitrary SQL.
 $sql=preg_replace('/^\\s*--[^\\r\\n]*$/m','',$sql);
 foreach(array_filter(array_map('trim',explode(';',$sql))) as $stmt){
   if(preg_match('/CREATE TABLE IF NOT EXISTS/i',$stmt))$db->exec($stmt);
 }
 echo "STAGING MIT mandate schema ready. No charge started.\n";exit;
}
if($command==='oneclick-doctor-staging'){
 if(\Iluma\Gateway\Config::get('APP_ENV')==='production')
  die("Staging diagnostic cannot run in production\n");
 $db=Db::get('staging');
 $table=Db::one($db,"SELECT COUNT(*) AS n FROM information_schema.tables
   WHERE table_schema=DATABASE() AND table_name='saved_card_agreements'");
 $ready=(int)($table['n']??0)===1;
 echo 'ONECLICK STAGING SCHEMA: '.($ready?'READY':'MISSING - run migration')."\n";
 if($ready){
  $counts=Db::all($db,'SELECT status,COUNT(*) AS n FROM saved_card_agreements GROUP BY status');
  foreach($counts as $row)
   echo 'CARD AGREEMENTS '.strtoupper((string)$row['status']).': '.(int)$row['n']."\n";
 }
 $app=Db::one(Db::get('registry'),"SELECT id FROM applications WHERE slug='iradiosgr'");
 if($app){
  $orders=Db::all($db,"SELECT order_id,status FROM payments
    WHERE application_id=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 12 HOUR)
    ORDER BY created_at DESC LIMIT 8",[(int)$app['id']]);
  foreach($orders as $row)
   echo 'RECENT IRADIOS ORDER '.(string)$row['order_id'].': '.(string)$row['status']."\n";
 }
 echo "No keys, tokens, customer emails or card data printed.\n";
 exit;
}
if($command==='migrate-staging-saved-cards'){
 if(\Iluma\Gateway\Config::get('APP_ENV')==='production')die("Cannot migrate from a production runtime\n");
 $db=Db::get('staging');
 $file=dirname(__DIR__).'/database/migrations/20261110_staging_saved_cards.sql';
 $sql=file_get_contents($file);
 if($sql===false)die("Migration missing\n");
 // A single, idempotent CREATE TABLE. No seed data, card data or production writes.
 $db->exec($sql);
 echo "STAGING saved_card_agreements schema ready. No payment created.\n";
 exit;
}
if ($command === 'doctor') {
 echo 'CLI PHP: '.PHP_VERSION.PHP_EOL;
 echo 'PRIVATE ENV FILE: '.(is_file(\Iluma\Gateway\EnvLoader::privateFilePath(dirname(__DIR__)))?'PRESENT':'NOT FOUND (check environment variables)').PHP_EOL;
 $ready = PHP_VERSION_ID >= 80300;
 if (!$ready) echo "PHP 8.3+ REQUIRED\n";
 foreach (['registry'=>6,'staging'=>15,'production'=>15] as $name=>$expected) {
  try {
   $db=Db::get($name);$tables=$db->query('SHOW TABLES')->fetchAll();
   $ok=count($tables)>=$expected;
   echo strtoupper($name).': '.($ok?'OK':'INCOMPLETE').' ('.count($tables).' tables, expected '.$expected.")\n";
   if (!$ok) $ready=false;
  } catch (\Throwable $e) { $ready=false;echo strtoupper($name).": CONNECTION FAILED\n"; }
 }
 try {
  $key=base64_decode(\Iluma\Gateway\Config::must('MASTER_KEY_B64'),true);
  if ($key===false || strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new \RuntimeException('Invalid');
  echo "ENCRYPTION: OK\n";
 } catch (\Throwable $e) { $ready=false;echo "ENCRYPTION: NOT CONFIGURED\n"; }
 foreach (['staging','production'] as $name) {
  try {\Iluma\Gateway\Config::base($name);echo strtoupper($name).": NEXI ENDPOINT OK\n";}
  catch (\Throwable $e){$ready=false;echo strtoupper($name).": INVALID NEXI ENDPOINT\n";}
 }
 try {
  $admin=Db::one(Db::get('registry'),'SELECT totp_secret_box FROM admin_users WHERE enabled=1 ORDER BY id LIMIT 1');
  if($admin===false){
   $ready=false;echo "ADMIN MFA: NO ACTIVE ADMIN\n";
  }else{
   $seed=Security::decrypt($admin['totp_secret_box']);
   if(base64_decode($seed,true)===false)throw new \RuntimeException('Bad MFA seed');
   echo "ADMIN MFA: OK (secret decrypts)\n";
  }
 } catch(\Throwable $e){$ready=false;echo "ADMIN MFA: DECRYPTION FAILED\n";}
 echo 'LIVE PAYMENTS: '.(\Iluma\Gateway\Config::liveAllowed()?'ENABLED (REVIEW REQUIRED)':'LOCKED')."\n";
 exit($ready?0:1);
}
// Staging-only CLI pilot; no public route and no production writes.
if(in_array($command,['sandbox-subscription','sandbox-subscription-status'],true)){
 if(($argv[2]??'')!=='staging')die("Usage: ".$command." staging <test-reference>\n");
 try {
  $ref=$argv[3]??'';
  $data=$command==='sandbox-subscription'
   ? \Iluma\Gateway\SubscriptionPilot::start($ref)
   : \Iluma\Gateway\SubscriptionPilot::status($ref);
  echo Security::json($data),PHP_EOL;
 }catch(\Throwable $e){fwrite(STDERR,'Sandbox subscription error: '.$e->getMessage().PHP_EOL);exit(1);}
 exit;
}
$reg=Db::get('registry');
if($command==='create-admin'){
 $email=$argv[2]??'';if(!filter_var($email,FILTER_VALIDATE_EMAIL))die("Usage: create-admin email\n");
 $pw=getenv('ADMIN_INITIAL_PASSWORD');if(!$pw||strlen($pw)<16)die("Set ADMIN_INITIAL_PASSWORD >=16 chars in process environment\n");
 $bytes=random_bytes(20);
 $secret=base64_encode($bytes);
 Db::exec($reg,'INSERT INTO admin_users(email,passhash,totp_secret_box) VALUES(?,?,?)',[$email,password_hash($pw,PASSWORD_ARGON2ID),Security::encrypt($secret)]);
 $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';
 foreach(str_split($bytes) as $byte)$bits.=str_pad(decbin(ord($byte)),8,'0',STR_PAD_LEFT);
 $base32='';for($i=0;$i<strlen($bits);$i+=5)$base32.=$alphabet[bindec(substr($bits,$i,5))];
 echo "Admin created. One-time TOTP secret (Base32, add manually to authenticator): ".$base32."\nNever store the secret in logs or Git.\n";exit;
}
if($command==='create-key'){
 $env=$argv[2]??'';if(!in_array($env,['staging','production'],true))die("Usage: create-key staging|production\n");
 $app=Db::one($reg,"SELECT id FROM applications WHERE slug='iradiosgr'");if(!$app)die("Seed registry first\n");
 $key='ilg_'.Security::id(24);$secret=Security::id(32);
 Db::exec($reg,'INSERT INTO application_credentials(application_id,environment,key_hash,key_prefix,secret_box) VALUES(?,?,?,?,?)',[$app['id'],$env,hash('sha256',$key),substr($key,0,18),Security::encrypt($secret)]);
 echo "SHOW ONCE: API KEY: $key\nSECRET: $secret\nKeep only in iRadios backend.\n";exit;
}
if($command==='product'){
 $env=$argv[2]??'';$sku=$argv[3]??'';$cents=(int)($argv[4]??0);
 if(!in_array($env,['staging','production'],true)||!preg_match('/^[a-z0-9._-]{1,80}$/D',$sku)||$cents<1)die("Usage: product staging|production sku amount_cents\n");
 $app=Db::one($reg,"SELECT id FROM applications WHERE slug='iradiosgr'");$db=Db::get($env);
 Db::exec($db,'INSERT INTO products(application_id,sku,title,amount_cents) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title)',[$app['id'],$sku,$sku,$cents]);
 echo "Product registered. Existing prices NEVER auto-changed.\n";exit;
}
echo "Commands: recurring-doctor-staging | oneclick-doctor-staging | migrate-staging-saved-cards | doctor | create-admin email | create-key staging|production | product staging|production sku cents | sandbox-subscription staging test-ref | sandbox-subscription-status staging test-ref\n";
