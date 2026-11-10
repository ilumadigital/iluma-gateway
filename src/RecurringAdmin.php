<?php
declare(strict_types=1);
namespace Iluma\Gateway;
use PDO;

/**
 * Read-only admin views of the actual Nexi MIT ledger. The legacy
 * "subscriptions" table is NOT the provider's recurring mandate ledger.
 */
final class RecurringAdmin {
 private static function esc(?string $value):string {
  return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 }
 private static function date(?string $utc):string {
  if(!$utc)return '—';
  try{
   return (new \DateTimeImmutable($utc,new \DateTimeZone('UTC')))
    ->setTimezone(new \DateTimeZone('Europe/Athens'))->format('d/m/Y H:i');
  }catch(\Throwable){return '—';}
 }
 private static function money(int $cents):string{
  return number_format($cents/100,2,',','.').' €';
 }
 private static function ready(PDO $db):bool {
  $r=Db::one($db,"SELECT COUNT(*) AS n FROM information_schema.tables
   WHERE table_schema=DATABASE() AND table_name='recurring_mandates'");
  return (int)($r['n']??0)===1;
 }
 public static function summary(PDO $db):string{
  if(!self::ready($db))return '<p>MIT schema: δεν έχει γίνει staging migration.</p>';
  $stats=Db::one($db,"SELECT
   COUNT(CASE WHEN status='active' THEN 1 END) AS active,
   COUNT(CASE WHEN status='past_due' THEN 1 END) AS past_due,
   COUNT(CASE WHEN status IN ('pending','verification_pending') THEN 1 END) AS verifying,
   COUNT(CASE WHEN status='active' AND next_due_at>=UTC_TIMESTAMP()
     AND next_due_at<DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY) THEN 1 END) AS upcoming
   FROM recurring_mandates");
  return '<p><b>Nexi MIT:</b> '.(int)$stats['active'].' ενεργές · '.
   (int)$stats['past_due'].' αποτυχημένες · '.(int)$stats['verifying'].
   ' προς επαλήθευση · '.(int)$stats['upcoming'].
   ' ανανεώσεις 7ημέρου. <a href="/ilm-admin/?p=subscriptions">Συνδρομές Nexi</a></p>';
 }
 public static function index(PDO $db,PDO $registry,string $query):string{
  if(!self::ready($db))
   return '<p>Δεν υπάρχει η staging migration recurring_mandates.</p>';
  $search=substr(trim($query),0,150);
  $where=$search!==''?' WHERE (c.email LIKE ? OR c.name LIKE ? OR m.id=?)':'';
  $args=$search!==''?['%'.$search.'%','%'.$search.'%',$search]:[];
  $applications=Db::all($registry,'SELECT id,name FROM applications');
  $names=[];foreach($applications as $app)$names[(int)$app['id']]=$app['name'];
  $rows=Db::all($db,"SELECT m.id,m.application_id,m.status,m.paid_through_at,
    m.next_due_at,m.amount_cents,m.currency,m.verified_at,
    c.name,c.email,pr.title
    FROM recurring_mandates m JOIN customers c ON c.id=m.customer_id
    JOIN payments p ON p.id=m.initial_payment_id
    JOIN products pr ON pr.id=p.product_id".$where."
    ORDER BY m.created_at DESC LIMIT 100",$args);
  $enabled=Config::get('MIT_SANDBOX_EXECUTION_ENABLED')==='1'
   && Config::get('MIT_SANDBOX_UAT_APPROVED')==='1'
   && Config::get('APP_ENV')==='staging';
  $html='<h2>Nexi MIT — Συνδρομές ακροατών</h2>';
  $html.='<p>Εκτέλεση αυτόματων χρεώσεων: <b>'.
   ($enabled?'ΕΝΕΡΓΗ (SANDBOX)':'ΑΠΕΝΕΡΓΟΠΟΙΗΜΕΝΗ').'</b>. Το ενεργό contract δεν συνεπάγεται χρέωση όσο οι σημαίες UAT είναι 0.</p>';
  $html.='<form method="get"><input type="hidden" name="p" value="subscriptions">'.
   '<input name="q" placeholder="Email / όνομα / Mandate ID" value="'.self::esc($search).'"><button>Αναζήτηση</button></form>';
  $html.='<table><tr><th>Πελάτης / εφαρμογή</th><th>Πλάνο</th><th>Μηνιαίο ποσό</th><th>MIT</th><th>Πληρωμένη έως</th><th>Επόμενη χρέωση</th><th></th></tr>';
  foreach($rows as $r){
   $name=$names[(int)$r['application_id']]??('App #'.$r['application_id']);
   $html.='<tr><td>'.self::esc($r['email']).'<br><small>'.self::esc($r['name']).' · '.self::esc($name).'</small></td>';
   $html.='<td>'.self::esc($r['title']).'</td><td>'.self::money((int)$r['amount_cents']).'/μήνα</td>';
   $html.='<td><span class="status '.self::esc($r['status']).'">'.self::esc($r['status']).'</span></td>';
   $html.='<td>'.self::date($r['paid_through_at']).'</td><td>'.self::date($r['next_due_at']).'</td>';
   $html.='<td><a href="/ilm-admin/?p=subscription&id='.self::esc($r['id']).'">Ανάλυση</a></td></tr>';
  }
  $html.='</table>';
  if(!$rows)$html.='<p>Δεν βρέθηκαν MIT συνδρομές.</p>';
  return $html;
 }
 public static function detail(PDO $db,PDO $registry,string $id):string{
  if(!self::ready($db)||!preg_match('/^[a-f0-9]{32}$/D',$id))
   throw new HttpError(404,'Subscription not found');
  $m=Db::one($db,"SELECT m.*,c.name,c.email,c.external_user_id,
   p.id AS initial_id,p.order_id AS initial_order,p.status AS initial_status,
   pr.title FROM recurring_mandates m JOIN customers c ON c.id=m.customer_id
   JOIN payments p ON p.id=m.initial_payment_id
   JOIN products pr ON pr.id=p.product_id WHERE m.id=?",[$id]);
  if(!$m)throw new HttpError(404,'Subscription not found');
  $app=Db::one($registry,'SELECT name FROM applications WHERE id=?',[(int)$m['application_id']]);
  $info=[
   'Πελάτης'=>$m['name'].' · '.$m['email'],
   'Εφαρμογή'=>$app['name']??'—',
   'Προϊόν'=>$m['title'],
   'Μηνιαίο ποσό'=>self::money((int)$m['amount_cents']),
   'MIT status'=>$m['status'],
   'Συναίνεση'=>$m['consent_version'],
   'Ημερομηνία συναίνεσης'=>self::date($m['consent_at']),
   'Επιβεβαίωση Nexi'=>self::date($m['verified_at']),
   'Πληρωμένη έως'=>self::date($m['paid_through_at']),
   'Επόμενη χρέωση'=>self::date($m['next_due_at']),
   'Ακύρωση'=>self::date($m['cancelled_at']),
   'Απενεργοποίηση Nexi'=>self::date($m['deactivated_at']),
   'Τελευταίο σφάλμα'=>$m['last_error_code']??'—',
   'Nexi contract ID (τελευταίοι 6 χαρακτήρες)'=>'••••••'.substr((string)$m['contract_id'],-6)
  ];
  $html='<p><a href="/ilm-admin/?p=subscriptions">← Όλες οι συνδρομές</a></p><h2>Nexi MIT · Ανάλυση συνδρομής</h2>';
  foreach($info as $title=>$value)$html.='<p><b>'.self::esc($title).':</b> '.self::esc((string)$value).'</p>';
  $html.='<p>Αρχική πληρωμή: <a href="/ilm-admin/?p=payment&id='.self::esc($m['initial_id']).'">'.
   self::esc($m['initial_order']).'</a> · '.self::esc($m['initial_status']).'</p>';
  $cycles=Db::all($db,"SELECT cy.cycle_due_at,cy.status,
   p.id AS payment_id,p.order_id,p.status AS payment_status,p.amount_cents
   FROM recurring_cycles cy JOIN payments p ON p.id=cy.payment_id
   WHERE cy.mandate_id=? ORDER BY cy.cycle_due_at DESC LIMIT 100",[$id]);
  $html.='<h2>Κύκλοι αυτόματων χρεώσεων</h2><table><tr><th>Κύκλος</th><th>Πληρωμή</th><th>Ποσό</th><th>Αποτέλεσμα</th></tr>';
  foreach($cycles as $cycle){
   $html.='<tr><td>'.self::date($cycle['cycle_due_at']).'</td><td><a href="/ilm-admin/?p=payment&id='.
    self::esc($cycle['payment_id']).'">'.self::esc($cycle['order_id']).'</a></td><td>'.
    self::money((int)$cycle['amount_cents']).'</td><td>'.
    self::esc($cycle['status']).' / '.self::esc($cycle['payment_status']).'</td></tr>';
  }
  $html.='</table>';
  if(!$cycles)$html.='<p>Δεν έχει πραγματοποιηθεί ακόμη αυτόματος κύκλος. Η πρώτη πληρωμή έγινε μέσω Nexi HPP.</p>';
  return $html;
 }
 public static function byPayment(PDO $db,string $paymentId):?string{
  if(!self::ready($db)||!preg_match('/^[a-f0-9]{32}$/D',$paymentId))return null;
  $mandate=Db::one($db,'SELECT id FROM recurring_mandates WHERE initial_payment_id=?',[$paymentId]);
  if(!$mandate)$mandate=Db::one($db,"SELECT m.id FROM recurring_cycles cy
    JOIN recurring_mandates m ON m.id=cy.mandate_id WHERE cy.payment_id=?",[$paymentId]);
  return $mandate['id']??null;
 }
}
