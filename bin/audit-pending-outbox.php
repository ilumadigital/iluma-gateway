<?php
declare(strict_types=1);
// Read-only summary of delayed outbox events by tenant, route, event type
// and provider-confirmed ledger state. No personal data or secrets.
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/src/bootstrap.php';
use Iluma\Gateway\Db;

foreach(['staging','production'] as $env){
 echo '=== '.strtoupper($env)." PENDING OUTBOX ===\n";
 $db=Db::get($env);
 // Base tenant.sql lacks the optional production route-provenance migration.
 // Never infer direct-play routing when the route column is absent.
 $hasRouteColumn=false;
 if($env==='production'){
  $column=Db::one($db,"SELECT COUNT(*) AS n FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='payments'
      AND column_name='iradios_stage_routed'");
  $hasRouteColumn=(int)($column['n']??0)===1;
  if(!$hasRouteColumn)echo "PRODUCTION_ROUTE_SCHEMA=UNAVAILABLE\n";
 }
 // MySQL interprets GROUP BY 0 as positional column #0, not a literal.
 // Group by the real route column ONLY when it exists; otherwise group by
 // event and payment state, with explicit non-claiming route metadata.
 $routeSelect=$env==='staging'?'0':
  ($hasRouteColumn?'p.iradios_stage_routed':"'SCHEMA_MISSING'");
 $routeGroup=$hasRouteColumn?',p.iradios_stage_routed':'';
 $rows=Db::all($db,"SELECT e.event_type,p.status AS payment_status,
  $routeSelect AS route_flag,COUNT(*) AS n,
  MAX(e.attempts) AS max_attempts,
  COALESCE(MIN(TIMESTAMPDIFF(MINUTE,UTC_TIMESTAMP(),e.next_attempt_at)),0) AS min_retry_minutes
  FROM outgoing_events e JOIN payments p ON p.id=e.payment_id
  WHERE e.status='pending'
  GROUP BY e.event_type,p.status$routeGroup
  ORDER BY e.event_type,p.status");
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
