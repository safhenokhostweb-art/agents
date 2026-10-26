<?php

declare(strict_types=1);

namespace Workspace\Agents\Support;

use RuntimeException;

final class ComposerProject
{
    /**
     * Composer's own lock fingerprint inputs (Composer\Package\Locker::getContentHash).
     * Verified byte-exact against both workspace lock files on 2026-10-26.
     */
    private const CONTENT_HASH_KEYS = [
        'name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide',
        'minimum-stability', 'prefer-stable', 'repositories', 'extra',
    ];

    private function __construct(
        public readonly string $root,
        public readonly array $manifest,
        public readonly ?array $lock,
    ) {
    }

    public static function load(string $root): self
    {
        $root = Paths::normalize($root);
        if (! is_file($root.'/composer.json')) {
            throw new RuntimeException("No composer.json in {$root}");
        }
        $lock = is_file($root.'/composer.lock') ? Json::decodeFile($root.'/composer.lock') : null;

        return new self($root, Json::decodeFile($root.'/composer.json'), $lock);
    }

    public function name(): ?string
    {
        return isset($this->manifest['name']) ? (string) $this->manifest['name'] : null;
    }

    /** @return array<string, string> require + require-dev */
    public function requirements(): array
    {
        return array_merge($this->manifest['require'] ?? [], $this->manifest['require-dev'] ?? []);
    }

    /** @return list<array{url: string, directory: string, symlink: bool|null}> */
    public function pathRepositories(): array
    {
        $repositories = [];
        foreach ($this->manifest['repositories'] ?? [] as $repository) {
            if (! is_array($repository) || ($repository['type'] ?? null) !== 'path' || ! isset($repository['url'])) {
                continue;
            }
            $repositories[] = [
                'url' => (string) $repository['url'],
                'directory' => Paths::join($this->root, (string) $repository['url']),
                'symlink' => isset($repository['options']['symlink']) ? (bool) $repository['options']['symlink'] : null,
            ];
        }

        return $repositories;
    }

    public static function contentHash(array $manifest): string
    {
        $relevant = [];
        foreach (array_intersect(self::CONTENT_HASH_KEYS, array_keys($manifest)) as $key) {
            $relevant[$key] = $manifest[$key];
        }
        if (isset($manifest['config']['platform'])) {
            $relevant['config']['platform'] = $manifest['config']['platform'];
        }
        ksort($relevant);

        return md5(json_encode($relevant, 0));
    }

    /** null when there is no lock file to compare against. */
    public function lockInSync(): ?bool
    {
        if ($this->lock === null) {
            return null;
        }

        return ($this->lock['content-hash'] ?? null) === self::contentHash($this->manifest);
    }

    public function lockedVersion(string $package): ?string
    {
        foreach (array_merge($this->lock['packages'] ?? [], $this->lock['packages-dev'] ?? []) as $entry) {
            if (($entry['name'] ?? null) === $package) {
                return (string) ($entry['version'] ?? '');
            }
        }

        return null;
    }

    public function platformPhp(): ?string
    {
        $php = $this->manifest['config']['platform']['php'] ?? null;

        return is_string($php) ? $php : null;
    }
}
