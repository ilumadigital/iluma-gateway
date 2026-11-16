<?php
declare(strict_types=1);
namespace Iluma\Gateway;

use PDO;

/** Scoped, read-only operational diagnostics plus an audited retry scheduler. */
final class AdminMonitoring {
 public static function webhooks(string $environment,PDO $registry,string $csrf):string {
  $db=Db::get($environment);
  $totals=Db::all($db,"SELECT status,COUNT(*) AS total FROM outgoing_events GROUP BY status");
  $counts=[];
  foreach($totals as $r)$counts[$r['status']]=(int)$r['total'];
  $apps=Db::all($registry,'SELECT id,name FROM applications');
  $names=[];
  foreach($apps as $a)$names[(int)$a['id']]=(string)$a['name'];
  $rows=Db::all($db,"SELECT e.id,e.application_id,e.event_type,e.status,e.attempts,
       e.created_at,e.next_attempt_at,p.order_id,
       (SELECT d.http_status FROM event_deliveries d WHERE d.event_id=e.id
        ORDER BY d.id DESC LIMIT 1) AS last_http,
       (SELECT d.result FROM event_deliveries d WHERE d.event_id=e.id
        ORDER BY d.id DESC LIMIT 1) AS last_result
     FROM outgoing_events e JOIN payments p ON p.id=e.payment_id
     ORDER BY e.created_at DESC LIMIT 100");
  $s='<section class="dashboard-section-head"><div><h2>Webhooks / '.Security::h(strtoupper($environment)).'</h2>'.
     '<p>Αυτό είναι το πραγματικό outbox της επιλεγμένης βάσης. Η επανάληψη δεν ξαναχρεώνει την κάρτα.</p></div></section>';
  $s.='<p><strong>Pending: '.(int)($counts['pending']??0).
     '</strong> · Sending: '.(int)($counts['sending']??0).
     ' · Delivered: '.(int)($counts['delivered']??0).'</p>';
  $s.='<div class="dashboard-table-scroll"><table><tr><th>Order / Event</th><th>Εφαρμογή</th>'.
     '<th>Κατάσταση</th><th>Απόπειρες</th><th>HTTP</th><th>Επόμενο</th><th></th></tr>';
  foreach($rows as $r){
   $s.='<tr><td><strong>'.Security::h((string)$r['order_id']).'</strong><br>'.
      Security::h((string)$r['event_type']).'</td><td>'.
      Security::h($names[(int)$r['application_id']]??'Unknown').'</td><td>'.
      Security::h((string)$r['status']).'</td><td>'.(int)$r['attempts'].'</td><td>'.
      Security::h((string)($r['last_http']??'—')).' · '.Security::h((string)($r['last_result']??'—')).
      '</td><td>'.Security::h((string)$r['next_attempt_at']).'</td><td>';
   if($r['status']==='pending')
    $s.='<form method="post" data-stepup="Επαναπρογραμματισμός webhook">'.
     '<input type="hidden" name="_csrf" value="'.Security::h($csrf).'">'.
     '<input type="hidden" name="otp" value=""><input type="hidden" name="event_id" value="'.Security::h((string)$r['id']).'">'.
     '<button name="action" value="webhook-retry">Retry</button></form>';
   $s.='</td></tr>';
  }
  $s.='</table></div><p class="management-hint">Το Retry επαναπρογραμματίζει μόνο υπάρχον pending event. Δεν δημιουργεί νέα πληρωμή ούτε παρακάμπτει επαλήθευση Nexi.</p>';
  return $s;
 }
 public static function retry(string $environment,string $eventId):string {
  if(!preg_match('/^[a-f0-9]{32}$/D',$eventId))
   throw new HttpError(422,'Invalid event ID');
  $db=Db::get($environment);
  $count=Db::exec($db,"UPDATE outgoing_events SET next_attempt_at=UTC_TIMESTAMP()
   WHERE id=? AND status='pending'",[$eventId]);
  if(!$count)throw new HttpError(409,'Το webhook δεν είναι πλέον σε κατάσταση pending.');
  return 'Το υπάρχον webhook μπήκε στην ουρά επανάληψης. Δεν έγινε νέα χρέωση.';
 }
 public static function refunds(string $environment):string {
  $rows=Db::all(Db::get($environment),"SELECT r.id,r.payment_id,r.amount_cents,r.status,
      r.created_at,r.confirmed_at,p.order_id
    FROM refunds r JOIN payments p ON p.id=r.payment_id
    ORDER BY r.created_at DESC LIMIT 100");
  $s='<h2>Επιστροφές / '.Security::h(strtoupper($environment)).'</h2>'.
    '<p class="management-hint">Προβολή ledger μόνο. Η πραγματική εκτέλεση refund απαιτεί εγκεκριμένη Nexi integration· δεν δηλώνουμε επιστροφή χωρίς επιβεβαίωση από τον πάροχο.</p>'.
    '<div class="dashboard-table-scroll"><table><tr><th>Order</th><th>Ποσό</th><th>Κατάσταση</th><th>Δημιουργία</th><th>Επιβεβαίωση</th></tr>';
  foreach($rows as $r)
   $s.='<tr><td>'.Security::h((string)$r['order_id']).'</td><td>'.
     number_format((int)$r['amount_cents']/100,2,',','.').' €</td><td>'.
     Security::h((string)$r['status']).'</td><td>'.Security::h((string)$r['created_at']).
     '</td><td>'.Security::h((string)($r['confirmed_at']??'—')).'</td></tr>';
  return $s.'</table></div>';
 }
}
