<?php
declare(strict_types=1);
// Read-only aggregate comparison after restoring the three private SQL dumps.
// Never prints emails, API keys, secrets, customer identifiers or order IDs.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';

use Iluma\Gateway\Db;

$sets = [
    'registry' => ['applications','application_credentials','nexi_credentials','admin_users','audit_logs','api_nonces'],
    'staging' => ['customers','products','payments','subscriptions','outgoing_events','event_deliveries','payment_attempts','broadcaster_mandates','recurring_mandates','saved_card_agreements'],
    'production' => ['customers','products','payments','subscriptions','outgoing_events','event_deliveries','payment_attempts'],
];
foreach ($sets as $environment => $tables) {
    try {
        $db = Db::get($environment);
        $present = Db::all($db, "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()");
        $names = array_fill_keys(array_column($present, 'table_name'), true);
        echo strtoupper($environment)." TABLES=".count($names).PHP_EOL;
        foreach ($tables as $table) {
            if (!isset($names[$table])) {
                echo strtoupper($environment).' '.$table."=MISSING\n";
                continue;
            }
            $row = Db::one($db, "SELECT COUNT(*) AS n FROM ".$table);
            echo strtoupper($environment).' '.$table.'='.(string)$row['n'].PHP_EOL;
        }
        if (isset($names['payments'])) {
            $rows = Db::all($db,
                "SELECT status,COUNT(*) AS n,COALESCE(SUM(amount_cents),0) AS cents FROM payments GROUP BY status ORDER BY status");
            foreach ($rows as $row) {
                echo strtoupper($environment).' PAYMENTS['.$row['status'].']='.
                    $row['n'].' amount_cents='.$row['cents'].PHP_EOL;
            }
        }
        if (isset($names['outgoing_events'])) {
            $rows = Db::all($db,
                "SELECT status,COUNT(*) AS n FROM outgoing_events GROUP BY status ORDER BY status");
            foreach ($rows as $row) {
                echo strtoupper($environment).' OUTBOX['.$row['status'].']='.
                    $row['n'].PHP_EOL;
            }
        }
    } catch (\Throwable $e) {
        error_log('VPS aggregate audit failed for '.$environment.': '.get_class($e));
        fwrite(STDERR, "FAIL: aggregate audit unavailable for $environment\n");
        exit(1);
    }
}
echo "READ-ONLY: compare these totals to the same frozen Hostinger snapshot.\n";
