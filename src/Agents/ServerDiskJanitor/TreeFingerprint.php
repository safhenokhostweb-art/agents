<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\ServerDiskJanitor;

/**
 * A digest of a directory tree's shape: every entry's relative path, type,
 * size, mtime and (for links) target, read with lstat and never following
 * links. Equal fingerprints before tar, after tar and before deletion
 * mean the archive holds exactly what is deleted.
 *
 * A tree that is not safe to archive-and-delete reports a `problem`:
 * another filesystem mounted inside, an unreadable directory, or a git
 * repository (`.git`), which may be an active work tree.
 */
final class TreeFingerprint
{
    /** @return array{fingerprint: string, bytes: int, files: int, entries: int, newest: int, problem: ?string} */
    public static function of(string $directory): array
    {
        clearstatcache();
        $root = @lstat($directory);
        $state = ['hash' => hash_init('sha256'), 'bytes' => 0, 'files' => 0, 'entries' => 0, 'newest' => (int) ($root['mtime'] ?? 0), 'problem' => null];
        if ($root === false || ! is_dir($directory) || is_link($directory) || DiskScanner::isJunction($directory)) {
            $state['problem'] = 'NOT_A_DIRECTORY';
        } else {
            self::walk($directory, '', (int) $root['dev'], $state);
        }

        return [
            'fingerprint' => hash_final($state['hash']),
            'bytes' => $state['bytes'],
            'files' => $state['files'],
            'entries' => $state['entries'],
            'newest' => $state['newest'],
            'problem' => $state['problem'],
        ];
    }

    private static function walk(string $directory, string $relative, int $device, array &$state): void
    {
        $names = @scandir($directory);
        if ($names === false) {
            $state['problem'] ??= 'UNREADABLE_DIRECTORY ('.($relative === '' ? '.' : $relative).')';

            return;
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory.'/'.$name;
            $rel = $relative === '' ? $name : $relative.'/'.$name;
            $stat = @lstat($path);
            if ($stat === false) {
                $state['problem'] ??= "UNREADABLE_ENTRY ({$rel})";
                continue;
            }
            $state['entries']++;
            if (is_link($path) || DiskScanner::isJunction($path)) {
                hash_update($state['hash'], "l\t{$rel}\t".(string) @readlink($path)."\n");
                continue;
            }
            if (is_dir($path)) {
                if (strtolower($name) === '.git') {
                    $state['problem'] ??= "GIT_REPOSITORY ({$rel})";
                }
                if ((int) $stat['dev'] !== $device) {
                    $state['problem'] ??= "MOUNT_POINT ({$rel})";
                    continue;
                }
                hash_update($state['hash'], "d\t{$rel}\t{$stat['mtime']}\n");
                self::walk($path, $rel, $device, $state);
                continue;
            }
            hash_update($state['hash'], "f\t{$rel}\t{$stat['size']}\t{$stat['mtime']}\n");
            $state['bytes'] += (int) $stat['size'];
            $state['files']++;
            $state['newest'] = max($state['newest'], (int) $stat['mtime']);
        }
    }
}
