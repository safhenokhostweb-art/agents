<?php

declare(strict_types=1);

namespace Workspace\Agents\Kernel;

interface MessageBus
{
    public function publish(Message $message): void;

    /** @param callable(Message): void $handler */
    public function subscribe(string $pattern, callable $handler): void;
}
