<?php

declare(strict_types=1);

namespace Workspace\Agents\Kernel;

use Throwable;

/**
 * Base class for agents. Subclasses implement perform(); this class enforces
 * the contract around it: task-type check, payload validation, and turning
 * every exception into a BLOCKED (precondition) or FAILED (defect) result.
 */
abstract class AbstractAgent implements Agent
{
    public function version(): string
    {
        return '1.0.0';
    }

    /**
     * Payload contract per task type: key => type, where type is one of
     * string|int|bool|array|list, prefixed with `?` when optional. Keys not
     * declared are rejected, so a mistyped option never passes silently.
     * Return null only for agents that accept arbitrary payloads (e.g. ones
     * fed by subscriptions to events whose payload they merely forward).
     *
     * @return array<string, string>|null
     */
    protected function payloadSchema(string $taskType): ?array
    {
        return [];
    }

    abstract protected function perform(Task $task, AgentContext $context): Result;

    final public function handle(Task $task, AgentContext $context): Result
    {
        try {
            if (! in_array($task->type, $this->handles(), true)) {
                throw new ProtocolException('UNSUPPORTED_TASK', "Agent {$this->id()} does not handle task type {$task->type}.");
            }
            $this->validatePayload($task);

            return $this->perform($task, $context);
        } catch (ProtocolException $exception) {
            return Result::blocked($exception->getMessage(), [Finding::blocker($exception->reason, $exception->getMessage(), $this->id())]);
        } catch (Throwable $exception) {
            return Result::failed("Agent {$this->id()} failed.", $exception::class.': '.$exception->getMessage());
        }
    }

    private function validatePayload(Task $task): void
    {
        $schema = $this->payloadSchema($task->type);
        if ($schema === null) {
            return;
        }
        foreach (array_keys($task->payload) as $key) {
            if (! array_key_exists($key, $schema)) {
                throw new ProtocolException('INVALID_PAYLOAD', "Unknown payload key '{$key}' for {$task->type}. Allowed: ".implode(', ', array_keys($schema)));
            }
        }
        foreach ($schema as $key => $type) {
            $optional = str_starts_with($type, '?');
            $type = ltrim($type, '?');
            if (! array_key_exists($key, $task->payload) || $task->payload[$key] === null) {
                if (! $optional) {
                    throw new ProtocolException('INVALID_PAYLOAD', "Payload key '{$key}' is required for {$task->type}.");
                }
                continue;
            }
            $value = $task->payload[$key];
            $valid = match ($type) {
                'string' => is_string($value),
                'int' => is_int($value),
                'bool' => is_bool($value),
                'array' => is_array($value),
                'list' => is_array($value) && array_is_list($value),
                default => false,
            };
            if (! $valid) {
                throw new ProtocolException('INVALID_PAYLOAD', "Payload key '{$key}' must be of type {$type}.");
            }
        }
    }
}
