<?php

declare(strict_types=1);

namespace Workspace\Agents\Support;

use RuntimeException;

/**
 * Read-only git access. Never fetches, stages, commits, or pushes; the
 * `--no-optional-locks` flag keeps even `status` from rewriting the index.
 */
final class Git
{
    public function __construct(private readonly string $root)
    {
    }

    public function isRepository(): bool
    {
        return is_dir($this->root)
            && trim($this->run(['rev-parse', '--is-inside-work-tree'], true)) === 'true'
            && Paths::relative(Paths::normalize(trim($this->run(['rev-parse', '--show-toplevel']))), $this->root) === '';
    }

    public function head(): ?string
    {
        return $this->resolve('HEAD');
    }

    /** Current branch name, or '' when HEAD is detached. */
    public function branch(): string
    {
        return trim($this->run(['branch', '--show-current']));
    }

    public function resolve(string $ref): ?string
    {
        $sha = strtolower(trim($this->run(['rev-parse', '-q', '--verify', $ref.'^{commit}'], true)));

        return preg_match('/^[0-9a-f]{40}$/', $sha) === 1 ? $sha : null;
    }

    public function mergeBase(string $a, string $b): ?string
    {
        $sha = strtolower(trim($this->run(['merge-base', $a, $b], true)));

        return preg_match('/^[0-9a-f]{40}$/', $sha) === 1 ? $sha : null;
    }

    /** @return array{ahead: int, behind: int} HEAD relative to $base */
    public function aheadBehind(string $base): array
    {
        $counts = preg_split('/\s+/', trim($this->run(['rev-list', '--left-right', '--count', $base.'...HEAD'])));

        return ['ahead' => (int) ($counts[1] ?? 0), 'behind' => (int) ($counts[0] ?? 0)];
    }

    /**
     * Every path that differs between $base and the working tree: committed,
     * staged, unstaged, deleted, and untracked (ignored files excluded).
     * Renames are split into delete + add so both sides are visible.
     *
     * @return list<string>
     */
    public function changedSince(string $base, ?string $pathspec = null): array
    {
        $spec = $pathspec === null ? [] : ['--', $pathspec];
        $paths = array_merge(
            $this->nullLines($this->run(array_merge(['diff', '--name-only', '--no-renames', '-z', $base], $spec))),
            $this->nullLines($this->run(array_merge(['ls-files', '--others', '--exclude-standard', '-z'], $spec))),
        );
        $paths = array_values(array_unique(array_map(static fn (string $p): string => str_replace('\\', '/', $p), $paths)));
        sort($paths, SORT_STRING);

        return $paths;
    }

    /** @return list<array{xy: string, path: string}> */
    public function status(?string $pathspec = null): array
    {
        $args = ['status', '--porcelain=v1', '-z', '--untracked-files=all'];
        if ($pathspec !== null) {
            array_push($args, '--', $pathspec);
        }
        $records = $this->nullLines($this->run($args));
        $entries = [];
        for ($index = 0; $index < count($records); $index++) {
            $record = $records[$index];
            if (strlen($record) < 4) {
                continue;
            }
            $xy = substr($record, 0, 2);
            if ($xy[0] === 'R' || $xy[0] === 'C') {
                $index++;
            }
            $entries[] = ['xy' => $xy, 'path' => str_replace('\\', '/', substr($record, 3))];
        }

        return $entries;
    }

    /** @param list<string> $arguments */
    public function run(array $arguments, bool $allowFailure = false): string
    {
        $result = Process::run(array_merge(['git', '--no-optional-locks', '-c', 'core.quotepath=off'], $arguments), $this->root);
        if ($result['code'] !== 0 && ! $allowFailure) {
            throw new RuntimeException('git '.implode(' ', $arguments).' failed in '.$this->root.': '.trim($result['stderr']));
        }

        return $result['code'] === 0 ? $result['stdout'] : '';
    }

    /** @return list<string> */
    private function nullLines(string $value): array
    {
        return array_values(array_filter(explode("\0", $value), static fn (string $line): bool => $line !== ''));
    }
}
