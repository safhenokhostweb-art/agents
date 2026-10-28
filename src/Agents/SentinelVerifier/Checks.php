<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\SentinelVerifier;

/**
 * The Sentinel check vocabulary and its fail-closed aggregation:
 *
 *   any fail    -> FAILED   (a link is proven wrong)
 *   any unknown -> UNKNOWN  (a link cannot be proven; never a pass)
 *   else        -> VERIFIED
 *
 * Same strings as deployment-system's SentinelEvidenceContract, so checks
 * read identically on the Hub and in agent artifacts.
 */
final class Checks
{
    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const UNKNOWN = 'unknown';

    public const VERIFIED = 'VERIFIED';
    public const FAILED = 'FAILED';
    public const INDETERMINATE = 'UNKNOWN';

    /** @return array{status: string, detail: string} */
    public static function pass(string $detail): array
    {
        return ['status' => self::PASS, 'detail' => $detail];
    }

    /** @return array{status: string, detail: string} */
    public static function fail(string $detail): array
    {
        return ['status' => self::FAIL, 'detail' => $detail];
    }

    /** @return array{status: string, detail: string} */
    public static function unknown(string $detail): array
    {
        return ['status' => self::UNKNOWN, 'detail' => $detail];
    }

    /** @param array<string, array{status?: string, detail?: string}> $checks */
    public static function aggregate(array $checks): string
    {
        $statuses = array_map(static fn (array $check): string => (string) ($check['status'] ?? self::UNKNOWN), $checks);
        if (in_array(self::FAIL, $statuses, true)) {
            return self::FAILED;
        }
        foreach ($statuses as $status) {
            if ($status !== self::PASS) {
                return self::INDETERMINATE;
            }
        }

        return $checks === [] ? self::INDETERMINATE : self::VERIFIED;
    }

    /** Combine verdicts with the same precedence: FAILED > UNKNOWN > VERIFIED. */
    public static function combine(string ...$verdicts): string
    {
        if ($verdicts === [] || in_array(self::FAILED, $verdicts, true)) {
            return $verdicts === [] ? self::INDETERMINATE : self::FAILED;
        }

        return in_array(self::INDETERMINATE, $verdicts, true) ? self::INDETERMINATE : self::VERIFIED;
    }
}
