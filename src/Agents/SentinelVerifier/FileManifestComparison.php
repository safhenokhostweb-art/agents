<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\SentinelVerifier;

use Workspace\Agents\Support\Glob;

/**
 * Compares an expected file manifest (from the artifact) with an observed
 * one (from the active release).
 *
 * Port of deployment-system's App\Services\Sentinel\SentinelFileComparisonService
 * with the same outcomes and verdict rules:
 *
 *  - any confirmed MISSING or HASH_MISMATCH          => FAIL
 *  - incomplete, unreadable or limit-exceeded input  => UNKNOWN
 *  - EXTRA files (no reviewed policy yet)            => UNKNOWN
 *  - PASS needs complete inventories that match and equal manifest hashes
 *
 * Additions for per-file audits: paths matching an approved exclusion
 * policy are EXCLUDED on both sides, files the observer could not read are
 * UNREADABLE (and so are expected files beneath an unreadable directory
 * link), and every discrepancy carries the expected/observed size and hash.
 * Called with empty inventories it is the Hub's hash-only comparison.
 */
final class FileManifestComparison
{
    /**
     * @param  array<string, array{size: int, sha256: string}>  $expectedManifest
     * @param  array<string, array{size: int, sha256: string}>  $observedManifest
     * @param  list<string>  $unreadable  observed paths that could not be hashed safely
     * @param  list<string>  $exclude  approved exclusion globs
     */
    public function __construct(
        private readonly array $expectedManifest,
        private readonly array $observedManifest,
        private readonly string $expectedManifestHash,
        private readonly string $observedManifestHash,
        private readonly int $expectedEntryCount,
        private readonly int $observedEntryCount,
        private readonly int $expectedTotalSize,
        private readonly int $observedTotalSize,
        private readonly string $comparisonScope = 'full',
        private readonly array $unreadable = [],
        private readonly array $exclude = [],
        private readonly bool $limitExceeded = false,
    ) {
    }

