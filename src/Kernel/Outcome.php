<?php

declare(strict_types=1);

namespace Workspace\Agents\Kernel;

/** One executed task as recorded by the orchestrator. */
final class Outcome
{
    /** @param list<array{artifact_id: string, kind: string, path: string, digest: string}> $artifacts */
    public function __construct(
        public readonly Task $task,
        public readonly ?string $agentId,
        public readonly Result $result,
        public readonly int $durationMs,
        public readonly array $artifacts = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'task' => $this->task->toArray(),
            'agent' => $this->agentId,
            'duration_ms' => $this->durationMs,
            'result' => $this->result->toArray(),
            'artifacts' => $this->artifacts,
        ];
    }
}
