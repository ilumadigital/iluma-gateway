<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/SourceIntegrity.php';

use Iluma\Gateway\SourceIntegrity;

function expectIntegrity(bool $ok, string $label): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "PASS: $label\n";
}

$root = dirname(__DIR__);
$source = file_get_contents($root.'/'.SourceIntegrity::ADMIN_FILE);
expectIntegrity($source !== false, 'canonical source available');
expectIntegrity(SourceIntegrity::gitBlob($source) === SourceIntegrity::ADMIN_GIT_BLOB,
    'pinned blob matches reviewed source in CI');
expectIntegrity(SourceIntegrity::inspect($root)['ok'], 'checked out source is intact');

// A throwaway fixture proves that deletion, replacement and symlinks cannot be
// silently treated as a valid release. Never mutate files in the repository.
$temp = sys_get_temp_dir().'/iluma-integrity-'.bin2hex(random_bytes(8));
expectIntegrity(mkdir($temp.'/src', 0700, true), 'private test fixture created');
$copy = $temp.'/'.SourceIntegrity::ADMIN_FILE;
try {
    expectIntegrity(file_put_contents($copy, $source) === strlen($source), 'fixture written');
    expectIntegrity(SourceIntegrity::inspect($temp)['ok'], 'intact fixture accepted');
    file_put_contents($copy, substr($source, 0, 120)."\n}\n");
    $bad = SourceIntegrity::inspect($temp);
    expectIntegrity(!$bad['ok'] && $bad['reason'] === 'git-blob-mismatch',
        'truncated Admin controller rejected');
    unlink($copy);
    expectIntegrity(SourceIntegrity::inspect($temp)['reason'] === 'missing',
        'missing Admin controller rejected');
    if (function_exists('symlink') && @symlink($root.'/'.SourceIntegrity::ADMIN_FILE, $copy)) {
        expectIntegrity(SourceIntegrity::inspect($temp)['reason'] === 'symlink',
            'symlinked controller rejected');
    }
} finally {
    if (is_link($copy) || is_file($copy)) unlink($copy);
    rmdir($temp.'/src');
    rmdir($temp);
}
