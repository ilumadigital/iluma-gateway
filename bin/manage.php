<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Iluma\Gateway\{Db,Security};
if(PHP_SAPI!=='cli')exit(1);
$command=$argv[1]??'';$reg=Db::get('registry');
if($command==='create-admin'){
 $email=$argv[2]??'';if(!filter_var($email,FILTER_VALIDATE_EMAIL))die("Usage: create-admin email\n");
 $pw=getenv('ADMIN_INITIAL_PASSWORD');if(!$pw||strlen($pw)<16)die("Set ADMIN_INITIAL_PASSWORD >=16 chars in process environment\n");
 $secret=base64_encode(random_bytes(20));
 Db::exec($reg,'INSERT INTO admin_users(email,passhash,totp_secret_box) VALUES(?,?,?)',[$email,password_hash($pw,PASSWORD_ARGON2ID),Security::encrypt($secret)]);
 echo "Admin created. One-time TOTP seed (base64): ".$secret."\nImport decoded bytes as Base32 in authenticator. Never store in logs.\n";exit;
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
echo "Commands: create-admin email | create-key staging|production | product staging|production sku cents\n";
