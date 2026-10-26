<?php

declare(strict_types=1);

namespace Workspace\Agents\Kernel;

use RuntimeException;
use Workspace\Agents\Support\Id;
use Workspace\Agents\Support\Json;

/**
 * Writes artifacts in the standard envelope:
 *
 *   <root>/<kind>/<artifact_id>/artifact.json
 *   <root>/<kind>/<artifact_id>/<attachment>.json
 *
 * The envelope digest is sha256 over the canonical JSON of the envelope
 * without its `integrity` block; each attachment's sha256 is recorded inside
 * the envelope, so one digest seals the whole artifact.
 */
final class ArtifactStore
{
    public const SCHEMA = 'agents.artifact/v1';

    public function __construct(private readonly string $root)
    {
    }

    /** @return array{artifact_id: string, kind: string, path: string, digest: string} */
    public function persist(Artifact $artifact, Task $task, Agent $producer): array
    {
        $id = Id::new($artifact->kind);
        $directory = $this->root.'/'.$artifact->kind.'/'.$id;
        if (! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create artifact directory: {$directory}");
        }

        $attachments = [];
        foreach ($artifact->attachments as $name => $document) {
            $bytes = Json::encode($document).PHP_EOL;
            $this->write($directory.'/'.$name, $bytes);
            $attachments[$name] = ['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)];
        }

        $envelope = [
            'schema' => self::SCHEMA,
            'artifact_id' => $id,
            'kind' => $artifact->kind,
            'producer' => ['agent' => $producer->id(), 'version' => $producer->version()],
            'task' => ['id' => $task->id, 'type' => $task->type, 'correlation_id' => $task->correlationId],
            'created_at' => gmdate('c'),
            'provenance' => $artifact->provenance,
            'payload' => $artifact->payload,
            'attachments' => $attachments,
        ];
        $digest = hash('sha256', Json::canonical($envelope));
        $envelope['integrity'] = ['algorithm' => 'sha256', 'scope' => 'canonical-envelope-without-integrity', 'digest' => $digest];
        $path = $directory.'/artifact.json';
        $this->write($path, Json::encode($envelope).PHP_EOL);

        return ['artifact_id' => $id, 'kind' => $artifact->kind, 'path' => $path, 'digest' => $digest];
    }

    /** @return array{valid: bool, errors: list<string>} */
    public static function verify(string $artifactPath): array
    {
        $errors = [];
        try {
            $envelope = Json::decodeFile($artifactPath);
        } catch (RuntimeException $exception) {
            return ['valid' => false, 'errors' => [$exception->getMessage()]];
        }
        if (($envelope['schema'] ?? null) !== self::SCHEMA) {
            $errors[] = 'Unknown artifact schema.';
        }
        $integrity = $envelope['integrity'] ?? [];
        unset($envelope['integrity']);
        if (($integrity['digest'] ?? null) !== hash('sha256', Json::canonical($envelope))) {
            $errors[] = 'Envelope digest mismatch: artifact.json was modified after it was sealed.';
        }
        foreach ($envelope['attachments'] ?? [] as $name => $meta) {
            $file = dirname($artifactPath).'/'.$name;
            if (! is_file($file)) {
                $errors[] = "Attachment missing: {$name}";
            } elseif (hash_file('sha256', $file) !== ($meta['sha256'] ?? null)) {
                $errors[] = "Attachment digest mismatch: {$name}";
            }
        }

        return ['valid' => $errors === [], 'errors' => $errors];
    }

    private function write(string $path, string $bytes): void
    {
        if (file_put_contents($path, $bytes, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write artifact file: {$path}");
        }
    }
}
