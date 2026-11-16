<?php
declare(strict_types=1);
namespace Iluma\Gateway;

use PDO;

/**
 * Authenticated ILM Admin management UI. The caller MUST check session, CSRF,
 * and step-up TOTP for writes. No browser-supplied tenant or app slug is trusted.
 * No DDL, provider POST or external webhook request is performed here.
 */
final class AdminManagement {
 private static function h(mixed $value):string {
  return Security::h(is_scalar($value)?(string)$value:'');
 }
 private static function amount(int $cents):string {
  return number_format($cents/100,2,',','.').' €';
 }
 private static function money(string $input):int {
  $v=trim(str_replace(',','.',$input));
  if(!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D',$v))
   throw new HttpError(422,'Μη έγκυρη τιμή σε ευρώ.');
  [$euros,$cents]=array_pad(explode('.',$v,2),2,'');
  $value=(int)$euros*100+(int)str_pad($cents,2,'0');
  if($value<1||$value>999999999)
   throw new HttpError(422,'Το ποσό πρέπει να είναι θετικό.');
  return $value;
 }
 private static function origin(string $value):string {
  $v=trim($value);
  if(strlen($v)>255 || !preg_match('#^https://([a-z0-9-]+\.)+[a-z]{2,}(?::443)?/?$#Di',$v))
   throw new HttpError(422,'Χρησιμοποίησε έγκυρο HTTPS domain, χωρίς path, query ή credentials.');
  return rtrim(strtolower($v),'/');
 }
 private static function app(PDO $registry,int $id):array {
  if($id<1)throw new HttpError(422,'Επίλεξε εφαρμογή.');
  $app=Db::one($registry,'SELECT * FROM applications WHERE id=?',[$id]);
  if(!$app)throw new HttpError(404,'Η εφαρμογή δεν βρέθηκε.');
  return $app;
 }
 private static function appSlug(array $app):string {
  return (string)$app['slug'];
 }
 private static function protectedSku(string $appSlug,string $sku):bool {
  return $appSlug==='iradiosgr' && (
   in_array($sku,['L-PREM','L-WINBACK-199','ADV-BUDGET'],true) ||
   isset(BroadcasterMandates::PRICE[$sku]) ||
   isset(BroadcasterBoost::PRICE[$sku])
  );
 }
 private static function pending(PDO $db,int $appId):int {
  $event=Db::one($db,"SELECT COUNT(*) AS n FROM outgoing_events WHERE application_id=? AND status IN ('pending','sending')",[$appId]);
  $payments=Db::one($db,"SELECT COUNT(*) AS n FROM payments WHERE application_id=? AND status IN ('pending','redirect','unknown')",[$appId]);
  return (int)($event['n']??0)+(int)($payments['n']??0);
 }
 public static function handle(string $action,PDO $registry,string $environment,array $post):string {
  if($action==='application-create'){
   $slug=trim((string)($post['slug']??''));
   $name=trim((string)($post['name']??''));
   if(!preg_match('/^[a-z][a-z0-9-]{2,69}$/D',$slug) ||
      in_array($slug,['iluma-internal'],true) || strlen($name)>160 || $name==='')
    throw new HttpError(422,'Έλεγξε το όνομα και το slug της εφαρμογής.');
   $stage=self::origin((string)($post['staging_origin']??''));
   $live=self::origin((string)($post['production_origin']??''));
   Db::exec($registry,'INSERT INTO applications(slug,name,staging_origin,production_origin,enabled) VALUES(?,?,?,?,1)',
    [$slug,$name,$stage,$live]);
   return 'Η εφαρμογή δημιουργήθηκε. Πρόσθεσε ξεχωριστό API Key ανά περιβάλλον.';
  }
  if($action==='application-update'){
   $app=self::app($registry,(int)($post['application_id']??0));
   if(self::appSlug($app)==='iluma-internal')
    throw new HttpError(403,'Η εσωτερική εφαρμογή δοκιμών δεν τροποποιείται.');
   $name=trim((string)($post['name']??''));
   if($name===''||mb_strlen($name)>160)throw new HttpError(422,'Μη έγκυρο όνομα.');
   $stage=self::origin((string)($post['staging_origin']??''));
   $live=self::origin((string)($post['production_origin']??''));
   $enabled=isset($post['enabled'])?1:0;
   // Legacy iRadios payment routing uses fixed verified destinations.
   if(self::appSlug($app)==='iradiosgr' &&
      ($stage!==$app['staging_origin']||$live!==$app['production_origin']))
    throw new HttpError(422,'Τα iRadios URLs είναι δεσμευμένα από την υπάρχουσα δρομολόγηση πληρωμών.');
   if(!$enabled && (self::pending(Db::get('staging'),(int)$app['id']) ||
                    self::pending(Db::get('production'),(int)$app['id'])))
    throw new HttpError(409,'Υπάρχουν εκκρεμείς συναλλαγές ή webhooks. Δεν επιτρέπεται απενεργοποίηση.');
   Db::exec($registry,'UPDATE applications SET name=?,staging_origin=?,production_origin=?,enabled=? WHERE id=?',
    [$name,$stage,$live,$enabled,$app['id']]);
   return $enabled?'Η εφαρμογή ενημερώθηκε.':'Η εφαρμογή απενεργοποιήθηκε χωρίς διαγραφή ιστορικού.';
  }
  if($action==='product-save'){
   $app=self::app($registry,(int)($post['application_id']??0));
   $sku=trim((string)($post['sku']??''));
   $title=trim((string)($post['title']??''));
   $period=trim((string)($post['period_days']??''));
   $enabled=isset($post['enabled'])?1:0;
   if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/D',$sku) ||
     $title==='' || strlen($title)>180)
    throw new HttpError(422,'Μη έγκυρο SKU ή τίτλος.');
   if($period!==''&&(!ctype_digit($period)||(int)$period<1||(int)$period>3650))
    throw new HttpError(422,'Η περίοδος πρέπει να είναι 1–3650 ημέρες.');
   $cents=self::money((string)($post['price_eur']??''));
   $db=Db::get($environment);
   $old=Db::one($db,'SELECT * FROM products WHERE application_id=? AND sku=?',[$app['id'],$sku]);
   // iRadios fixed-price protocols currently validate these SKUs in code.
   // Changing them from SQL alone breaks HPP/MIT, so fail closed.
   if(self::protectedSku(self::appSlug($app),$sku)){
    if(!$old || (int)$old['amount_cents']!==$cents || (int)$old['enabled']!==$enabled ||
       (string)($old['period_days']??'')!==$period)
     throw new HttpError(409,'Αυτό το iRadios SKU ελέγχεται και στον κώδικα. Απαιτεί συγχρονισμένη αλλαγή τιμολόγησης.');
   }
   if($old) {
    Db::exec($db,'UPDATE products SET title=?,amount_cents=?,period_days=?,enabled=? WHERE id=? AND application_id=?',
      [$title,$cents,$period===''?null:(int)$period,$enabled,$old['id'],$app['id']]);
   } else {
    Db::exec($db,"INSERT INTO products(application_id,sku,title,amount_cents,currency,period_days,enabled) VALUES(?,?,?,?,'EUR',?,?)",
      [$app['id'],$sku,$title,$cents,$period===''?null:(int)$period,$enabled]);
   }
   return ($old?'Το προϊόν ενημερώθηκε.':'Το προϊόν δημιουργήθηκε.').' Οι υπάρχουσες πληρωμές δεν επηρεάστηκαν.';
  }
  throw new HttpError(400,'Άγνωστη ενέργεια διαχείρισης.');
 }
 public static function applications(PDO $reg,string $csrf):string {
  $apps=Db::all($reg,"SELECT a.*,
    (SELECT COUNT(*) FROM application_credentials c WHERE c.application_id=a.id AND c.environment='staging' AND c.enabled=1) AS stage_keys,
    (SELECT COUNT(*) FROM application_credentials c WHERE c.application_id=a.id AND c.environment='production' AND c.enabled=1) AS live_keys
   FROM applications a ORDER BY a.id");
  $s='<section class="dashboard-section-head"><div><h2>Εφαρμογές Gateway</h2><p>Οι δύο βάσεις και τα API Keys είναι ανεξάρτητα.</p></div></section>';
  $s.='<div class="dashboard-table-scroll"><table><tr><th>Εφαρμογή</th><th>Staging</th><th>Production</th><th>Κατάσταση</th><th></th></tr>';
  foreach($apps as $a) {
   $id=(int)$a['id'];
   $s.='<tr><td><strong>'.self::h($a['name']).'</strong><br><small>'.self::h($a['slug']).'</small></td>'.
    '<td>'.self::h($a['staging_origin']).'<br><small>Ενεργά Keys: '.(int)$a['stage_keys'].'</small></td>'.
    '<td>'.self::h($a['production_origin']).'<br><small>Ενεργά Keys: '.(int)$a['live_keys'].'</small></td>'.
    '<td>'.($a['enabled']?'Ενεργή':'Ανενεργή').'</td>'.
    '<td><a href="/ilm-admin/?p=application&id='.$id.'">Διαχείριση</a></td></tr>';
  }
  $s.='</table></div>';
  $s.='<article><h2>Προσθήκη εφαρμογής</h2>'.
   '<form method="post" class="management-form" data-stepup="Δημιουργία εφαρμογής">'.
   '<input type="hidden" name="_csrf" value="'.self::h($csrf).'"><input type="hidden" name="action" value="application-create"><input type="hidden" name="otp" value=""> '.
   '<label>Όνομα<input name="name" maxlength="160" required placeholder="Νέα εφαρμογή"></label>'.
   '<label>Slug<input name="slug" maxlength="70" pattern="[a-z][a-z0-9-]{2,69}" required placeholder="my-application"></label>'.
   '<label>Staging origin<input name="staging_origin" type="url" placeholder="https://stage.example.gr" required></label>'.
   '<label>Production origin<input name="production_origin" type="url" placeholder="https://app.example.gr" required></label>'.
   '<p class="management-hint">Η δημιουργία δεν ενεργοποιεί χρεώσεις. Χρειάζεται API Key και επιβεβαιωμένη δρομολόγηση webhooks.</p>'.
   '<button type="submit">Προσθήκη εφαρμογής</button></form></article>';
  return $s;
 }
 public static function application(PDO $reg,string $csrf,int $id):string {
  $a=self::app($reg,$id);
  $s='<p><a href="/ilm-admin/?p=applications">← Επιστροφή στις εφαρμογές</a></p><article><h2>'.self::h($a['name']).'</h2>'.
   '<p>Application ID: '.(int)$a['id'].' · Slug: '.self::h($a['slug']).'</p>';
  if($a['slug']==='iradiosgr')
   $s.='<p class="management-hint">Τα υπάρχοντα iRadios URLs είναι κλειδωμένα γιατί οι callbacks χρησιμοποιούν συγκεκριμένη διαδρομή.</p>';
  $s.='<form method="post" class="management-form" data-stepup="Ενημέρωση εφαρμογής">'.
   '<input type="hidden" name="_csrf" value="'.self::h($csrf).'"><input type="hidden" name="otp" value="">'.
   '<input type="hidden" name="application_id" value="'.(int)$a['id'].'"><input type="hidden" name="action" value="application-update">'.
   '<label>Όνομα<input name="name" maxlength="160" value="'.self::h($a['name']).'" required></label>'.
   '<label>Staging origin<input name="staging_origin" type="url" value="'.self::h($a['staging_origin']).'" required></label>'.
   '<label>Production origin<input name="production_origin" type="url" value="'.self::h($a['production_origin']).'" required></label>'.
   '<label class="management-check"><input type="checkbox" name="enabled" value="1"'.($a['enabled']?' checked':'').'> Ενεργή εφαρμογή</label>'.
   '<p class="management-hint">Απενεργοποίηση = χωρίς νέα αιτήματα. Δεν διαγράφουμε ιστορικό, συναλλαγές ή κλειδιά.</p>'.
   '<button type="submit">Αποθήκευση</button></form></article>';
  $s.='<p><a href="/ilm-admin/?p=keys&application_id='.(int)$a['id'].'">Διαχείριση API Keys</a> · '.
   '<a href="/ilm-admin/?p=products&application_id='.(int)$a['id'].'">Προϊόντα εφαρμογής</a></p>';
  return $s;
 }
 public static function products(PDO $reg,string $env,string $csrf,int $chosen=0):string {
  $apps=Db::all($reg,'SELECT id,slug,name FROM applications ORDER BY name');
  if(!$apps)return '<p>Πρόσθεσε πρώτα μία εφαρμογή.</p>';
  $found=false;
  foreach($apps as $a)if((int)$a['id']===$chosen)$found=true;
  if(!$found)$chosen=(int)$apps[0]['id'];
  $app=self::app($reg,$chosen);
  $db=Db::get($env);
  $rows=Db::all($db,'SELECT id,sku,title,amount_cents,currency,period_days,enabled FROM products WHERE application_id=? ORDER BY sku',[$chosen]);
  $s='<section class="dashboard-section-head"><div><h2>Products / '.self::h(strtoupper($env)).'</h2>'.
    '<p>Πραγματικές εγγραφές της βάσης '.self::h($env).'. Όχι ενδεικτικές τιμές κώδικα.</p></div></section>';
  $s.='<form method="get" action="/ilm-admin/"><input type="hidden" name="p" value="products"><label>Εφαρμογή<select name="application_id">';
  foreach($apps as $a)$s.='<option value="'.(int)$a['id'].'"'.((int)$a['id']===$chosen?' selected':'').'>'.self::h($a['name']).' · '.self::h($a['slug']).'</option>';
  $s.='</select></label> <button>Προβολή</button></form>';
  $s.='<p>Προϊόντα: <strong>'.count($rows).'</strong> · Περιβάλλον: <strong>'.self::h(strtoupper($env)).'</strong></p>';
  $s.='<div class="dashboard-table-scroll"><table><tr><th>SKU / Τίτλος</th><th>Ποσό</th><th>Περίοδος</th><th>Κατάσταση</th><th></th></tr>';
  foreach($rows as $r) {
   $locked=self::protectedSku(self::appSlug($app),(string)$r['sku']);
   $s.='<tr><td><strong>'.self::h($r['sku']).'</strong><br>'.self::h($r['title']).'</td>'.
     '<td>'.self::amount((int)$r['amount_cents']).'</td>'.
     '<td>'.($r['period_days']?(int)$r['period_days'].' ημέρες':'Εφάπαξ / κατά τη χρήση').'</td>'.
     '<td>'.($r['enabled']?'Ενεργό':'Ανενεργό').'</td>'.
     '<td>'.($locked?'<small>Τιμή προστατευμένη από integration</small>':
      '<a href="/ilm-admin/?p=product&application_id='.$chosen.'&sku='.rawurlencode((string)$r['sku']).'">Επεξεργασία</a>').'</td></tr>';
  }
  $s.='</table></div>';
  $s.='<p><a href="/ilm-admin/?p=product&application_id='.$chosen.'">+ Νέο προϊόν</a></p>';
  return $s;
 }
 public static function product(PDO $reg,string $env,string $csrf,int $appId,string $sku):string {
  $app=self::app($reg,$appId);
  $row=$sku===''?null:Db::one(Db::get($env),'SELECT * FROM products WHERE application_id=? AND sku=?',[$appId,$sku]);
  if($sku!==''&&!$row)throw new HttpError(404,'Το προϊόν δεν βρέθηκε.');
  if($row&&self::protectedSku(self::appSlug($app),(string)$row['sku']))
   throw new HttpError(403,'Το προϊόν ελέγχεται από τον κώδικα της ολοκλήρωσης.');
  $s='<p><a href="/ilm-admin/?p=products&application_id='.$appId.'">← Products</a></p>'.
     '<article><h2>'.($row?'Επεξεργασία προϊόντος':'Νέο προϊόν').' / '.self::h(strtoupper($env)).'</h2>'.
     '<p>Εφαρμογή: '.self::h($app['name']).'</p>'.
     '<form method="post" class="management-form" data-stepup="Αλλαγή καταλόγου προϊόντων">'.
     '<input type="hidden" name="_csrf" value="'.self::h($csrf).'"><input type="hidden" name="otp" value="">'.
     '<input type="hidden" name="action" value="product-save"><input type="hidden" name="application_id" value="'.$appId.'">'.
     '<label>SKU<input name="sku" maxlength="80" required '.($row?'readonly ':'').'value="'.self::h($row['sku']??'').'" placeholder="service-monthly"></label>'.
     '<label>Τίτλος<input name="title" maxlength="180" required value="'.self::h($row['title']??'').'"></label>'.
     '<label>Τιμή (€) <input name="price_eur" required inputmode="decimal" value="'.($row?number_format((int)$row['amount_cents']/100,2,',',''):'').'"></label>'.
     '<label>Περίοδος σε ημέρες (κενό για εφάπαξ)<input name="period_days" inputmode="numeric" value="'.self::h($row['period_days']??'').'"></label>'.
     '<label class="management-check"><input type="checkbox" name="enabled" value="1"'.(!$row||$row['enabled']?' checked':'').'> Ενεργό προϊόν</label>'.
     '<p class="management-hint">Τα παλιά payments κρατούν το αρχικό ποσό. Η αλλαγή τιμής ισχύει για νέες πληρωμές.</p>'.
     '<button type="submit">Αποθήκευση προϊόντος</button></form></article>';
  return $s;
 }
 public static function system(string $env):string {
  $db=Db::get($env);
  $known=['customers','products','payments','subscriptions','outgoing_events','event_deliveries'];
  $q=implode(',',array_fill(0,count($known),'?'));
  $rows=Db::all($db,"SELECT table_name AS name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ($q)",$known);
  $present=array_column($rows,'name');
  $missing=array_diff($known,$present);
  $unseen=Db::one($db,"SELECT COUNT(*) AS n FROM outgoing_events WHERE status IN ('pending','sending')");
  $failed=Db::one($db,"SELECT COUNT(*) AS n FROM payments WHERE status IN ('unknown','failed')");
  $reg=Db::get('registry');
  $merchant=Db::one($reg,'SELECT check_status,last_checked_at FROM nexi_credentials WHERE environment=?',[$env]);
  $live=Config::liveAllowed();
  $html='<h2>System Health / '.self::h(strtoupper($env)).'</h2>'.
   '<table><tr><th>Έλεγχος</th><th>Αποτέλεσμα</th></tr>'.
   '<tr><td>Βασικοί πίνακες</td><td>'.($missing?'Λείπουν: '.self::h(implode(', ',$missing)):'OK').'</td></tr>'.
   '<tr><td>Εκκρεμή Webhooks</td><td>'.(int)$unseen['n'].'</td></tr>'.
   '<tr><td>Αποτυχημένες / αβέβαιες πληρωμές</td><td>'.(int)$failed['n'].'</td></tr>'.
   '<tr><td>Nexi σύνδεση</td><td>'.self::h($merchant['check_status']??'not configured').'</td></tr>'.
   '<tr><td>Τελευταίος έλεγχος Nexi</td><td>'.self::h($merchant['last_checked_at']??'—').'</td></tr>'.
   '<tr><td>Production release gate</td><td>'.($live?'ENABLED':'LOCKED').'</td></tr></table>'.
   '<p class="management-hint">Δεν πραγματοποιήθηκε χρέωση ή κλήση προς Nexi σε αυτή την οθόνη. Οι workers και το merchant ID χρειάζονται επιβεβαίωση από υποδομή / Nexi.</p>';
  return $html;
 }
}
