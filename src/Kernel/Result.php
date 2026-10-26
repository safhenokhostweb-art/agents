<?php

declare(strict_types=1);

namespace Workspace\Agents\Kernel;

/**
 * What an agent returns. Agents never touch the bus or the artifact store
 * directly: they declare artifacts, events, and follow-up tasks here, and the
 * orchestrator performs those side effects with correct provenance.
 */
final class Result
{
    /**
     * @param  list<Finding>  $findings
     * @param  list<Artifact>  $artifacts
     * @param  list<array{topic: string, payload: array}>  $events
     * @param  list<Task>  $followUps
     */
    private function __construct(
        public readonly Status $status,
        public readonly string $summary,
        public readonly array $findings = [],
        public readonly array $artifacts = [],
        public readonly array $events = [],
        public readonly array $followUps = [],
        public readonly array $data = [],
        public readonly ?string $error = null,
    ) {
    }

    /**
     * @param  list<Finding>  $findings
     * @param  list<Artifact>  $artifacts
     * @param  list<array{topic: string, payload: array}>  $events
     * @param  list<Task>  $followUps
     */
    public static function pass(string $summary, array $findings = [], array $artifacts = [], array $events = [], array $followUps = [], array $data = []): self
    {
        return new self(Status::Pass, $summary, $findings, $artifacts, $events, $followUps, $data);
    }

    /** @param list<Finding> $findings */
    public static function blocked(string $summary, array $findings = []): self
    {
        return new self(Status::Blocked, $summary, $findings);
    }

    public static function failed(string $summary, string $error): self
    {
        return new self(Status::Failed, $summary, error: $error);
    }

    public function highestSeverity(): ?Severity
    {
        $highest = null;
        foreach ($this->findings as $finding) {
            if ($highest === null || $finding->severity->rank() > $highest->rank()) {
                $highest = $finding->severity;
            }
        }

        return $highest;
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'summary' => $this->summary,
            'findings' => array_map(static fn (Finding $f): array => $f->toArray(), $this->findings),
            'data' => $this->data,
            'error' => $this->error,
        ];
    }
}
