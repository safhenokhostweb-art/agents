<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Support;

use Closure;
use Workspace\Agents\Kernel\AbstractAgent;
use Workspace\Agents\Kernel\AgentContext;
use Workspace\Agents\Kernel\Result;
use Workspace\Agents\Kernel\Task;

/** An agent whose behaviour is a closure; records every task it receives. */
final class ScriptedAgent extends AbstractAgent
{
    /** @var list<Task> */
    public array $received = [];

    /**
     * @param  list<string>  $handles
     * @param  array<string, string>|null  $schema  null accepts any payload
     */
    public function __construct(
        private readonly string $agentId,
        private readonly array $handles,
        private readonly ?Closure $behaviour = null,
        private readonly ?array $schema = null,
    ) {
    }

    public function id(): string
    {
        return $this->agentId;
    }

    public function handles(): array
    {
        return $this->handles;
    }

    protected function payloadSchema(string $taskType): ?array
    {
        return $this->schema;
    }

    protected function perform(Task $task, AgentContext $context): Result
    {
        $this->received[] = $task;

        return $this->behaviour === null ? Result::pass("{$this->agentId} handled {$task->type}") : ($this->behaviour)($task, $context);
    }
}
