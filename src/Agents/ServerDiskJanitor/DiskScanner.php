<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\ServerDiskJanitor;

use Workspace\Agents\Support\Glob;
use Workspace\Agents\Support\Paths;

/**
 * Builds the cleanup plan: walks each rule's roots read-only and returns the
 * entries the rule selects, split into candidates and protected entries.
 *
 * The walk never follows a symbolic link or junction, never crosses into
 * another filesystem, and stops at the policy's entry and depth limits. A
 * directory selected as a whole (kind `entries`) is a unit: it is deletable
 * only when every file inside it was seen, none is protected and none is a
 * link, and its age is that of its newest file.
 */
final class DiskScanner
{
    /** @var list<array<string, mixed>> */
    private array $roots = [];

    private int $entries = 0;

    private bool $truncated = false;

    public function __construct(
        private readonly JanitorPolicy $policy,
        private readonly ProtectionGuard $guard,
        private readonly int $now,
    ) {
    }

    /**
     * @param  list<string>|null  $ruleIds  rules to run; null runs all
     * @return array{candidates: list<array<string, mixed>>, protected: list<array<string, mixed>>, retained: list<array<string, mixed>>, roots: list<array<string, mixed>>, entries_scanned: int, truncated: bool}
     */
    public function scan(?array $ruleIds = null): array
    {
        $candidates = $protected = $retained = [];
        $seen = [];
        foreach ($this->policy->rules as $rule) {
            if ($ruleIds !== null && ! in_array($rule['id'], $ruleIds, true)) {
                continue;
            }
            foreach ($this->expandRoots($rule) as $root) {
                $found = match ($rule['kind']) {
                    JanitorPolicy::KIND_ENTRIES => $this->entries($rule, $root),
                    default => $this->files($rule, $root),
                };
                foreach ($found['selected'] as $entry) {
                    // The first rule that selects a path owns it.
                    if (isset($seen[$entry['path']])) {
                        continue;
                    }
                    $seen[$entry['path']] = true;
                    $reason = $entry['protection'] ?? $this->guard->reason($entry['path']);
                    unset($entry['protection']);
                    if ($reason !== null) {
                        $protected[] = $entry + ['protection' => $reason];
                    } elseif (count($candidates) >= $this->policy->limits['max_candidates']) {
                        $this->truncated = true;
                    } else {
                        $candidates[] = $entry;
                    }
                }
                array_push($retained, ...$found['retained']);
            }
        }

        // Git is consulted once per work tree, after the cheap path checks.
        foreach ($this->guard->trackedByGit($candidates) as $index => $reason) {
            $protected[] = $candidates[$index] + ['protection' => $reason];
            unset($candidates[$index]);
        }

        return [
            'candidates' => array_values($candidates),
            'protected' => $protected,
            'retained' => $retained,
            'roots' => $this->roots,
            'entries_scanned' => $this->entries,
            'truncated' => $this->truncated,
        ];
    }

    /** @return list<array<string, mixed>> every root considered so far, with its scan status */
    public function roots(): array
    {
        return $this->roots;
    }

    /** @return list<string> existing, real, allowed root directories of a rule */
    public function expandRoots(array $rule): array
    {
        $roots = [];
        foreach ($rule['roots'] as $pattern) {
            // Windows resolves /tmp against the current drive (C:\tmp): a POSIX
            // root names a Linux location and never applies to a Windows host.
            if (PHP_OS_FAMILY === 'Windows' && str_starts_with($pattern, '/')) {
                $this->roots[] = ['rule' => $rule['id'], 'root' => $pattern, 'status' => 'missing'];
                continue;
            }
            $matches =str_contains($pattern, '*') ? (glob($pattern, GLOB_ONLYDIR | GLOB_NOSORT) ?: []) : [$pattern];
            if ($matches === [] || ! is_dir($matches[0])) {
                $this->roots[] = ['rule' => $rule['id'], 'root' => $pattern, 'status' => 'missing'];
                continue;
            }
            foreach ($matches as $match) {
                $match = str_replace('\\', '/', $match);
                $real = Paths::normalize($match);
                $status = match (true) {
                    Glob::matches($match, $rule['skip_roots']) => 'skipped',
                    JanitorPolicy::isForbiddenRoot($real) => 'forbidden',
                    ! is_readable($real) || @scandir($real) === false => 'unreadable',
                    default => 'scanned',
                };
                $this->roots[] = ['rule' => $rule['id'], 'root' => $real, 'status' => $status];
                if ($status === 'scanned' && ! in_array($real, $roots, true)) {
                    $roots[] = $real;
                }
            }
        }

        return $roots;
    }

