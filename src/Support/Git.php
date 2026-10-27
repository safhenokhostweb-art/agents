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
    /**
     * System Git first: Laragon bundles an older Git (2.37) that ignores
     * command-line safe.directory, so outside a login environment it rejects
     * Administrators-owned repositories as dubious.
     */
    public const WINDOWS_FALLBACKS = [
        'C:\\Program Files\\Git\\cmd\\git.exe',
        'C:\\Program Files\\Git\\bin\\git.exe',
        'C:\\laragon\\bin\\git\\cmd\\git.exe',
        'C:\\laragon\\bin\\git\\bin\\git.exe',
    ];

    public const POSIX_FALLBACKS = ['/usr/bin/git', '/usr/local/bin/git', '/opt/homebrew/bin/git'];

    private static ?string $binary = null;

    public function __construct(private readonly string $root)
    {
    }

    /**
     * The git executable to spawn. Web-server SAPIs (Laragon's Apache/nginx)
     * often run with a PATH that lacks git, and CreateProcess searches the
     * parent's PATH, so a bare 'git' cannot start there. AGENTS_GIT_BINARY
     * overrides the search; 'git' is the last resort.
     */
    public static function binary(): string
    {
        return self::$binary ??= self::findBinary((string) getenv('AGENTS_GIT_BINARY'), (string) getenv('PATH'), PHP_OS_FAMILY === 'Windows') ?? 'git';
    }

    /** @param list<string>|null $fallbacks */
    public static function findBinary(string $override, string $path, bool $windows, ?array $fallbacks = null): ?string
    {
        if ($override !== '') {
            return $override;
        }
        $separator = $windows ? '\\' : '/';
        $candidates = [];
        foreach (explode($windows ? ';' : ':', $path) as $directory) {
            $directory = rtrim(trim($directory, " \""), '\\/');
            if ($directory !== '') {
                $candidates[] = $directory.$separator.($windows ? 'git.exe' : 'git');
            }
        }
        foreach (array_merge($candidates, $fallbacks ?? ($windows ? self::WINDOWS_FALLBACKS : self::POSIX_FALLBACKS)) as $candidate) {
            if (is_file($candidate) && ($windows || is_executable($candidate))) {
                return $candidate;
            }
        }

        return null;
    }

    public function isRepository(): bool
    {
        return $this->notRepositoryReason() === null;
    }

    /** Why the root is not the top level of a git work tree, or null when it is. */
    public function notRepositoryReason(): ?string
    {
        if (! is_dir($this->root)) {
            return 'directory does not exist';
        }
        $result = $this->exec(['rev-parse', '--show-toplevel']);
        if ($result['code'] !== 0) {
            // Drop warnings (e.g. about old-style safe.directory entries) so the fatal reason leads.
            $lines = array_filter(array_map('trim', preg_split('/\R/', trim($result['stderr'])) ?: []), static fn (string $line): bool => $line !== '' && ! str_starts_with($line, 'warning:'));

            return $lines === [] ? "git exited with code {$result['code']}" : implode(' ', $lines);
        }
        $top = trim($result['stdout']);
        if (Paths::relative($top, $this->root) !== '') {
            return "git reports the work tree top level as {$top}";
        }

        return null;
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
        $result = $this->exec($arguments);
        if ($result['code'] !== 0 && ! $allowFailure) {
            throw new RuntimeException('git '.implode(' ', $arguments).' failed in '.$this->root.': '.trim($result['stderr']));
        }

        return $result['code'] === 0 ? $result['stdout'] : '';
    }

    /**
     * safe.directory is scoped to this exact root, which workspace.json (owner
     * config) declares, never '*'. It keeps a repository whose .git is owned by
     * another account (e.g. BUILTIN\Administrators) readable when the global
     * gitconfig that trusts it cannot be found, as under a web-server SAPI.
     * Git honors it on the command line from 2.38 (protected configuration).
     *
     * @param  list<string>  $arguments
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function exec(array $arguments): array
    {
        $prefix = [self::binary(), '--no-optional-locks', '-c', 'core.quotepath=off', '-c', 'safe.directory='.Paths::normalize($this->root)];

        return Process::run(array_merge($prefix, $arguments), $this->root);
    }

    /** @return list<string> */
    private function nullLines(string $value): array
    {
        return array_values(array_filter(explode("\0", $value), static fn (string $line): bool => $line !== ''));
    }
}
