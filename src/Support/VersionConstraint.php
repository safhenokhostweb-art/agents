<?php

declare(strict_types=1);

namespace Workspace\Agents\Support;

/**
 * The subset of Composer constraint semantics needed to check a shared
 * package's requirements against a host's locked versions: `^`, `~`,
 * wildcards, comparison operators, AND (space/comma), OR (`|`/`||`),
 * hyphen ranges, stability flags, and `N.x-dev` branch aliases.
 *
 * Pre-release ordering is not modelled. Anything it cannot evaluate
 * returns null so callers report "unverified" instead of guessing.
 */
final class VersionConstraint
{
    private const OPEN = 999999;

    public static function satisfies(string $version, string $constraint): ?bool
    {
        $candidate = self::parseVersion($version);
        if ($candidate === null) {
            return null;
        }
        $constraint = trim(preg_replace('/@(dev|alpha|beta|rc|stable)\b/i', '', $constraint) ?? '');
        if ($constraint === '') {
            return true;
        }

        $anyUnknown = false;
        foreach (preg_split('/\s*\|\|?\s*/', $constraint) as $alternative) {
            $result = self::satisfiesAll($candidate, $alternative);
            if ($result === true) {
                return true;
            }
            $anyUnknown = $anyUnknown || $result === null;
        }

        return $anyUnknown ? null : false;
    }

    /** @param array{0: int, 1: int, 2: int} $candidate */
    private static function satisfiesAll(array $candidate, string $group): ?bool
    {
        $group = trim($group);
        if (preg_match('/^(\S+)\s+-\s+(\S+)$/', $group, $range) === 1) {
            $group = '>='.$range[1].' <='.$range[2];
        }
        // Composer accepts whitespace between an operator and its version.
        $group = preg_replace('/(>=|<=|!=|==|<>|>|<|=)\s+/', '$1', $group) ?? $group;

        foreach (preg_split('/\s*,\s*|\s+/', $group) as $atom) {
            if ($atom === '') {
                continue;
            }
            $bounds = self::bounds($atom);
            if ($bounds === null) {
                return null;
            }
            foreach ($bounds as [$operator, $limit]) {
                if (! self::compare($candidate, $operator, $limit)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return list<array{0: string, 1: array{0: int, 1: int, 2: int}}>|null */
    private static function bounds(string $atom): ?array
    {
        if ($atom === '*' || strtolower($atom) === 'x') {
            return [];
        }
        if (preg_match('/^(\^|~)(.+)$/', $atom, $m) === 1) {
            $parts = self::parts($m[2]);
            if ($parts === null) {
                return null;
            }
            [$version, $given] = $parts;

            return [['>=', $version], ['<', $m[1] === '^' ? self::caretCeiling($version, $given) : self::tildeCeiling($version, $given)]];
        }
        if (preg_match('/^(\d+)(?:\.(\d+))?\.[*xX]$/', $atom, $m) === 1) {
            $lower = [(int) $m[1], (int) ($m[2] ?? 0), 0];
            $upper = isset($m[2]) && $m[2] !== '' ? [(int) $m[1], (int) $m[2] + 1, 0] : [(int) $m[1] + 1, 0, 0];

            return [['>=', $lower], ['<', $upper]];
        }
        if (preg_match('/^(>=|<=|!=|==|<>|>|<|=)?(.+)$/', $atom, $m) === 1) {
            $parts = self::parts($m[2]);
            if ($parts === null) {
                return null;
            }
            $operator = match ($m[1]) {
                '', '=', '==' => '==',
                '<>' => '!=',
                default => $m[1],
            };

            return [[$operator, $parts[0]]];
        }

        return null;
    }

    /** @return array{0: int, 1: int, 2: int}|null */
    private static function parseVersion(string $version): ?array
    {
        $version = ltrim(trim($version), 'vV');
        if (preg_match('/^(\d+)(?:\.(\d+|x))?(?:\.(\d+|x))?(?:\.\d+)?-dev$/i', $version, $m) === 1) {
            // Branch alias such as 10.x-dev: the newest version in that line.
            $minor = ($m[2] ?? 'x') === 'x' || ($m[2] ?? '') === '' ? self::OPEN : (int) $m[2];
            $patch = ($m[3] ?? 'x') === 'x' || ($m[3] ?? '') === '' ? self::OPEN : (int) $m[3];

            return [(int) $m[1], $minor, $patch];
        }
        $parts = self::parts($version);

        return $parts === null ? null : $parts[0];
    }

    /** @return array{0: array{0: int, 1: int, 2: int}, 1: int}|null version and how many components were given */
    private static function parts(string $value): ?array
    {
        $value = ltrim($value, 'vV');
        if (preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.\d+)?(?:[-+][0-9A-Za-z.\-]+)?$/', $value, $m) !== 1) {
            return null;
        }
        $given = 1 + (int) (($m[2] ?? '') !== '') + (int) (($m[3] ?? '') !== '');

        return [[(int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0)], $given];
    }

    /** @param array{0: int, 1: int, 2: int} $v */
    private static function caretCeiling(array $v, int $given): array
    {
        for ($i = 0; $i < $given; $i++) {
            if ($v[$i] !== 0) {
                return self::bump($v, $i);
            }
        }

        return self::bump($v, $given - 1);
    }

    /** @param array{0: int, 1: int, 2: int} $v */
    private static function tildeCeiling(array $v, int $given): array
    {
        return self::bump($v, $given >= 3 ? 1 : 0);
    }

    /** @param array{0: int, 1: int, 2: int} $v */
    private static function bump(array $v, int $index): array
    {
        $result = [0, 0, 0];
        for ($i = 0; $i < $index; $i++) {
            $result[$i] = $v[$i];
        }
        $result[$index] = $v[$index] + 1;

        return $result;
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $a
     * @param  array{0: int, 1: int, 2: int}  $b
     */
    private static function compare(array $a, string $operator, array $b): bool
    {
        $order = $a <=> $b;

        return match ($operator) {
            '>=' => $order >= 0,
            '>' => $order > 0,
            '<=' => $order <= 0,
            '<' => $order < 0,
            '!=' => $order !== 0,
            default => $order === 0,
        };
    }
}