    /** Kinds `files` and `expired-cache`: individual regular files below the root. */
    private function files(array $rule, string $root): array
    {
        $selected = [];
        $minAge = $rule['min_age_days'] * 86400;
        $device = @lstat($root)['dev'] ?? null;
        $this->walk($root, $device, 0, function (string $path, array $stat) use ($rule, $root, $minAge, &$selected): void {
            $relative = substr($path, strlen($root) + 1);
            if (! Glob::matches($relative, $rule['match']) || Glob::matches($relative, $rule['exclude'])) {
                return;
            }
            if ($this->now - $stat['mtime'] < $minAge) {
                return;
            }
            $why = $rule['reason'];
            if ($rule['kind'] === JanitorPolicy::KIND_EXPIRED_CACHE) {
                $expires = self::cacheExpiry($path);
                if ($expires === null || $expires > $this->now) {
                    return;
                }
                $why .= ' (expired '.gmdate('c', $expires).')';
            }
            $selected[] = self::entry($rule, $path, 'file', $stat, $why);
        });

        return ['selected' => $selected, 'retained' => []];
    }

    /** Kind `entries`: top-level files and directories of the root, by name, with optional retention. */
    private function entries(array $rule, string $root): array
    {
        $device = @lstat($root)['dev'] ?? null;
        $minAge = $rule['min_age_days'] * 86400;
        $items = [];
        foreach (@scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..' || ! Glob::matches($name, $rule['match']) || Glob::matches($name, $rule['exclude'])) {
                continue;
            }
            $this->entries++;
            $path = $root.'/'.$name;
            $stat = @lstat($path);
            if ($stat === false || is_link($path) || self::isJunction($path) || (! is_dir($path) && ! is_file($path))) {
                continue;
            }
            $items[] = ['path' => $path, 'stat' => $stat];
        }

        // Retention first, by name, so the copies it keeps are never walked.
        [$items, $retained] = self::retain($rule, $items);

        $selected = [];
        foreach ($items as ['path' => $path, 'stat' => $stat]) {
            // A unit is as old as its newest file, and never older than itself.
            if ($this->now - $stat['mtime'] < $minAge) {
                continue;
            }
            if (is_file($path)) {
                $selected[] = self::entry($rule, $path, 'file', $stat, $rule['reason']);
                continue;
            }
            $unit = $this->unit($path, $device);
            if ($this->now - $unit['newest'] < $minAge) {
                continue;
            }
            $entry = self::entry($rule, $path, 'directory', $stat, $rule['reason']);
            $entry['bytes'] = $unit['bytes'];
            $entry['files'] = $unit['files'];
            $entry['modified_at'] = gmdate('c', $unit['newest']);
            if ($unit['protection'] !== null) {
                $entry['protection'] = $unit['protection'];
            }
            $selected[] = $entry;
        }

