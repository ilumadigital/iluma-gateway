<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

// Hostinger can suppress PHP CLI error output. Report only safe configuration
// errors to CLI, never reveal credentials or stack traces in HTTP responses.
try {
    require_once __DIR__ . '/EnvLoader.php';
    \Iluma\Gateway\EnvLoader::load(dirname(__DIR__));
} catch (\Throwable $e) {
    error_log('ILUMA configuration failed: ' . $e->getMessage());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'ILUMA CONFIG ERROR: ' . $e->getMessage() . PHP_EOL);
    } else {
        http_response_code(503);
        echo 'Service unavailable';
    }
    exit(1);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Iluma\\Gateway\\';
    if (!str_starts_with($class, $prefix)) return;
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require_once $path;
});

set_exception_handler(static function (\Throwable $e): void {
    error_log('ILUMA uncaught ' . get_class($e) . ': ' . $e->getMessage());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'ILUMA ERROR: ' . get_class($e) . ' (review private PHP error log)' . PHP_EOL);
    } else {
        http_response_code(500);
        echo 'Internal server error';
    }
});
