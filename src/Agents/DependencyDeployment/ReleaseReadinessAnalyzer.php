<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\DependencyDeployment;

use Workspace\Agents\Kernel\Finding;
use Workspace\Agents\Support\Glob;
use Workspace\Agents\Support\Paths;

/**
 * Pre-flight for deployment-system's release-prepare: classifies a project's
 * branch delta exactly as the release contract will, surfaces what would
 * fail closed there, and drafts the `.deployment/release-task.json` it needs.
 *
 * This deliberately does not re-implement release-prepare. It never stages,
 * commits, pushes, or builds; `release-prepare check` remains the gate.
 */
final class ReleaseReadinessAnalyzer
{
    public const TASK_FILE = '.deployment/release-task.json';
    private const SAMPLE = 25;
    private const POLICY_FIELDS = ['project_key', 'production_branch', 'allowed_release_paths', 'excluded_runtime_paths', 'checks', 'migration_policy', 'build', 'health'];

    /**
     * @param  list<array>  $packages  SharedPackageAnalyzer records
     * @return array{record: array, draft: ?array, findings: list<Finding>}
     */
    public function analyze(ProjectSnapshot $project, array $packages, ?string $taskId, ?string $summary, string $date): array
    {
        $findings = [];
        $riskFlags = [];
        $directory = (string) Paths::relative($project->root, $project->composer->root);
        $manifest = ($directory === '' ? '' : $directory.'/').'composer.json';
        $record = [
            'role' => $project->role,
            'branch' => $project->branch === '' ? null : $project->branch,
            'head' => $project->head,
            'base_ref' => $project->baseRef,
            'base_sha' => $project->baseSha,
            'merge_base' => $project->mergeBase,
            'ahead' => $project->ahead,
            'behind' => $project->behind,
            'dirty_entries' => $project->dirtyEntries,
            'composer' => [
                'manifest' => $manifest,
                'lock_in_sync' => $project->composer->lockInSync(),
                'platform_php' => $project->composer->platformPhp(),
            ],
        ];

        $lock = $project->composer->lockInSync();
        if ($lock === false) {
            $findings[] = Finding::blocker(
                'LOCK_OUT_OF_SYNC',
                "{$manifest} changed without updating its composer.lock (content-hash mismatch). A server-side composer install would warn and install the old dependency set.",
                $project->name,
                ['manifest' => $manifest],
                'Run composer update --lock (or the intended composer require/update) and commit both files together.',
            );
            $riskFlags[] = 'LOCK_OUT_OF_SYNC';
        } elseif ($lock === null) {
            $findings[] = Finding::warning('LOCK_MISSING', "No composer.lock beside {$manifest}; server installs are not reproducible.", $project->name);
        }

        $policy = $project->policy;
        if ($policy === null) {
            $findings[] = Finding::warning('RELEASE_POLICY_MISSING', 'No .deployment/release-policy.json; no release draft can be prepared.', $project->name);

            return ['record' => $record, 'draft' => null, 'findings' => $findings];
        }
        $missing = array_values(array_diff(self::POLICY_FIELDS, array_keys($policy)));
        if ($missing !== []) {
            $findings[] = Finding::blocker('RELEASE_POLICY_INVALID', 'release-policy.json is missing: '.implode(', ', $missing).'. release-prepare will refuse it.', $project->name);

            return ['record' => $record, 'draft' => null, 'findings' => $findings];
        }
        $record['project_key'] = $policy['project_key'];
        $record['artifact_contract'] = $policy['build']['artifact'] ?? null;

        if ($project->baseSha === null) {
            $findings[] = Finding::warning(
                'BASE_UNRESOLVED',
                "No local {$project->baseRef} ref; the production baseline is unknown.",
                $project->name,
                remedy: "git -C {$project->name} fetch ".($policy['remote'] ?? 'origin')." {$policy['production_branch']}",
            );

            return ['record' => $record, 'draft' => null, 'findings' => $findings];
        }
        if ($project->behind > 0) {
            $findings[] = Finding::warning(
                'BRANCH_BEHIND_PRODUCTION',
                "{$project->branch} is {$project->behind} commit(s) behind {$project->baseRef}. Applying its delta onto production may conflict; bring production into the branch before preparing the release.",
                $project->name,
            );
            $riskFlags[] = 'BRANCH_BEHIND_PRODUCTION';
        }

        // The branch's own delta (from its merge base), as release-prepare
        // will see it once applied onto the production HEAD.
        $changed = array_values(array_filter(
            $project->git->changedSince($project->mergeBase ?? $project->baseSha),
            static fn (string $path): bool => $path !== self::TASK_FILE,
        ));
        $candidate = [];
        $excluded = [];
        $outside = [];
        foreach ($changed as $path) {
            if (Glob::matches($path, $policy['excluded_runtime_paths'])) {
                $excluded[] = $path;
            } elseif (Glob::matches($path, $policy['allowed_release_paths'])) {
                $candidate[] = $path;
            } else {
                $outside[] = $path;
            }
        }
        $record['delta'] = ['changed' => count($changed), 'candidate' => count($candidate), 'excluded_runtime' => count($excluded), 'outside_policy' => count($outside)];

        if ($outside !== []) {
            $findings[] = Finding::warning(
                'PATHS_OUTSIDE_RELEASE_POLICY',
                count($outside).' changed path(s) are outside allowed_release_paths. They are left out of the draft; if present in the release worktree, release-prepare fails with MIXED WORKTREE.',
                $project->name,
                ['paths' => array_slice($outside, 0, self::SAMPLE)],
                'Delete stray files, or keep them out of the release worktree.',
            );
        }
        if ($candidate === []) {
            $findings[] = Finding::info('NO_RELEASE_CANDIDATE', "No releasable change since {$project->baseRef}.", $project->name);

            return ['record' => $record, 'draft' => null, 'findings' => $findings];
        }

        foreach ($policy['dependency_rules'] ?? [] as $rule) {
            if ($this->any($candidate, (string) $rule['when']) && ! $this->any($candidate, (string) $rule['requires'])) {
                $findings[] = Finding::blocker(
                    'PACKAGE_BOUNDARY',
                    "{$rule['when']} changed without {$rule['requires']}; release-prepare fails with PACKAGE BOUNDARY FAILURE.",
                    $project->name,
                );
                $riskFlags[] = 'PACKAGE_BOUNDARY';
            }
        }
        foreach ($this->migrationIssues($project->root, $candidate, $policy['migration_policy']) as $path => $reason) {
            $findings[] = Finding::blocker(
                'MIGRATION_REVIEW_REQUIRED',
                "{$path}: {$reason}. release-prepare fails closed until the owner reviews it.",
                $project->name,
                ['path' => $path],
            );
            $riskFlags[] = 'MIGRATION_REVIEW_REQUIRED';
        }
        foreach ($packages as $package) {
            $unreleased = ($package['provider_state']['unreleased'] ?? 0) > 0;
            if ($unreleased && ($package['provider'] === $project->name || $package['consumer'] === $project->name)) {
                $riskFlags[] = 'CROSS_REPO_COUPLING';
            }
        }

        $riskFlags = array_values(array_unique($riskFlags));
        sort($riskFlags);
        $draft = [
            'schema_version' => 1,
            'task_id' => $taskId ?? sprintf('%s-readiness-%s', $policy['project_key'], $date),
            'summary' => $summary ?? "Drafted by dependency-deployment agent from {$project->branch}; narrow owned_paths to the work unit before release-prepare.",
            'base_sha' => $project->baseSha,
            'owned_paths' => $candidate,
            'risk_flags' => $riskFlags,
        ];

        return ['record' => $record, 'draft' => $draft, 'findings' => $findings];
    }

