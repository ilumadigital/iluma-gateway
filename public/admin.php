<?php
declare(strict_types=1);
// Only public Admin entrypoint. The implementation lives in the single private AdminApplication.php.
// Never duplicate or version the controller in public/. Keep payment APIs separate.
require dirname(__DIR__) . '/src/AdminApplication.php';
