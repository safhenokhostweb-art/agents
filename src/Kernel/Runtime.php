<?php

declare(strict_types=1);

namespace Workspace\Agents\Kernel;

/**
 * Wires the standard runtime from workspace.json + agents.json: a journaled
 * bus, the artifact store, the registry, and the orchestrator.
 */
final class Runtime
{
    private function __construct(
        public readonly AgentContext $context,
        public readonly AgentRegistry $registry,
        public readonly MessageBus $bus,
        public readonly ArtifactStore $artifacts,
        public readonly Orchestrator $orchestrator,
        public readonly string $journalPath,
    ) {
    }

    public static function boot(string $workspaceFile, string $registryFile): self
    {
        $context = AgentContext::fromFile($workspaceFile);
        $settings = (array) $context->setting('runtime', []);
        $journal = $context->path((string) ($settings['journal'] ?? 'agents/var/bus/journal.jsonl'));
        $bus = new JournaledBus(new InMemoryBus(), $journal);
        $store = new ArtifactStore($context->path((string) ($settings['artifacts'] ?? 'agents/var/artifacts')));
        $registry = AgentRegistry::fromFile($registryFile);

        return new self($context, $registry, $bus, $store, new Orchestrator($registry, $bus, $store, $context), $journal);
    }
}