    /**
     * Mirrors ReleasePreparer::checkMigrations(), including content-bound
     * owner reviews (migration_policy.reviewed): a review clears exactly the
     * reviewed bytes, sha256 over LF-normalized content.
     *
     * @return array<string, string> path => reason
     */
    private function migrationIssues(string $root, array $candidate, array $migrationPolicy): array
    {
        $reviews = [];
        foreach ($migrationPolicy['reviewed'] ?? [] as $review) {
            if (is_array($review) && is_string($review['path'] ?? null) && is_string($review['sha256'] ?? null)) {
                $reviews[$review['path']] = $review['sha256'];
            }
        }
        $issues = [];
        foreach ($candidate as $path) {
            if (! Glob::matches($path, $migrationPolicy['paths'] ?? [])) {
                continue;
            }
            $content = @file_get_contents($root.'/'.$path);
            if ($content === false) {
                $issues[$path] = 'migration deleted or unreadable';
                continue;
            }
            foreach ($migrationPolicy['review_required_patterns'] ?? [] as $pattern) {
                if (@preg_match('/'.$pattern.'/i', $content) === 1) {
                    $digest = hash('sha256', str_replace(["\r\n", "\r"], "\n", $content));
                    if (isset($reviews[$path]) && hash_equals($reviews[$path], $digest)) {
                        break;
                    }
                    $issues[$path] = isset($reviews[$path])
                        ? "matches review pattern {$pattern} and changed since its recorded review"
                        : "matches review pattern {$pattern}";
                    break;
                }
            }
        }

        return $issues;
    }

    private function any(array $paths, string $pattern): bool
    {
        foreach ($paths as $path) {
            if (Glob::matches($path, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
