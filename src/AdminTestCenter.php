<?php
declare(strict_types=1);
namespace Iluma\Gateway;

use PDO;

/**
 * Operator-only one-euro checkout. Isolated from iRadios subscription catalog.
 * AdminApplication performs CSRF and fresh TOTP before every write.
 */
final class AdminTestCenter {
 public const SLUG='iluma-internal';
 public const SKU='ILUMA-TEST-100';
 private static function app(PDO $registry):?array {
  return Db::one($registry,'SELECT id,slug FROM applications WHERE slug=?',[self::SLUG]) ?: null;
 }
 private static function schemaReady(string $env):bool {
  $db=Db::get($env);
  $tables=Db::one($db,"SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=DATABASE()
    AND table_name IN ('customers','products','payments','payment_logs','outgoing_events','event_deliveries')");
  if((int)($tables['n']??0)!==6)return false;
  if($env==='production'){
   $columns=Db::one($db,"SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema=DATABASE()
     AND table_name='payments' AND column_name IN ('iradios_stage_routed','origin_credential_id')");
   return (int)($columns['n']??0)===2;
  }
  return true;
 }
 private static function prepared(string $env,PDO $registry):?array {
  $app=self::app($registry);
  if(!$app)return null;
  return Db::one(Db::get($env),"SELECT id,amount_cents,currency,period_days,enabled FROM products
   WHERE application_id=? AND sku=?",[$app['id'],self::SKU]) ?: null;
 }
 public static function prepare(string $env,PDO $registry):string {
  if(!in_array($env,['staging','production'],true))throw new HttpError(422,'Invalid environment');
  if(!self::schemaReady($env))throw new HttpError(503,'Η δομή της βάσης δεν είναι έτοιμη για ασφαλή δοκιμή.');
  $app=self::app($registry);
  if(!$app){
   Db::exec($registry,"INSERT INTO applications(slug,name,staging_origin,production_origin,enabled)
    VALUES('iluma-internal','ILUMA Internal Payment Test','https://gateway.iluma.gr','https://gateway.iluma.gr',1)");
   $app=self::app($registry);
  }
  if(!$app)throw new \RuntimeException('Missing internal test application');
  $product=self::prepared($env,$registry);
  if($product) {
   if((int)$product['amount_cents']!==100 || $product['currency']!=='EUR' ||
      (int)$product['enabled']!==1 || $product['period_days']!==null)
    throw new HttpError(409,'Το εσωτερικό SKU υπάρχει με διαφορετικές ρυθμίσεις.');
  } else {
   Db::exec(Db::get($env),"INSERT INTO products(application_id,sku,title,amount_cents,currency,period_days,enabled)
    VALUES(?,?,?,100,'EUR',NULL,1)",[$app['id'],self::SKU,'ILUMA Gateway — δοκιμή 1€']);
  }
  return 'Το ανεξάρτητο προϊόν δοκιμής 1€ είναι έτοιμο. Δεν δημιουργήθηκε χρέωση.';
 }
 public static function start(string $env,PDO $registry,int $adminId,array $post):array {
  if(!in_array($env,['staging','production'],true))throw new HttpError(422,'Invalid environment');
  if((string)($post['confirm_charge']??'')!=='YES')
   throw new HttpError(422,'Απαιτείται ρητή αποδοχή της δοκιμαστικής πληρωμής.');
  if($env==='production'&&!Config::liveAllowed())
   throw new HttpError(503,'Οι πραγματικές πληρωμές είναι κλειδωμένες στο Gateway.');
  if(!self::schemaReady($env))throw new HttpError(503,'Η βάση δεν έχει το απαιτούμενο schema.');
  $app=self::app($registry);
  $product=self::prepared($env,$registry);
  if(!$app||!$product||(int)$product['amount_cents']!==100||
    $product['currency']!=='EUR'||(int)$product['enabled']!==1||
    $product['period_days']!==null)
   throw new HttpError(503,'Προετοίμασε πρώτα το εσωτερικό προϊόν 1€.');
  $admin=Db::one($registry,'SELECT email FROM admin_users WHERE id=? AND enabled=1',[$adminId]);
  if(!$admin)throw new HttpError(403,'Δεν βρέθηκε ενεργός διαχειριστής.');
  // Reuse the one server-side intent on accidental resubmission. Never trust a
  // browser-supplied payment amount, application, key, customer or environment.
  if(empty($_SESSION['test_payment_intent'])||
     !preg_match('/^[a-f0-9]{32}$/D',(string)$_SESSION['test_payment_intent']))
   $_SESSION['test_payment_intent']=Security::id();
  $nonce=(string)$_SESSION['test_payment_intent'];
  $result=Payments::checkout(
   ['environment'=>$env,'application_id'=>(int)$app['id'],'slug'=>self::SLUG],
   ['customer_id'=>'iluma-admin-test-'.$adminId,'product_sku'=>self::SKU,
     'email'=>(string)$admin['email'],'name'=>'ILUMA Internal Test'],
   'admin-test-'.$nonce
  );
  // Preserve idempotency if checkout outcome is uncertain; do not reset
  // intent until operator explicitly requests a new test.
  return $result;
 }
 public static function resetIntent():void {
  $_SESSION['test_payment_intent']=Security::id();
 }
 public static function view(string $env,PDO $registry,string $csrf,string $order=''):string {
  $app=self::app($registry);
  $product=self::prepared($env,$registry);
  $ready=self::schemaReady($env);
  $isLive=$env==='production';
  $merchant=Db::one($registry,'SELECT check_status,last_checked_at FROM nexi_credentials WHERE environment=?',[$env]);
  $html='<section class="dashboard-section-head"><div><h2>Gateway Test Center</h2>'.
   '<p>Δοκιμαστική εφάπαξ πληρωμή 1€ — χωρίς συνδρομή iRadios.</p></div></section>';
  $html.='<article><h2>'.($isLive?'LIVE / Πραγματική χρέωση':'STAGING / Sandbox').'</h2>'.
   '<p>Schema: '.($ready?'OK':'NOT READY').'</p>'.
   '<p>Gateway Production: '.(Config::liveAllowed()?'ENABLED':'LOCKED').'</p>'.
   '<p>Nexi connection check: '.Security::h((string)($merchant['check_status']??'not configured')).'</p>'.
   '<p>Test product: '.($product?'1,00 € / '.self::SKU:'Δεν έχει προετοιμαστεί').'</p>'.
   '<p class="management-hint">Το Test Center δεν επιβεβαιώνει μόνο του το Terminal/Merchant ID. Ταυτοποίησε το Order ID στο Nexi Merchant Portal.</p>';
  if(!$ready){
   $html.='<p class="alert">Λείπουν απαιτούμενοι πίνακες ή production columns. Δεν ξεκινάμε χρέωση.</p>';
  } else {
   if(!$product){
    $html.='<form method="post" data-stepup="Προετοιμασία δοκιμής 1 ευρώ">'.
     '<input type="hidden" name="_csrf" value="'.Security::h($csrf).'"><input type="hidden" name="otp" value="">'.
     '<button name="action" value="test-prepare">Προετοιμασία δοκιμής (χωρίς χρέωση)</button></form>';
   } else {
    $html.='<form method="post" data-stepup="Πραγματική δοκιμαστική πληρωμή 1 ευρώ">'.
     '<input type="hidden" name="_csrf" value="'.Security::h($csrf).'"><input type="hidden" name="otp" value="">'.
     '<label class="management-check"><input type="checkbox" name="confirm_charge" value="YES" required> Κατανοώ ότι θα δημιουργηθεί checkout 1€ '.($isLive?'με πραγματική κάρτα και πιθανή χρέωση':'στο sandbox').'.</label>'.
     '<button name="action" value="test-start"'.($isLive&&!Config::liveAllowed()?' disabled':'').'>Δημιουργία ασφαλούς checkout 1€</button></form>';
    $html.='<form method="post" data-stepup="Νέα δοκιμαστική απόπειρα"><input type="hidden" name="_csrf" value="'.Security::h($csrf).'"><input type="hidden" name="otp" value=""><button name="action" value="test-new">Νέα δοκιμαστική απόπειρα</button></form>';
   }
  }
  $html.='</article>';
  if($app){
   $rows=Db::all(Db::get($env),'SELECT p.order_id,p.status,p.amount_cents,p.confirmed_at,p.created_at,p.id
    FROM payments p WHERE p.application_id=? ORDER BY p.created_at DESC LIMIT 20',[(int)$app['id']]);
   $html.='<article><h2>Ιστορικό εσωτερικών tests</h2>'.
    '<div class="dashboard-table-scroll"><table><tr><th>Order ID</th><th>Ποσό</th><th>Κατάσταση</th><th>Επιβεβαίωση</th></tr>';
   foreach($rows as $r){
    $highlight=$order!==''&&hash_equals($order,(string)$r['order_id'])?' <strong>← Return</strong>':'';
    $html.='<tr><td><code>'.Security::h((string)$r['order_id']).'</code>'.$highlight.'</td>'.
     '<td>'.number_format((int)$r['amount_cents']/100,2,',','.').' €</td>'.
     '<td>'.Security::h((string)$r['status']).'</td>'.
     '<td>'.Security::h((string)($r['confirmed_at']??'—')).'</td></tr>';
   }
   $html.='</table></div></article>';
  }
  return $html;
 }
}
