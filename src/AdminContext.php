<?php
declare(strict_types=1);
namespace Iluma\Gateway;

/** Session-scoped display environment; never changes API key environment. */
final class AdminContext
{
    public static function environment(array $session): string
    {
        $environment = $session['view_env'] ?? 'staging';
        return in_array($environment, ['staging', 'production'], true) ? $environment : 'staging';
    }
}
