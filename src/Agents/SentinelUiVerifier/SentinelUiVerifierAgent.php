<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\SentinelUiVerifier;

use Workspace\Agents\Kernel\AbstractAgent;
use Workspace\Agents\Kernel\AgentContext;
use Workspace\Agents\Kernel\ArtifactStore;
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
 *
 * Guardrails (owner, 2026-11-02), so it can never spin on its own output:
 *  - read-only advisor: it reports tasks (Markdown + JSON) and changes no
 *    code, content or baseline;
 *  - capped output: at most `max_tasks` (3–5, default 5) findings per scan,
 *    most urgent first (P1 blocker, P2 warning, P3 UX friction); the rest
 *    stay in the sealed report as backlog;
 *  - state fingerprint: target, pages, widths, the deployed commit, the
 *    suite commit and the widget-profile (schema) hash. While it is
 *    unchanged, tasks already reported are not reported again, and a rerun
 *    inside `cooldown_seconds` does not even start the browser. `force`
 *    lifts both for a deliberate manual review.
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
    public const MAX_TASKS_DEFAULT = 5;
    public const MAX_TASKS_RANGE = [3, 5];
    public const COOLDOWN_DEFAULT = 600;
    private const PREVIOUS_SCAN_LIMIT = 50;

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
            'max_tasks' => '?int',
            'force' => '?bool',
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

        $maxTasks = $p['max_tasks'] ?? ($config['max_tasks'] ?? self::MAX_TASKS_DEFAULT);
        if (! is_int($maxTasks) || $maxTasks < self::MAX_TASKS_RANGE[0] || $maxTasks > self::MAX_TASKS_RANGE[1]) {
            throw new ProtocolException('INVALID_PAYLOAD', 'max_tasks must be between '.self::MAX_TASKS_RANGE[0].' and '.self::MAX_TASKS_RANGE[1].'.');
        }
        $cooldown = $config['cooldown_seconds'] ?? self::COOLDOWN_DEFAULT;
        $cooldown = is_int($cooldown) && $cooldown >= 0 ? $cooldown : self::COOLDOWN_DEFAULT;
        $force = ($p['force'] ?? false) === true;

        $suiteHead = (new Git($suiteRoot))->head();
        $profilesFile = $suiteRoot.'/tests/sentinel/widget-profiles.json';
        $targetKey = implode('|', [$project, (string) $environment, $baseUrl]);
        $state = [
            'target' => $targetKey,
            'pages' => $pages,
            'widths' => $widths,
            'visual' => ($p['visual'] ?? false) === true,
            'editor_paths' => $editorPaths,
            'deployed_commit' => is_string($p['commit_sha'] ?? null) ? $p['commit_sha'] : null,
            'suite_commit' => $suiteHead,
            'profiles_sha256' => is_file($profilesFile) ? hash_file('sha256', $profilesFile) : null,
        ];
        $fingerprint = hash('sha256', Json::canonical($state));
        $previous = $force ? null : $this->previousAudit($context, $targetKey);
        $sameState = $previous !== null && $previous['fingerprint'] === $fingerprint;
        if ($sameState && $cooldown > 0 && $previous['age'] < $cooldown) {
            return Result::pass(
                sprintf('UI audit of %s skipped: nothing changed since %s (%ds ago, cooldown %ds).', $baseUrl, $previous['artifact_id'], $previous['age'], $cooldown),
                [Finding::info('SENTINEL_UI_UNCHANGED', 'Same commit, schema and target as the last audit inside the cooldown window: not re-evaluated (pass force to override).', $baseUrl, ['previous' => $previous['artifact_id'], 'state_fingerprint' => $fingerprint])],
                data: ['status' => 'UNCHANGED', 'verdict' => $previous['verdict'], 'previous' => $previous['artifact_id'], 'state_fingerprint' => $fingerprint],
            );
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

        // Dedup by state, then cap: only new tasks are reported, most urgent first.
        $known = $sameState ? $previous['known_task_keys'] : [];
        $tasks = $report->prioritizedTasks();
        $new = array_values(array_filter($tasks, static fn (array $t): bool => ! in_array(UiReport::taskKey($t), $known, true)));
        $suppressed = array_values(array_filter($tasks, static fn (array $t): bool => in_array(UiReport::taskKey($t), $known, true)));
        $emitted = array_slice($new, 0, $maxTasks);
        $backlog = array_slice($new, $maxTasks);
        $keys = static fn (array $list): array => array_values(array_map([UiReport::class, 'taskKey'], $list));
        $findings = UiReport::findingsFor($emitted, $baseUrl);
        if ($suppressed !== []) {
            $findings[] = Finding::info('SENTINEL_UI_ALREADY_REPORTED', sprintf('%d task(s) were already reported for this state (%s) and are not repeated.', count($suppressed), $previous['artifact_id']), $baseUrl, ['task_keys' => $keys($suppressed)]);
        }
        if ($backlog !== []) {
            $findings[] = Finding::info('SENTINEL_UI_BACKLOG', sprintf('%d more task(s) wait in the report (at most %d per scan, most urgent first).', count($backlog), $maxTasks), $baseUrl, ['task_keys' => $keys($backlog)]);
        }
        if ($tasks === []) {
            $findings[] = Finding::info('SENTINEL_UI_READY', 'No UI change required on '.$baseUrl.'.', $baseUrl);
        }

        $summary = sprintf('UI audit of %s: %s (%d blocker, %d warning, %d info; %d task(s): %d new reported, %d backlog, %d already reported).', $baseUrl, $verdict, $counts['blocker'], $counts['warning'], $counts['info'], count($tasks), count($emitted), count($backlog), count($suppressed));
        $eventPayload = $target + ['status' => $status, 'verdict' => $verdict, 'counts' => $counts, 'tasks' => count($tasks), 'new_tasks' => count($emitted), 'report' => $files, 'state_fingerprint' => $fingerprint];

        return Result::pass(
            $summary,
            findings: $findings,
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
                    'tasks' => array_slice($tasks, 0, UiReport::FINDING_LIMIT),
                    'target_key' => $targetKey,
                    'state' => $state,
                    'state_fingerprint' => $fingerprint,
                    'guardrails' => ['read_only' => true, 'max_tasks' => $maxTasks, 'cooldown_seconds' => $cooldown, 'forced' => $force],
                    'emitted_task_keys' => $keys($emitted),
                    'backlog_task_keys' => $keys($backlog),
                    'suppressed_task_keys' => $keys($suppressed),
                    'known_task_keys' => array_values(array_unique([...$known, ...$keys($tasks)])),
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

    /**
     * The newest intact `sentinel-ui` artifact this agent sealed for the same
     * target (envelope and attachment digests verify). Tampered or foreign
     * artifacts are skipped, never trusted.
     *
     * @return array{artifact_id: string, fingerprint: ?string, known_task_keys: list<string>, verdict: string, age: int}|null
     */
    private function previousAudit(AgentContext $context, string $targetKey): ?array
    {
        $settings = (array) $context->setting('runtime', []);
        $root = $context->path((string) ($settings['artifacts'] ?? 'agents/var/artifacts')).'/'.self::ARTIFACT_KIND;
        $candidates = [];
        foreach (is_dir($root) ? (scandir($root) ?: []) : [] as $entry) {
            if (preg_match('/-(\d{8}T\d{6})-[0-9a-f]{8}$/D', $entry, $match) === 1 && is_file("{$root}/{$entry}/artifact.json")) {
                $candidates[] = [$match[1], $entry];
            }
        }
        rsort($candidates);
        foreach (array_slice($candidates, 0, self::PREVIOUS_SCAN_LIMIT) as [$stamp, $entry]) {
            $path = "{$root}/{$entry}/artifact.json";
            if (! ArtifactStore::verify($path)['valid']) {
                continue;
            }
            $envelope = Json::decodeFile($path);
            $payload = (array) ($envelope['payload'] ?? []);
            if (($envelope['producer']['agent'] ?? null) !== self::ID || ($payload['target_key'] ?? null) !== $targetKey) {
                continue;
            }
            $sealed = \DateTimeImmutable::createFromFormat('Ymd\THis', $stamp, new \DateTimeZone('UTC'));

            return [
                'artifact_id' => (string) ($envelope['artifact_id'] ?? $entry),
                'fingerprint' => is_string($payload['state_fingerprint'] ?? null) ? $payload['state_fingerprint'] : null,
                'known_task_keys' => array_values(array_filter((array) ($payload['known_task_keys'] ?? []), 'is_string')),
                'verdict' => is_string($payload['verdict'] ?? null) ? $payload['verdict'] : 'ATTENTION',
                'age' => $sealed === false ? PHP_INT_MAX : max(0, time() - $sealed->getTimestamp()),
            ];
        }

        return null;
    }
}
