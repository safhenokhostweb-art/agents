<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Support;

use Workspace\Agents\Agents\SentinelVerifier\FileManifest;
use Workspace\Agents\Agents\SentinelVerifier\SentinelVerifierAgent;
use Workspace\Agents\Kernel\AgentContext;
use Workspace\Agents\Kernel\AgentRegistry;
use Workspace\Agents\Kernel\ArtifactStore;
use Workspace\Agents\Kernel\InMemoryBus;
use Workspace\Agents\Kernel\Orchestrator;
use Workspace\Agents\Support\Json;

/**
 * A disposable workspace holding one coherent deployment as the Hub and a
 * Server Agent would describe it: the active release directory, the
 * artifact's file manifest, a sentinel.v1 evidence document and a
 * sentinel.runtime.v1 snapshot. Tests tamper with one piece at a time.
 */
final class SentinelFixture
{
    public const COMMIT = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0';
    public const CHECKSUM = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';
    public const PROJECT = 'customer-site';
    public const ENVIRONMENT = 'production';
    public const RELEASE = 'rel-0001';
    public const DEPLOYMENT = 'dep-0001';
    public const AGENT = 'agent-0001';
    public const MARKER = 'release:'.self::DEPLOYMENT.'|commit:'.self::COMMIT;

    public const FILES = [
        'public/index.php' => "<?php echo 'ok';\n",
        'app/Http/Kernel.php' => "<?php // kernel\n",
        'composer.json' => "{\"name\":\"acme/site\"}\n",
        '.deployment/release-manifest.json' => '{"format":"deployment-system-release-manifest","version":1,"commit_sha":"'.self::COMMIT.'"}',
    ];

    public readonly string $root;

    public readonly InMemoryBus $bus;

    public string $manifestHash = '';

    public function __construct()
    {
        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/agents-sentinel-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/release', 0777, true);
        $this->writeJson('workspace.json', [
            'schema' => AgentContext::SCHEMA,
            'projects' => ['site' => ['path' => '.']],
            'runtime' => ['artifacts' => 'var/artifacts', 'journal' => 'var/bus/journal.jsonl'],
        ]);
        $this->bus = new InMemoryBus();
        $this->release(self::FILES);
        $this->evidence();
        $this->snapshot();
    }

    /** Lays out the release and writes the artifact manifest that describes it. */
    public function release(array $files): void
    {
        $entries = [];
        foreach ($files as $path => $content) {
            $this->writeFile('release/'.$path, $content);
            $entries[$path] = ['size' => strlen($content), 'sha256' => hash('sha256', $content)];
        }
        $this->writeFile('release/.deployment/runtime-marker', self::MARKER."\n");
        $manifest = FileManifest::fromEntries($entries);
        $this->manifestHash = $manifest->hash();
        ksort($entries, SORT_STRING);
        $this->writeJson('expected-manifest.json', ['entries' => $entries, 'format' => FileManifest::FORMAT, 'version' => FileManifest::FORMAT_VERSION, 'manifest_hash' => $this->manifestHash]);
    }

    public function evidence(array $overrides = [], array $remove = []): void
    {
        $evidence = array_replace([
            'evidence_version' => 'sentinel.v1',
            'project_key' => self::PROJECT,
            'environment' => self::ENVIRONMENT,
            'expected_commit_sha' => self::COMMIT,
            'observed_commit_sha' => self::COMMIT,
            'artifact_id' => 'art-0001',
            'artifact_checksum_sha256' => self::CHECKSUM,
            'observed_artifact_checksum_sha256' => self::CHECKSUM,
            'release_id' => self::RELEASE,
            'observed_release_id' => self::RELEASE,
            'agent_id' => self::AGENT,
            'agent_version' => '1.4.3',
            'runtime_release_path' => 'releases/'.self::DEPLOYMENT,
            'expected_runtime_marker' => self::MARKER,
            'runtime_marker' => self::MARKER,
            'health_endpoint' => 'https://site.example.com/health',
            'expected_health_status' => 200,
            'health_status' => 200,
            'checks' => [
                'release_available' => ['status' => 'pass', 'detail' => 'expected release resolved from authoritative records.'],
                'freshness' => ['status' => 'pass', 'detail' => 'runtime observation is fresh (12s old).'],
            ],
            'verified_at' => gmdate('c'),
            'result' => null,
            'expected_file_manifest_hash' => $this->manifestHash,
            'observed_file_manifest_hash' => $this->manifestHash,
        ], $overrides);
        foreach ($remove as $field) {
            unset($evidence[$field]);
        }
        $this->writeJson('evidence.json', $evidence);
    }

    public function snapshot(array $overrides = [], array $remove = []): void
    {
        $snapshot = array_replace([
            'evidence_version' => 'sentinel.runtime.v1',
            'agent_id' => self::AGENT,
            'agent_version' => '1.4.3',
            'project_key' => self::PROJECT,
            'environment' => self::ENVIRONMENT,
            'release_id' => self::RELEASE,
            'deployment_id' => self::DEPLOYMENT,
            'current_release_path' => 'releases/'.self::DEPLOYMENT,
            'runtime_marker' => self::MARKER,
            'release_manifest_commit_sha' => self::COMMIT,
            'timestamp' => gmdate('c', time() - 12),
            'sequence' => 1761600000,
            'nonce' => bin2hex(random_bytes(16)),
            'snapshot_status' => 'complete',
            'file_manifest_hash' => $this->manifestHash,
            'file_manifest_entry_count' => count(self::FILES),
            'file_manifest_total_size' => array_sum(array_map('strlen', self::FILES)),
            'file_observation_status' => 'verified',
        ], $overrides);
        foreach ($remove as $field) {
            unset($snapshot[$field]);
        }
        $this->writeJson('snapshot.json', $snapshot);
    }

    /** The task payload a deployment pipeline would send for this deployment. */
    public function payload(array $overrides = [], array $remove = []): array
    {
        $payload = array_replace([
            'evidence' => 'evidence.json',
            'snapshot' => 'snapshot.json',
            'expected_manifest' => 'expected-manifest.json',
            'release_dir' => 'release',
            'project_key' => self::PROJECT,
            'environment' => self::ENVIRONMENT,
            'release_id' => self::RELEASE,
            'deployment_id' => self::DEPLOYMENT,
            'commit_sha' => self::COMMIT,
            'artifact_checksum_sha256' => self::CHECKSUM,
            'agent_id' => self::AGENT,
        ], $overrides);

        return array_diff_key($payload, array_flip($remove));
    }

    /** An orchestrator wired with the sentinel-verifier subscriptions exactly as the shipped agents.json declares them. */
    public function orchestrator(): Orchestrator
    {
        $registry = new AgentRegistry();
        $shipped = Json::decodeFile(dirname(__DIR__, 2).'/agents.json');
        $entry = array_values(array_filter($shipped['agents'], static fn (array $a): bool => $a['id'] === SentinelVerifierAgent::ID))[0];
        $registry->register(new SentinelVerifierAgent(), $entry['subscriptions']);

        return new Orchestrator($registry, $this->bus, new ArtifactStore($this->root.'/var/artifacts'), AgentContext::fromFile($this->root.'/workspace.json'));
    }

    public function writeFile(string $relative, string $content): void
    {
        $path = $this->root.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    public function writeJson(string $relative, array $document): void
    {
        $this->writeFile($relative, Json::encode($document));
    }

    public function destroy(): void
    {
        WorkspaceFixture::remove($this->root);
    }
}
