<?php

declare(strict_types=1);

namespace Workspace\Agents\Kernel;

use RuntimeException;
use Workspace\Agents\Support\Json;

/**
 * Appends every message to a JSONL journal before delivering it, so agents in
 * other processes (or a later run) can replay what happened.
 */
final class JournaledBus implements MessageBus
{
    public function __construct(private readonly MessageBus $inner, private readonly string $journalPath)
    {
        $directory = dirname($journalPath);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create bus journal directory: {$directory}");
        }
    }

    public function publish(Message $message): void
    {
        if (file_put_contents($this->journalPath, Json::encode($message->toArray(), false).PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Cannot append to bus journal: {$this->journalPath}");
        }
        $this->inner->publish($message);
    }

    public function subscribe(string $pattern, callable $handler): void
    {
        $this->inner->subscribe($pattern, $handler);
    }

    /** @return list<Message> */
    public static function read(string $journalPath, ?string $correlationId = null): array
    {
        if (! is_file($journalPath)) {
            return [];
        }
        $messages = [];
        foreach (file($journalPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $message = Message::fromArray(Json::decode($line, $journalPath));
            if ($correlationId === null || $message->correlationId === $correlationId) {
                $messages[] = $message;
            }
        }

        return $messages;
    }
}
