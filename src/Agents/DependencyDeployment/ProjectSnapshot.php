<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\DependencyDeployment;

use Workspace\Agents\Kernel\ProtocolException;
use Workspace\Agents\Support\ComposerProject;
use Workspace\Agents\Support\Git;
use Workspace\Agents\Support\Json;

/**
 * Everything read once about one project. Production branch and remote come
 * from the project's own .deployment/release-policy.json, never from config
 * here, so the release contract stays the single source of truth.
 */
final class ProjectSnapshot
{
    private function __construct(
        public readonly string $name,
        public readonly string $root,
        public readonly string $role,
        public readonly ComposerProject $composer,
        public readonly Git $git,
        public readonly ?array $policy,
        public readonly string $branch,
        public readonly ?string $head,
        public readonly string $baseRef,
        public readonly ?string $baseSha,
        public readonly ?string $mergeBase,
        public readonly int $ahead,
        public readonly int $behind,
        public readonly int $dirtyEntries,
    ) {
    }

    /** @param  string|null  $composerRoot  directory of composer.json when it is not the git root */
    public static function capture(string $name, string $root, string $role, ?string $composerRoot = null): self
    {
        if (! is_dir($root)) {
            throw new ProtocolException('PROJECT_MISSING', "Project '{$name}' directory does not exist: {$root}");
        }
        $git = new Git($root);
        $reason = $git->notRepositoryReason();
        if ($reason !== null) {
            throw new ProtocolException('NOT_A_REPOSITORY', "Project '{$name}' is not the root of a git repository: {$root} ({$reason})");
        }
        try {
            $composer = ComposerProject::load($composerRoot ?? $root);
        } catch (\RuntimeException $exception) {
            throw new ProtocolException('COMPOSER_UNREADABLE', "Project '{$name}': ".$exception->getMessage());
        }
        $policyFile = $root.'/.deployment/release-policy.json';
        $policy = is_file($policyFile) ? Json::decodeFile($policyFile) : null;

        // Local remote-tracking ref only: the agent never fetches. The
        // artifact records this ref so a stale view is visible, not hidden.
        $baseRef = ($policy['remote'] ?? 'origin').'/'.($policy['production_branch'] ?? 'main');
        $head = $git->head();
        $baseSha = $git->resolve($baseRef);
        $mergeBase = $baseSha !== null && $head !== null ? $git->mergeBase($baseSha, $head) : null;
        $position = $baseSha !== null && $head !== null ? $git->aheadBehind($baseSha) : ['ahead' => 0, 'behind' => 0];

        return new self(
            $name, $root, $role, $composer, $git, $policy,
            $git->branch(), $head, $baseRef, $baseSha, $mergeBase,
            $position['ahead'], $position['behind'], count($git->status()),
        );
    }

    public function provenance(): array
    {
        return [
            'head' => $this->head,
            'branch' => $this->branch === '' ? null : $this->branch,
            'base_ref' => $this->baseRef,
            'base_sha' => $this->baseSha,
            'dirty_entries' => $this->dirtyEntries,
        ];
    }
}
