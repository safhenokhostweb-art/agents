<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\DependencyDeployment;

use Workspace\Agents\Kernel\AbstractAgent;
use Workspace\Agents\Kernel\AgentContext;
use Workspace\Agents\Kernel\Artifact;
use Workspace\Agents\Kernel\Finding;
use Workspace\Agents\Kernel\ProtocolException;
use Workspace\Agents\Kernel\Result;
use Workspace\Agents\Kernel\Task;
use Workspace\Agents\Support\Paths;

/**
 * Dependency & Deployment Agent.
 *
 * Monitors coordination between the workspace projects (shared Composer
 * packages, lock integrity, version constraints, cross-repo release order)
 * and prepares the inputs deployment-system's release-prepare consumes.
 *
 * Boundary (WORK_UNIT_DELIVERY_WORKFLOW.md): the deployment artifact is
 * server-managed-exact-commit, built by deployment-system. This agent never
 * builds a competing archive, never fetches, stages, commits, or pushes, and
 * never deploys. Its artifact is a sealed readiness report plus
 * release-task drafts for a human or delivery agent to install.
 */
final class DependencyDeploymentAgent extends AbstractAgent
{
    public const ID = 'dependency-deployment';
    public const TASK = 'deployment.readiness';
    public const EVENT_REPORTED = 'deployment.readiness.reported';
    public const EVENT_DRIFT = 'dependency.drift.detected';
    public const ARTIFACT_KIND = 'deployment-readiness';

    public function id(): string
    {
        return self::ID;
    }

    public function handles(): array
    {
        return [self::TASK];
    }

    protected function payloadSchema(string $taskType): ?array
    {
        return ['projects' => '?list', 'task_id' => '?string', 'summary' => '?string'];
    }

    protected function perform(Task $task, AgentContext $context): Result
    {
        $taskId = $task->payload['task_id'] ?? null;
        if ($taskId !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $taskId) !== 1) {
            throw new ProtocolException('INVALID_PAYLOAD', 'task_id must be 1-100 characters of letters, digits, dot, underscore, or dash.');
        }
        // Dependency monitoring always spans the whole topology: scoping it
        // would hide coupling. `projects` only scopes release drafting.
        $declared = $context->projects();
        $selected = array_map('strval', $task->payload['projects'] ?? array_keys($declared));
        foreach ($selected as $name) {
            $context->projectPath($name);
        }

        $snapshots = [];
        foreach (array_keys($declared) as $name) {
            $snapshots[$name] = ProjectSnapshot::capture((string) $name, $context->projectPath((string) $name), (string) ($declared[$name]['role'] ?? 'project'), $context->composerPath((string) $name));
        }

        $shared = (new SharedPackageAnalyzer())->analyze($snapshots);
        $findings = $shared['findings'];
        $projects = [];
        $drafts = [];
        $readiness = new ReleaseReadinessAnalyzer();
        foreach ($snapshots as $name => $snapshot) {
            if (! in_array($name, $selected, true)) {
                $projects[$name] = ['role' => $snapshot->role, 'scope' => 'monitored-only'] + $snapshot->provenance();
                continue;
            }
            $analysis = $readiness->analyze($snapshot, $shared['packages'], $taskId, $task->payload['summary'] ?? null, gmdate('Ymd'));
            $projects[$name] = $analysis['record'];
            array_push($findings, ...$analysis['findings']);
            if ($analysis['draft'] !== null) {
                $drafts[$name] = $analysis['draft'];
            }
        }
        $handoff = $this->handoff($context, $snapshots, $drafts, $shared['packages'], $findings);

        $counts = ['blocker' => 0, 'warning' => 0, 'info' => 0];
        foreach ($findings as $finding) {
            $counts[$finding->severity->value]++;
        }
        $verdict = $counts['blocker'] > 0 ? 'BLOCKED' : ($counts['warning'] > 0 ? 'ATTENTION' : 'READY');

        $attachments = [];
        foreach ($drafts as $name => $draft) {
            $attachments["release-task.{$name}.json"] = $draft;
        }
        $artifact = new Artifact(
            self::ARTIFACT_KIND,
            [
                'verdict' => $verdict,
                'counts' => $counts,
                'projects' => $projects,
                'shared_packages' => $shared['packages'],
                'release_drafts' => $drafts,
                'handoff' => $handoff,
                'findings' => array_map(static fn (Finding $f): array => $f->toArray(), $findings),
            ],
            [
                'workspace_root' => $context->workspaceRoot,
                'projects' => array_map(static fn (ProjectSnapshot $s): array => $s->provenance(), $snapshots),
            ],
            $attachments,
        );

        $events = [[
            'topic' => self::EVENT_REPORTED,
            'payload' => ['verdict' => $verdict, 'counts' => $counts, 'projects' => array_keys($snapshots), 'drafts' => array_keys($drafts)],
        ]];
        $drifted = array_values(array_filter($shared['packages'], static fn (array $p): bool => ($p['install']['in_sync'] ?? true) === false));
        if ($drifted !== []) {
            $events[] = [
                'topic' => self::EVENT_DRIFT,
                'payload' => ['packages' => array_map(static fn (array $p): array => [
                    'package' => $p['package'],
                    'consumer' => $p['consumer'],
                    'provider' => $p['provider'],
                    'modified' => $p['install']['modified'],
                    'missing' => $p['install']['missing'],
                    'extra' => $p['install']['extra'],
                ], $drifted)],
            ];
        }

