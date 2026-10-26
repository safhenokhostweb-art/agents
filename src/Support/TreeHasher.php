<?php

declare(strict_types=1);

namespace Workspace\Agents\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Byte-exact directory manifests, used to prove a mirrored copy equals its source. */
final class TreeHasher
{
    /**
     * @param  list<string>  $ignoredSegments  any path containing one of these segments is skipped
     * @return array<string, string> relative path => sha256
     */
    public static function manifest(string $directory, array $ignoredSegments = ['.git']): array
    {
        $root = Paths::normalize($directory);
        $manifest = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
        );
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
            if (array_intersect(explode('/', $relative), $ignoredSegments) !== []) {
                continue;
            }
            $manifest[$relative] = hash_file('sha256', $file->getPathname());
        }
        ksort($manifest, SORT_STRING);

        return $manifest;
    }

    /**
     * @param  array<string, string>  $source
     * @param  array<string, string>  $mirror
     * @return array{missing: list<string>, extra: list<string>, modified: list<string>}
     */
    public static function compare(array $source, array $mirror): array
    {
        $modified = [];
        foreach (array_intersect_key($source, $mirror) as $path => $hash) {
            if ($mirror[$path] !== $hash) {
                $modified[] = (string) $path;
            }
        }

        return [
            'missing' => array_map('strval', array_keys(array_diff_key($source, $mirror))),
            'extra' => array_map('strval', array_keys(array_diff_key($mirror, $source))),
            'modified' => $modified,
        ];
    }

    /** @param array<string, string> $manifest */
    public static function digest(array $manifest): string
    {
        return hash('sha256', Json::canonical($manifest));
    }
}
