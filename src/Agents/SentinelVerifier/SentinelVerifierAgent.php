<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\SentinelVerifier;

use DateTimeImmutable;
use InvalidArgumentException;
use Workspace\Agents\Kernel\AbstractAgent;
use Workspace\Agents\Kernel\AgentContext;
use Workspace\Agents\Kernel\Artifact;
use Workspace\Agents\Kernel\Finding;
use Workspace\Agents\Kernel\ProtocolException;
use Workspace\Agents\Kernel\Result;
use Workspace\Agents\Kernel\Task;
use Workspace\Agents\Support\Paths;

/**
 * Sentinel Verifier: audits one deployment's proof chain
 *
 *   Commit SHA -> Artifact -> Hub Release -> Server Agent -> Active Runtime -> Health
 *
 * from explicit evidence files, and seals the verdict as a `sentinel`
 * artifact. It judges three independent inputs and never mixes their roles:
 *
 *  - a sentinel.v1 evidence document (as the Hub collects it), re-verified
 *    by EvidenceVerifier, the port of the Hub's SentinelVerifier;
 *  - a sentinel.runtime.v1 snapshot from the Server Agent, checked for
 *    completeness, freshness, deployment-marker coherence and attribution;
 *  - optionally the artifact's file manifest against the active release
 *    (another manifest, or the release directory re-hashed read-only),
 *    listing every non-conformant file.
 *
 * Boundary (deployment-system docs/review/ADR-SENTINEL-BOUNDARIES-DRAFT.md):
 * a read-only consumer of explicit evidence. It never queries the Hub
 * database, calls a runtime or health endpoint, follows a link out of a
 * release, or writes anywhere but its own artifact. Missing or
 * uninterpretable evidence is UNKNOWN, never VERIFIED.
 */
final class SentinelVerifierAgent extends AbstractAgent
{
    public const ID = 'sentinel-verifier';
    public const TASK = 'sentinel.verify';
    public const TOPIC_DEPLOYMENT_COMPLETED = 'deployment.completed';
    public const TOPIC_AUDIT_REQUESTED = 'sentinel.audit-requested';
    public const EVENT_PASSED = 'sentinel.verification.passed';
    public const EVENT_FAILED = 'sentinel.verification.failed';
    public const ARTIFACT_KIND = 'sentinel';
    /** Reports land in var/artifacts/sentinel/audit-<timestamp>-<random>/artifact.json. */
    public const ARTIFACT_NAME = 'audit';
    public const REPORT_SCHEMA = 'sentinel.audit-report/v1';
    /** The binary audit status: PASSED only when VERIFIED; UNKNOWN fails closed. */
    public const STATUS_PASSED = 'PASSED';
    public const STATUS_FAILED = 'FAILED';

    /** The Hub's default deployment.runtime_snapshot_freshness_seconds. */
    public const DEFAULT_MAX_SNAPSHOT_AGE = 300;
    public const MAX_INPUT_BYTES = 16 * 1024 * 1024;
    public const MAX_RELEASE_FILES = 50000;
    public const MAX_RELEASE_BYTES = 1024 * 1024 * 1024;
    /** Non-conformant files listed in the report; the total is always recorded. */
    public const REPORT_FILE_LIMIT = 1000;
    /** Written into the release by the Server Agent at activation; never part of an artifact. */
    public const DEFAULT_EXCLUDE = ['.deployment/runtime-marker'];

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
        $expectation = array_fill_keys(EvidenceVerifier::EXPECTATION_KEYS, '?string');