    /**
     * @return array{
     *   match_count: int, missing_count: int, hash_mismatch_count: int, extra_count: int,
     *   excluded_count: int, unreadable_count: int, incomplete: bool, stale: bool, wrong_target: bool,
     *   unreadable: bool, discrepancies: list<array<string, mixed>>, summary: string, scope: string
     * }
     */
    public function compare(): array
    {
        $discrepancies = [];
        $matchCount = $missingCount = $hashMismatchCount = $extraCount = $excludedCount = $unreadableCount = 0;
        $manifestHashMismatch = ! hash_equals($this->expectedManifestHash, $this->observedManifestHash);
        $unreadable = array_fill_keys($this->unreadable, true);

        foreach ($this->expectedManifest as $path => $expected) {
            $path = (string) $path;
            if ($this->excluded($path)) {
                $excludedCount++;
                continue;
            }
            $observed = $this->observedManifest[$path] ?? null;
            if ($observed === null && $this->beneathUnreadable($path, $unreadable)) {
                $unreadableCount++;
                $discrepancies[] = ['path' => $path, 'outcome' => 'UNREADABLE', 'detail' => 'File could not be hashed safely in the runtime (unreadable or behind a link that is never followed)', 'expected' => $expected, 'observed' => null];
            } elseif ($observed === null) {
                $missingCount++;
                $discrepancies[] = ['path' => $path, 'outcome' => 'MISSING', 'detail' => 'File exists in artifact but not observed in runtime', 'expected' => $expected, 'observed' => null];
            } elseif (! hash_equals($expected['sha256'], $observed['sha256'])) {
                $hashMismatchCount++;
                $discrepancies[] = ['path' => $path, 'outcome' => 'HASH_MISMATCH', 'detail' => 'File content hash differs from artifact', 'expected' => $expected, 'observed' => $observed];
            } elseif ($expected['size'] !== $observed['size']) {
                $hashMismatchCount++;
                $discrepancies[] = ['path' => $path, 'outcome' => 'HASH_MISMATCH', 'detail' => 'File size differs from artifact', 'expected' => $expected, 'observed' => $observed];
            } else {
                $matchCount++;
            }
        }
        foreach ($this->observedManifest as $path => $observed) {
            $path = (string) $path;
            if (isset($this->expectedManifest[$path])) {
                continue;
            }
            if ($this->excluded($path)) {
                $excludedCount++;
                continue;
            }
            $extraCount++;
            $discrepancies[] = ['path' => $path, 'outcome' => 'EXTRA', 'detail' => 'File observed in runtime but not present in artifact', 'expected' => null, 'observed' => $observed];
        }
        foreach (array_keys($unreadable) as $path) {
            if (! isset($this->expectedManifest[$path]) && ! $this->excluded((string) $path)) {
                $unreadableCount++;
                $discrepancies[] = ['path' => (string) $path, 'outcome' => 'UNREADABLE', 'detail' => 'Runtime entry is not a regular readable file and is not in the artifact', 'expected' => null, 'observed' => null];
            }
        }

        // Empty inventories with non-zero declared counts mean only hash-level
        // aggregates exist: no per-file comparison is possible, never a PASS.
        $incomplete = $this->limitExceeded
            || $this->expectedEntryCount === 0 || $this->observedEntryCount === 0
            || ($this->expectedManifest === [] && $this->expectedEntryCount > 0)
            || ($this->observedManifest === [] && $this->observedEntryCount > 0);
        $stale = false;       // judged by the snapshot freshness check
        $wrongTarget = false; // judged by the attribution checks
        $isUnreadable = $unreadableCount > 0;

        $summary = match (true) {
            $wrongTarget => 'WRONG_TARGET: Evidence attribution does not match deployment',
            $isUnreadable => 'UNREADABLE: Required files could not be hashed safely',
            $incomplete => 'INCOMPLETE: One or both manifests are incomplete',
            $stale => 'STALE: Observation exceeds freshness window',
            $missingCount > 0 || $hashMismatchCount > 0 => 'FAIL: '.$missingCount.' missing, '.$hashMismatchCount.' hash mismatches',
            $extraCount > 0 => 'EXTRA: '.$extraCount.' extra files observed (policy-dependent)',
            $manifestHashMismatch && $excludedCount === 0 => 'FAIL: Manifest hash mismatch detected',
            default => 'MATCH: All '.$matchCount.' files verified',
        };

        return [
            'match_count' => $matchCount,
            'missing_count' => $missingCount,
            'hash_mismatch_count' => $hashMismatchCount,
            'extra_count' => $extraCount,
            'excluded_count' => $excludedCount,
            'unreadable_count' => $unreadableCount,
            'incomplete' => $incomplete,
            'stale' => $stale,
            'wrong_target' => $wrongTarget,
            'unreadable' => $isUnreadable,
            'discrepancies' => $discrepancies,
            'summary' => $summary,
            'scope' => $this->comparisonScope,
        ];
    }

    /** PASS, FAIL or UNKNOWN. */
    public function verdict(): string
    {
        return $this->verdictOf($this->compare());
    }

    /** @param array<string, mixed> $result a compare() result */
    public function verdictOf(array $result): string
    {
        if ($result['wrong_target']) {
            return 'FAIL';
        }
        if ($result['unreadable'] || $result['incomplete'] || $result['stale']) {
            return 'UNKNOWN';
        }
        if ($result['missing_count'] > 0 || $result['hash_mismatch_count'] > 0) {
            return 'FAIL';
        }
        if ($result['extra_count'] > 0) {
            return 'UNKNOWN';
        }
        // With an exclusion policy the whole-manifest hashes differ by
        // design; the per-file comparison above is then the authority.
        if ($result['excluded_count'] === 0 && ! hash_equals($this->expectedManifestHash, $this->observedManifestHash)) {
            return 'FAIL';
        }

        return 'PASS';
    }

    private function excluded(string $path): bool
    {
        return $this->exclude !== [] && Glob::matches($path, $this->exclude);
    }

    /** @param array<string, true> $unreadable */
    private function beneathUnreadable(string $path, array $unreadable): bool
    {
        foreach (array_keys($unreadable) as $entry) {
            if ($path === $entry || str_starts_with($path, $entry.'/')) {
                return true;
            }
        }

        return false;
    }
}
