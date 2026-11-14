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
    public const ADMIN_FILE = 'src/AdminController.php';
    public const ADMIN_GIT_BLOB = '7ebf87690f7411589eb5e11214b76f8ff577ab33';

    public static function gitBlob(string $content): string
    {
        return sha1('blob '.strlen($content)."\0".$content);
    }

    /**
     * @return array{ok:bool,reason:string,actual:string,mtime:string,inode:string}
     */
    public static function inspect(string $projectRoot): array
    {
        $file = rtrim($projectRoot, '/').'/'.self::ADMIN_FILE;
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
        $details['ok'] = hash_equals(self::ADMIN_GIT_BLOB, $details['actual']);
        $details['reason'] = $details['ok'] ? 'match' : 'git-blob-mismatch';
        return $details;
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
        return $status['ok'];
    }
}
