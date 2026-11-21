<?php
declare(strict_types=1);
// Read-only summary of delayed outbox events by tenant, route, event type
// and provider-confirmed ledger state. No personal data or secrets.
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/src/bootstrap.php';
use Iluma\Gateway\Db;
foreach(['staging','production'] as $env){
 echo '=== '.strtoupper($env)." PENDING OUTBOX ===\n";
 $route=$env==='production'?'p.iradios_stage_routed':'0';
 $rows=Db::all(Db::get($env),"SELECT e.event_type,p.status AS payment_status,
  $route AS route_flag,COUNT(*) AS n,
  MAX(e.attempts) AS max_attempts,
  COALESCE(MIN(TIMESTAMPDIFF(MINUTE,UTC_TIMESTAMP(),e.next_attempt_at)),0) AS min_retry_minutes
  FROM outgoing_events e JOIN payments p ON p.id=e.payment_id
  WHERE e.status='pending'
  GROUP BY e.event_type,p.status,$route
  ORDER BY e.event_type,p.status,$route");
 foreach($rows as $r){
  echo 'EVENT=',(string)$r['event_type'],
    ' PAYMENT_STATE=',(string)$r['payment_status'],
    ' ROUTE=',(string)$r['route_flag'],
    ' COUNT=',(string)$r['n'],
    ' MAX_ATTEMPTS=',(string)$r['max_attempts'],
    ' RETRY_MIN=',(string)$r['min_retry_minutes'],"\n";
 }
}
echo "READ_ONLY: do not re-route or grant rights from this aggregate report.\n";
