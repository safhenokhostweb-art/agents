<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\SentinelVerifier;

use InvalidArgumentException;

/**
 * The fail-closed comparator of the Deployment Proof chain. Compares the
 * EXPECTED values against the OBSERVED values of a sentinel.v1 document
 * and returns exactly one verdict: VERIFIED, FAILED or UNKNOWN.
 *
 * Port of deployment-system's App\Services\Sentinel\SentinelVerifier with
 * identical checks, details and aggregation (tests/Integration/SentinelParityTest).
 * The one deliberate difference: file-level verification is a constructor
 * flag instead of the Laravel `sentinel.file_verification_enabled` config;
 * the default, disabled, is the same.
 */
final class EvidenceVerifier
{
    /** The only expectation keys a verification may be asked about. */
    public const EXPECTATION_KEYS = ['project_key', 'environment', 'release_id', 'commit_sha', 'artifact_checksum_sha256', 'agent_id', 'deployment_id'];

    public function __construct(private readonly bool $fileVerification = false)
    {
    }

    /**
     * @param  array<string, mixed>  $expectation  must contain release_id for a VERIFIED verdict
     * @return array{result: string, checks: array<string, array{status: string, detail: string}>, evidence_hash: string}
     */
    public function verify(EvidenceContract $evidence, array $expectation = []): array
    {
        foreach (array_keys($expectation) as $key) {
            if (! is_string($key) || ! in_array($key, self::EXPECTATION_KEYS, true)) {
                throw new InvalidArgumentException(sprintf("sentinel expectation field '%s' is not part of the verification schema.", is_string($key) ? $key : gettype($key)));
            }
        }

        $checks = [
            'evidence_version' => $this->checkVersion($evidence),
            'contract_completeness' => $this->checkCompleteness($evidence),
            'commit_match' => $this->checkCommit($evidence, $expectation),
            'artifact_checksum_match' => $this->checkArtifactChecksum($evidence, $expectation),
            'release_identity' => $this->checkReleaseIdentity($evidence, $expectation),
            'agent_identity' => $this->checkAgentIdentity($evidence, $expectation),
            'runtime_release_path' => $this->checkRuntimeReleasePath($evidence, $expectation),
            'runtime_marker_match' => $this->checkRuntimeMarker($evidence),
            'health_response' => $this->checkHealth($evidence),
            'recorded_checks' => $this->checkRecordedChecks($evidence),
            'verified_at' => $this->checkVerifiedAt($evidence),
            'expectation_alignment' => $this->checkExpectationAlignment($evidence, $expectation),
        ];
        if ($this->fileVerification) {
            $checks['file_manifest_hash_match'] = $this->checkFileManifestHash($evidence);
            $checks['file_manifest_entry_count_match'] = $this->checkFileManifestCount($evidence, 'entry_count', 'entry count');
            $checks['file_manifest_total_size_match'] = $this->checkFileManifestCount($evidence, 'total_size', 'total size');
            $checks['file_comparison'] = $this->checkFileComparison($evidence);
        }

        return ['result' => $this->aggregate($checks), 'checks' => $checks, 'evidence_hash' => $evidence->hash()];
    }

    /** The active `current` path must equal releases/{deploymentId} exactly. */
    public static function isExpectedReleasePath(mixed $path, mixed $deploymentId): bool
    {
        if (! is_string($deploymentId)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/D', $deploymentId) !== 1
            || ! is_string($path)) {
            return false;
        }

        return hash_equals('releases/'.$deploymentId, $path);
    }

    /**
     * Fail-closed aggregation, with a final guard: VERIFIED is downgraded to
     * UNKNOWN unless the version and completeness checks explicitly passed.
     *
     * @param  array<string, array{status: string, detail: string}>  $checks
     */
    private function aggregate(array $checks): string
    {
        $verdict = Checks::aggregate($checks);
        if ($verdict !== Checks::VERIFIED) {
            return $verdict;
        }

        return ($checks['evidence_version']['status'] ?? null) === Checks::PASS && ($checks['contract_completeness']['status'] ?? null) === Checks::PASS
            ? Checks::VERIFIED
            : Checks::INDETERMINATE;
    }

