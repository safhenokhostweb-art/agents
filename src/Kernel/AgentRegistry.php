<?php

declare(strict_types=1);

namespace Workspace\Agents\Kernel;

use RuntimeException;
use Workspace\Agents\Support\Json;

/**
 * Which agents exist, which task types they handle, and which bus topics
 * they subscribe to. Loaded from agents.json; tests register in-process.
 */
final class AgentRegistry
{
    public const SCHEMA = 'agents.registry/v1';

    /** @var array<string, Agent> */
    private array $agents = [];

    /** @var list<array{agent: string, topic: string, task: string}> */
    private array $subscriptions = [];

    public static function fromFile(string $registryFile): self
    {
        $config = Json::decodeFile($registryFile);
        if (($config['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException("Unsupported registry schema in {$registryFile}.");
        }
        $registry = new self();
        foreach ($config['agents'] ?? [] as $entry) {
            if (($entry['enabled'] ?? true) !== true) {
                continue;
            }
            $class = (string) ($entry['class'] ?? '');
            if (! class_exists($class) || ! is_subclass_of($class, Agent::class)) {
                throw new RuntimeException("Registry entry '{$class}' is not an Agent class.");
            }
            $agent = new $class();
            if (($entry['id'] ?? null) !== $agent->id()) {
                throw new RuntimeException("Registry id '".($entry['id'] ?? '')."' does not match {$class}::id() '{$agent->id()}'.");
            }
            $registry->register($agent, $entry['subscriptions'] ?? []);
        }

        return $registry;
    }

    /** @param list<array{topic: string, task: string}> $subscriptions */
    public function register(Agent $agent, array $subscriptions = []): void
    {
        $id = $agent->id();
        if (preg_match(Task::AGENT_PATTERN, $id) !== 1) {
            throw new RuntimeException("Agent id '{$id}' must be lowercase kebab-case.");
        }
        if (isset($this->agents[$id])) {
            throw new RuntimeException("Agent '{$id}' is already registered.");
        }
        foreach ($subscriptions as $subscription) {
            $task = (string) ($subscription['task'] ?? '');
            if (! in_array($task, $agent->handles(), true)) {
                throw new RuntimeException("Agent '{$id}' subscribes with task type '{$task}' it does not handle.");
            }
            $this->subscriptions[] = ['agent' => $id, 'topic' => (string) $subscription['topic'], 'task' => $task];
        }
        $this->agents[$id] = $agent;
    }

    public function get(string $id): Agent
    {
        return $this->agents[$id] ?? throw new ProtocolException('NO_AGENT', "No agent registered with id '{$id}'.");
    }

    /** Explicit target wins; otherwise exactly one agent must handle the type. */
    public function resolve(Task $task): Agent
    {
        if ($task->target !== null) {
            $agent = $this->get($task->target);
            if (! in_array($task->type, $agent->handles(), true)) {
                throw new ProtocolException('NO_AGENT', "Agent '{$task->target}' does not handle '{$task->type}'.");
            }

            return $agent;
        }
        $handlers = array_values(array_filter($this->agents, static fn (Agent $a): bool => in_array($task->type, $a->handles(), true)));
        if ($handlers === []) {
            throw new ProtocolException('NO_AGENT', "No agent handles task type '{$task->type}'.");
        }
        if (count($handlers) > 1) {
            $ids = implode(', ', array_map(static fn (Agent $a): string => $a->id(), $handlers));
            throw new ProtocolException('AMBIGUOUS_ROUTE', "Task type '{$task->type}' is handled by several agents ({$ids}); set task.target.");
        }

        return $handlers[0];
    }

    /** @return array<string, Agent> */
    public function all(): array
    {
        return $this->agents;
    }

    /** @return list<array{agent: string, topic: string, task: string}> */
    public function subscriptions(): array
    {
        return $this->subscriptions;
    }
}
