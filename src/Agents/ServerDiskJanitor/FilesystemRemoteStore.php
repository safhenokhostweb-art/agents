<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\ServerDiskJanitor;

use RuntimeException;

/**
 * A mounted off-site volume (NFS, sshfs, rclone mount, a USB disk). The
 * destination digest is computed by reading the stored copy back, so a
 * short write or a lying cache shows up as a mismatch.
 */
final class FilesystemRemoteStore implements RemoteStore
{
    public function __construct(private readonly string $root)
    {
    }

    public function describe(): array
    {
        return ['driver' => 'filesystem', 'path' => $this->root];
    }

    public function put(string $localPath, string $key, array $digests): array
    {
        if (! is_dir($this->root)) {
            throw new RuntimeException("remote path {$this->root} is not a mounted directory");
        }
        $target = $this->path($key);
        if (! is_dir(dirname($target)) && ! @mkdir(dirname($target), 0750, true) && ! is_dir(dirname($target))) {
            throw new RuntimeException('cannot create remote directory '.dirname($target));
        }
        $temporary = $target.'.'.bin2hex(random_bytes(4)).'.part';
        $in = @fopen($localPath, 'rb');
        $out = @fopen($temporary, 'xb');
        if ($in === false || $out === false) {
            throw new RuntimeException("cannot open {$localPath} or {$temporary} for the transfer");
        }
        $copied = stream_copy_to_stream($in, $out);
        fflush($out);
        if (function_exists('fsync')) {
            @fsync($out);
        }
        fclose($in);
        fclose($out);
        if ($copied !== $digests['size'] || ! @rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException("short or failed write to {$target}");
        }

        return ['bytes_written' => $copied];
    }

    public function stat(string $key): ?array
    {
        $path = $this->path($key);
        clearstatcache(true, $path);
        if (! is_file($path)) {
            return null;
        }

        return ['size' => (int) filesize($path), 'sha256' => hash_file('sha256', $path) ?: null, 'md5' => hash_file('md5', $path) ?: null, 'source' => 'read-back'];
    }

    private function path(string $key): string
    {
        if (str_contains($key, '..') || str_starts_with($key, '/')) {
            throw new RuntimeException("invalid object key {$key}");
        }

        return rtrim($this->root, '/\\').'/'.$key;
    }
}
