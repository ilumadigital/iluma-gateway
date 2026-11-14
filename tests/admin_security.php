<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use Iluma\Gateway\Security;
use Iluma\Gateway\HttpError;
use Iluma\Gateway\AdminLoginState;
use Iluma\Gateway\AdminTurnstile;

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

$ip='192.0.2.42';
assertAdminSecurity(AdminLoginState::current($ip)===null, 'No pending MFA means no login');
AdminLoginState::begin(123,$ip);
assertAdminSecurity(AdminLoginState::current($ip)['admin_id']===123
    && empty($_SESSION['admin_id']), 'Password stage does not authenticate user');
assertAdminSecurity(AdminLoginState::current('192.0.2.43')===null, 'MFA challenge is IP-bound');
AdminLoginState::begin(123,$ip);
for($i=0;$i<4;$i++) assertAdminSecurity(AdminLoginState::failed($ip)===$i+1, 'MFA failure counted');
assertAdminSecurity(AdminLoginState::failed($ip)===5 && AdminLoginState::current($ip)===null,
    'Five invalid MFA attempts invalidate challenge');
AdminLoginState::begin(123,$ip);
$_SESSION['pending_admin_login']['created_at']=time()-301;
assertAdminSecurity(AdminLoginState::current($ip)===null,'Expired MFA challenge rejected');
AdminLoginState::begin(123,$ip);
AdminLoginState::clear();
assertAdminSecurity(AdminLoginState::current($ip)===null,'Cancelling login clears MFA challenge');

$valid=json_encode(['success'=>true,'hostname'=>'gateway.iluma.gr','action'=>'admin_login']);
assertAdminSecurity(AdminTurnstile::validResponse($valid,'gateway.iluma.gr'),
    'Matching Cloudflare hostname and action accepted');
foreach([
    ['success'=>false,'hostname'=>'gateway.iluma.gr','action'=>'admin_login'],
    ['success'=>true,'hostname'=>'evil.example','action'=>'admin_login'],
    ['success'=>true,'hostname'=>'gateway.iluma.gr','action'=>'other'],
    ['success'=>'true','hostname'=>'gateway.iluma.gr','action'=>'admin_login'],
] as $invalid){
    assertAdminSecurity(!AdminTurnstile::validResponse(json_encode($invalid),'gateway.iluma.gr'),
        'Untrusted Turnstile result rejected');
}
assertAdminSecurity(!AdminTurnstile::validResponse('{invalid','gateway.iluma.gr'),
    'Malformed Cloudflare response rejected');
assertAdminSecurity(!AdminTurnstile::verify(''), 'Missing Cloudflare token rejected before network');

$source=file_get_contents(dirname(__DIR__).'/src/AdminApplication.php');
assertAdminSecurity(strpos($source, 'AdminLoginState::begin(') <
    strpos($source, "\$_SESSION['admin_id']=(int)\$u['id'];"),
    'Authenticated session issued only after MFA stage');
echo "ADMIN SECURITY REGRESSIONS PASSED\n";
