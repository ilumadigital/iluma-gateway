<?php
declare(strict_types=1);
// Independent, read-only CLI probe. Works even when AdminController.php has a
// parse error, and without a Git checkout, database, Nexi or private ENV file.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__).'/src/SourceIntegrity.php';

use Iluma\Gateway\SourceIntegrity;

$status = SourceIntegrity::inspect(dirname(__DIR__));
if ($status['ok']) {
    echo "PASS: active Admin source matches the approved Git blob\n";
    $legacy = SourceIntegrity::inspectLegacy(dirname(__DIR__));
    if (!$legacy['ok']) {
        // The legacy filename is NOT on the live HTTP execution path.
        fwrite(STDERR, sprintf("WARN: unused legacy AdminController.php was modified (reason=%s actual=%s); investigate external writer\n",
            $legacy['reason'], $legacy['actual']));
    }
    exit(0);
}
fwrite(STDERR, sprintf(
    "FAIL: Admin source drift reason=%s expected=%s actual=%s mtime_utc=%s inode=%s\n",
    $status['reason'], SourceIntegrity::ADMIN_GIT_BLOB, $status['actual'],
    $status['mtime'], $status['inode']
));
exit(1);
