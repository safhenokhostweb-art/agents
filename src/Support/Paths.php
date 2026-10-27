<?php

declare(strict_types=1);

namespace Workspace\Agents\Support;

final class Paths
{
    /**
     * Forward-slash absolute form with an uppercase drive letter (git prints
     * C:/…, shells and IDEs pass c:\…); resolved through realpath when the
     * path exists.
     */
    public static function normalize(string $path): string
    {
        $real = realpath($path);
        $path = str_replace('\\', '/', $real === false ? $path : $real);
        if (preg_match('#^[a-z]:(/|$)#', $path) === 1) {
            $path = strtoupper($path[0]).substr($path, 1);
        }

        return $path === '/' ? $path : rtrim($path, '/');
    }

    public static function join(string $base, string $relative): string
    {
        if (self::isAbsolute($relative)) {
            return self::normalize($relative);
        }

        return self::normalize(rtrim($base, '/\\').'/'.$relative);
    }

    /** Path of $path relative to $base, or null when $path is not inside $base. */
    public static function relative(string $base, string $path): ?string
    {
        $base = self::normalize($base);
        $path = self::normalize($path);
        $same = PHP_OS_FAMILY === 'Windows'
            ? static fn (string $a, string $b): bool => strcasecmp($a, $b) === 0
            : static fn (string $a, string $b): bool => $a === $b;
        if ($same($base, $path)) {
            return '';
        }
        $prefix = $base.'/';
        if (! $same(substr($path, 0, strlen($prefix)), $prefix)) {
            return null;
        }

        return substr($path, strlen($prefix));
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
    }
}
