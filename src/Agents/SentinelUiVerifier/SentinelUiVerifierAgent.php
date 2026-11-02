<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\SentinelUiVerifier;

use Workspace\Agents\Kernel\AbstractAgent;
use Workspace\Agents\Kernel\AgentContext;
use Workspace\Agents\Kernel\Artifact;
use Workspace\Agents\Kernel\Finding;
use Workspace\Agents\Kernel\ProtocolException;
use Workspace\Agents\Kernel\Result;
use Workspace\Agents\Kernel\Task;
use Workspace\Agents\Support\Git;
use Workspace\Agents\Support\Id;
use Workspace\Agents\Support\Json;
use Workspace\Agents\Support\Paths;
use Workspace\Agents\Support\Process;

/**
 * Sentinel UI verifier (ADR-077, owner-approved 2026-11-02): after a
 * deployment passes the integrity audit, loads the live pages in Playwright
 * and reports overflow, missing commercial widget features, broken media,
 * RTL/font and motion defects as tasks.
 *
 * It runs saas-platform's tests/sentinel suite (the runner in workspace.json
 * `sentinel_ui.runner`) against a base URL, reads its report.json and seals
 * it as a `sentinel-ui` artifact. It only reads pages (HTTP GET in a
 * browser); it never writes to the projects. Its output directory is under
 * agents/var.
 */
final class SentinelUiVerifierAgent extends AbstractAgent
{
    public const ID = 'sentinel-ui-verifier';
    public const TASK = 'sentinel.ui-audit';
    public const TOPIC_VERIFICATION_PASSED = 'sentinel.verification.passed';
    public const TOPIC_AUDIT_REQUESTED = 'sentinel.ui-audit-requested';
    public const EVENT_PASSED = 'sentinel.ui-audit.passed';
    public const EVENT_FAILED = 'sentinel.ui-audit.failed';
    public const ARTIFACT_KIND = 'sentinel-ui';
    public const ARTIFACT_NAME = 'ui-audit';
    public const REPORT_SCHEMA = 'sentinel.ui-audit/v1';

    public function id(): string
    {
        return self::ID;
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function handles(): array
    {
        return [self::TASK];
    }

    protected function payloadSchema(string $taskType): ?array
    {
        return [
            'base_url' => '?string',
            'project' => '?string',
            'environment' => '?string',
            'pages' => '?list',
            'widths' => '?list',
            'visual' => '?bool',
            'storage_state' => '?string',
            'editor_paths' => '?list',
            // Forwarded by the sentinel.verification.passed subscription.
            'project_key' => '?string',
            'release_id' => '?string',
            'commit_sha' => '?string',
            'deployment_id' => '?string',
            'status' => '?string',
            'sentinel_verdict' => '?string',
            'verdict' => '?string',
            'evidence_hash' => '?string',
            'snapshot_hash' => '?string',
            'non_conformant_files' => '?int',
            'reasons' => '?list',
            'reaudit_of' => '?string',
        ];
    }

    protected function perform(Task $task, AgentContext $context): Result
    {
        $p = $task->payload;
        if (is_string($p['reaudit_of'] ?? null) && $p['reaudit_of'] !== '') {
            return Result::pass('Skipped: a re-audit of '.$p['reaudit_of'].' is not a new deployment.', [Finding::info('SENTINEL_UI_SKIPPED', 'The integrity re-audit did not deploy anything, so there is no new UI to audit.', $p['reaudit_of'])], data: ['status' => 'SKIPPED', 'verdict' => 'READY']);
        }

        $config = $context->setting('sentinel_ui');
        if (! is_array($config)) {
            throw new ProtocolException('NOT_CONFIGURED', 'workspace.json has no sentinel_ui section.');
        }
        $project = $p['project'] ?? $p['project_key'] ?? null;
        if (! is_string($project) || $project === '') {
            throw new ProtocolException('PROJECT_REQUIRED', 'Name the project to audit (payload project or project_key).');
        }
        $environment = $p['environment'] ?? 'local';
        $baseUrl = $p['base_url'] ?? ($config['targets'][$project][$environment] ?? null);
        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new ProtocolException('TARGET_UNKNOWN', "No base URL for {$project}/{$environment}: pass base_url or add sentinel_ui.targets.{$project}.{$environment} to workspace.json.");
        }
        if (preg_match('#^https?://[A-Za-z0-9.-]+(:\d{1,5})?/?$#', $baseUrl) !== 1) {
            throw new ProtocolException('TARGET_INVALID', 'base_url must be a bare http(s) origin, e.g. https://example.com.');
        }
        $baseUrl = rtrim($baseUrl, '/');

        $pages = $p['pages'] ?? ($config['pages'][$project] ?? ['/']);
        foreach ($pages as $page) {
            if (! is_string($page) || ! str_starts_with($page, '/') || str_starts_with($page, '//') || str_contains($page, ',')) {
                throw new ProtocolException('INVALID_PAYLOAD', 'pages are same-origin paths starting with "/" (no commas).');
            }
        }
        $widths = $p['widths'] ?? ($config['widths'] ?? [360, 390, 768, 1024, 1280, 1440]);
        foreach ($widths as $width) {
            if (! is_int($width) || $width < 280 || $width > 3840) {
                throw new ProtocolException('INVALID_PAYLOAD', 'widths are integers between 280 and 3840.');
            }
        }
        $editorPaths = $p['editor_paths'] ?? [];
        foreach ($editorPaths as $path) {
            if (! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, ',')) {
                throw new ProtocolException('INVALID_PAYLOAD', 'editor_paths are same-origin paths starting with "/" (no commas).');
            }
        }
        $storageState = null;
        if (is_string($p['storage_state'] ?? null)) {
            $storageState = Paths::isAbsolute($p['storage_state']) ? Paths::normalize($p['storage_state']) : $context->path($p['storage_state']);
            if (! is_file($storageState)) {
                throw new ProtocolException('STORAGE_STATE_MISSING', 'storage_state does not exist: '.$p['storage_state']);
            }
        }

