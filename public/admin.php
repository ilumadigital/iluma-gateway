<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Iluma\Gateway\{Config,Db,Security,NexiClient,PaymentState,Payments,HttpError};
$host=strtolower(explode(':',$_SERVER['HTTP_HOST']??'')[0]);
if($host!==Config::get('ADMIN_HOST')||Config::get('ADMIN_ACCESS_VERIFIED')!=='1'){http_response_code(404);exit('Not found');}
header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_secure','1');ini_set('session.cookie_samesite','Strict');
ini_set('session.gc_maxlifetime','1800');session_name('__Host-ilumaAdmin');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);session_start();
$reg=Db::get('registry');$error='';$notice='';$reveal='';
function audit(string $action,string $target):void {Db::exec(Db::get('registry'),'INSERT INTO audit_logs(admin_id,action,target,ip) VALUES(?,?,?,?)',[$_SESSION['admin_id']??null,$action,$target,$_SERVER['REMOTE_ADDR']??null]);}
function html(string $v):string{return Security::h($v);}
function euro(int $v):string{return number_format($v/100,2,',','.').' €';}
function localDate(?string $utc):string { if(!$utc)return '—';return (new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Athens'))->format('d/m/Y H:i'); }
function credentialEnv():string {return in_array($_SESSION['view_env']??'staging',['staging','production'],true)?$_SESSION['view_env']:'staging';}
function page(string $title,string $body,string $error='',string $notice=''):never {
 $user=isset($_SESSION['admin_id']);$nav='';$environment=credentialEnv();$csrf=Security::csrf();
 if($user){foreach(['dashboard'=>'Dashboard','payments'=>'Πληρωμές','subscriptions'=>'Συνδρομές','customers'=>'Πελάτες','applications'=>'Εφαρμογές','keys'=>'API Keys','nexi'=>'Nexi Settings','logs'=>'Ιστορικό Πληρωμών','export'=>'Εξαγωγή CSV','settings'=>'Ρυθμίσεις'] as $slug=>$label)$nav.='<a href="/?p='.$slug.'">'.html($label).'</a>';
 $nav.='<form method="post"><input type="hidden" name="_csrf" value="'.$csrf.'"><button name="action" value="logout">Αποσύνδεση</button></form>';
 $nav.='<form method="post"><input type="hidden" name="_csrf" value="'.$csrf.'"><input type="hidden" name="action" value="environment"><select name="environment"><option value="staging"'.($environment==='staging'?' selected':'').'>STAGING</option><option value="production"'.($environment==='production'?' selected':'').'>PRODUCTION</option></select><button>Επιλογή</button></form>';
 }
 echo '<!doctype html><html lang="el"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.html($title).' — ILUMA</title><link rel="stylesheet" href="/admin.css"></head><body><header><strong>ILUMA<span> / PAYMENT GATEWAY</span></strong><span>'.($user?html(strtoupper($environment)).' — '.($environment==='staging'?'TEST ENVIRONMENT':'LIVE DATA (CHARGES LOCKED BY DEFAULT)'):'Προστατευμένη πρόσβαση').'</span></header><div class="layout"><aside>'.$nav.'</aside><main><h1>'.html($title).'</h1>'.($error?'<p class="alert">'.html($error).'</p>':'').($notice?'<p class="notice">'.html($notice).'</p>':'').$body.'</main></div></body></html>';exit;
}
try {
 if($_SERVER['REQUEST_METHOD']==='POST') {
  Security::verifyCsrf();$action=$_POST['action']??'';
  if($action==='login'){
   $email=(string)($_POST['email']??'');$pw=(string)($_POST['password']??'');$otp=(string)($_POST['otp']??'');
   $u=Db::one($reg,'SELECT * FROM admin_users WHERE email=? AND enabled=1',[$email]);
   $allowed=$u && (!$u['locked_until']||strtotime($u['locked_until'].' UTC')<time());
   $success=$allowed && password_verify($pw,$u['passhash']) && Security::otpVerify(Security::decrypt($u['totp_secret_box']),$otp);
   if(!$success){if($u){Db::exec($reg,'UPDATE admin_users SET failed_attempts=failed_attempts+1,locked_until=IF(failed_attempts>=4,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE),locked_until) WHERE id=?',[$u['id']]);}$error='Αποτυχία σύνδεσης.';}
   else{session_regenerate_id(true);$_SESSION['admin_id']=$u['id'];$_SESSION['since']=time();$_SESSION['last']=time();$_SESSION['view_env']='staging';Db::exec($reg,'UPDATE admin_users SET failed_attempts=0,locked_until=NULL,last_login_at=UTC_TIMESTAMP() WHERE id=?',[$u['id']]);audit('login','backoffice');header('Location: /');exit;}
  } elseif($action==='logout'){if(!empty($_SESSION['admin_id']))audit('logout','backoffice');$_SESSION=[];session_destroy();header('Location: /');exit;}
  elseif(empty($_SESSION['admin_id']))throw new HttpError(403,'Not authenticated');
  elseif($action==='environment'){if(!in_array($_POST['environment']??'', ['staging','production'],true))throw new HttpError(422,'Invalid environment');$_SESSION['view_env']=$_POST['environment'];header('Location: /');exit;}
  elseif($action==='nexi-save'){
   $env=credentialEnv();$value=(string)($_POST['secret']??'');$otp=(string)($_POST['otp']??'');
   $u=Db::one($reg,'SELECT totp_secret_box FROM admin_users WHERE id=?',[$_SESSION['admin_id']]);
   if(!Security::otpVerify(Security::decrypt($u['totp_secret_box']),$otp))throw new HttpError(403,'MFA required');
   if(strlen($value)<12||strlen($value)>300)throw new HttpError(422,'Invalid credential');
   Db::exec($reg,"INSERT INTO nexi_credentials(environment,secret_box,updated_at) VALUES(?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE secret_box=VALUES(secret_box),updated_at=UTC_TIMESTAMP(),check_status='untested'",[$env,Security::encrypt($value)]);
   audit('nexi_credential_replace',$env);$notice='Το κλειδί αποθηκεύτηκε κρυπτογραφημένο.';
  } elseif($action==='nexi-test'){
   $env=credentialEnv();try{$ok=NexiClient::forEnvironment($env)->testConnection();$notice='Η μη χρεωστική κλήση GET /payment_methods ολοκληρώθηκε.';$status='ok';}
   catch(\Throwable $e){$error='Ο έλεγχος σύνδεσης απέτυχε.';$status='failed';}
   Db::exec($reg,'UPDATE nexi_credentials SET last_checked_at=UTC_TIMESTAMP(),check_status=? WHERE environment=?',[$status,$env]);audit('nexi_test',$env);
  } elseif($action==='key-create'){
   $env=credentialEnv();$otp=(string)($_POST['otp']??'');
   $u=Db::one($reg,'SELECT totp_secret_box FROM admin_users WHERE id=?',[$_SESSION['admin_id']]);
   if(!Security::otpVerify(Security::decrypt($u['totp_secret_box']),$otp))throw new HttpError(403,'MFA required');
   $a=Db::one($reg,"SELECT id FROM applications WHERE slug='iradiosgr'");$key='ilg_'.Security::id(24);$secret=Security::id(32);
   Db::exec($reg,'INSERT INTO application_credentials(application_id,environment,key_hash,key_prefix,secret_box) VALUES(?,?,?,?,?)',[$a['id'],$env,hash('sha256',$key),substr($key,0,18),Security::encrypt($secret)]);
   audit('api_key_create',$env);$reveal='<div class="secret"><strong>Εμφάνιση ΜΟΝΟ ΜΙΑ ΦΟΡΑ</strong><pre>'.html('KEY: '.$key."\nSECRET: ".$secret).'</pre></div>';
  } elseif($action==='key-disable'){
   $env=credentialEnv();$otp=(string)($_POST['otp']??'');$u=Db::one($reg,'SELECT totp_secret_box FROM admin_users WHERE id=?',[$_SESSION['admin_id']]);if(!Security::otpVerify(Security::decrypt($u['totp_secret_box']),$otp))throw new HttpError(403,'MFA required');
   $id=(int)($_POST['id']??0);Db::exec($reg,'UPDATE application_credentials SET enabled=0 WHERE id=? AND environment=?',[$id,$env]);audit('api_key_disable',$env.':'.$id);$notice='Το κλειδί απενεργοποιήθηκε.';
  }
 }
 if(empty($_SESSION['admin_id'])){
  $csrf=Security::csrf();$form='<form class="login" method="post"><input type="hidden" name="_csrf" value="'.$csrf.'"><input type="hidden" name="action" value="login"><label>Email<input name="email" type="email" required autocomplete="username"></label><label>Κωδικός<input name="password" type="password" required autocomplete="current-password"></label><label>Κωδικός MFA (6 ψηφία)<input name="otp" inputmode="numeric" maxlength="6" required autocomplete="one-time-code"></label><button>Σύνδεση</button></form>';
  page('Σύνδεση διαχειριστή',$form,$error);
 }
 if(time()-(int)($_SESSION['last']??0)>1800 || time()-(int)($_SESSION['since']??0)>28800){$_SESSION=[];session_destroy();header('Location: /');exit;}
 $_SESSION['last']=time();$env=credentialEnv();$db=Db::get($env);$p=(string)($_GET['p']??'dashboard');$csrf=Security::csrf();$content=$reveal;
 if($p==='export'){
  audit('csv_export',$env);$rows=Db::all($db,'SELECT p.id,p.order_id,p.created_at,c.email,pr.title,p.amount_cents,p.currency,p.status FROM payments p JOIN customers c ON c.id=p.customer_id JOIN products pr ON pr.id=p.product_id ORDER BY p.created_at DESC LIMIT 10000');
  header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="iluma-'.$env.'-payments.csv"');$out=fopen('php://output','w');
  fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['Payment ID','Nexi Order ID','Ημερομηνία Αθήνας','Email','Υπηρεσία','Cents','Currency','Status']);
  foreach($rows as $row){$values=[$row['id'],$row['order_id'],localDate($row['created_at']),$row['email'],$row['title'],$row['amount_cents'],$row['currency'],$row['status']];$values=array_map(static function($v):string{$v=(string)$v;return preg_match('/^[=+@-]/u',$v)?"'".$v:$v;},$values);fputcsv($out,$values);}
  fclose($out);exit;
 }

 if($p==='dashboard'){
  $tz=new DateTimeZone('Europe/Athens');$today=new DateTimeImmutable('today',$tz);
  $month=new DateTimeImmutable('first day of this month 00:00:00',$tz);
  $asUtc=static fn(DateTimeImmutable $dt):string=>$dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
  $todayMin=$asUtc($today);$tomorrow=$asUtc($today->modify('+1 day'));
  $monthMin=$asUtc($month);$nextMonth=$asUtc($month->modify('+1 month'));
  $stats=Db::one($db,"SELECT COUNT(CASE WHEN status='paid' AND confirmed_at>=? AND confirmed_at<? THEN 1 END) AS paid_today,
   COALESCE(SUM(CASE WHEN status='paid' AND confirmed_at>=? AND confirmed_at<? THEN amount_cents ELSE 0 END),0) AS amount_today,
   COUNT(CASE WHEN status='paid' AND confirmed_at>=? AND confirmed_at<? THEN 1 END) AS paid_month,
   COALESCE(SUM(CASE WHEN status='paid' AND confirmed_at>=? AND confirmed_at<? THEN amount_cents ELSE 0 END),0) AS amount_month,
   COUNT(CASE WHEN status='failed' THEN 1 END) AS failed, COUNT(CASE WHEN status='canceled' THEN 1 END) AS canceled FROM payments",
   [$todayMin,$tomorrow,$todayMin,$tomorrow,$monthMin,$nextMonth,$monthMin,$nextMonth]);
  foreach(['Πληρωμές σήμερα'=>$stats['paid_today'],'Εισπράξεις σήμερα'=>euro((int)$stats['amount_today']),'Πληρωμές μήνα'=>$stats['paid_month'],'Εισπράξεις μήνα'=>euro((int)$stats['amount_month']),'Αποτυχημένες'=>$stats['failed'],'Ακυρωμένες'=>$stats['canceled']] as $label=>$value)$content.='<article class="stat"><small>'.html($label).'</small><strong>'.html((string)$value).'</strong></article>';
  $content.='<div class="clear"></div><p>Τα ποσά προέρχονται αποκλειστικά από το ενεργό περιβάλλον. Οι επιστροφές εμφανίζονται ξεχωριστά.</p>';
  $sub=Db::one($db,"SELECT COUNT(CASE WHEN state='active' THEN 1 END) active,COUNT(CASE WHEN state='past_due' THEN 1 END) past_due,COUNT(CASE WHEN next_billing_at>=UTC_TIMESTAMP() AND next_billing_at<DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY) THEN 1 END) upcoming FROM subscriptions");
  $content.='<p>Ενεργές συνδρομές: '.(int)$sub['active'].' · Ληξιπρόθεσμες: '.(int)$sub['past_due'].' · Ανανεώσεις 7ημέρου: '.(int)$sub['upcoming'].'</p>';
  $byProduct=Db::all($db,"SELECT pr.title,COUNT(*) paid_count,COALESCE(SUM(p.amount_cents),0) gross FROM payments p JOIN products pr ON pr.id=p.product_id WHERE p.status='paid' GROUP BY pr.id ORDER BY gross DESC LIMIT 15");
  $content.='<h2>Εισπράξεις ανά υπηρεσία</h2><table><tr><th>Προϊόν</th><th>Επιτυχείς πληρωμές</th><th>Ακαθάριστο ποσό</th></tr>';
  foreach($byProduct as $r)$content.='<tr><td>'.html($r['title']).'</td><td>'.(int)$r['paid_count'].'</td><td>'.euro((int)$r['gross']).'</td></tr>';
  $content.='</table>';

  $refund=Db::one($db,"SELECT COALESCE(SUM(amount_cents),0) AS amount FROM refunds WHERE status='confirmed'");$content.='<p>Επιβεβαιωμένες επιστροφές: '.euro((int)$refund['amount']).'</p>';
 } elseif(in_array($p,['payments','logs'],true)){
  $query=trim((string)($_GET['q']??''));$where=$query!==''?' WHERE p.id=? OR p.order_id=? OR c.email=?':'';$args=$query!==''?[$query,$query,$query]:[];
  $rows=Db::all($db,'SELECT p.id,p.order_id,p.created_at,p.status,p.amount_cents,c.email,pr.title FROM payments p JOIN customers c ON c.id=p.customer_id JOIN products pr ON pr.id=p.product_id'.$where.' ORDER BY p.created_at DESC LIMIT 100',$args);
  $content.='<form method="get"><input type="hidden" name="p" value="payments"><input name="q" placeholder="Payment ID / Nexi Order ID / email" value="'.html($query).'"><button>Αναζήτηση</button></form><table><tr><th>Ημερομηνία (Αθήνα)</th><th>Πελάτης</th><th>Υπηρεσία</th><th>Ποσό</th><th>Αποτέλεσμα</th></tr>';
  $statuses=['paid'=>'Πληρώθηκε','failed'=>'Απέτυχε','canceled'=>'Ακυρώθηκε','unknown'=>'Αναμονή επιβεβαίωσης','redirect'=>'Εκκρεμεί','pending'=>'Εκκρεμεί','refunded'=>'Επιστράφηκε'];
  foreach($rows as $r)$content.='<tr><td><a href="/?p=payment&id='.html($r['id']).'">'.html(localDate($r['created_at'])).'</a></td><td>'.html($r['email']).'</td><td>'.html($r['title']).'</td><td>'.euro((int)$r['amount_cents']).'</td><td><span class="status '.html($r['status']).'">'.html($statuses[$r['status']]??$r['status']).'</span></td></tr>';
  $content.='</table>';
 } elseif($p==='payment'){
  $id=(string)($_GET['id']??'');$row=Db::one($db,'SELECT p.*,c.email,pr.title FROM payments p JOIN customers c ON c.id=p.customer_id JOIN products pr ON pr.id=p.product_id WHERE p.id=?',[$id]);if(!$row)throw new HttpError(404,'Not found');
  foreach(['Payment ID'=>$row['id'],'Nexi Order ID'=>$row['order_id'],'Nexi Operation ID'=>$row['operation_id']??'—','Περιβάλλον'=>$env,'Πελάτης'=>$row['email'],'Προϊόν'=>$row['title'],'Κατάσταση'=>$row['status'],'Ποσό'=>euro((int)$row['amount_cents'])] as $label=>$value)$content.='<p><b>'.html($label).':</b> '.html((string)$value).'</p>';
  $logs=Db::all($db,'SELECT event_type,message_gr,occurred_at FROM payment_logs WHERE payment_id=? ORDER BY occurred_at,id',[$id]);
  $content.='<h2>Χρονολόγιο γεγονότων</h2><ol>';foreach($logs as $entry)$content.='<li><time>'.html(localDate($entry['occurred_at'])).'</time> — '.html($entry['message_gr']).'</li>';$content.='</ol>';
  $out=Db::all($db,'SELECT status,attempts FROM outgoing_events WHERE payment_id=?',[$id]);foreach($out as $r)$content.='<p>Ενημέρωση iRadios: '.html($r['status']).' (προσπάθειες: '.(int)$r['attempts'].')</p>';
 } elseif($p==='customers'){
  $rows=Db::all($db,'SELECT c.id,c.email,c.name,c.external_user_id,COUNT(p.id) payments FROM customers c LEFT JOIN payments p ON p.customer_id=c.id GROUP BY c.id ORDER BY c.created_at DESC LIMIT 100');
  $content.='<table><tr><th>Όνομα</th><th>Email</th><th>External User ID</th><th>Πληρωμές</th></tr>';
  foreach($rows as $r)$content.='<tr><td>'.html($r['name']).'</td><td>'.html($r['email']).'</td><td>'.html($r['external_user_id']).'</td><td>'.(int)$r['payments'].'</td></tr>';$content.='</table>';
 } elseif($p==='subscriptions'){
  $rows=Db::all($db,'SELECT s.*,c.email,pr.title FROM subscriptions s JOIN customers c ON c.id=s.customer_id JOIN products pr ON pr.id=s.product_id ORDER BY s.created_at DESC LIMIT 100');
  $content.='<table><tr><th>Πελάτης</th><th>Προϊόν</th><th>Κατάσταση</th><th>Auto Renewal</th><th>Επόμενη χρέωση</th></tr>';
  foreach($rows as $r)$content.='<tr><td>'.html($r['email']).'</td><td>'.html($r['title']).'</td><td>'.html($r['state']).'</td><td>'.($r['auto_renew']?'Ναι':'Όχι').'</td><td>'.html($r['next_billing_at']??'—').'</td></tr>';$content.='</table>';
 } elseif($p==='applications'){
  $apps=Db::all($reg,'SELECT * FROM applications');foreach($apps as $app)$content.='<article><h2>'.html($app['name']).'</h2><p>Staging: '.html($app['staging_origin']).'</p><p>Production: '.html($app['production_origin']).'</p></article>';
 } elseif($p==='keys'){
  $keys=Db::all($reg,'SELECT id,key_prefix,created_at,last_used_at,enabled FROM application_credentials WHERE environment=? ORDER BY id DESC',[$env]);
  $content.='<form method="post"><input type="hidden" name="_csrf" value="'.$csrf.'"><input type="hidden" name="action" value="key-create"><p>Νέο API key για iRadiosGR / '.html($env).'</p><label>MFA<input name="otp" required maxlength="6" autocomplete="one-time-code"></label><button>Δημιουργία κλειδιού</button></form>';
  $content.='<table><tr><th>Key Prefix</th><th>Δημιουργία</th><th>Τελευταία χρήση</th><th>Ενεργό</th><th></th></tr>';
  foreach($keys as $k)$content.='<tr><td>'.html($k['key_prefix']).'…</td><td>'.html($k['created_at']).'</td><td>'.html($k['last_used_at']??'—').'</td><td>'.($k['enabled']?'Ναι':'Όχι').'</td><td><form method="post"><input type="hidden" name="_csrf" value="'.$csrf.'"><input type="hidden" name="id" value="'.(int)$k['id'].'"><input name="otp" inputmode="numeric" maxlength="6" placeholder="MFA" required><button name="action" value="key-disable">Απενεργοποίηση</button></form></td></tr>';
  $content.='</table>';
 } elseif($p==='nexi'){
  $row=Db::one($reg,'SELECT * FROM nexi_credentials WHERE environment=?',[$env]);$masked=$row?'•••••••• (κρυπτογραφημένο)':'Δεν έχει οριστεί';
  $content.='<p>Endpoint: '.html(Config::base($env)).'</p><p>API key: '.html($masked).'</p><p>Last Connection: '.html($row['last_checked_at']??'—').'</p><p>Connection Status: '.html($row['check_status']??'not configured').'</p>';
  $content.='<form method="post"><input type="hidden" name="_csrf" value="'.$csrf.'"><input type="hidden" name="action" value="nexi-save"><label>Νέο Nexi API Key<input name="secret" type="password" autocomplete="off" required></label><label>MFA<input name="otp" maxlength="6" required></label><button>Αποθήκευση / Αντικατάσταση</button></form>';
  $content.='<form method="post"><input type="hidden" name="_csrf" value="'.$csrf.'"><button name="action" value="nexi-test">Έλεγχος Σύνδεσης (χωρίς χρέωση)</button></form>';
 } elseif($p==='settings'){
  $content.='<p>Διαχειριστικό: πρόσβαση μέσω Zero Trust/VPN + TOTP MFA.</p><p>Live transactions: '.(Config::liveAllowed()?'Ενεργοποίηση από runtime — απαιτείται ανεξάρτητος έλεγχος.':'ΑΠΕΝΕΡΓΟΠΟΙΗΜΕΝΕΣ').'</p>';
 } else throw new HttpError(404,'Not found');
 page(['dashboard'=>'Dashboard','payments'=>'Πληρωμές','subscriptions'=>'Συνδρομές','customers'=>'Πελάτες','applications'=>'Εφαρμογές','keys'=>'API Keys','nexi'=>'Nexi Settings','logs'=>'Ιστορικό Πληρωμών','payment'=>'Ανάλυση πληρωμής','settings'=>'Ρυθμίσεις'][$p]??'ILUMA',$content,$error,$notice);
} catch(\Throwable $e){error_log('ILUMA admin '.get_class($e).': '.$e->getMessage());
 if(!empty($_SESSION['admin_id']))page('Σφάλμα','<p>Η ενέργεια δεν ολοκληρώθηκε.</p>');
 page('Σύνδεση','<p>Η πρόσβαση δεν είναι διαθέσιμη.</p>');
}
