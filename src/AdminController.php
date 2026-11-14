<?php
declare(strict_types=1);
// Legacy compatibility entrypoint, deliberately unused by public/admin.php.
// Do not place runtime logic here. The hosting provider repeatedly rewrote
// this filename; use src/AdminApplication.php for the canonical Admin route.
require __DIR__.'/AdminApplication.php';
