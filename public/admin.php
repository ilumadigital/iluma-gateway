<?php
declare(strict_types=1);
// Only public Admin entrypoint. The implementation is a single private controller.
// Never duplicate or version the controller in public/. Keep payment APIs separate.
require dirname(__DIR__) . '/src/AdminController.php';
