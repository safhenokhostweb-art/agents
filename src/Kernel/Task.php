<?php

declare(strict_types=1);

namespace Workspace\Agents\Kernel;

use Workspace\Agents\Support\Id;

/**
 * The task contract: the only way work is handed to an agent.
 *
 * A task is addressed by `type` (routed to the single agent that handles it)
 * or explicitly by `target`. `correlation_id` ties together every task and
 * message of one logical run; `causation_id` names the task or message that
 * directly caused this one; `hop` counts causal depth and bounds loops.
 */
final class Task
{
    public const SCHEMA = 'agents.task/v1';
    public const NAME_PATTERN = '/^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)+$/';
    public const AGENT_PATTERN = '/^[a-z][a-z0-9-]*$/';

    private function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?string $target,
        public readonly array $payload,
        public readonly string $correlationId,
        public readonly ?string $causationId,
        public readonly int $hop,
        public readonly string $createdAt,
    ) {
        if (preg_match(self::NAME_PATTERN, $type) !== 1) {
            throw new ProtocolException('INVALID_TASK', "Task type '{$type}' must be dotted lowercase, e.g. deployment.readiness.");
        }
        if ($target !== null && preg_match(self::AGENT_PATTERN, $target) !== 1) {
            throw new ProtocolException('INVALID_TASK', "Task target '{$target}' is not a valid agent id.");
        }
        if ($hop < 0) {
            throw new ProtocolException('INVALID_TASK', 'Task hop must be >= 0.');
        }
    }

    public static function create(string $type, array $payload = [], ?string $target = null): self
    {
        $id = Id::new('task');

        return new self($id, $type, $target, $payload, $id, null, 0, gmdate('c'));
    }

    /** A follow-up task caused by this one, in the same correlation chain. */
    public function spawn(string $type, array $payload = [], ?string $target = null): self
    {
        return new self(Id::new('task'), $type, $target, $payload, $this->correlationId, $this->id, $this->hop + 1, gmdate('c'));
    }

    /** A task triggered by a subscription to a bus message. */
    public static function fromMessage(Message $message, string $type, string $target): self
    {
        return new self(Id::new('task'), $type, $target, $message->payload, $message->correlationId, $message->id, $message->hop + 1, gmdate('c'));
    }

    public static function fromArray(array $data): self
    {
        if (($data['schema'] ?? self::SCHEMA) !== self::SCHEMA) {
            throw new ProtocolException('INVALID_TASK', 'Unsupported task schema: '.json_encode($data['schema']));
        }
        foreach (['type' => 'is_string', 'payload' => 'is_array'] as $field => $check) {
            if (! isset($data[$field]) || ! $check($data[$field])) {
                throw new ProtocolException('INVALID_TASK', "Task field '{$field}' is missing or has the wrong type.");
            }
        }
        $id = isset($data['id']) && is_string($data['id']) && $data['id'] !== '' ? $data['id'] : Id::new('task');

        return new self(
            $id,
            $data['type'],
            isset($data['target']) ? (string) $data['target'] : null,
            $data['payload'],
            isset($data['correlation_id']) ? (string) $data['correlation_id'] : $id,
            isset($data['causation_id']) ? (string) $data['causation_id'] : null,
            (int) ($data['hop'] ?? 0),
            isset($data['created_at']) ? (string) $data['created_at'] : gmdate('c'),
        );
    }

    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'id' => $this->id,
            'type' => $this->type,
            'target' => $this->target,
            'payload' => $this->payload,
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
            'hop' => $this->hop,
            'created_at' => $this->createdAt,
        ];
    }
}
