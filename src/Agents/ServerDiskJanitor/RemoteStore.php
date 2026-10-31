<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\ServerDiskJanitor;

/**
 * Off-site storage for encrypted archives. A store only moves bytes and
 * reports what the destination holds; whether that is good enough to
 * delete the local copy is BackupArchiver's decision (the handshake).
 *
 * Methods throw RuntimeException on any failure; messages must never
 * contain credentials.
 */
interface RemoteStore
{
    /** @return array<string, mixed> driver and location, no credentials */
    public function describe(): array;

    /**
     * Upload $localPath to $key. $digests carries the local `sha256` and
     * `md5` (hex) so the store can ask the destination to verify them.
     *
     * @param  array{sha256: string, md5: string, size: int}  $digests
     * @return array<string, mixed> transfer details for the report (e.g. HTTP status)
     */
    public function put(string $localPath, string $key, array $digests): array;

    /**
     * What the destination holds at $key, or null when nothing is there.
     * Digests are those the destination itself computed: `sha256` and/or
     * `md5` (hex), null when it cannot say.
     *
     * @return array{size: ?int, sha256: ?string, md5: ?string, source: string}|null
     */
    public function stat(string $key): ?array;
}