        $runner = $config['runner'] ?? null;
        if (! is_array($runner) || ! is_string($runner['project'] ?? null) || ! is_array($runner['command'] ?? null) || $runner['command'] === [] || array_filter($runner['command'], 'is_string') !== $runner['command']) {
            throw new ProtocolException('NOT_CONFIGURED', 'sentinel_ui.runner needs a project and a command list.');
        }
        $suiteRoot = $context->projectPath($runner['project']);
        if (! is_dir($suiteRoot)) {
            throw new ProtocolException('RUNNER_MISSING', "The suite project {$runner['project']} is not checked out at {$suiteRoot}.");
        }

        $runId = Id::new('ui');
        $outputDir = $context->path((string) ($config['output'] ?? 'agents/var/sentinel-ui')).'/'.$runId;
        if (! is_dir($outputDir) && ! @mkdir($outputDir, 0777, true) && ! is_dir($outputDir)) {
            throw new ProtocolException('OUTPUT_UNWRITABLE', "Cannot create {$outputDir}.");
        }
        $env = [
            'BASE_URL' => $baseUrl,
            'SENTINEL_OUTPUT_DIR' => $outputDir,
            'SENTINEL_PAGES' => implode(',', $pages),
            'SENTINEL_WIDTHS' => implode(',', $widths),
            'SENTINEL_VISUAL' => ($p['visual'] ?? false) ? '1' : '0',
            'SENTINEL_UPDATE_BASELINES' => '0',
            'SENTINEL_STORAGE_STATE' => $storageState ?? '',
            'SENTINEL_EDITOR_PATHS' => implode(',', $editorPaths),
            'SENTINEL_PROJECT' => $project,
            'SENTINEL_ENVIRONMENT' => (string) $environment,
        ];
        $exit = Process::runToFiles($runner['command'], $suiteRoot, $env, $outputDir.'/runner.stdout.log', $outputDir.'/runner.stderr.log');

        $reportFile = $outputDir.'/report.json';
        if (! is_file($reportFile)) {
            $tail = trim(substr((string) @file_get_contents($outputDir.'/runner.stderr.log'), -800));
            throw new ProtocolException('RUNNER_NO_REPORT', "The suite wrote no report (exit {$exit}). {$tail}");
        }
        $report = UiReport::fromArray(Json::decodeFile($reportFile));
        $counts = $report->counts();
        $verdict = $report->verdict();
        $status = $counts['blocker'] === 0 ? 'PASSED' : 'FAILED';

        $target = array_filter([
            'project' => $project,
            'environment' => (string) $environment,
            'base_url' => $baseUrl,
            'release_id' => $p['release_id'] ?? null,
            'commit_sha' => $p['commit_sha'] ?? null,
            'deployment_id' => $p['deployment_id'] ?? null,
        ], static fn (mixed $v): bool => is_string($v) && $v !== '');
        $files = ['json' => $reportFile, 'markdown' => $outputDir.'/report.md'];
        $suiteHead = (new Git($suiteRoot))->head();

        $summary = sprintf('UI audit of %s: %s (%d blocker, %d warning, %d info; %d task(s)).', $baseUrl, $verdict, $counts['blocker'], $counts['warning'], $counts['info'], count($report->tasks()));
        $eventPayload = $target + ['status' => $status, 'verdict' => $verdict, 'counts' => $counts, 'tasks' => count($report->tasks()), 'report' => $files];

        return Result::pass(
            $summary,
            findings: $report->findings($baseUrl),
            artifacts: [new Artifact(
                self::ARTIFACT_KIND,
                [
                    'schema' => self::REPORT_SCHEMA,
                    'run_id' => $runId,
                    'status' => $status,
                    'verdict' => $verdict,
                    'target' => $target,
                    'counts' => $counts,
                    'tests' => $report->document['tests'] ?? null,
                    'pages' => $pages,
                    'widths' => $widths,
                    'tasks' => array_slice($report->tasks(), 0, UiReport::FINDING_LIMIT),
                    'runner_exit_code' => $exit,
                    'report' => $files,
                ],
                ['suite' => ['project' => $runner['project'], 'commit' => $suiteHead, 'command' => $runner['command']], 'base_url' => $baseUrl],
                ['report.json' => $report->document],
                self::ARTIFACT_NAME,
            )],
            events: [['topic' => $status === 'PASSED' ? self::EVENT_PASSED : self::EVENT_FAILED, 'payload' => $eventPayload]],
            data: $eventPayload + ['run_id' => $runId],
        );
    }
}
