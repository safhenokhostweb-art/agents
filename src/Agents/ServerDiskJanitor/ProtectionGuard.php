<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\ServerDiskJanitor;

use RuntimeException;
use Workspace\Agents\Support\Git;
use Workspace\Agents\Support\Glob;
use Workspace\Agents\Support\Paths;

/**
 * The protection list. A path it names is never deleted, whatever rule
 * matched it: environment files, databases, secrets, git repositories,
 * media uploads, and any project file outside the project's disposable
 * subtrees. Every check is by path only; protected files are never opened.
 */
final class ProtectionGuard
{
    public const GIT_TRACKED = 'GIT_TRACKED';
    public const GIT_UNVERIFIABLE = 'GIT_UNVERIFIABLE';
    public const PROJECT_FILE = 'PROJECT_FILE';
    public const PROTECTED_ROOT = 'PROTECTED_ROOT';
    public const PROTECTED_SEGMENT = 'PROTECTED_SEGMENT';

    /** Pathspecs per `git ls-files` call; keeps the command line short on Windows. */
    private const GIT_BATCH = 100;

    /** @var array<string, string|null> directory => git work tree root (null: none) */
    private array $workTrees = [];

    public function __construct(private readonly JanitorPolicy $policy)
    {
    }

    /**
     * Why $path must never be deleted, as `CODE`, or null when it may be.
     * $path is absolute and forward-slashed.
     */
    public function reason(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        $lower = strtolower($path);
        $segments = explode('/', trim($lower, '/'));
        foreach ($segments as $segment) {
            if (in_array($segment, $this->policy->protect['segments'], true)) {
                return self::PROTECTED_SEGMENT.':'.$segment;
            }
        }
        $name = (string) end($segments);
        foreach ($this->policy->protect['names'] as $category => $patterns) {
            if (Glob::matches($name, $patterns)) {
                return 'PROTECTED_'.strtoupper(str_replace('-', '_', $category));
            }
        }
        foreach ($this->policy->protect['paths'] as $category => $patterns) {
            if (Glob::matches($path, $patterns) || Glob::matches($lower, $patterns)) {
                return 'PROTECTED_'.strtoupper(str_replace('-', '_', $category));
            }
        }
        foreach ($this->policy->protect['roots'] as $root) {
            if (Paths::relative($root, $path) !== null) {
                return self::PROTECTED_ROOT;
            }
        }
        if (JanitorPolicy::isForbiddenRoot($path)) {
            return self::PROTECTED_ROOT;
        }
        foreach ($this->policy->projects as $root => $disposable) {
            $relative = Paths::relative($root, $path);
            if ($relative !== null && ($relative === '' || ! Glob::matches($relative, $disposable))) {
                return self::PROJECT_FILE;
            }
        }

        return null;
    }

    /**
     * Candidates inside a git work tree that git tracks (for a directory:
     * that contains a tracked file) are protected. When git cannot answer,
     * every candidate in that work tree is protected: fail closed.
     *
     * @param  list<array<string, mixed>>  $candidates  each with `path` and `type`
     * @return array<int, string> candidate index => protection reason
     */
    public function trackedByGit(array $candidates): array
    {
        $byTree = [];
        foreach ($candidates as $index => $candidate) {
            $tree = $this->workTree(dirname($candidate['path']));
            if ($tree !== null) {
                $byTree[$tree][$index] = (string) Paths::relative($tree, $candidate['path']);
            }
        }
        $protected = [];
        foreach ($byTree as $tree => $relatives) {
            try {
                $tracked = $this->tracked($tree, array_values($relatives));
            } catch (RuntimeException) {
                foreach (array_keys($relatives) as $index) {
                    $protected[$index] = self::GIT_UNVERIFIABLE;
                }
                continue;
            }
            foreach ($relatives as $index => $relative) {
                foreach ($tracked as $file) {
                    if ($file === $relative || str_starts_with($file, $relative.'/')) {
                        $protected[$index] = self::GIT_TRACKED;
                        break;
                    }
                }
            }
        }

        return $protected;
    }

    /** The git work tree containing $directory, found by looking for `.git` upwards. */
    public function workTree(string $directory): ?string
    {
        $directory = str_replace('\\', '/', $directory);
        if (array_key_exists($directory, $this->workTrees)) {
            return $this->workTrees[$directory];
        }
        // dirname('C:/') is 'C:\': compare normalized, or the walk never ends at a drive root.
        $parent = str_replace('\\', '/', dirname($directory));
        $tree = file_exists(rtrim($directory, '/').'/.git') ? $directory : ($parent === $directory || $parent === '.' ? null : $this->workTree($parent));

        return $this->workTrees[$directory] = $tree;
    }

    /**
     * @param  list<string>  $relatives
     * @return list<string> tracked paths among (or below) $relatives
     */
    private function tracked(string $tree, array $relatives): array
    {
        $git = new Git($tree);
        $tracked = [];
        foreach (array_chunk($relatives, self::GIT_BATCH) as $chunk) {
            $output = $git->run(array_merge(['--literal-pathspecs', 'ls-files', '-z', '--'], $chunk));
            foreach (explode("\0", $output) as $file) {
                if ($file !== '') {
                    $tracked[] = str_replace('\\', '/', $file);
                }
            }
        }

        return $tracked;
    }
}
