<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\SentinelVerifier;

use DateTimeImmutable;
use Throwable;

/**
 * Health of a Server Agent runtime snapshot (sentinel.runtime.v1).
 *
 * The Hub's AgentRuntimeEvidenceReader silently drops a snapshot that is
 * incomplete, stale or attributed elsewhere. This inspector applies the same
 * rules as named checks, so an audit says *why* runtime evidence is not
 * usable, and adds the deployment-marker coherence the Server Agent's
 * activation guarantees:
 *
 *   .deployment/runtime-marker = release:{deployment_id}|commit:{commit_sha}
 *
 * whose commit must be the release manifest's commit and whose release part
 * must name the deployment the `current` link resolves to.
 */
final class RuntimeSnapshotInspector
{
    /** Clock skew tolerated before a snapshot counts as coming from the future (the Hub collector's bound). */
    public const FUTURE_SKEW_SECONDS = 60;

    public const MARKER_PATTERN = '/^release:([A-Za-z0-9_-]+)\|commit:([0-9a-f]{40})$/D';

    public function __construct(
        private readonly int $maxAgeSeconds,
        private readonly ?string $minAgentVersion = null,
        private readonly bool $fileVerification = false,
    ) {
    }

    /**
     * @param  array<string, string>  $expectation  EvidenceVerifier::EXPECTATION_KEYS subset
     * @return array<string, array{status: string, detail: string}>
     */
    public function inspect(RuntimeEvidenceContract $snapshot, array $expectation, DateTimeImmutable $reference): array
    {
        $checks = [
            'snapshot_version' => $snapshot->isSupportedVersion()
                ? Checks::pass('snapshot declares '.RuntimeEvidenceContract::VERSION.'.')
                : Checks::unknown("unsupported snapshot version '".($snapshot->version() ?? 'none')."'."),
            'snapshot_complete' => $this->complete($snapshot),
        ];
        if ($this->minAgentVersion !== null) {
            $checks['agent_version'] = $this->agentVersion($snapshot);
        }
        $checks['freshness'] = $this->freshness($snapshot, $reference);
        $checks['release_path'] = $this->releasePath($snapshot, $expectation);
        $checks['deployment_marker'] = $this->marker($snapshot, $expectation);
        $checks['attribution'] = $this->attribution($snapshot, $expectation);
        if ($this->fileVerification) {
            $checks['file_observation'] = $this->fileObservation($snapshot);
        }

        return $checks;
    }

    /**
     * The Hub's evidence document is assembled from this same snapshot; two
     * records of one runtime observation that disagree mean one is not it.
     *
     * @return array{status: string, detail: string}
     */
    public static function agreement(EvidenceContract $evidence, RuntimeEvidenceContract $snapshot): array
    {
        $pairs = [
            'agent_id' => 'agent_id',
            'agent_version' => 'agent_version',
            'observed_release_id' => 'release_id',
            'observed_commit_sha' => 'release_manifest_commit_sha',
            'runtime_marker' => 'runtime_marker',
            'runtime_release_path' => 'current_release_path',
            'observed_file_manifest_hash' => 'file_manifest_hash',
            'project_key' => 'project_key',
            'environment' => 'environment',
        ];
        $compared = [];
        $mismatched = [];
        foreach ($pairs as $evidenceField => $snapshotField) {
            $left = $evidence->get($evidenceField);
            $right = $snapshot->get($snapshotField);
            if (! is_scalar($left) || ! is_scalar($right) || (string) $left === '' || (string) $right === '') {
                continue;
            }
            $compared[] = $evidenceField;
            if (! self::same((string) $left, (string) $right, str_contains($evidenceField, 'commit') || str_contains($evidenceField, 'hash'))) {
                $mismatched[] = "{$evidenceField} ≠ snapshot {$snapshotField}";
            }
        }
        if ($mismatched !== []) {
            return Checks::fail('evidence document and runtime snapshot disagree: '.implode('; ', $mismatched).'.');
        }

        return $compared === []
            ? Checks::unknown('evidence document and runtime snapshot share no comparable field.')
            : Checks::pass('evidence document and runtime snapshot agree on '.implode(', ', $compared).'.');
    }

    /** @return array{status: string, detail: string} */
    private function complete(RuntimeEvidenceContract $snapshot): array
    {
        $missing = array_keys($snapshot->missingRequiredFields());
        if ($missing !== []) {
            return Checks::unknown('incomplete snapshot — missing: '.implode(', ', $missing).'.');
        }

        return $snapshot->isComplete()
            ? Checks::pass('snapshot is complete.')
            : Checks::unknown('the agent reported snapshot_status=incomplete (no current release, path escape, or unreadable marker/manifest).');
    }

    /** @return array{status: string, detail: string} */
    private function agentVersion(RuntimeEvidenceContract $snapshot): array
    {
        $version = $snapshot->get('agent_version');
        if (! is_string($version) || preg_match('/^\d+(\.\d+)*([-+][0-9A-Za-z.-]+)?$/D', $version) !== 1) {
            return Checks::unknown('agent_version is missing or not a version number.');
        }

        return version_compare($version, (string) $this->minAgentVersion, '>=')
            ? Checks::pass("Server Agent {$version} meets the minimum {$this->minAgentVersion}.")
            : Checks::fail("Server Agent {$version} is older than the required {$this->minAgentVersion}.");
    }

