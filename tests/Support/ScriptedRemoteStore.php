<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Support;

use Closure;
use Workspace\Agents\Agents\ServerDiskJanitor\FilesystemRemoteStore;
use Workspace\Agents\Agents\ServerDiskJanitor\RemoteStore;

/**
 * A filesystem store whose behaviour tests can bend: fail a given upload,
 * lie about what the destination holds, or act during a transfer.
 */
final class ScriptedRemoteStore implements RemoteStore
{
    public int $puts = 0;

    /**
     * @param  Closure(string $key, int $put): void|null  $onPut  may throw to fail the upload
     * @param  Closure(string $key, ?array $stat): ?array|null  $onStat  may rewrite the destination's answer
     */
    public function __construct(
        private readonly FilesystemRemoteStore $inner,
        private readonly ?Closure $onPut = null,
        private readonly ?Closure $onStat = null,
    ) {
    }

    public function describe(): array
    {
        return ['driver' => 'scripted'] + $this->inner->describe();
    }

    public function put(string $localPath, string $key, array $digests): array
    {
        $this->puts++;
        if ($this->onPut !== null) {
            ($this->onPut)($key, $this->puts);
        }

        return $this->inner->put($localPath, $key, $digests);
    }

    public function stat(string $key): ?array
    {
        $stat = $this->inner->stat($key);

        return $this->onStat !== null ? ($this->onStat)($key, $stat) : $stat;
    }
}
