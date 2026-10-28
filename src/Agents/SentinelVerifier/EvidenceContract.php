<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\SentinelVerifier;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * sentinel.v1 — the immutable Deployment Proof evidence document:
 *
 *   Local Source -> Commit SHA -> Artifact -> Hub Release -> Agent ->
 *   Server Release -> Active Runtime -> Public Health
 *
 * Port of deployment-system's App\Services\Sentinel\SentinelEvidenceContract
 * (same whitelist, normalization, secret rules and canonical form, so
 * hash() equals the Hub's evidence hash for the same document). Parity is
 * enforced by tests/Integration/SentinelParityTest.
 *
 * Fail-closed at construction: unknown fields, malformed types and
 * secret-shaped values are rejected; health_endpoint loses userinfo, query
 * and fragment; the document is recursively key-sorted. `result` is
 * advisory only — EvidenceVerifier always recomputes the verdict.
 */
final class EvidenceContract
{
    public const VERSION = 'sentinel.v1';

    public const RESULT_VALUES = [Checks::VERIFIED, Checks::FAILED, Checks::INDETERMINATE];

    public const ALLOWED_FIELDS = [
        'evidence_version',
        'project_key',
        'environment',
        'expected_commit_sha',
        'observed_commit_sha',
        'artifact_id',
        'artifact_checksum_sha256',
        'observed_artifact_checksum_sha256',
        'release_id',
        'observed_release_id',
        'agent_id',
        'agent_version',
        'runtime_release_path',
        'expected_runtime_marker',
        'runtime_marker',
        'health_endpoint',
        'expected_health_status',
        'health_status',
        'checks',
        'verified_at',
        'result',
        // File-level verification (additive, feature-flagged)
        'expected_file_manifest_hash',
        'observed_file_manifest_hash',
        'expected_file_manifest_entry_count',
        'observed_file_manifest_entry_count',
        'expected_file_manifest_total_size',
        'observed_file_manifest_total_size',
        'file_comparison_scope',
    ];

    /** Missing any of these yields UNKNOWN, never VERIFIED. File-level fields are opt-in, so not listed. */
    public const REQUIRED_FIELDS = [
        'evidence_version',
        'project_key',
        'environment',
        'expected_commit_sha',
        'observed_commit_sha',
        'artifact_id',
        'artifact_checksum_sha256',
        'observed_artifact_checksum_sha256',
        'release_id',
        'observed_release_id',
        'agent_id',
        'agent_version',
        'runtime_release_path',
        'expected_runtime_marker',
        'runtime_marker',
        'health_endpoint',
        'health_status',
        'verified_at',
        'checks',
    ];

    public const DEFAULT_EXPECTED_HEALTH_STATUS = 200;

    public const ALLOWED_CHECK_KEYS = ['status', 'detail'];

    /** @param array<string, mixed> $evidence already canonicalized */
    private function __construct(private readonly array $evidence)
    {
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidArgumentException when the input cannot be represented as sentinel.v1
     */
    public static function fromArray(array $input): self
    {
        foreach (array_keys($input) as $key) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_FIELDS, true)) {
                throw new InvalidArgumentException(sprintf(
                    "sentinel.v1 evidence field '%s' is not part of the contract schema.",
                    is_string($key) ? $key : gettype($key),
                ));
            }
            EvidenceGuard::assertKeyAllowed($key, self::VERSION);
        }

        $evidence = [];
        foreach (self::ALLOWED_FIELDS as $field) {
            $value = $input[$field] ?? null;
            if ($value === null) {
                $evidence[$field] = null;
                continue;
            }

            switch ($field) {
                case 'health_status':
                case 'expected_health_status':
                    if (! is_int($value)) {
                        throw new InvalidArgumentException("sentinel.v1 evidence field '{$field}' must be an integer HTTP status.");
                    }
                    $evidence[$field] = $value;
                    break;

                case 'checks':
                    if (! is_array($value)) {
                        throw new InvalidArgumentException("sentinel.v1 evidence field 'checks' must be an object map.");
                    }
                    $evidence[$field] = self::normalizeChecks($value);
                    break;

                case 'result':
                    if (! is_string($value) || ! in_array($value, self::RESULT_VALUES, true)) {
                        throw new InvalidArgumentException("sentinel.v1 evidence field 'result' must be VERIFIED, FAILED, UNKNOWN or null.");
                    }
                    $evidence[$field] = $value;
                    break;

                case 'expected_file_manifest_hash':
                case 'observed_file_manifest_hash':
                    if (! is_string($value) || ! preg_match('/^[0-9a-f]{64}$/i', $value)) {
                        throw new InvalidArgumentException("sentinel.v1 evidence field '{$field}' must be a 64-character hex SHA-256.");
                    }
                    $evidence[$field] = strtolower($value);
                    break;

                case 'expected_file_manifest_entry_count':
                case 'observed_file_manifest_entry_count':
                case 'expected_file_manifest_total_size':
                case 'observed_file_manifest_total_size':
                    if (! is_int($value) || $value < 0) {
                        throw new InvalidArgumentException("sentinel.v1 evidence field '{$field}' must be a non-negative integer.");
                    }
                    $evidence[$field] = $value;
                    break;

                case 'file_comparison_scope':
                    if (! is_string($value)) {
                        throw new InvalidArgumentException("sentinel.v1 evidence field '{$field}' must be a string.");
                    }
                    $value = trim($value);
                    if ($value !== '') {
                        EvidenceGuard::assertNoSecret($value, $field, self::VERSION);
                    }
                    $evidence[$field] = $value === '' ? null : $value;
                    break;

                default:
                    if (! is_string($value) && ! is_int($value)) {
                        throw new InvalidArgumentException("sentinel.v1 evidence field '{$field}' must be a string.");
                    }
                    $value = is_string($value) ? trim($value) : $value;
                    if ($value === '') {
                        $evidence[$field] = null;
                        break;
                    }
                    $value = (string) $value;
                    if ($field === 'health_endpoint') {
                        // Strip credentials before the secret scan so a
                        // credentialed URL degrades to a clean endpoint.
                        $value = self::sanitizeEndpoint($value);
                    }
                    EvidenceGuard::assertNoSecret($value, $field, self::VERSION);
                    $evidence[$field] = $value;
                    break;
            }
        }

        // Never guess an expected health status: 200 unless the producer said otherwise.
        $evidence['expected_health_status'] ??= self::DEFAULT_EXPECTED_HEALTH_STATUS;

        return new self(EvidenceGuard::canonicalize($evidence));
    }

    /** @throws InvalidArgumentException */
    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('sentinel.v1 evidence is not valid JSON: '.$e->getMessage(), 0, $e);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('sentinel.v1 evidence must be a JSON object.');
        }

        return self::fromArray($decoded);
    }

    public function version(): ?string
    {
        $version = $this->evidence['evidence_version'];

        return is_string($version) ? $version : null;
    }

    public function get(string $field): mixed
    {
        if (! in_array($field, self::ALLOWED_FIELDS, true)) {
            throw new InvalidArgumentException("sentinel.v1 evidence field '{$field}' is not part of the contract schema.");
        }

        return $this->evidence[$field] ?? null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->evidence;
    }

    public function toJson(): string
    {
        return json_encode($this->evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** SHA-256 of the canonical JSON: the Hub's evidence hash for the same document. */
    public function hash(): string
    {
        return hash('sha256', $this->toJson());
    }

    /** @return array<string, int> missing field => 1 */
    public function missingRequiredFields(): array
    {
        $missing = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            $value = $this->evidence[$field] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $missing[$field] = 1;
            }
        }

        return $missing;
    }

    public function hasParseableVerifiedAt(): bool
    {
        return $this->verifiedAt() !== null;
    }

    public function verifiedAt(): ?DateTimeImmutable
    {
        $value = $this->evidence['verified_at'] ?? null;
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<mixed, mixed>  $checks
     * @return array<string, array<string, mixed>>
     */
    private static function normalizeChecks(array $checks): array
    {
        $normalized = [];
        foreach ($checks as $id => $check) {
            if (! is_string($id) || $id === '') {
                throw new InvalidArgumentException('sentinel.v1 evidence check ids must be non-empty strings.');
            }
            EvidenceGuard::assertKeyAllowed($id, self::VERSION);
            if (! is_array($check)) {
                throw new InvalidArgumentException("sentinel.v1 evidence check '{$id}' must be an object.");
            }
            foreach (array_keys($check) as $key) {
                if (! is_string($key) || ! in_array($key, self::ALLOWED_CHECK_KEYS, true)) {
                    throw new InvalidArgumentException(sprintf(
                        "sentinel.v1 evidence check '%s' has an unsupported key '%s'.",
                        $id,
                        is_string($key) ? $key : gettype($key),
                    ));
                }
            }
            $status = $check['status'] ?? null;
            if (! is_string($status) || ! in_array($status, [Checks::PASS, Checks::FAIL, Checks::UNKNOWN], true)) {
                throw new InvalidArgumentException("sentinel.v1 evidence check '{$id}' status must be pass, fail or unknown.");
            }
            $detail = $check['detail'] ?? null;
            if ($detail !== null) {
                if (! is_string($detail)) {
                    throw new InvalidArgumentException("sentinel.v1 evidence check '{$id}' detail must be a string.");
                }
                EvidenceGuard::assertNoSecret($detail, "checks.{$id}.detail", self::VERSION);
            }
            $normalized[$id] = $detail === null ? ['status' => $status] : ['status' => $status, 'detail' => $detail];
        }

        return $normalized;
    }

    /** Only scheme://host[:port]/path enters evidence. */
    private static function sanitizeEndpoint(string $url): string
    {
        $clean = preg_replace('/#.*$/', '', $url) ?? $url;
        $clean = preg_replace('/\?.*$/', '', $clean) ?? $clean;

        return preg_replace('#(//)[^/@]*@#', '$1', $clean) ?? $clean;
    }
}
