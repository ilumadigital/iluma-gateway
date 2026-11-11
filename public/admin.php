<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Iluma\Gateway\{Config,Db,Security,NexiClient,PaymentState,Payments,HttpError};
$host=strtolower(explode(':',$_SERVER['HTTP_HOST']??'')[0]);
if($host!==Config::get('GATEWAY_HOST')||!in_array(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH),['/ilm-admin','/ilm-admin/'],true)){http_response_code(404);exit('Not found');}
header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Cache-Control: private, no-store, max-age=0');
ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_secure','1');ini_set('session.cookie_samesite','Strict');
ini_set('session.gc_maxlifetime','1800');session_name('__Host-ilumaAdmin');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);session_start();
$reg=null;$error='';$notice='';$reveal='';
function audit(string $action,string $target):void {Db::exec(Db::get('registry'),'INSERT INTO audit_logs(admin_id,action,target,ip) VALUES(?,?,?,?)',[$_SESSION['admin_id']??null,$action,$target,$_SERVER['REMOTE_ADDR']??null]);}
function html(string $v):string{return Security::h($v);}
function euro(int $v):string{return number_format($v/100,2,',','.').' €';}
function localDate(?string $utc):string { if(!$utc)return '—';return (new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Athens'))->format('d/m/Y H:i'); }
function credentialEnv():string {return \Iluma\Gateway\AdminContext::environment((array)($_SESSION??[]));}
/** Mandatory server-side TOTP verification before sensitive actions. */
function requireStepUp(\PDO $registry,string $code):void {
 $adminId=(int)($_SESSION['admin_id']??0);
 if($adminId<1)throw new HttpError(403,'Not authenticated');
 if(time()<(int)($_SESSION['stepup_block_until']??0))throw new HttpError(429,'MFA temporarily locked');
 $user=Db::one($registry,'SELECT totp_secret_box FROM admin_users WHERE id=? AND enabled=1',[$adminId]);
 $valid=$user && Security::otpVerify(Security::decrypt($user['totp_secret_box']),$code);
 if(!$valid){
  $fails=(int)($_SESSION['stepup_fails']??0)+1;
  $_SESSION['stepup_fails']=$fails;
  if($fails>=5)$_SESSION['stepup_block_until']=time()+900;
  Db::exec($registry,'INSERT INTO audit_logs(admin_id,action,target,ip) VALUES(?,?,?,?)',[$adminId,'admin_mfa_failed','step_up',$_SERVER['REMOTE_ADDR']??null]);
  throw new HttpError(403,'MFA required');
 }
 unset($_SESSION['stepup_fails'],$_SESSION['stepup_block_until']);
}

// PHP class method is resolved at compile time, unlike a request-scoped callable.
// Avoid global function/closure symbols that have failed under Hostinger web PHP.
final class AdminPageView {
 public static function render(string $title,string $body,string $error='',string $notice=''): never {
 $user=isset($_SESSION['admin_id']);$nav='';$environment=credentialEnv();$csrf=Security::csrf();
 if($user){foreach(['dashboard'=>'Dashboard','payments'=>'Πληρωμές','subscriptions'=>'Συνδρομές','customers'=>'Πελάτες','applications'=>'Εφαρμογές','keys'=>'API Keys','nexi'=>'Nexi Settings','logs'=>'Ιστορικό Πληρωμών','settings'=>'Ρυθμίσεις'] as $slug=>$label)$nav.='<a href="/ilm-admin/?p='.$slug.'">'.html($label).'</a>';
 $nav.='<form method="post" class="nav-action" data-stepup="Εξαγωγή CSV"><input type="hidden" name="_csrf" value="'.$csrf.'"><input type="hidden" name="action" value="export"><input type="hidden" name="otp" value=""><button type="submit">Εξαγωγή CSV</button></form>';
 $nav.='<form method="post"><input type="hidden" name="_csrf" value="'.$csrf.'"><button name="action" value="logout">Αποσύνδεση</button></form>';
 $nav.='<form method="post"><input type="hidden" name="_csrf" value="'.$csrf.'"><input type="hidden" name="action" value="environment"><select name="environment"><option value="staging"'.($environment==='staging'?' selected':'').'>STAGING</option><option value="production"'.($environment==='production'?' selected':'').'>PRODUCTION</option></select><button>Επιλογή</button></form>';
 }
 $mfaDialog=$user?'<dialog id="mfa-dialog" class="mfa-dialog" aria-labelledby="mfa-title" aria-describedby="mfa-description"><div class="mfa-sheet"><div class="mfa-icon">✳</div><h2 id="mfa-title">Επιβεβαίωση ενέργειας</h2><p id="mfa-description">Άνοιξε το Google Authenticator και βάλε τον 6ψήφιο κωδικό. Η ενέργεια δεν εκτελείται πριν επιβεβαιώσεις.</p><p class="mfa-action-label" id="mfa-action-label"></p><label for="mfa-code">Κωδικός επιβεβαίωσης</label><input id="mfa-code" class="mfa-code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000"><p id="mfa-validation" class="mfa-validation" role="alert" hidden>Συμπλήρωσε έναν 6ψήφιο κωδικό.</p><div class="mfa-actions"><button type="button" class="button-secondary" id="mfa-cancel">Άκυρο</button><button type="button" id="mfa-confirm">Επιβεβαίωση</button></div></div></dialog>':'';
 echo '<!doctype html><html lang="el"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.html($title).' — ILUMA</title><link rel="stylesheet" href="/ilm-admin/admin.css"></head><body class="'.($user?'is-authenticated':'is-login').'"><header><strong class="brand-mark">ILUMA<span> / GATEWAY</span></strong><span>'.($user?html(strtoupper($environment)).' — '.($environment==='staging'?'TEST ENVIRONMENT':'LIVE DATA (CHARGES LOCKED BY DEFAULT)'):'Προστατευμένη πρόσβαση').'</span></header><div class="layout"><aside aria-label="Πλοήγηση διαχειριστικού">'.$nav.'</aside><main id="main-content"><h1>'.html($title).'</h1>'.($error?'<p class="alert" role="alert">'.html($error).'</p>':'').($notice?'<p class="notice" role="status">'.html($notice).'</p>':'').$body.'</main></div>'.$mfaDialog.'<script defer src="/ilm-admin/admin.js"></script></body></html>';exit;
 }
}
try {
 if($_SERVER['REQUEST_METHOD']==='POST') {
  // DB errors are now caught and logged without exposing credentials.
  $reg=Db::get('registry');
  Security::verifyCsrf();$action=$_POST['action']??'';
  if($action==='login'){
   $ip=(string)($_SERVER['REMOTE_ADDR']??'');
   $recent=Db::one($reg,"SELECT COUNT(*) AS total FROM audit_logs WHERE action='admin_login_failed' AND ip=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)",[$ip]);
   if((int)($recent['total']??0)>=15){
    http_response_code(429);
    AdminPageView::render('Πολλές απόπειρες σύνδεσης','<p>Προσπαθήστε ξανά αργότερα.</p>');
   }
   $email=(string)($_POST['email']??'');$pw=(string)($_POST['password']??'');
   $u=Db::one($reg,'SELECT * FROM admin_users WHERE email=? AND enabled=1',[$email]);
   $allowed=$u && (!$u['locked_until']||strtotime($u['locked_until'].' UTC')<time());
   $success=$allowed && password_verify($pw,$u['passhash']);
   if(!$success){if($u){Db::exec($reg,'UPDATE admin_users SET failed_attempts=failed_attempts+1,locked_until=IF(failed_attempts>=4,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE),locked_until) WHERE id=?',[$u['id']]);}audit('admin_login_failed','backoffice');$error='Αποτυχία σύνδεσης.';}
   else{session_regenerate_id(true);$_SESSION['admin_id']=$u['id'];$_SESSION['since']=time();$_SESSION['last']=time();$_SESSION['view_env']='staging';Db::exec($reg,'UPDATE admin_users SET failed_attempts=0,locked_until=NULL,last_login_at=UTC_TIMESTAMP() WHERE id=?',[$u['id']]);audit('login','backoffice');header('Location: /ilm-admin/');exit;}
  } elseif($action==='logout'){if(!empty($_SESSION['admin_id']))audit('logout','backoffice');$_SESSION=[];session_destroy();header('Location: /ilm-admin/');exit;}
  elseif(empty($_SESSION['admin_id']))throw new HttpError(403,'Not authenticated');
  elseif($action==='environment'){if(!in_array($_POST['environment']??'', ['staging','production'],true))throw new HttpError(422,'Invalid environment');$_SESSION['view_env']=$_POST['environment'];header('Location: /ilm-admin/');exit;}
  elseif($action==='nexi-save'){
   $env=credentialEnv();$value=(string)($_POST['secret']??'');$otp=(string)($_POST['otp']??'');
   $u=Db::one($reg,'SELECT totp_secret_box FROM admin_users WHERE id=?',[$_SESSION['admin_id']]);
   requireStepUp($reg,$otp);
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
   requireStepUp($reg,$otp);
   $a=Db::one($reg,"SELECT id FROM applications WHERE slug='iradiosgr'");$key='ilg_'.Security::id(24);$secret=Security::id(32);
   Db::exec($reg,'INSERT INTO application_credentials(application_id,environment,key_hash,key_prefix,secret_box) VALUES(?,?,?,?,?)',[$a['id'],$env,hash('sha256',$key),substr($key,0,18),Security::encrypt($secret)]);
   audit('api_key_create',$env);$reveal='<div class="secret"><strong>Εμφάνιση ΜΟΝΟ ΜΙΑ ΦΟΡΑ</strong><pre>'.html('KEY: '.$key."\nSECRET: ".$secret).'</pre></div>';
  } elseif($action==='key-disable'){
   $env=credentialEnv();$otp=(string)($_POST['otp']??'');$u=Db::one($reg,'SELECT totp_secret_box FROM admin_users WHERE id=?',[$_SESSION['admin_id']]);requireStepUp($reg,$otp);
   $id=(int)($_POST['id']??0);Db::exec($reg,'UPDATE application_credentials SET enabled=0 WHERE id=? AND environment=?',[$id,$env]);audit('api_key_disable',$env.':'.$id);$notice='Το κλειδί απενεργοποιήθηκε.';
  } elseif($action==='export'){
   requireStepUp($reg,(string)($_POST['otp']??''));
   $_GET['p']='export';
  }
 }
 if(empty($_SESSION['admin_id'])){
  $csrf=Security::csrf();$form='<form class="login" method="post"><input type="hidden" name="_csrf" value="'.$csrf.'"><input type="hidden" name="action" value="login"><label>Email<input name="email" type="email" required autocomplete="username"></label><label>Κωδικός<input name="password" type="password" required autocomplete="current-password"></label><p class="login-hint">Το Google Authenticator ζητείται μόνο όταν επιβεβαιώνεις μια ευαίσθητη ενέργεια.</p><button>Σύνδεση</button></form>';
  AdminPageView::render('Σύνδεση διαχειριστή',$form,$error);
 }
 if($reg===null)$reg=Db::get('registry');
 if(time()-(int)($_SESSION['last']??0)>1800 || time()-(int)($_SESSION['since']??0)>28800){$_SESSION=[];session_destroy();header('Location: /ilm-admin/');exit;}
 $_SESSION['last']=time();$env=credentialEnv();$db=Db::get($env);$p=(string)($_GET['p']??'dashboard');$csrf=Security::csrf();$content=$reveal;
 if($p==='export'){
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')throw new HttpError(403,'MFA required for export');
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
  $content.='<p>Παλαιότερες συνδρομές: '.(int)$sub['active'].' ενεργές · '.(int)$sub['past_due'].' ληξιπρόθεσμες.</p>';
  if($env==='staging')$content.=\Iluma\Gateway\RecurringAdmin::summary($db);
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
  foreach($rows as $r)$content.='<tr><td><a href="/ilm-admin/?p=payment&id='.html($r['id']).'">'.html(localDate($r['created_at'])).'</a></td><td>'.html($r['email']).'</td><td>'.html($r['title']).'</td><td>'.euro((int)$r['amount_cents']).'</td><td><span class="status '.html($r['status']).'">'.html($statuses[$r['status']]??$r['status']).'</span></td></tr>';
  $content.='</table>';
 } elseif($p==='payment'){
  $id=(string)($_GET['id']??'');$row=Db::one($db,'SELECT p.*,c.email,pr.title FROM payments p JOIN customers c ON c.id=p.customer_id JOIN products pr ON pr.id=p.product_id WHERE p.id=?',[$id]);if(!$row)throw new HttpError(404,'Not found');
  foreach(['Payment ID'=>$row['id'],'Nexi Order ID'=>$row['order_id'],'Nexi Operation ID'=>$row['operation_id']??'—','Περιβάλλον'=>$env,'Πελάτης'=>$row['email'],'Προϊόν'=>$row['title'],'Κατάσταση'=>$row['status'],'Ποσό'=>euro((int)$row['amount_cents'])] as $label=>$value)$content.='<p><b>'.html($label).':</b> '.html((string)$value).'</p>';
  if($env==='staging'){
   $mandateId=\Iluma\Gateway\RecurringAdmin::byPayment($db,$id)
    ??\Iluma\Gateway\BroadcasterRecurringAdmin::byPayment($db,$id);
   if($mandateId)$content.='<p><a href="/ilm-admin/?p=subscription&id='.html($mandateId).'">Προβολή συνδρομής Nexi MIT</a></p>';
   $scope=\Iluma\Gateway\BroadcasterRecurringAdmin::scopeByPayment($db,$id);
   if($scope){
    $content.='<p><b>Προϊόν Broadcaster:</b> '.html((string)$scope['sku']).'</p>';
    $content.='<p><b>Σταθμός:</b> '.html((string)($scope['resource_id']??'Όλος ο λογαριασμός')).'</p>';
   }
  }
  $logs=Db::all($db,'SELECT event_type,message_gr,occurred_at FROM payment_logs WHERE payment_id=? ORDER BY occurred_at,id',[$id]);
  $content.='<h2>Χρονολόγιο γεγονότων</h2><ol>';foreach($logs as $entry)$content.='<li><time>'.html(localDate($entry['occurred_at'])).'</time> — '.html($entry['message_gr']).'</li>';$content.='</ol>';
  $out=Db::all($db,'SELECT o.status,o.attempts,
    (SELECT d.http_status FROM event_deliveries d WHERE d.event_id=o.id ORDER BY d.id DESC LIMIT 1) AS last_http_status
    FROM outgoing_events o WHERE o.payment_id=?',[$id]);
  foreach($out as $delivery){
   $content.='<p>Ενημέρωση iRadios: '.html($delivery['status']).' (προσπάθειες: '.(int)$delivery['attempts'].')</p>';
   if($delivery['last_http_status']!==null)
    $content.='<p>Τελευταίο HTTP webhook: '.(int)$delivery['last_http_status'].'</p>';
   if($delivery['status']==='pending' && (int)$delivery['attempts']===0)
    $content.='<p class="alert">Δεν έχει πραγματοποιηθεί αποστολή προς το iRadios. Ελέγξτε τον staging worker / cron στο Gateway. ΜΗ δημιουργήσετε νέα πληρωμή.</p>';
   elseif($delivery['status']==='pending')
    $content.='<p class="alert">Η παράδοση απέτυχε. Ελέγξτε το HTTP status, τα gateway worker logs και την πρόσβαση του /api/gateway/webhook από Cloudflare.</p>';
  }
 } elseif($p==='customers'){
  $rows=Db::all($db,'SELECT c.id,c.email,c.name,c.external_user_id,COUNT(p.id) payments FROM customers c LEFT JOIN payments p ON p.customer_id=c.id GROUP BY c.id ORDER BY c.created_at DESC LIMIT 100');
  $content.='<table><tr><th>Όνομα</th><th>Email</th><th>External User ID</th><th>Πληρωμές</th></tr>';
  foreach($rows as $r)$content.='<tr><td>'.html($r['name']).'</td><td>'.html($r['email']).'</td><td>'.html($r['external_user_id']).'</td><td>'.(int)$r['payments'].'</td></tr>';$content.='</table>';
 } elseif($p==='subscriptions'){
  if($env==='staging'){
   $content.=\Iluma\Gateway\RecurringAdmin::index($db,$reg,(string)($_GET['q']??''));
   $content.=\Iluma\Gateway\BroadcasterRecurringAdmin::index($db,$reg,(string)($_GET['q']??''));
  }
  else $content.='<p>Η Nexi MIT recurring υπηρεσία δεν είναι ενεργή στο production.</p>';
  $legacy=Db::all($db,"SELECT s.state,s.next_billing_at,c.email,pr.title
   FROM subscriptions s JOIN customers c ON c.id=s.customer_id
   JOIN products pr ON pr.id=s.product_id ORDER BY s.created_at DESC LIMIT 100");
  if($legacy){
   $content.='<h2>Παλαιότερες συνδρομές (legacy)</h2><table><tr><th>Πελάτης</th><th>Πλάνο</th><th>Κατάσταση</th><th>Επόμενη χρέωση</th></tr>';
   foreach($legacy as $item)
    $content.='<tr><td>'.html($item['email']).'</td><td>'.html($item['title']).'</td><td>'.html($item['state']).'</td><td>'.html($item['next_billing_at']?localDate($item['next_billing_at']):'—').'</td></tr>';
   $content.='</table>';
  }
 } elseif($p==='subscription'){
  if($env!=='staging')throw new HttpError(404,'Not found');
  $subscriptionId=(string)($_GET['id']??'');
  $content.=\Iluma\Gateway\BroadcasterRecurringAdmin::hasMandate($db,$subscriptionId)
   ?\Iluma\Gateway\BroadcasterRecurringAdmin::detail($db,$reg,$subscriptionId)
   :\Iluma\Gateway\RecurringAdmin::detail($db,$reg,$subscriptionId);
 } elseif($p==='applications'){
  $apps=Db::all($reg,'SELECT * FROM applications');foreach($apps as $app)$content.='<article><h2>'.html($app['name']).'</h2><p>Staging: '.html($app['staging_origin']).'</p><p>Production: '.html($app['production_origin']).'</p></article>';
 } elseif($p==='keys'){
  $keys=Db::all($reg,'SELECT id,key_prefix,created_at,last_used_at,enabled FROM application_credentials WHERE environment=? ORDER BY id DESC',[$env]);
  $content.='<form method="post" data-stepup="Δημιουργία API Key"><input type="hidden" name="_csrf" value="'.$csrf.'"><input type="hidden" name="action" value="key-create"><p>Νέο API key για iRadiosGR / '.html($env).'</p><input type="hidden" name="otp" value=""><button>Δημιουργία κλειδιού</button></form>';
  $content.='<table><tr><th>Key Prefix</th><th>Δημιουργία</th><th>Τελευταία χρήση</th><th>Ενεργό</th><th></th></tr>';
  foreach($keys as $k)$content.='<tr><td>'.html($k['key_prefix']).'…</td><td>'.html($k['created_at']).'</td><td>'.html($k['last_used_at']??'—').'</td><td>'.($k['enabled']?'Ναι':'Όχι').'</td><td><form method="post" data-stepup="Απενεργοποίηση API Key"><input type="hidden" name="_csrf" value="'.$csrf.'"><input type="hidden" name="id" value="'.(int)$k['id'].'"><input type="hidden" name="action" value="key-disable"><input type="hidden" name="otp" value=""><button class="danger-button" type="submit">Απενεργοποίηση</button></form></td></tr>';
  $content.='</table>';
 } elseif($p==='nexi'){
  $row=Db::one($reg,'SELECT * FROM nexi_credentials WHERE environment=?',[$env]);$masked=$row?'•••••••• (κρυπτογραφημένο)':'Δεν έχει οριστεί';
  $content.='<p>Endpoint: '.html(Config::base($env)).'</p><p>API key: '.html($masked).'</p><p>Last Connection: '.html($row['last_checked_at']??'—').'</p><p>Connection Status: '.html($row['check_status']??'not configured').'</p>';
  $content.='<form method="post" data-stepup="Αποθήκευση Nexi API Key"><input type="hidden" name="_csrf" value="'.$csrf.'"><input type="hidden" name="action" value="nexi-save"><label>Νέο Nexi API Key<input name="secret" type="password" autocomplete="off" required></label><input type="hidden" name="otp" value=""><button>Αποθήκευση / Αντικατάσταση</button></form>';
  $content.='<form method="post"><input type="hidden" name="_csrf" value="'.$csrf.'"><button name="action" value="nexi-test">Έλεγχος Σύνδεσης (χωρίς χρέωση)</button></form>';
 } elseif($p==='settings'){
  $content.='<p>Σύνδεση με email και password. Το Google Authenticator ζητείται για αλλαγές κλειδιών και εξαγωγή CSV, πριν εφαρμοστεί η ενέργεια.</p><p>Live transactions: '.(Config::liveAllowed()?'Ενεργοποίηση από runtime — απαιτείται ανεξάρτητος έλεγχος.':'ΑΠΕΝΕΡΓΟΠΟΙΗΜΕΝΕΣ').'</p>';
 } else throw new HttpError(404,'Not found');
 AdminPageView::render(['dashboard'=>'Dashboard','payments'=>'Πληρωμές','subscriptions'=>'Συνδρομές','customers'=>'Πελάτες','applications'=>'Εφαρμογές','keys'=>'API Keys','nexi'=>'Nexi Settings','logs'=>'Ιστορικό Πληρωμών','payment'=>'Ανάλυση πληρωμής','subscription'=>'Ανάλυση Nexi MIT συνδρομής','settings'=>'Ρυθμίσεις'][$p]??'ILUMA',$content,$error,$notice);
} catch(\Throwable $e){error_log('ILUMA admin '.get_class($e).': '.$e->getMessage());
 if(!empty($_SESSION['admin_id'])){
  if($e instanceof HttpError && $e->getMessage()==='MFA required')
   AdminPageView::render('Επιβεβαίωση 2FA','<p class="alert" role="alert">Ο κωδικός Google Authenticator δεν έγινε δεκτός. Δοκίμασε ξανά από την ενέργεια.</p>');
  AdminPageView::render('Δεν ολοκληρώθηκε','<p class="alert" role="alert">Η ενέργεια δεν ολοκληρώθηκε. Έλεγξε τα στοιχεία και προσπάθησε ξανά.</p>');
 }
 AdminPageView::render('Σύνδεση','<p>Η πρόσβαση δεν είναι διαθέσιμη.</p>');
}
