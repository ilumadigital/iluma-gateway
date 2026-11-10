<?php
declare(strict_types=1);
namespace Iluma\Gateway;
use PDO;

/** Read-only Broadcaster MIT admin views alongside, never replacing, Listener. */
final class BroadcasterRecurringAdmin {
 private static function e(?string $value):string{
  return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 }
 private static function date(?string $value):string{
  if(!$value)return '—';
  try{return (new \DateTimeImmutable($value,new \DateTimeZone('UTC')))
   ->setTimezone(new \DateTimeZone('Europe/Athens'))->format('d/m/Y H:i');}
  catch(\Throwable){return '—';}
 }
 private static function money(int $cents):string{
  return number_format($cents/100,2,',','.').' €';
 }
 private static function ready(PDO $db):bool {
  $r=Db::one($db,"SELECT COUNT(*) AS n FROM information_schema.tables
   WHERE table_schema=DATABASE() AND table_name='broadcaster_mandates'");
  return (int)($r['n']??0)===1;
 }
 public static function hasMandate(PDO $db,string $id):bool{
  if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!self::ready($db))return false;
  return (bool)Db::one($db,'SELECT id FROM broadcaster_mandates WHERE id=?',[$id]);
 }
 public static function byPayment(PDO $db,string $paymentId):?string{
  if(!self::ready($db)||!preg_match('/^[a-f0-9]{32}$/D',$paymentId))return null;
  $initial=Db::one($db,'SELECT id FROM broadcaster_mandates WHERE initial_payment_id=?',[$paymentId]);
  if(!$initial)$initial=Db::one($db,"SELECT m.id FROM broadcaster_cycles cy
    JOIN broadcaster_mandates m ON m.id=cy.mandate_id WHERE cy.payment_id=?",[$paymentId]);
  return $initial['id']??null;
 }
 public static function scopeByPayment(PDO $db,string $id):?array{
  if(!self::ready($db)||!preg_match('/^[a-f0-9]{32}$/D',$id))return null;
  $r=Db::one($db,"SELECT sku,resource_id FROM broadcaster_mandates
   WHERE initial_payment_id=?",[$id]);
  if(!$r)$r=Db::one($db,"SELECT m.sku,m.resource_id FROM broadcaster_cycles c
   JOIN broadcaster_mandates m ON m.id=c.mandate_id WHERE c.payment_id=?",[$id]);
  if($r)return ['sku'=>$r['sku'],'resource_id'=>$r['resource_id']];
  $boostSchema=Db::one($db,"SELECT COUNT(*) AS n FROM information_schema.tables
   WHERE table_schema=DATABASE() AND table_name='broadcaster_boost_scopes'");
  if((int)($boostSchema['n']??0)!==1)return null;
  $boost=Db::one($db,'SELECT sku,resource_id FROM broadcaster_boost_scopes WHERE payment_id=?',[$id]);
  return $boost?['sku'=>$boost['sku'],'resource_id'=>$boost['resource_id']]:null;
 }
 public static function index(PDO $db,PDO $registry,string $query=''):string{
  if(!self::ready($db))return '<h2>Nexi MIT — Broadcasters</h2><p>Δεν υπάρχει ακόμη το staging Broadcaster schema.</p>';
  $q=substr(trim($query),0,150);
  $where=$q!==''?' WHERE (c.email LIKE ? OR c.name LIKE ? OR m.id=? OR m.resource_id=?)':'';
  $params=$q!==''?['%'.$q.'%','%'.$q.'%',$q,$q]:[];
  $applications=Db::all($registry,'SELECT id,name FROM applications');
  $names=[];foreach($applications as $app)$names[(int)$app['id']]=$app['name'];
  $rows=Db::all($db,"SELECT m.id,m.application_id,m.sku,m.resource_id,m.status,
    m.paid_through_at,m.next_due_at,m.amount_cents,c.email,c.name
   FROM broadcaster_mandates m JOIN customers c ON c.id=m.customer_id".$where."
   ORDER BY m.created_at DESC LIMIT 150",$params);
  $enabled=Config::get('APP_ENV')==='staging'
   &&Config::get('BROADCASTER_MIT_SANDBOX_EXECUTION_ENABLED')==='1'
   &&Config::get('BROADCASTER_MIT_SANDBOX_UAT_APPROVED')==='1';
  $html='<h2>Broadcaster Premium & Add-ons — Nexi MIT</h2>';
  $html.='<p>Αυτόματες Broadcaster MIT: <strong>'.
   ($enabled?'ΕΝΕΡΓΕΣ — SANDBOX':'ΚΛΕΙΔΩΜΕΝΕΣ / UAT').'</strong>. Κάθε συνδρομή είναι ανεξάρτητη ανά προϊόν και σταθμό.</p>';
  $html.='<table><tr><th>Πελάτης / εφαρμογή</th><th>Προϊόν / σταθμός</th><th>Μηνιαία χρέωση</th><th>Κατάσταση</th><th>Πληρωμένη έως</th><th>Επόμενη χρέωση</th><th></th></tr>';
  foreach($rows as $item){
   $app=$names[(int)$item['application_id']]??('App #'.$item['application_id']);
   $html.='<tr><td>'.self::e($item['email']).'<br><small>'.self::e($item['name']).' · '.self::e($app).'</small></td>';
   $html.='<td>'.self::e($item['sku']).'<br><small>'.self::e($item['resource_id']??'Όλος ο λογαριασμός').'</small></td>';
   $html.='<td>'.self::money((int)$item['amount_cents']).'/μήνα</td>';
   $html.='<td><span class="status '.self::e($item['status']).'">'.self::e($item['status']).'</span></td>';
   $html.='<td>'.self::date($item['paid_through_at']).'</td><td>'.self::date($item['next_due_at']).'</td>';
   $html.='<td><a href="/ilm-admin/?p=subscription&id='.self::e($item['id']).'">Ανάλυση</a></td></tr>';
  }
  $html.='</table>';
  if(!$rows)$html.='<p>Δεν έχουν ενεργοποιηθεί Broadcaster MIT συνδρομές.</p>';
  return $html;
 }
 public static function detail(PDO $db,PDO $registry,string $id):string{
  if(!self::hasMandate($db,$id))throw new HttpError(404,'Broadcaster subscription not found');
  $r=Db::one($db,"SELECT m.*,c.email,c.name,c.external_user_id,p.order_id AS initial_order,
    p.status AS initial_status FROM broadcaster_mandates m
    JOIN customers c ON c.id=m.customer_id
    JOIN payments p ON p.id=m.initial_payment_id WHERE m.id=?",[$id]);
  $app=Db::one($registry,'SELECT name FROM applications WHERE id=?',[(int)$r['application_id']]);
  $info=['Πελάτης'=>$r['name'].' · '.$r['email'],
   'Εφαρμογή'=>$app['name']??'—','Προϊόν'=>$r['sku'],
   'Station ID'=>$r['resource_id']??'Όλος ο λογαριασμός',
   'Μηνιαία χρέωση'=>self::money((int)$r['amount_cents']),
   'Nexi MIT status'=>$r['status'],
   'Συναίνεση'=>$r['consent_version'],
   'Ημερομηνία συναίνεσης'=>self::date($r['consent_at']),
   'Επιβεβαίωση Nexi'=>self::date($r['verified_at']),
   'Πληρωμένη έως'=>self::date($r['paid_through_at']),
   'Επόμενη χρέωση'=>self::date($r['next_due_at']),
   'Ακύρωση'=>self::date($r['cancelled_at']),
   'Απενεργοποίηση Nexi'=>self::date($r['deactivated_at']),
   'Τελευταίο σφάλμα'=>$r['last_error_code']??'—',
   'Nexi contract (τελευταίοι 6 χαρακτήρες)'=>'••••••'.substr((string)$r['contract_id'],-6)];
  $html='<p><a href="/ilm-admin/?p=subscriptions">← Συνδρομές</a></p><h2>Broadcaster Nexi MIT · Ανάλυση</h2>';
  foreach($info as $label=>$value)$html.='<p><b>'.self::e($label).':</b> '.self::e((string)$value).'</p>';
  $html.='<p>Πρώτη συναλλαγή: <a href="/ilm-admin/?p=payment&id='.
   self::e($r['initial_payment_id']).'">'.self::e($r['initial_order']).'</a> · '.self::e($r['initial_status']).'</p>';
  $cycles=Db::all($db,"SELECT cy.cycle_due_at,cy.status,p.id,p.order_id,p.amount_cents,p.status AS payment_status
    FROM broadcaster_cycles cy JOIN payments p ON p.id=cy.payment_id
    WHERE cy.mandate_id=? ORDER BY cy.cycle_due_at DESC LIMIT 100",[$id]);
  $html.='<h2>Μηνιαίοι κύκλοι</h2><table><tr><th>Κύκλος</th><th>Συναλλαγή</th><th>Ποσό</th><th>Κατάσταση</th></tr>';
  foreach($cycles as $cy){
   $html.='<tr><td>'.self::date($cy['cycle_due_at']).'</td><td><a href="/ilm-admin/?p=payment&id='.
    self::e($cy['id']).'">'.self::e($cy['order_id']).'</a></td><td>'.
    self::money((int)$cy['amount_cents']).'</td><td>'.
    self::e($cy['status'].' / '.$cy['payment_status']).'</td></tr>';
  }
  $html.='</table>';
  if(!$cycles)$html.='<p>Δεν υπάρχει ακόμη αυτόματος μήνας. Μόνο πρώτη αγορά με Nexi HPP.</p>';
  return $html;
 }
}