    /** @return array{status: string, detail: string} */
    private function checkVersion(EvidenceContract $evidence): array
    {
        $version = $evidence->version();
        if ($version === null) {
            return Checks::unknown('evidence_version is missing.');
        }
        if ($version !== EvidenceContract::VERSION) {
            return Checks::unknown("unsupported evidence version '{$version}' — only ".EvidenceContract::VERSION.' can be judged.');
        }

        return Checks::pass('evidence declares '.EvidenceContract::VERSION.'.');
    }

    /** @return array{status: string, detail: string} */
    private function checkCompleteness(EvidenceContract $evidence): array
    {
        $missing = array_keys($evidence->missingRequiredFields());

        return $missing === []
            ? Checks::pass('all required evidence fields are present.')
            : Checks::unknown('incomplete evidence — missing: '.implode(', ', $missing).'.');
    }

    /** @return array{status: string, detail: string} */
    private function checkCommit(EvidenceContract $evidence, array $expectation): array
    {
        $expected = $evidence->get('expected_commit_sha');
        $observed = $evidence->get('observed_commit_sha');
        if (! $this->isSha($expected) || ! $this->isSha($observed)) {
            return Checks::unknown('expected or observed commit SHA is missing or malformed.');
        }
        $expected = strtolower((string) $expected);
        $observed = strtolower((string) $observed);
        if (! hash_equals($expected, $observed)) {
            return Checks::fail("observed commit {$observed} does not match expected commit {$expected}.");
        }

        $wanted = $expectation['commit_sha'] ?? null;
        if ($wanted !== null) {
            if (! $this->isSha($wanted)) {
                return Checks::unknown('expected commit SHA supplied for verification is malformed.');
            }
            if (! hash_equals($expected, strtolower((string) $wanted))) {
                return Checks::fail("stale evidence — built for commit {$expected} but verification asked about {$wanted}.");
            }
        }

        return Checks::pass("commit chain matches at {$observed}.");
    }

    /** @return array{status: string, detail: string} */
    private function checkArtifactChecksum(EvidenceContract $evidence, array $expectation): array
    {
        $expected = $evidence->get('artifact_checksum_sha256');
        $observed = $evidence->get('observed_artifact_checksum_sha256');
        if (! $this->isSha256($expected) || ! $this->isSha256($observed)) {
            return Checks::unknown('expected or observed artifact checksum is missing or malformed.');
        }
        $expected = strtolower((string) $expected);
        $observed = strtolower((string) $observed);
        if (! hash_equals($expected, $observed)) {
            return Checks::fail("observed artifact checksum {$observed} does not match expected {$expected}.");
        }

        $wanted = $expectation['artifact_checksum_sha256'] ?? null;
        if ($wanted !== null) {
            if (! $this->isSha256($wanted)) {
                return Checks::unknown('expected artifact checksum supplied for verification is malformed.');
            }
            if (! hash_equals($expected, strtolower((string) $wanted))) {
                return Checks::fail("stale evidence — built for artifact checksum {$expected} but verification asked about {$wanted}.");
            }
        }

        return Checks::pass('artifact checksum matches.');
    }

    /** @return array{status: string, detail: string} */
    private function checkReleaseIdentity(EvidenceContract $evidence, array $expectation): array
    {
        $release = $evidence->get('release_id');
        $observed = $evidence->get('observed_release_id');
        if (! is_string($release) || $release === '' || ! is_string($observed) || $observed === '') {
            return Checks::unknown('release identity is unknown — release_id or observed_release_id missing.');
        }
        if (! hash_equals($release, $observed)) {
            return Checks::fail("active runtime reports release {$observed} but evidence is about release {$release}.");
        }

        $wanted = $expectation['release_id'] ?? null;
        if (! is_string($wanted) || $wanted === '') {
            return Checks::unknown('no expected release supplied — evidence cannot be attributed to any release.');
        }
        if (! hash_equals($release, $wanted)) {
            return Checks::fail("stale evidence — built for release {$release} but verification asked about {$wanted}.");
        }

        return Checks::pass("release identity matches at {$release}.");
    }