        $summary = sprintf(
            '%s: %d blocker(s), %d warning(s), %d info; %d release draft(s)%s',
            $verdict, $counts['blocker'], $counts['warning'], $counts['info'], count($drafts),
            $drafts === [] ? '' : ' ('.implode(', ', array_keys($drafts)).')',
        );

        return Result::pass($summary, $findings, [$artifact], $events, data: ['verdict' => $verdict, 'counts' => $counts, 'drafts' => array_keys($drafts)]);
    }

    /**
     * How the drafts reach deployment-system, and in which order.
     *
     * @param  array<string, ProjectSnapshot>  $snapshots
     * @param  list<Finding>  $findings  appended to
     */
    private function handoff(AgentContext $context, array $snapshots, array $drafts, array $packages, array &$findings): array
    {
        $settings = (array) $context->setting('deployment_system', []);
        $tool = isset($settings['release_prepare']) ? $context->path((string) $settings['release_prepare']) : null;
        $available = $tool !== null && is_file($tool);
        if (! $available && $drafts !== []) {
            $findings[] = Finding::warning(
                'HANDOFF_TOOL_MISSING',
                'deployment-system release-prepare.php was not found at the path configured in workspace.json; drafts cannot be checked.',
                'deployment-system',
                ['configured' => $settings['release_prepare'] ?? null],
            );
        }

        // Providers whose unreleased package changes a consumer depends on
        // must be released first.
        $before = [];
        foreach ($packages as $package) {
            if ($package['scope'] === 'cross-project' && ($package['provider_state']['unreleased'] ?? 0) > 0) {
                $before[$package['consumer']][$package['provider']] = true;
            }
        }
        $order = [];
        $pending = array_keys($snapshots);
        while ($pending !== []) {
            foreach ($pending as $index => $name) {
                $waiting = array_diff(array_keys($before[$name] ?? []), $order, [$name]);
                if (array_intersect($waiting, $pending) === []) {
                    $order[] = $name;
                    unset($pending[$index]);
                    continue 2;
                }
            }
            // A dependency cycle: keep declared order rather than loop.
            array_push($order, ...$pending);
            break;
        }

        $consumers = [];
        foreach ($packages as $package) {
            if ($package['scope'] === 'cross-project') {
                $consumers[$package['consumer']][$package['provider']] = true;
            }
        }
        foreach ($consumers as $consumer => $providers) {
            $policy = $snapshots[$consumer]->policy ?? null;
            if ($policy === null) {
                continue;
            }
            // A declared dependency covers a provider when its path, resolved
            // the way ReleasePreparer does ({project_root} = consumer root),
            // is that provider's checkout.
            foreach ($policy['git_dependencies'] ?? [] as $dependency) {
                $resolved = Paths::normalize(str_replace('{project_root}', $snapshots[$consumer]->root, (string) ($dependency['path'] ?? '')));
                foreach (array_keys($providers) as $provider) {
                    if (Paths::relative($snapshots[$provider]->root, $resolved) === '') {
                        unset($providers[$provider]);
                    }
                }
            }
            if ($providers !== []) {
                $findings[] = Finding::info(
                    'RECOMMEND_GIT_DEPENDENCY',
                    "{$consumer} installs packages from ".implode(', ', array_keys($providers))." but no git_dependencies entry in its release-policy resolves to that checkout, so its release metadata cannot record which provider commit it was validated against.",
                    $consumer,
                    ['suggested_git_dependencies' => array_map(static fn (string $provider): array => [
                        'name' => $provider,
                        'path' => '{project_root}/../'.basename($snapshots[$provider]->root),
                        'branch' => $snapshots[$provider]->policy['production_branch'] ?? 'main',
                    ], array_keys($providers))],
                    'Owner decision: add git_dependencies to the consumer release-policy.json.',
                );
            }
        }

        $steps = [];
        foreach ($drafts as $name => $draft) {
            $steps[$name] = [
                'attachment' => "release-task.{$name}.json",
                'install_as' => ReleaseReadinessAnalyzer::TASK_FILE,
                'in' => "an isolated {$name} worktree whose HEAD is {$draft['base_sha']} on the production branch, with the work unit applied as unstaged changes",
                'check_command' => $available ? 'php '.escapeshellarg($tool).' check' : null,
            ];
        }

        return [
            'deployment_system' => ['release_prepare' => $tool, 'available' => $available],
            'artifact_contract' => 'server-managed-exact-commit: deployment-system builds the artifact from the pushed release commit; this agent never does.',
            'procedure' => 'saas-platform/docs/quality/WORK_UNIT_DELIVERY_WORKFLOW.md (P5 Artifact -> P7 Confirm Receipt)',
            'release_order' => $order,
            'steps' => $steps,
        ];
    }
}
