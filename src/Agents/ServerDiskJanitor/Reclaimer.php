<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\ServerDiskJanitor;

/**
 * Deletes planned entries, and only if they are still what was planned.
 *
 * Between the plan and the deletion a file can be rewritten, replaced or
 * swapped for a link; each of those makes the entry `changed` and it is
 * left alone. A unit (directory) is removed file by file, deepest first;
 * a file in it that became protected, a link, or newer than the unit's
 * planned age stops that unit, and what was already removed is reported.
 */
final class Reclaimer
{
    public const DELETED = 'deleted';
    public const PARTIAL = 'partial';
    public const CHANGED = 'changed';
    public const VANISHED = 'vanished';
    public const FAILED = 'failed';

    public function __construct(private readonly ProtectionGuard $guard)
    {
    }

    /**
     * @param  array<string, mixed>  $entry  a scanner candidate
     * @return array{outcome: string, bytes_freed: int, files_deleted: int, error: ?string}
     */
    public function reclaim(array $entry): array
    {
        $path = $entry['path'];
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            return $this->result(self::VANISHED);
        }
        $identity = $entry['identity'];
        if (is_link($path) || DiskScanner::isJunction($path) || (int) $stat['dev'] !== $identity['dev'] || (int) $stat['ino'] !== $identity['ino']) {
            return $this->result(self::CHANGED, error: 'entry was replaced after the plan was made');
        }
        if (($reason = $this->guard->reason($path)) !== null) {
            return $this->result(self::CHANGED, error: "entry is now protected ({$reason})");
        }

        if ($entry['type'] === 'file') {
            if (! is_file($path) || (int) $stat['size'] !== $identity['size'] || (int) $stat['mtime'] !== $identity['mtime']) {
                return $this->result(self::CHANGED, error: 'file was modified after the plan was made');
            }
            if (! @unlink($path)) {
                return $this->result(self::FAILED, error: self::lastError());
            }

            return $this->result(self::DELETED, (int) $stat['size'], 1);
        }

        $progress = ['bytes' => 0, 'files' => 0];
        $newest = strtotime((string) $entry['modified_at']) ?: 0;
        $error = $this->removeTree($path, $newest, $progress);
        if ($error === null) {
            return $this->result(self::DELETED, $progress['bytes'], $progress['files']);
        }

        return $this->result($progress['files'] > 0 ? self::PARTIAL : self::CHANGED, $progress['bytes'], $progress['files'], $error);
    }

    /** @param array{bytes: int, files: int} $progress */
    private function removeTree(string $directory, int $newest, array &$progress): ?string
    {
        $names = @scandir($directory);
        if ($names === false) {
            return "cannot list {$directory}: ".self::lastError();
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory.'/'.$name;
            $stat = @lstat($path);
            if ($stat === false) {
                continue;
            }
            if (is_link($path) || DiskScanner::isJunction($path)) {
                return "{$path} became a link";
            }
            if (($reason = $this->guard->reason($path)) !== null) {
                return "{$path} is protected ({$reason})";
            }
            if (is_dir($path)) {
                if (($error = $this->removeTree($path, $newest, $progress)) !== null) {
                    return $error;
                }
                continue;
            }
            // Directory mtimes move as their files go; the unit's age is its files'.
            if ((int) $stat['mtime'] > $newest) {
                return "{$path} was modified after the plan was made";
            }
            if (! @unlink($path)) {
                return "cannot delete {$path}: ".self::lastError();
            }
            $progress['bytes'] += (int) $stat['size'];
            $progress['files']++;
        }
        if (! @rmdir($directory)) {
            return "cannot remove directory {$directory}: ".self::lastError();
        }

        return null;
    }

    private static function lastError(): string
    {
        $message = error_get_last()['message'] ?? 'unknown error';

        return preg_replace('/^\w+\(.*?\): /', '', $message) ?? $message;
    }

    /** @return array{outcome: string, bytes_freed: int, files_deleted: int, error: ?string} */
    private function result(string $outcome, int $bytes = 0, int $files = 0, ?string $error = null): array
    {
        return ['outcome' => $outcome, 'bytes_freed' => $bytes, 'files_deleted' => $files, 'error' => $error];
    }
}
