<?php
declare(strict_types=1);
namespace Iluma\Gateway;

/** Restricted Hostinger private-config reader, not a public .env loader. */
final class EnvLoader
{
    public static function privateFilePath(string $projectRoot): string
    {
        $root = realpath($projectRoot);
        if ($root === false) throw new \RuntimeException('Application root not found');
        if (preg_match('~^(.*?)/public_html(?:/|$)~', $root, $matches) === 1) {
            $parent = $matches[1];
        } else {
            $parent = dirname($root);
        }
        return $parent . '/private/iluma-gateway.env';
    }

    public static function load(string $projectRoot): bool
    {
        $file = self::privateFilePath($projectRoot);
        if (!is_file($file)) return false;
        if (is_link($file)) throw new \RuntimeException('Secret file must not be a symlink');
        $real = realpath($file);
        $root = realpath($projectRoot);
        if ($real === false || $root === false || str_starts_with($real, $root . '/') || str_contains($real, '/public_html/')) {
            throw new \RuntimeException('Unsafe secrets location');
        }
        $mode = fileperms($file);
        if ($mode === false || ($mode & 0077) !== 0) {
            throw new \RuntimeException('Secret file permissions must be 0600 or stricter');
        }
        if (!is_readable($file)) throw new \RuntimeException('Secret file cannot be read');

        $allowed = array_fill_keys([
            'APP_ENV', 'GATEWAY_HOST', 'ADMIN_HOST', 'ADMIN_ACCESS_VERIFIED', 'PRODUCTION_ENABLED',
            'MASTER_KEY_B64', 'REGISTRY_DSN', 'REGISTRY_USER', 'REGISTRY_PASSWORD',
            'STAGING_DSN', 'STAGING_USER', 'STAGING_PASSWORD', 'PRODUCTION_DSN',
            'PRODUCTION_USER', 'PRODUCTION_PASSWORD', 'NEXI_STAGING_BASE',
            'NEXI_PRODUCTION_BASE', 'IRADIOS_STAGING_WEBHOOK', 'IRADIOS_PRODUCTION_WEBHOOK',
        ], true);
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) throw new \RuntimeException('Cannot read secrets');
        $parsed = [];
        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (!preg_match('/^([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/D', $line, $parts)) {
                throw new \RuntimeException('Malformed private configuration at line ' . ($lineNumber + 1));
            }
            $key = $parts[1];
            if (!isset($allowed[$key]) || isset($parsed[$key])) {
                throw new \RuntimeException('Unrecognized or duplicate config key at line ' . ($lineNumber + 1));
            }
            $value = trim($parts[2]);
            if (str_starts_with($value, '"') || str_starts_with($value, "'")) {
                $quote = $value[0];
                if (strlen($value) < 2 || !str_ends_with($value, $quote)) {
                    throw new \RuntimeException('Malformed quoted config at line ' . ($lineNumber + 1));
                }
                $value = substr($value, 1, -1);
            }
            if (str_contains($value, "\0") || str_contains($value, "\n")) {
                throw new \RuntimeException('Invalid configuration value');
            }
            $parsed[$key] = $value;
        }
        foreach ($parsed as $key => $value) {
            if (getenv($key) === false) putenv($key . '=' . $value);
        }
        return true;
    }
}
