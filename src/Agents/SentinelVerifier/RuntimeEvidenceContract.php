<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\SentinelVerifier;

use InvalidArgumentException;
use JsonException;

/**
 * sentinel.runtime.v1 — the runtime snapshot a Server Agent sends in its
 * signed heartbeat (agent/lib/ServerAgentRuntimeSnapshotCollector.php).
 *
 * Port of deployment-system's App\Services\Sentinel\SentinelRuntimeEvidenceContract:
 * same whitelist, secret rules, path-containment rule and canonical form,
 * so hash() matches the Hub's for the same snapshot. When the producer does
 * not state snapshot_status it is derived from REQUIRED_FIELDS.
 */
final class RuntimeEvidenceContract
{
    public const VERSION = 'sentinel.runtime.v1';

    public const ALLOWED_FIELDS = [
        'evidence_version',
        'agent_id',
        'agent_version',
        'project_key',
        'environment',
        'release_id',
        'deployment_id',
        'current_release_path',
        'runtime_marker',
        'release_manifest_commit_sha',
        'artifact_checksum_sha256',
        'timestamp',
        'sequence',
        'nonce',
        'snapshot_status',
        // File-level observation (additive, feature-flagged)
        'file_manifest_hash',
        'file_manifest_entry_count',
        'file_manifest_total_size',
        'file_observation_status',
    ];

    public const REQUIRED_FIELDS = [
        'evidence_version',
        'agent_id',
        'agent_version',
        'project_key',
        'environment',
        'release_id',
        'deployment_id',
        'current_release_path',
        'runtime_marker',
        'timestamp',
        'sequence',
        'nonce',
    ];

    public const SNAPSHOT_STATUS_COMPLETE = 'complete';
    public const SNAPSHOT_STATUS_INCOMPLETE = 'incomplete';

    /** Patterns marking an attempt to escape the release root. */
    private const PATH_TRAVERSAL_PATTERNS = [
        '/^[A-Za-z]:/',
        '~^[\\\\/]~',
        '/\.\.\//',
        '/\.\.\\\\/',
        '/^\\//',
        '/^[a-zA-Z]:\\\\/',
    ];

    /** @param array<string, mixed> $evidence already canonicalized */
    private function __construct(private readonly array $evidence)
    {
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidArgumentException unknown field, malformed type, secret value, path escape
     */
    public static function fromArray(array $input): self
    {
        foreach (array_keys($input) as $key) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_FIELDS, true)) {
                throw new InvalidArgumentException(sprintf(
                    "sentinel.runtime.v1 evidence field '%s' is not part of the contract schema.",
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
                case 'sequence':
                    if (! is_int($value) || $value < 1) {
                        throw new InvalidArgumentException("sentinel.runtime.v1 evidence field 'sequence' must be a positive integer.");
                    }
                    $evidence[$field] = $value;
                    break;

                case 'snapshot_status':
                    if (! in_array($value, [self::SNAPSHOT_STATUS_COMPLETE, self::SNAPSHOT_STATUS_INCOMPLETE], true)) {
                        throw new InvalidArgumentException("sentinel.runtime.v1 evidence field 'snapshot_status' must be complete or incomplete.");
                    }
                    $evidence[$field] = $value;
                    break;

                case 'file_manifest_hash':
                    if (! is_string($value) || ! preg_match('/^[0-9a-f]{64}$/i', $value)) {
                        throw new InvalidArgumentException("sentinel.runtime.v1 evidence field 'file_manifest_hash' must be a 64-character hex SHA-256.");
                    }
                    $evidence[$field] = strtolower($value);
                    break;

                case 'file_manifest_entry_count':
                case 'file_manifest_total_size':
                    if (! is_int($value) || $value < 0) {
                        throw new InvalidArgumentException("sentinel.runtime.v1 evidence field '{$field}' must be a non-negative integer.");
                    }
                    $evidence[$field] = $value;
                    break;

                case 'file_observation_status':
                    if (! is_string($value)) {
                        throw new InvalidArgumentException("sentinel.runtime.v1 evidence field 'file_observation_status' must be a string.");
                    }
                    $value = trim($value);
                    if ($value !== '') {
                        EvidenceGuard::assertNoSecret($value, $field, self::VERSION);
                    }
                    $evidence[$field] = $value === '' ? null : $value;
                    break;

                default:
                    if (! is_string($value) && ! is_int($value)) {
                        throw new InvalidArgumentException("sentinel.runtime.v1 evidence field '{$field}' must be a string.");
                    }
                    $value = is_string($value) ? trim($value) : $value;
                    if ($value === '') {
                        $evidence[$field] = null;
                        break;
                    }
                    $value = (string) $value;
                    EvidenceGuard::assertNoSecret($value, $field, self::VERSION);
                    if ($field === 'current_release_path') {
                        foreach (self::PATH_TRAVERSAL_PATTERNS as $pattern) {
                            if (preg_match($pattern, $value) === 1) {
                                throw new InvalidArgumentException("sentinel.runtime.v1 evidence field '{$field}' contains path traversal; paths must be contained within the release root.");
                            }
                        }
                    }
                    $evidence[$field] = $value;
                    break;
            }
        }

        if ($evidence['snapshot_status'] === null) {
            $complete = true;
            foreach (self::REQUIRED_FIELDS as $field) {
                $complete = $complete && $evidence[$field] !== null;
            }
            $evidence['snapshot_status'] = $complete ? self::SNAPSHOT_STATUS_COMPLETE : self::SNAPSHOT_STATUS_INCOMPLETE;
        }

        return new self(EvidenceGuard::canonicalize($evidence));
    }

    /** @throws InvalidArgumentException */
    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('sentinel.runtime.v1 evidence is not valid JSON: '.$e->getMessage(), 0, $e);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('sentinel.runtime.v1 evidence must be a JSON object.');
        }

        return self::fromArray($decoded);
    }

    public function version(): ?string
    {
        $version = $this->evidence['evidence_version'];

        return is_string($version) ? $version : null;
    }

    public function isSupportedVersion(): bool
    {
        return $this->version() === self::VERSION;
    }

    public function get(string $field): mixed
    {
        if (! in_array($field, self::ALLOWED_FIELDS, true)) {
            throw new InvalidArgumentException("sentinel.runtime.v1 evidence field '{$field}' is not part of the contract schema.");
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

    public function isComplete(): bool
    {
        return $this->evidence['snapshot_status'] === self::SNAPSHOT_STATUS_COMPLETE;
    }
}
