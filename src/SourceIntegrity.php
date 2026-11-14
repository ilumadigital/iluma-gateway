<?php
declare(strict_types=1);
namespace Iluma\Gateway;

/**
 * Read-only, deployment-pinned check of the canonical Admin controller.
 *
 * This does not repair source files or identify the writer. The expected Git
 * blob is reviewed with the release; never learn it from the deployed file.
 * No database, payment provider, or secret configuration is read here.
 */
final class SourceIntegrity
{
    public const ADMIN_FILE = 'src/AdminApplication.php';
    public const LEGACY_FILE = 'src/AdminController.php';
    public const LEGACY_GIT_BLOB = '1678e64a787dfa11ed3c090c6b5692c65e497c82';
    public const ADMIN_GIT_BLOB = '189a3303dcb445b5bc3798d14eb3fbfbe052e0d7';

    public static function gitBlob(string $content): string
    {
        return sha1('blob '.strlen($content)."\0".$content);
    }

    /**
     * @return array{ok:bool,reason:string,actual:string,mtime:string,inode:string}
     */
    public static function inspect(string $projectRoot, string $relativePath = self::ADMIN_FILE, string $expectedBlob = self::ADMIN_GIT_BLOB): array
    {
        $file = rtrim($projectRoot, '/').'/'.$relativePath;
        $details = ['ok' => false, 'reason' => 'unreadable', 'actual' => '-',
                    'mtime' => '-', 'inode' => '-'];
        clearstatcache(true, $file);
        if (is_link($file)) {
            $details['reason'] = 'symlink';
            return $details;
        }
        if (!is_file($file)) {
            $details['reason'] = 'missing';
            return $details;
        }
        $content = @file_get_contents($file);
        if ($content === false) return $details;
        $details['actual'] = self::gitBlob($content);
        $mtime = @filemtime($file);
        $inode = @fileinode($file);
        if ($mtime !== false) $details['mtime'] = gmdate('Y-m-d\TH:i:s\Z', $mtime);
        if ($inode !== false) $details['inode'] = (string)$inode;
        $details['ok'] = hash_equals($expectedBlob, $details['actual']);
        $details['reason'] = $details['ok'] ? 'match' : 'git-blob-mismatch';
        return $details;
    }

    /** Read-only drift of the unused old filename, without affecting availability. */
    public static function inspectLegacy(string $projectRoot): array
    {
        return self::inspect($projectRoot, self::LEGACY_FILE, self::LEGACY_GIT_BLOB);
    }

    /**
     * Record evidence at worker boundaries without blocking signed webhook
     * delivery. There is intentionally NO automatic git restore or file write.
     */
    public static function logIfDrift(string $projectRoot, string $phase): bool
    {
        $status = self::inspect($projectRoot);
        if (!$status['ok']) {
            error_log(sprintf(
                'ILUMA SOURCE INTEGRITY ALERT phase=%s reason=%s expected=%s actual=%s mtime_utc=%s inode=%s',
                $phase, $status['reason'], self::ADMIN_GIT_BLOB,
                $status['actual'], $status['mtime'], $status['inode']
            ));
        }
        $legacy = self::inspectLegacy($projectRoot);
        if (!$legacy['ok']) {
            error_log(sprintf(
                'ILUMA LEGACY SOURCE DRIFT phase=%s reason=%s expected=%s actual=%s mtime_utc=%s inode=%s',
                $phase, $legacy['reason'], self::LEGACY_GIT_BLOB,
                $legacy['actual'], $legacy['mtime'], $legacy['inode']
            ));
        }
        return $status['ok'];
    }
}
