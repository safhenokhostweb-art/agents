<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\SentinelVerifier;

use FilesystemIterator;
use InvalidArgumentException;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Workspace\Agents\Support\Glob;

/**
 * A file inventory in deployment-system's artifact manifest format
 * (`.deployment/file-manifest.json`, agent/lib/ArtifactFileManifest.php):
 * relative path => {size, sha256}, sealed by the SHA-256 of its canonical
 * JSON. Same normalization and hash, so a manifest built here from a
 * release tree equals the artifact's manifest exactly when every byte does.
 *
 * Read side only: the agent never writes a manifest into a release.
 */
final class FileManifest
{
    public const FILENAME = '.deployment/file-manifest.json';
    public const FORMAT = 'deployment-system-file-manifest';
    public const FORMAT_VERSION = 1;

    /** @param array<string, array{size: int, sha256: string}> $entries sorted by path */
    private function __construct(private readonly array $entries, private readonly string $hash)
    {
    }

    /** @throws InvalidArgumentException unsupported format, malformed entry, or a hash that does not seal the entries */
    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('File manifest is not valid JSON: '.$e->getMessage(), 0, $e);
        }
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('File manifest must be a JSON object');
        }
        if (($decoded['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException('Unsupported manifest format.');
        }
        if (($decoded['version'] ?? null) !== self::FORMAT_VERSION) {
            throw new InvalidArgumentException('Unsupported manifest version.');
        }
        $entries = $decoded['entries'] ?? [];
        if (! is_array($entries)) {
            throw new InvalidArgumentException('Manifest entries must be an object');
        }
        $provided = $decoded['manifest_hash'] ?? null;
        if (! is_string($provided) || preg_match('/^[0-9a-f]{64}$/D', $provided) !== 1) {
            throw new InvalidArgumentException('Manifest hash is missing or malformed');
        }
        foreach ($entries as $path => $entry) {
            $path = (string) $path;
            if (self::normalize($path) !== $path) {
                throw new InvalidArgumentException('Invalid normalized path in manifest.');
            }
            if (! is_array($entry) || ! is_int($entry['size'] ?? null) || $entry['size'] < 0
                || ! is_string($entry['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $entry['sha256']) !== 1) {
                throw new InvalidArgumentException("Invalid entry for path: {$path}");
            }
        }
        $manifest = self::fromEntries($entries);
        if (! hash_equals($provided, $manifest->hash)) {
            throw new InvalidArgumentException('Manifest hash does not match canonical manifest content');
        }

        return $manifest;
    }

    /** @param array<string, array{size: int, sha256: string}> $entries */
    public static function fromEntries(array $entries): self
    {
        $normalized = [];
        foreach ($entries as $path => $entry) {
            $normalized[(string) $path] = ['size' => $entry['size'], 'sha256' => strtolower($entry['sha256'])];
        }
        ksort($normalized, SORT_STRING);
        $canonical = EvidenceGuard::canonicalize(['format' => self::FORMAT, 'version' => self::FORMAT_VERSION, 'entries' => $normalized]);

        return new self($normalized, hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }

    /**
     * Inventory a release directory read-only. Symlinks and junctions are
     * never followed (they are reported as unreadable), the manifest file
     * itself and $exclude globs are skipped, and the walk stops at the file
     * or byte bound rather than running unbounded.
     *
     * @param  list<string>  $exclude  release-relative globs (`**` crosses directories)
     * @return array{manifest: self, unreadable: list<string>, excluded: int, limit_exceeded: bool, bytes: int}
     */
    public static function observe(string $directory, array $exclude, int $maxFiles, int $maxBytes): array
    {
        $root = realpath($directory);
        if ($root === false || ! is_dir($root)) {
            throw new InvalidArgumentException('Release directory does not exist.');
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $entries = [];
        $unreadable = [];
        $excluded = 0;
        $bytes = 0;
        $limitExceeded = false;

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO));
        foreach ($iterator as $file) {
            $absolute = str_replace('\\', '/', $file->getPathname());
            $relative = substr($absolute, strlen($root) + 1);
            if ($relative === self::FILENAME || Glob::matches($relative, $exclude)) {
                $excluded++;
                continue;
            }
            if ($file->isLink() || ! $file->isFile() || self::normalize($relative) !== $relative) {
                $unreadable[] = $relative;
                continue;
            }
            $size = $file->getSize();
            if (count($entries) >= $maxFiles || $bytes + $size > $maxBytes) {
                $limitExceeded = true;
                break;
            }
            $sha = @hash_file('sha256', $file->getPathname());
            if ($sha === false) {
                $unreadable[] = $relative;
                continue;
            }
            $bytes += $size;
            $entries[$relative] = ['size' => $size, 'sha256' => $sha];
        }
        sort($unreadable, SORT_STRING);

        return ['manifest' => self::fromEntries($entries), 'unreadable' => $unreadable, 'excluded' => $excluded, 'limit_exceeded' => $limitExceeded, 'bytes' => $bytes];
    }

    /** @return array<string, array{size: int, sha256: string}> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function hash(): string
    {
        return $this->hash;
    }

    public function entryCount(): int
    {
        return count($this->entries);
    }

    public function totalSize(): int
    {
        return array_sum(array_column($this->entries, 'size'));
    }

    /** ArtifactFileManifest::normalizePath(): forward slashes, no absolute, drive or traversal path, leading "./" removed. */
    private static function normalize(string $path): ?string
    {
        if (str_starts_with($path, '/') || str_contains($path, '..') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return null;
        }
        $normalized = str_replace('\\', '/', $path);
        while (str_starts_with($normalized, './')) {
            $normalized = substr($normalized, 2);
        }

        return $normalized === '' || $normalized === '.' ? null : $normalized;
    }
}
