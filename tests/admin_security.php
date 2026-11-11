<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use Iluma\Gateway\Security;
use Iluma\Gateway\HttpError;

function csrfCheckRejects(): bool {
    try { Security::verifyCsrf(); }
    catch (HttpError $e) { return $e->status === 403; }
    return false;
}
function assertAdminSecurity(bool $result, string $name): void {
    if (!$result) { fwrite(STDERR, "FAIL $name\n"); exit(1); }
    echo "PASS $name\n";
}

$_SESSION = [];
$_POST = [];
assertAdminSecurity(csrfCheckRejects(), 'CSRF rejects missing session and POST tokens');
$_SESSION['csrf'] = str_repeat('a', 64);
assertAdminSecurity(csrfCheckRejects(), 'CSRF rejects missing submitted token');
$_POST['_csrf'] = '';
assertAdminSecurity(csrfCheckRejects(), 'CSRF rejects empty submitted token');
$_POST['_csrf'] = str_repeat('b', 64);
assertAdminSecurity(csrfCheckRejects(), 'CSRF rejects mismatched token');
$_POST['_csrf'] = $_SESSION['csrf'];
assertAdminSecurity(!csrfCheckRejects(), 'CSRF accepts matching non-empty token');
$_SESSION = [];
$_POST = [];

echo "ADMIN SECURITY REGRESSIONS PASSED\n";