        return ['selected' => $selected, 'retained' => $retained];
    }

    /**
     * Apply a rule's retention to top-level items (each with `path` and `stat`):
     * names outside group_pattern are dropped, and the keep_newest copies of
     * each group (by the `stamp` capture, else mtime) are set aside.
     *
     * @return array{0: list<array>, 1: list<array{rule: string, path: string, group: string}>}
     */
    public static function retain(array $rule, array $items): array
    {
        $retained = [];
        if ($rule['retention'] === null) {
            return [array_values($items), $retained];
        }
        $groups = [];
        foreach ($items as $index => $item) {
            if (preg_match($rule['retention']['group_pattern'], basename($item['path']), $m) !== 1) {
                unset($items[$index]);
                continue;
            }
            $groups[$m['group']][] = ['index' => $index, 'order' => isset($m['stamp']) && $m['stamp'] !== '' ? $m['stamp'] : sprintf('%020d', $item['stat']['mtime'])];
        }
        foreach ($groups as $group => $members) {
            usort($members, static fn (array $a, array $b): int => strcmp($b['order'], $a['order']));
            foreach (array_slice($members, 0, $rule['retention']['keep_newest']) as $keep) {
                $retained[] = ['rule' => $rule['id'], 'path' => $items[$keep['index']]['path'], 'group' => (string) $group];
                unset($items[$keep['index']]);
            }
        }

        return [array_values($items), $retained];
    }

    /** @return array{bytes: int, files: int, newest: int, protection: ?string} */
    private function unit(string $directory, ?int $device): array
    {
        $unit = ['bytes' => 0, 'files' => 0, 'newest' => (int) (@lstat($directory)['mtime'] ?? 0), 'protection' => null];
        $complete = $this->walk($directory, $device, 0, function (string $path, array $stat) use (&$unit): void {
            $unit['bytes'] += $stat['size'];
            $unit['files']++;
            $unit['newest'] = max($unit['newest'], $stat['mtime']);
            if ($unit['protection'] === null && ($reason = $this->guard->reason($path)) !== null) {
                $unit['protection'] = $reason.' ('.basename($path).')';
            }
        }, function (string $path, string $problem) use (&$unit): void {
            $unit['protection'] ??= $problem;
        }, PHP_INT_MAX);
        if (! $complete) {
            $unit['protection'] ??= 'SCAN_INCOMPLETE';
        }

        return $unit;
    }

    /**
     * Depth-first, read-only walk calling $onFile for every regular file.
     * $onProblem receives links, foreign filesystems and unreadable
     * directories (for units, each one disqualifies the unit).
     *
     * @return bool false when a limit stopped the walk
     */
    private function walk(string $directory, ?int $device, int $depth, callable $onFile, ?callable $onProblem = null, ?int $maxDepth = null): bool
    {
        $names = @scandir($directory);
        if ($names === false) {
            $onProblem && $onProblem($directory, 'UNREADABLE_DIRECTORY');

            return true;
        }
        $complete = true;
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (++$this->entries > $this->policy->limits['max_entries']) {
                $this->truncated = true;

                return false;
            }
            $path = $directory.'/'.$name;
            $stat = @lstat($path);
            if ($stat === false) {
                continue;
            }
            if (is_link($path) || self::isJunction($path)) {
                $onProblem && $onProblem($path, 'SYMLINK_IN_UNIT');
                continue;
            }
            if (is_dir($path)) {
                if ($device !== null && $stat['dev'] !== $device) {
                    $onProblem && $onProblem($path, 'MOUNT_POINT_IN_UNIT');
                    continue;
                }
                if ($depth + 1 > ($maxDepth ?? $this->policy->limits['max_depth'])) {
                    $onProblem && $onProblem($path, 'DEPTH_LIMIT');
                    continue;
                }
                $complete = $this->walk($path, $device, $depth + 1, $onFile, $onProblem, $maxDepth) && $complete;
                if (! $complete) {
                    return false;
                }
            } elseif (is_file($path)) {
                $onFile($path, $stat);
            }
        }

        return $complete;
    }

    /** A Windows junction is a directory that resolves somewhere other than where it is listed. */
    public static function isJunction(string $path): bool
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! is_dir($path)) {
            return false;
        }
        $real = realpath($path);

        return $real !== false && strcasecmp(str_replace('\\', '/', $real), Paths::normalize(dirname($path)).'/'.basename($path)) !== 0;
    }

    /** Laravel's file cache prefixes each entry with its 10-digit expiry timestamp (9999999999 = forever). */
    public static function cacheExpiry(string $path): ?int
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $prefix = (string) fread($handle, 10);
        fclose($handle);
        if (preg_match('/^\d{10}$/', $prefix) !== 1 || $prefix === '9999999999') {
            return null;
        }

        return (int) $prefix;
    }

    /** @return array<string, mixed> */
    private static function entry(array $rule, string $path, string $type, array $stat, string $reason): array
    {
        return [
            'path' => $path,
            'type' => $type,
            'rule' => $rule['id'],
            'reason' => $reason,
            'bytes' => $type === 'file' ? (int) $stat['size'] : 0,
            'files' => $type === 'file' ? 1 : 0,
            'modified_at' => gmdate('c', (int) $stat['mtime']),
            // POSIX has no birth time in stat(); ctime there is the inode change time.
            'created_at' => PHP_OS_FAMILY === 'Windows' ? gmdate('c', (int) $stat['ctime']) : null,
            'changed_at' => gmdate('c', (int) $stat['ctime']),
            'identity' => ['dev' => (int) $stat['dev'], 'ino' => (int) $stat['ino'], 'size' => (int) $stat['size'], 'mtime' => (int) $stat['mtime']],
        ];
    }
}
