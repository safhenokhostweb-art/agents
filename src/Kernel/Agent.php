<?php

declare(strict_types=1);

namespace Workspace\Agents\Kernel;

interface Agent
{
    /** Stable kebab-case id used for routing, registry entries, and artifact provenance. */
    public function id(): string;

    /** Semantic version recorded in every artifact this agent produces. */
    public function version(): string;

    /** @return list<string> task types this agent accepts */
    public function handles(): array;

    /** Must not throw: every failure is expressed as a FAILED or BLOCKED Result. */
    public function handle(Task $task, AgentContext $context): Result;
}
