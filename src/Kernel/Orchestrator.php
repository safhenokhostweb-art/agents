<?php

declare(strict_types=1);

namespace Workspace\Agents\Kernel;

use LogicException;
use Throwable;
use Workspace\Agents\Support\Id;

/**
 * Runs tasks and performs every side effect agents declare.
 *
 * For each task it publishes, in order: task.started, artifact.created (per
 * artifact), the agent's own events, then task.completed — or task.rejected
 * when no agent can take the task. Subscriptions turn bus messages into new
 * tasks for the subscribing agent; follow-up tasks and subscription tasks run
 * breadth-first in the same dispatch until the queue drains. `hop` bounds
 * the causal depth so two agents can never ping-pong forever.
 */
final class Orchestrator
{
    public const SOURCE = 'orchestrator';

    /** Source of messages published into the runtime from outside it. */
    public const EXTERNAL = 'external';

    /** @var list<Task> */
    private array $queue = [];

    private bool $running = false;

    public function __construct(
        private readonly AgentRegistry $registry,
        private readonly MessageBus $bus,
        private readonly ArtifactStore $artifacts,
        private readonly AgentContext $context,
        private readonly int $maxHops = 8,
    ) {
        foreach ($registry->subscriptions() as $subscription) {
            $bus->subscribe($subscription['topic'], function (Message $message) use ($subscription): void {
                // An agent never triggers itself; that loop is always a bug.
                if ($message->source !== $subscription['agent']) {
                    $this->queue[] = Task::fromMessage($message, $subscription['task'], $subscription['agent']);
                }
            });
        }
    }

    /** @return list<Outcome> every task executed, the dispatched task first */
    public function dispatch(Task $task): array
    {
        return $this->run(function () use ($task): void {
            $this->queue = [$task];
        });
    }

    /**
     * Publish a message from outside the runtime (e.g. deployment-system
     * announcing deployment.completed) and run every task its subscribers
     * derive from it, breadth-first, exactly as for an agent's own events.
     *
     * @return list<Outcome> every task executed; empty when nothing subscribes
     */
    public function publish(string $topic, array $payload, string $source = self::EXTERNAL): array
    {
        $message = Message::create($topic, $payload, $source, Id::new('event'));

        return $this->run(function () use ($message): void {
            $this->bus->publish($message);
        });
    }

    /** @return list<Outcome> */
    private function run(callable $seed): array
    {
        if ($this->running) {
            throw new LogicException('Orchestrator is not re-entrant; return follow-up tasks from the Result instead.');
        }
        $this->running = true;
        $this->queue = [];
        $outcomes = [];
        try {
            $seed();
            while (($next = array_shift($this->queue)) !== null) {
                $outcomes[] = $this->execute($next);
            }
        } finally {
            $this->running = false;
            $this->queue = [];
        }

        return $outcomes;
    }

    private function execute(Task $task): Outcome
    {
        if ($task->hop > $this->maxHops) {
            return $this->reject($task, 'HOP_LIMIT', "Task {$task->type} exceeded the causal hop limit of {$this->maxHops}.");
        }
        try {
            $agent = $this->registry->resolve($task);
        } catch (ProtocolException $exception) {
            return $this->reject($task, $exception->reason, $exception->getMessage());
        }

        $this->emit($task, 'task.started', ['task_type' => $task->type, 'agent' => $agent->id()], self::SOURCE);
        $started = hrtime(true);
        try {
            $result = $agent->handle($task, $this->context);
        } catch (Throwable $exception) {
            $result = Result::failed("Agent {$agent->id()} violated the contract by throwing.", $exception::class.': '.$exception->getMessage());
        }

        $refs = [];
        if ($result->status === Status::Pass) {
            try {
                foreach ($result->artifacts as $artifact) {
                    $ref = $this->artifacts->persist($artifact, $task, $agent);
                    $refs[] = $ref;
                    $this->emit($task, 'artifact.created', $ref + ['agent' => $agent->id()], self::SOURCE);
                }
                foreach ($result->events as $event) {
                    $this->emit($task, $event['topic'], $event['payload'], $agent->id());
                }
                foreach ($result->followUps as $followUp) {
                    $this->queue[] = $followUp;
                }
            } catch (Throwable $exception) {
                $result = Result::failed("Side effects of {$agent->id()} could not be applied.", $exception::class.': '.$exception->getMessage());
            }
        }
        $duration = (int) round((hrtime(true) - $started) / 1e6);

        $counts = ['info' => 0, 'warning' => 0, 'blocker' => 0];
        foreach ($result->findings as $finding) {
            $counts[$finding->severity->value]++;
        }
        $this->emit($task, 'task.completed', [
            'task_type' => $task->type,
            'agent' => $agent->id(),
            'status' => $result->status->value,
            'summary' => $result->summary,
            'findings' => $counts,
            'artifacts' => $refs,
            'duration_ms' => $duration,
            'error' => $result->error,
        ], self::SOURCE);

        return new Outcome($task, $agent->id(), $result, $duration, $refs);
    }

    private function reject(Task $task, string $reason, string $message): Outcome
    {
        $this->emit($task, 'task.rejected', ['task_type' => $task->type, 'reason' => $reason, 'message' => $message], self::SOURCE);

        return new Outcome($task, null, Result::blocked($message, [Finding::blocker($reason, $message)]), 0);
    }

    private function emit(Task $task, string $topic, array $payload, string $source): void
    {
        $this->bus->publish(Message::create($topic, $payload, $source, $task->correlationId, $task->id, $task->hop));
    }
}