    /** @return array{status: string, detail: string} */
    private function freshness(RuntimeEvidenceContract $snapshot, DateTimeImmutable $reference): array
    {
        $timestamp = $snapshot->get('timestamp');
        if (! is_string($timestamp) || $timestamp === '') {
            return Checks::unknown('snapshot timestamp missing.');
        }
        try {
            $observedAt = new DateTimeImmutable($timestamp);
        } catch (Throwable) {
            return Checks::unknown('snapshot timestamp unparseable.');
        }
        $age = $reference->getTimestamp() - $observedAt->getTimestamp();
        if ($age < -self::FUTURE_SKEW_SECONDS) {
            return Checks::unknown('snapshot timestamp lies in the future.');
        }
        if ($age > $this->maxAgeSeconds) {
            return Checks::unknown("snapshot is stale ({$age}s old, max {$this->maxAgeSeconds}s).");
        }

        return Checks::pass("snapshot is fresh ({$age}s old).");
    }

    /** @return array{status: string, detail: string} */
    private function releasePath(RuntimeEvidenceContract $snapshot, array $expectation): array
    {
        $path = $snapshot->get('current_release_path');
        $deploymentId = $snapshot->get('deployment_id');
        if (! is_string($path) || ! is_string($deploymentId)) {
            return Checks::unknown('current release path or deployment id was not reported.');
        }
        if (! EvidenceVerifier::isExpectedReleasePath($path, $deploymentId)) {
            return Checks::fail('current release path is not exactly releases/{deployment_id} of the reported deployment.');
        }
        $wanted = $expectation['deployment_id'] ?? null;
        if ($wanted !== null && ! hash_equals($wanted, $deploymentId)) {
            return Checks::fail("current resolves to deployment {$deploymentId}, expected {$wanted}.");
        }

        return Checks::pass("current resolves to releases/{$deploymentId}.");
    }

    /** @return array{status: string, detail: string} */
    private function marker(RuntimeEvidenceContract $snapshot, array $expectation): array
    {
        $marker = $snapshot->get('runtime_marker');
        if (! is_string($marker)) {
            return Checks::unknown('runtime marker was not reported (the agent could not read .deployment/runtime-marker).');
        }
        if (preg_match(self::MARKER_PATTERN, $marker, $parts) !== 1) {
            return Checks::fail('runtime marker is not release:{deployment_id}|commit:{sha}.');
        }
        [, $release, $commit] = $parts;
        $problems = [];
        $deploymentId = $snapshot->get('deployment_id');
        if (is_string($deploymentId) && ! hash_equals($deploymentId, $release)) {
            $problems[] = "marker names deployment {$release} but the snapshot reports {$deploymentId}";
        }
        $manifestCommit = $snapshot->get('release_manifest_commit_sha');
        if (is_string($manifestCommit) && ! hash_equals(strtolower($manifestCommit), $commit)) {
            $problems[] = "marker commit {$commit} differs from the release manifest commit {$manifestCommit}";
        }
        if (isset($expectation['commit_sha']) && ! hash_equals(strtolower($expectation['commit_sha']), $commit)) {
            $problems[] = "marker commit {$commit} is not the expected commit {$expectation['commit_sha']}";
        }
        if ($problems !== []) {
            return Checks::fail(implode('; ', $problems).'.');
        }
        if (! is_string($manifestCommit)) {
            return Checks::unknown('runtime marker is well-formed but the release manifest commit was not reported.');
        }

        return Checks::pass("deployment marker binds deployment {$release} to commit {$commit}.");
    }

    /**
     * AgentRuntimeEvidenceReader::attributionMatches(), but as a verdict:
     * a snapshot of another project, environment, release, deployment or
     * agent is wrong-target evidence and fails.
     *
     * @return array{status: string, detail: string}
     */
    private function attribution(RuntimeEvidenceContract $snapshot, array $expectation): array
    {
        $pairs = [
            'project_key' => 'project_key',
            'environment' => 'environment',
            'release_id' => 'release_id',
            'deployment_id' => 'deployment_id',
            'agent_id' => 'agent_id',
            'commit_sha' => 'release_manifest_commit_sha',
            'artifact_checksum_sha256' => 'artifact_checksum_sha256',
        ];
        $mismatched = [];
        foreach ($pairs as $expected => $field) {
            if (! isset($expectation[$expected])) {
                continue;
            }
            $have = $snapshot->get($field);
            // The Server Agent snapshot does not carry the artifact checksum; absent is not a mismatch.
            if ($have === null && $field === 'artifact_checksum_sha256') {
                continue;
            }
            if (! is_string($have) || ! self::same($expectation[$expected], $have, in_array($expected, ['commit_sha', 'artifact_checksum_sha256'], true))) {
                $mismatched[] = $expected;
            }
        }
        if ($mismatched !== []) {
            return Checks::fail('snapshot is attributed to a different '.implode(', ', $mismatched).' than expected.');
        }
        if (! isset($expectation['release_id'])) {
            return Checks::unknown('no expected release supplied — the snapshot cannot be attributed to any release.');
        }

        return Checks::pass('snapshot is attributed to the expected '.implode(', ', array_keys(array_intersect_key($pairs, $expectation))).'.');
    }

    /** Identifiers compare exactly (as on the Hub); hex digests compare case-insensitively. */
    private static function same(string $left, string $right, bool $digest): bool
    {
        return $digest ? hash_equals(strtolower($left), strtolower($right)) : hash_equals($left, $right);
    }

    /** @return array{status: string, detail: string} */
    private function fileObservation(RuntimeEvidenceContract $snapshot): array
    {
        $status = $snapshot->get('file_observation_status') ?? 'unknown';

        return match ($status) {
            'verified' => Checks::pass('the agent re-hashed every manifest entry on disk and all matched.'),
            'mismatch' => Checks::fail('the agent found files on disk that differ from the release file manifest.'),
            default => Checks::unknown("file observation status: {$status}."),
        };
    }
}
