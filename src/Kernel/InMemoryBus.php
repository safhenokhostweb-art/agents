<?php

declare(strict_types=1);

namespace Workspace\Agents\Kernel;

/**
 * Synchronous in-process bus. Messages published from inside a handler are
 * queued and delivered after the current one, so delivery order is FIFO and
 * handlers never recurse into each other.
 */
final class InMemoryBus implements MessageBus
{
    /** @var list<array{0: string, 1: callable}> */
    private array $subscribers = [];

    /** @var list<Message> */
    private array $queue = [];

    /** @var list<Message> */
    private array $history = [];

    private bool $draining = false;

    public function subscribe(string $pattern, callable $handler): void
    {
        $this->subscribers[] = [$pattern, $handler];
    }

    public function publish(Message $message): void
    {
        $this->history[] = $message;
        $this->queue[] = $message;
        if ($this->draining) {
            return;
        }
        $this->draining = true;
        try {
            while (($next = array_shift($this->queue)) !== null) {
                foreach ($this->subscribers as [$pattern, $handler]) {
                    if ($next->matches($pattern)) {
                        $handler($next);
                    }
                }
            }
        } finally {
            $this->draining = false;
            $this->queue = [];
        }
    }

    /** @return list<Message> */
    public function history(): array
    {
        return $this->history;
    }
}