    /** @return array{status: string, detail: string} */
    private function checkAgentIdentity(EvidenceContract $evidence, array $expectation): array
    {
        $agent = $evidence->get('agent_id');
        $version = $evidence->get('agent_version');
        if (! is_string($agent) || $agent === '' || ! is_string($version) || $version === '') {
            return Checks::unknown('agent identity is unknown — agent_id or agent_version missing.');
        }

        $wanted = $expectation['agent_id'] ?? null;
        if ($wanted !== null && (! is_string($wanted) || ! hash_equals($agent, $wanted))) {
            return Checks::fail("evidence came from agent {$agent} but verification expected agent {$wanted}.");
        }

        return Checks::pass("agent {$agent} at version {$version}.");
    }

    /** @return array{status: string, detail: string} */
    private function checkRuntimeReleasePath(EvidenceContract $evidence, array $expectation): array
    {
        $path = $evidence->get('runtime_release_path');
        $deploymentId = $expectation['deployment_id'] ?? null;
        if (! is_string($path) || $path === '' || ! is_string($deploymentId) || $deploymentId === '') {
            return Checks::unknown('active runtime release path or expected deployment id is unknown.');
        }

        return self::isExpectedReleasePath($path, $deploymentId)
            ? Checks::pass('active runtime reports the exact expected relative release path.')
            : Checks::fail('active runtime release path is not the exact expected relative path.');
    }

    /** @return array{status: string, detail: string} */
    private function checkRuntimeMarker(EvidenceContract $evidence): array
    {
        $expected = $evidence->get('expected_runtime_marker');
        $observed = $evidence->get('runtime_marker');
        if (! is_string($expected) || $expected === '' || ! is_string($observed) || $observed === '') {
            return Checks::unknown('runtime marker is unknown — expected or observed marker missing.');
        }

        return hash_equals($expected, $observed)
            ? Checks::pass('runtime marker matches.')
            : Checks::fail("active runtime marker '{$observed}' does not match expected '{$expected}'.");
    }

    /** @return array{status: string, detail: string} */
    private function checkHealth(EvidenceContract $evidence): array
    {
        $endpoint = $evidence->get('health_endpoint');
        $observed = $evidence->get('health_status');
        $expected = $evidence->get('expected_health_status');
        $expected = is_int($expected) ? $expected : EvidenceContract::DEFAULT_EXPECTED_HEALTH_STATUS;
        if (! is_string($endpoint) || $endpoint === '') {
            return Checks::unknown('health endpoint is unknown.');
        }
        if (! is_int($observed)) {
            return Checks::unknown('health status was never observed.');
        }

        return $observed === $expected
            ? Checks::pass("health endpoint {$endpoint} returned HTTP {$observed}.")
            : Checks::fail("health endpoint {$endpoint} returned HTTP {$observed}, expected {$expected}.");
    }

    /** @return array{status: string, detail: string} */
    private function checkRecordedChecks(EvidenceContract $evidence): array
    {
        $checks = $evidence->get('checks');
        if (! is_array($checks) || $checks === []) {
            return Checks::unknown('no recorded checks in evidence.');
        }
        $failDetail = null;
        $unknownDetail = null;
        foreach ($checks as $id => $check) {
            $status = is_array($check) ? ($check['status'] ?? null) : null;
            if ($status === Checks::FAIL) {
                $failDetail ??= "recorded check '{$id}' failed.";
            } elseif ($status !== Checks::PASS) {
                $unknownDetail ??= "recorded check '{$id}' is not a pass.";
            }
        }
        if ($failDetail !== null) {
            return Checks::fail($failDetail);
        }
        if ($unknownDetail !== null) {
            return Checks::unknown($unknownDetail);
        }

        return Checks::pass(count($checks).' recorded checks all passed.');
    }

    /** @return array{status: string, detail: string} */
    private function checkVerifiedAt(EvidenceContract $evidence): array
    {
        return $evidence->hasParseableVerifiedAt()
            ? Checks::pass('evidence carries a parseable verified_at timestamp.')
            : Checks::unknown('verified_at is missing or unparseable.');
    }