        return $expectation + [
            'evidence' => '?string',
            'snapshot' => '?string',
            'expected_manifest' => '?string',
            'observed_manifest' => '?string',
            'release_dir' => '?string',
            'exclude' => '?list',
            'file_verification' => '?bool',
            'min_agent_version' => '?string',
            'max_snapshot_age_seconds' => '?int',
        ];
    }

    protected function perform(Task $task, AgentContext $context): Result
    {
        $payload = $task->payload;
        $options = $this->options($payload);
        $expectation = $this->expectation($payload);
        $inputs = [];
        $sections = [];
        $verdicts = [];
        $attachments = [];
        $hashes = ['evidence' => null, 'snapshot' => null];
        $evidence = null;
        $snapshot = null;

        if (isset($payload['evidence'])) {
            $input = $this->read($context, 'evidence', $payload['evidence']);
            $inputs['evidence'] = $input['meta'];
            try {
                $evidence = EvidenceContract::fromJson($input['bytes']);
            } catch (InvalidArgumentException $exception) {
                $sections['evidence'] = ['evidence_contract' => Checks::unknown('evidence document rejected as '.EvidenceContract::VERSION.': '.EvidenceGuard::safeMessage($exception->getMessage()))];
            }
            if ($evidence !== null) {
                $verification = (new EvidenceVerifier($options['file_verification']))->verify($evidence, $expectation);
                $sections['evidence'] = $verification['checks'];
                $verdicts['evidence'] = $verification['result'];
                $hashes['evidence'] = $verification['evidence_hash'];
                $attachments['evidence.json'] = $evidence->toArray();
            }
        } else {
            $sections['evidence'] = ['evidence_document' => Checks::unknown('no '.EvidenceContract::VERSION.' evidence document supplied — a runtime snapshot alone cannot prove the deployment chain.')];
        }
        $verdicts['evidence'] ??= Checks::aggregate($sections['evidence']);

        // Freshness is judged at the moment the Hub collected the evidence;
        // without an evidence document, at the moment of this audit.
        $reference = $evidence?->verifiedAt() ?? new DateTimeImmutable();
        if (isset($payload['snapshot'])) {
            $input = $this->read($context, 'snapshot', $payload['snapshot']);
            $inputs['snapshot'] = $input['meta'];
            try {
                $snapshot = RuntimeEvidenceContract::fromJson($input['bytes']);
            } catch (InvalidArgumentException $exception) {
                $sections['snapshot'] = ['snapshot_contract' => Checks::unknown('runtime snapshot rejected as '.RuntimeEvidenceContract::VERSION.': '.EvidenceGuard::safeMessage($exception->getMessage()))];
            }
            if ($snapshot !== null) {
                $inspector = new RuntimeSnapshotInspector($options['max_snapshot_age_seconds'], $options['min_agent_version'], $options['file_verification']);
                $sections['snapshot'] = $inspector->inspect($snapshot, $expectation, $reference);
                $hashes['snapshot'] = $snapshot->hash();
                $attachments['runtime-snapshot.json'] = $snapshot->toArray();
            }
            $verdicts['snapshot'] = Checks::aggregate($sections['snapshot']);
            if ($evidence !== null && $snapshot !== null) {
                $sections['agreement'] = ['evidence_snapshot_agreement' => RuntimeSnapshotInspector::agreement($evidence, $snapshot)];
                $verdicts['agreement'] = Checks::aggregate($sections['agreement']);
            }
        }

        $files = null;
        if (isset($payload['expected_manifest'])) {
            [$sections['files'], $files] = $this->files($context, $payload, $options, $evidence, $snapshot, $inputs);
            $verdicts['files'] = Checks::aggregate($sections['files']);
        }

        $sentinelVerdict = Checks::combine(...array_values($verdicts));
        $status = $sentinelVerdict === Checks::VERIFIED ? self::STATUS_PASSED : self::STATUS_FAILED;
        $verdict = match ($sentinelVerdict) {
            Checks::VERIFIED => 'READY',
            Checks::FAILED => 'BLOCKED',
            default => 'ATTENTION',
        };
        $nonConformant = array_map($this->reportEntry(...), $files['discrepancies'] ?? []);
        $target = $this->target($expectation, $evidence, $snapshot);

        [$findings, $reasons, $counts] = $this->findings($sections, $nonConformant);
        if ($sentinelVerdict === Checks::VERIFIED) {
            $findings[] = Finding::info('SENTINEL_VERIFIED', 'Every evaluated link of the deployment proof chain holds.', $target['release_id'] ?? null, $hashes);
        }

        $report = [
            'schema' => self::REPORT_SCHEMA,
            'status' => $status,
            'sentinel_verdict' => $sentinelVerdict,
            'verdict' => $verdict,
            'checked_at' => gmdate('c'),
            'reference_time' => $reference->format(DATE_ATOM),
            'target' => $target,
            'expectation' => $expectation,
            // A release directory has no single digest; its scan is in provenance.
            'evidence_hash' => $hashes + ['inputs' => array_filter(array_map(static fn (array $meta): ?string => $meta['sha256'] ?? null, $inputs))],
            'sections' => array_map(static fn (string $name): array => ['verdict' => $verdicts[$name], 'checks' => $sections[$name]], array_combine(array_keys($sections), array_keys($sections))),
            'runtime_evidence' => isset($verdicts['snapshot']) ? [
                'verdict' => $verdicts['snapshot'],
                'snapshot_status' => $snapshot?->get('snapshot_status'),
                'agent_version' => $snapshot?->get('agent_version'),
                'deployment_marker' => $sections['snapshot']['deployment_marker']['status'] ?? null,
                'snapshot_hash' => $hashes['snapshot'],
            ] : null,
            'files' => $files === null ? null : array_diff_key($files, ['discrepancies' => true]),
            'non_conformant_files' => array_slice($nonConformant, 0, self::REPORT_FILE_LIMIT),
            'non_conformant_total' => count($nonConformant),
            'reasons' => $reasons,
            'findings' => array_map(static fn (Finding $f): array => $f->toArray(), $findings),
        ];
        $artifact = new Artifact(
            self::ARTIFACT_KIND,
            $report,
            [
                'workspace_root' => $context->workspaceRoot,
                'inputs' => $inputs,
                'options' => $options,
            ],
            $attachments,
            self::ARTIFACT_NAME,
        );

        $event = [
            'topic' => $status === self::STATUS_PASSED ? self::EVENT_PASSED : self::EVENT_FAILED,
            'payload' => $target + [
                'status' => $status,
                'sentinel_verdict' => $sentinelVerdict,
                'verdict' => $verdict,
                'evidence_hash' => $hashes['evidence'],
                'snapshot_hash' => $hashes['snapshot'],
                'non_conformant_files' => count($nonConformant),
                'reasons' => $reasons,
            ],
        ];

        $summary = sprintf(
            '%s (%s): %d failed and %d unknown check(s)%s%s',
            $status,
            $sentinelVerdict,
            $counts['fail'],
            $counts['unknown'],
            $files === null ? '' : sprintf(', %d non-conformant file(s)', count($nonConformant)),
            isset($target['release_id']) ? " for release {$target['release_id']}" : '',
        );

        return Result::pass($summary, $findings, [$artifact], [$event], data: [
            'status' => $status,
            'verdict' => $verdict,
            'sentinel_verdict' => $sentinelVerdict,
            'evidence_hash' => $hashes['evidence'],
            'non_conformant_files' => count($nonConformant),
        ]);
    }

    /**
     * @return array{file_verification: bool, min_agent_version: ?string, max_snapshot_age_seconds: int, exclude: list<string>}
     */
    private function options(array $payload): array
    {
        if (! isset($payload['evidence']) && ! isset($payload['snapshot'])) {
            throw new ProtocolException('EVIDENCE_MISSING', 'sentinel.verify needs a sentinel.v1 evidence document (payload.evidence) and/or a sentinel.runtime.v1 snapshot (payload.snapshot).');
        }
        $observedSources = (int) isset($payload['observed_manifest']) + (int) isset($payload['release_dir']);
        if (isset($payload['expected_manifest']) ? $observedSources !== 1 : $observedSources !== 0) {
            throw new ProtocolException('INVALID_PAYLOAD', 'File comparison needs expected_manifest together with exactly one of observed_manifest or release_dir.');
        }

        $minVersion = $payload['min_agent_version'] ?? null;
        if ($minVersion !== null && preg_match('/^\d+(\.\d+){0,3}$/D', $minVersion) !== 1) {
            throw new ProtocolException('INVALID_PAYLOAD', 'min_agent_version must be a dotted version number such as 1.4.3.');
        }
        $maxAge = $payload['max_snapshot_age_seconds'] ?? self::DEFAULT_MAX_SNAPSHOT_AGE;
        if ($maxAge < 1 || $maxAge > 30 * 86400) {
            throw new ProtocolException('INVALID_PAYLOAD', 'max_snapshot_age_seconds must be between 1 and 2592000.');
        }

        $exclude = $payload['exclude'] ?? [];
        if (count($exclude) > 200) {
            throw new ProtocolException('INVALID_PAYLOAD', 'exclude accepts at most 200 patterns.');
        }
        foreach ($exclude as $pattern) {
            if (! is_string($pattern) || $pattern === '' || strlen($pattern) > 512 || Paths::isAbsolute($pattern)
                || preg_match('#(^|/)\.\.(/|$)#', str_replace('\\', '/', $pattern)) === 1) {
                throw new ProtocolException('INVALID_PAYLOAD', 'exclude entries must be release-relative glob patterns without "..".');
            }
        }

        return [
            'file_verification' => $payload['file_verification'] ?? false,
            'min_agent_version' => $minVersion,
            'max_snapshot_age_seconds' => $maxAge,
            'exclude' => array_values(array_unique(array_merge(self::DEFAULT_EXCLUDE, $exclude))),
        ];
    }

    /** @return array<string, string> the verification target, as supplied by the caller (never inferred from the evidence) */
    private function expectation(array $payload): array
    {
        $expectation = [];
        foreach (EvidenceVerifier::EXPECTATION_KEYS as $key) {
            if (! isset($payload[$key])) {
                continue;
            }
            if (trim($payload[$key]) === '' || strlen($payload[$key]) > 255 || preg_match('/[\x00-\x1F\x7F]/', $payload[$key]) === 1) {
                throw new ProtocolException('INVALID_PAYLOAD', "Payload key '{$key}' must be a non-empty single-line string of at most 255 characters.");
            }
            $expectation[$key] = $payload[$key];
        }

        return $expectation;
    }

    /**
     * @param  array<string, array{path: string, sha256?: string, bytes?: int}>  $inputs  appended to
     * @return array{0: array<string, array{status: string, detail: string}>, 1: array<string, mixed>|null}
     */
    private function files(AgentContext $context, array $payload, array $options, ?EvidenceContract $evidence, ?RuntimeEvidenceContract $snapshot, array &$inputs): array
    {
        $input = $this->read($context, 'expected_manifest', $payload['expected_manifest']);
        $inputs['expected_manifest'] = $input['meta'];
        try {
            $expected = FileManifest::fromJson($input['bytes']);
        } catch (InvalidArgumentException $exception) {
            return [['expected_manifest' => Checks::unknown('expected file manifest rejected: '.$exception->getMessage())], null];
        }

        $unreadable = [];
        $limitExceeded = false;
        if (isset($payload['release_dir'])) {
            $directory = $this->resolve($context, 'release_dir', $payload['release_dir']);
            if (! is_dir($directory)) {
                throw new ProtocolException('INPUT_UNREADABLE', "payload.release_dir is not a directory: {$payload['release_dir']}");
            }
            $observation = FileManifest::observe($directory, $options['exclude'], self::MAX_RELEASE_FILES, self::MAX_RELEASE_BYTES);
            $observed = $observation['manifest'];
            $unreadable = $observation['unreadable'];
            $limitExceeded = $observation['limit_exceeded'];
            $inputs['release_dir'] = [
                'path' => $this->display($context, $directory),
                'files_hashed' => $observed->entryCount(),
                'bytes_hashed' => $observation['bytes'],
                'excluded' => $observation['excluded'],
                'limit_exceeded' => $limitExceeded,
            ];
        } else {
            $input = $this->read($context, 'observed_manifest', $payload['observed_manifest']);
            $inputs['observed_manifest'] = $input['meta'];
            try {
                $observed = FileManifest::fromJson($input['bytes']);
            } catch (InvalidArgumentException $exception) {
                return [['observed_manifest' => Checks::unknown('observed file manifest rejected: '.$exception->getMessage())], null];
            }
        }

        $scope = $evidence?->get('file_comparison_scope') ?? 'full';
        $comparison = new FileManifestComparison(
            $expected->entries(), $observed->entries(),
            $expected->hash(), $observed->hash(),
            $expected->entryCount(), $observed->entryCount(),
            $expected->totalSize(), $observed->totalSize(),
            $scope, $unreadable, $options['exclude'], $limitExceeded,
        );
        $result = $comparison->compare();
        $detail = "scope '{$scope}': {$result['summary']}".($limitExceeded ? ' (observation stopped at the file/byte bound)' : '').'.';
        $checks = [
            'manifest_binding' => $this->binding($expected, $evidence, $snapshot),
            'file_comparison' => match ($comparison->verdictOf($result)) {
                'PASS' => Checks::pass($detail),
                'FAIL' => Checks::fail($detail),
                default => Checks::unknown($detail),
            },
        ];

        return [$checks, [
            'scope' => $scope,
            'expected_manifest_hash' => $expected->hash(),
            'observed_manifest_hash' => $observed->hash(),
            'expected_entries' => $expected->entryCount(),
            'observed_entries' => $observed->entryCount(),
            'counts' => array_intersect_key($result, array_flip(['match_count', 'missing_count', 'hash_mismatch_count', 'extra_count', 'excluded_count', 'unreadable_count'])),
            'summary' => $result['summary'],
            'discrepancies' => $result['discrepancies'],
        ]];
    }

    /**
     * The expected manifest is only evidence for this deployment if the
     * deployment's own records name it.
     *
     * @return array{status: string, detail: string}
     */
    private function binding(FileManifest $expected, ?EvidenceContract $evidence, ?RuntimeEvidenceContract $snapshot): array
    {
        $claims = array_filter([
            'evidence expected_file_manifest_hash' => $evidence?->get('expected_file_manifest_hash'),
            'snapshot file_manifest_hash' => $snapshot?->get('file_manifest_hash'),
        ], static fn (mixed $hash): bool => is_string($hash));
        if ($claims === []) {
            return Checks::unknown('the expected manifest is not bound to this deployment: neither the evidence document nor the runtime snapshot records a file manifest hash.');
        }
        $mismatched = array_keys(array_filter($claims, static fn (string $hash): bool => ! hash_equals($hash, $expected->hash())));
        if ($mismatched !== []) {
            return Checks::fail("expected manifest {$expected->hash()} is not the manifest recorded by the ".implode(' and ', $mismatched).'.');
        }

        return Checks::pass('expected manifest is the one recorded by the '.implode(' and ', array_keys($claims)).'.');
    }

    /**
     * @param  array<string, array<string, array{status: string, detail: string}>>  $sections
     * @param  list<array<string, mixed>>  $nonConformant
     * @return array{0: list<Finding>, 1: list<string>, 2: array{fail: int, unknown: int}}
     */
    private function findings(array $sections, array $nonConformant): array
    {
        $findings = [];
        $reasons = [];
        $counts = ['fail' => 0, 'unknown' => 0];
        foreach ($sections as $section => $checks) {
            foreach ($checks as $id => $check) {
                if ($check['status'] === Checks::PASS) {
                    continue;
                }
                $failed = $check['status'] === Checks::FAIL;
                $counts[$failed ? 'fail' : 'unknown']++;
                $subject = "{$section}.{$id}";
                $reasons[] = "{$subject} [".strtoupper($check['status'])."]: {$check['detail']}";
                $evidence = $subject === 'files.file_comparison' && $nonConformant !== []
                    ? ['non_conformant_total' => count($nonConformant), 'first' => array_slice($nonConformant, 0, 20)]
                    : [];
                $findings[] = $failed
                    ? Finding::blocker('SENTINEL_CHECK_FAILED', $check['detail'], $subject, $evidence, 'The deployed runtime is proven not to be the expected release; investigate before trusting it.')
                    : Finding::warning('SENTINEL_CHECK_UNKNOWN', $check['detail'], $subject, $evidence, 'Supply the missing or fresh evidence and audit again; UNKNOWN is never a pass.');
            }
        }

        return [$findings, $reasons, $counts];
    }

    /** Names and hashes of credential-like files never reach a report. */
    private function reportEntry(array $discrepancy): array
    {
        if (EvidenceGuard::isSensitivePath($discrepancy['path'])) {
            return [
                'path' => '[redacted:'.substr(hash('sha256', $discrepancy['path']), 0, 12).']',
                'outcome' => $discrepancy['outcome'],
                'detail' => $discrepancy['detail'],
                'redacted' => true,
            ];
        }

        return array_filter($discrepancy, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * What the report is about, for labels and event routing only: the
     * caller's expectation first, else what the evidence says about itself.
     *
     * @return array<string, string>
     */
    private function target(array $expectation, ?EvidenceContract $evidence, ?RuntimeEvidenceContract $snapshot): array
    {
        $target = [
            'project_key' => $expectation['project_key'] ?? $evidence?->get('project_key') ?? $snapshot?->get('project_key'),
            'environment' => $expectation['environment'] ?? $evidence?->get('environment') ?? $snapshot?->get('environment'),
            'release_id' => $expectation['release_id'] ?? $evidence?->get('release_id') ?? $snapshot?->get('release_id'),
            'commit_sha' => $expectation['commit_sha'] ?? $evidence?->get('expected_commit_sha') ?? $snapshot?->get('release_manifest_commit_sha'),
            'deployment_id' => $expectation['deployment_id'] ?? $snapshot?->get('deployment_id'),
        ];

        return array_filter($target, static fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    /** @return array{bytes: string, meta: array{path: string, sha256: string, bytes: int}} */
    private function read(AgentContext $context, string $key, string $value): array
    {
        $path = $this->resolve($context, $key, $value);
        if (! is_file($path) || ! is_readable($path)) {
            throw new ProtocolException('INPUT_UNREADABLE', "payload.{$key} is not a readable file: {$value}");
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'json') {
            throw new ProtocolException('INVALID_PAYLOAD', "payload.{$key} must name a .json file.");
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_INPUT_BYTES) {
            throw new ProtocolException('INPUT_TOO_LARGE', "payload.{$key} exceeds ".self::MAX_INPUT_BYTES.' bytes.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new ProtocolException('INPUT_UNREADABLE', "payload.{$key} could not be read: {$value}");
        }

        return ['bytes' => $bytes, 'meta' => ['path' => $this->display($context, $path), 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)]];
    }

    /** Relative paths resolve against the workspace root, like every path in workspace.json. */
    private function resolve(AgentContext $context, string $key, string $value): string
    {
        if (trim($value) === '' || str_contains($value, "\0")) {
            throw new ProtocolException('INVALID_PAYLOAD', "payload.{$key} must be a path.");
        }

        return Paths::join($context->workspaceRoot, $value);
    }

    private function display(AgentContext $context, string $path): string
    {
        return Paths::relative($context->workspaceRoot, $path) ?? Paths::normalize($path);
    }
}
