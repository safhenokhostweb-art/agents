<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\SentinelVerifier;

use InvalidArgumentException;

/**
 * The secret and canonical-form rules both Sentinel evidence contracts
 * enforce. Patterns are byte-identical to deployment-system's
 * SentinelEvidenceContract / SentinelRuntimeEvidenceContract: an evidence
 * document the Hub refuses must be refused here too.
 */
final class EvidenceGuard
{
    /** Key names that must never appear in evidence (also applied to check ids). */
    public const FORBIDDEN_KEY_PATTERN = '/(token|secret|password|passwd|cookie|credential|authorization|api[_-]?key|private[_-]?key)/i';

    /** A value matching any of these makes the document refuse to build. */
    public const SECRET_VALUE_PATTERNS = [
        '/Bearer\s+[A-Za-z0-9._~+\/=-]{8,}/i',
        '/Basic\s+[A-Za-z0-9+\/=]{8,}/i',
        '/gh[pousr]_[A-Za-z0-9]{10,}/',
        '/AKIA[0-9A-Z]{16}/',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/',
        '/\b(?:password|passwd|secret|token|api[_-]?key|credential)\s*[=:]\s*[^\s&;,"\'()]+/i',
    ];

    /** Release-relative paths whose names or hashes are never written to a report. */
    private const SENSITIVE_PATH_PATTERN = '#(^|/)(\.env[^/]*|id_(rsa|dsa|ecdsa|ed25519)[^/]*|auth\.json|[^/]*\.(pem|key|p12|pfx|jks|kdbx)|[^/]*(secret|credential|password|passwd|token|private[_-]?key)[^/]*)$#i';

    public static function assertKeyAllowed(string $key, string $contract): void
    {
        if (preg_match(self::FORBIDDEN_KEY_PATTERN, $key) === 1) {
            throw new InvalidArgumentException("{$contract} evidence key '{$key}' looks credential-bearing and is forbidden.");
        }
    }

    public static function assertNoSecret(string $value, string $field, string $contract): void
    {
        if (self::containsSecret($value)) {
            throw new InvalidArgumentException("{$contract} evidence field '{$field}' contains credential-shaped content; secrets are never stored in evidence.");
        }
    }

    public static function containsSecret(string $value): bool
    {
        foreach (self::SECRET_VALUE_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * A contract's rejection message names the offending key, and keys are
     * producer-controlled: never let one carry a credential into a report.
     */
    public static function safeMessage(string $message): string
    {
        return self::containsSecret($message) ? 'the document contains a credential-shaped key (redacted).' : $message;
    }

    public static function isSensitivePath(string $path): bool
    {
        return preg_match(self::SENSITIVE_PATH_PATTERN, $path) === 1;
    }

    /**
     * Recursively sort associative keys (list order preserved) so a document
     * has exactly one canonical byte representation.
     *
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    public static function canonicalize(array $value): array
    {
        ksort($value, SORT_STRING);
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = array_is_list($child)
                    ? array_map(static fn ($item) => is_array($item) ? self::canonicalize($item) : $item, $child)
                    : self::canonicalize($child);
            }
        }

        return $value;
    }
}