    /** @return array{status: string, detail: string} */
    private function checkExpectationAlignment(EvidenceContract $evidence, array $expectation): array
    {
        $problems = [];
        $known = true;
        foreach (['project_key', 'environment'] as $key) {
            if (! array_key_exists($key, $expectation)) {
                continue;
            }
            $wanted = $expectation[$key];
            $have = $evidence->get($key);
            if (! is_string($wanted) || $wanted === '') {
                return Checks::unknown("expectation {$key} is missing.");
            }
            if (! is_string($have) || $have === '') {
                $known = false;
                continue;
            }
            if (! hash_equals($have, $wanted)) {
                $problems[] = "evidence {$key} '{$have}' does not match expected '{$wanted}'";
            }
        }
        if ($problems !== []) {
            return Checks::fail(implode('; ', $problems).'.');
        }

        return $known
            ? Checks::pass('evidence context aligns with the expectation.')
            : Checks::unknown('evidence project/environment context is missing.');
    }

    /** @return array{status: string, detail: string} */
    private function checkFileManifestHash(EvidenceContract $evidence): array
    {
        $expected = $evidence->get('expected_file_manifest_hash');
        $observed = $evidence->get('observed_file_manifest_hash');
        if (! is_string($expected) || $expected === '' || ! is_string($observed) || $observed === '') {
            return Checks::unknown('expected or observed file manifest hash is missing.');
        }
        $expected = strtolower($expected);
        $observed = strtolower($observed);

        return hash_equals($expected, $observed)
            ? Checks::pass('file manifest hash matches.')
            : Checks::fail("observed file manifest hash {$observed} does not match expected {$expected}.");
    }

    /** @return array{status: string, detail: string} */
    private function checkFileManifestCount(EvidenceContract $evidence, string $field, string $label): array
    {
        $expected = $evidence->get("expected_file_manifest_{$field}");
        $observed = $evidence->get("observed_file_manifest_{$field}");
        if (! is_int($expected) || ! is_int($observed)) {
            return Checks::unknown("expected or observed file manifest {$label} is missing.");
        }

        return $expected === $observed
            ? Checks::pass("file manifest {$label} matches.")
            : Checks::fail("observed file manifest {$label} {$observed} does not match expected {$expected}.");
    }

    /**
     * A file_comparison check recorded by the collector is authoritative;
     * otherwise the comparison is recomputed from manifest metadata alone.
     *
     * @return array{status: string, detail: string}
     */
    private function checkFileComparison(EvidenceContract $evidence): array
    {
        $recorded = $evidence->get('checks');
        if (is_array($recorded) && is_array($recorded['file_comparison'] ?? null)) {
            $recordedDetail = $recorded['file_comparison']['detail'] ?? null;
            $detail = is_string($recordedDetail) && $recordedDetail !== '' ? $recordedDetail : 'recorded file comparison check.';

            return match ($recorded['file_comparison']['status'] ?? null) {
                Checks::PASS => Checks::pass($detail),
                Checks::FAIL => Checks::fail($detail),
                default => Checks::unknown($detail),
            };
        }

        $scope = $evidence->get('file_comparison_scope') ?? 'full';
        $expectedHash = $evidence->get('expected_file_manifest_hash');
        $observedHash = $evidence->get('observed_file_manifest_hash');
        $expectedCount = $evidence->get('expected_file_manifest_entry_count');
        $observedCount = $evidence->get('observed_file_manifest_entry_count');
        $expectedSize = $evidence->get('expected_file_manifest_total_size');
        $observedSize = $evidence->get('observed_file_manifest_total_size');
        if (! is_string($expectedHash) || $expectedHash === '' || ! is_string($observedHash) || $observedHash === ''
            || ! is_int($expectedCount) || ! is_int($observedCount) || ! is_int($expectedSize) || ! is_int($observedSize)) {
            return Checks::unknown('file comparison requires complete manifest metadata on both sides.');
        }

        $verdict = (new FileManifestComparison([], [], $expectedHash, $observedHash, $expectedCount, $observedCount, $expectedSize, $observedSize, $scope))->verdict();

        return match ($verdict) {
            'PASS' => Checks::pass("file comparison scope '{$scope}': all files match (manifest hash verified)."),
            'FAIL' => Checks::fail("file comparison scope '{$scope}': manifest hash mismatch detected."),
            default => Checks::unknown("file comparison scope '{$scope}': {$verdict}."),
        };
    }

    private function isSha(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/i', $value) === 1;
    }

    private function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/i', $value) === 1;
    }
}
