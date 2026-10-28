<?php

declare(strict_types=1);

namespace Workspace\Agents\Kernel;

/** An agent's declared output, persisted by the orchestrator through ArtifactStore. */
final class Artifact
{
    /**
     * @param  array  $provenance  facts identifying the inputs (commits, paths) the payload was derived from
     * @param  array<string, array>  $attachments  file name => JSON document written beside the envelope
     * @param  string|null  $name  prefix of the artifact id (<name>-<timestamp>-<random>); defaults to the kind
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $payload,
        public readonly array $provenance = [],
        public readonly array $attachments = [],
        public readonly ?string $name = null,
    ) {
        if (preg_match(Task::AGENT_PATTERN, $kind) !== 1) {
            throw new ProtocolException('INVALID_ARTIFACT', "Artifact kind '{$kind}' must be lowercase kebab-case.");
        }
        if ($name !== null && preg_match(Task::AGENT_PATTERN, $name) !== 1) {
            throw new ProtocolException('INVALID_ARTIFACT', "Artifact name '{$name}' must be lowercase kebab-case.");
        }
        foreach (array_keys($attachments) as $name) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.json$/', (string) $name) !== 1) {
                throw new ProtocolException('INVALID_ARTIFACT', "Attachment name '{$name}' must be a plain .json file name.");
            }
        }
    }
}
