<?php

declare(strict_types=1);

namespace Workspace\Agents\Kernel;

use Workspace\Agents\Support\Id;

/** The bus envelope: an immutable fact that something happened. */
final class Message
{
    public const SCHEMA = 'agents.message/v1';

    private function __construct(
        public readonly string $id,
        public readonly string $topic,
        public readonly string $source,
        public readonly array $payload,
        public readonly string $correlationId,
        public readonly ?string $causationId,
        public readonly int $hop,
        public readonly string $occurredAt,
    ) {
        if (preg_match(Task::NAME_PATTERN, $topic) !== 1) {
            throw new ProtocolException('INVALID_MESSAGE', "Topic '{$topic}' must be dotted lowercase, e.g. dependency.drift.detected.");
        }
    }

    public static function create(string $topic, array $payload, string $source, string $correlationId, ?string $causationId = null, int $hop = 0): self
    {
        return new self(Id::new('msg'), $topic, $source, $payload, $correlationId, $causationId, $hop, gmdate('c'));
    }

    public static function fromArray(array $data): self
    {
        if (($data['schema'] ?? null) !== self::SCHEMA) {
            throw new ProtocolException('INVALID_MESSAGE', 'Unsupported message schema.');
        }

        return new self(
            (string) $data['id'],
            (string) $data['topic'],
            (string) $data['source'],
            (array) $data['payload'],
            (string) $data['correlation_id'],
            isset($data['causation_id']) ? (string) $data['causation_id'] : null,
            (int) $data['hop'],
            (string) $data['occurred_at'],
        );
    }

    /** `*` matches everything; `a.b.*` matches `a.b.` and deeper; otherwise exact. */
    public function matches(string $pattern): bool
    {
        if ($pattern === '*') {
            return true;
        }
        if (str_ends_with($pattern, '.*')) {
            return str_starts_with($this->topic, substr($pattern, 0, -1));
        }

        return $this->topic === $pattern;
    }

    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'id' => $this->id,
            'topic' => $this->topic,
            'source' => $this->source,
            'payload' => $this->payload,
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
            'hop' => $this->hop,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
