<?php
declare(strict_types=1);
namespace Iluma\Gateway;

/** An MFA challenge is NOT an authenticated administrator session. */
final class AdminLoginState
{
    private const KEY = 'pending_admin_login';
    private const TTL_SECONDS = 300;
    private const MAX_FAILURES = 5;

    public static function begin(int $adminId, string $ip): void
    {
        self::clear();
        $_SESSION[self::KEY] = [
            'admin_id' => $adminId,
            'created_at' => time(),
            'ip' => $ip,
            'failures' => 0,
        ];
    }

    public static function current(string $ip): ?array
    {
        $pending = $_SESSION[self::KEY] ?? null;
        if (!is_array($pending)
            || !isset($pending['admin_id'], $pending['created_at'], $pending['ip'], $pending['failures'])
            || !is_int($pending['admin_id']) || $pending['admin_id'] <= 0
            || !is_int($pending['created_at'])
            || $pending['created_at'] > time()
            || time() - $pending['created_at'] > self::TTL_SECONDS
            || !is_string($pending['ip']) || !hash_equals($pending['ip'], $ip)
            || !is_int($pending['failures']) || $pending['failures'] >= self::MAX_FAILURES) {
            self::clear();
            return null;
        }
        return $pending;
    }

    /** Count failed MFA entries within a single pending login. */
    public static function failed(string $ip): int
    {
        $pending = self::current($ip);
        if ($pending === null) return self::MAX_FAILURES;
        $count = $pending['failures'] + 1;
        if ($count >= self::MAX_FAILURES) self::clear();
        else $_SESSION[self::KEY]['failures'] = $count;
        return $count;
    }

    public static function clear(): void
    {
        unset($_SESSION[self::KEY]);
    }
}
