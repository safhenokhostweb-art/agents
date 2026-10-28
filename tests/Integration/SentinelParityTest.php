<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Integration;

use InvalidArgumentException;
use Throwable;
use Workspace\Agents\Agents\SentinelVerifier\EvidenceContract;
use Workspace\Agents\Agents\SentinelVerifier\EvidenceVerifier;
use Workspace\Agents\Agents\SentinelVerifier\FileManifest;
use Workspace\Agents\Agents\SentinelVerifier\RuntimeEvidenceContract;
use Workspace\Agents\Kernel\AgentContext;
use Workspace\Agents\Support\Json;
use Workspace\Agents\Tests\Support\WorkspaceFixture;
use Workspace\Agents\Tests\TestCase;

/**
 * The Sentinel core here is a port of deployment-system's; the Hub keeps
 * its own copy for the deployment page. This test runs both on the same
 * inputs so the two can never drift silently. It loads the Hub classes from
 * the workspace's deployment-system checkout (via workspace.json) and skips
 * when that checkout or its vendor/ is absent.
 */
final class SentinelParityTest extends TestCase
{
    private const EXPECTATION = [
        'project_key' => 'deployment-system',
        'environment' => 'staging',
        'release_id' => 'rel-0001',
        'deployment_id' => 'dep-0001',
        'commit_sha' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0',
        'artifact_checksum_sha256' => '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
    ];

    private string $deploymentSystem = '';

    public function setUp(): void
    {
        $context = AgentContext::fromFile(dirname(__DIR__, 2).'/workspace.json');
        $this->deploymentSystem = $context->path((string) ($context->setting('deployment_system')['path'] ?? 'deployment-system'));
        $autoload = $this->deploymentSystem.'/service/vendor/autoload.php';
        if (! is_file($autoload)) {
            $this->skip('deployment-system/service/vendor is not installed in this workspace');
        }
        require_once $autoload;
        if (! class_exists(\App\Services\Sentinel\SentinelVerifier::class)) {
            $this->skip('deployment-system has no Sentinel verifier');
        }
    }

    public function testVerifierVerdictsChecksAndEvidenceHashesAreIdenticalToTheHub(): void
    {
        $fixtures = $this->deploymentSystem.'/service/tests/Unit/Services/Sentinel/fixtures';
        $base = Json::decodeFile($fixtures.'/sentinel-verifiable.json');
        $variants = [
            'verifiable' => [$base, []],
            'incomplete fixture' => [Json::decodeFile($fixtures.'/sentinel-incomplete.json'), []],
            'wrong commit' => [['observed_commit_sha' => str_repeat('b', 40)] + $base, []],
            'wrong checksum' => [['observed_artifact_checksum_sha256' => str_repeat('c', 64)] + $base, []],
            'wrong marker' => [['runtime_marker' => 'release:x|commit:'.str_repeat('a', 40)] + $base, []],
            'unhealthy' => [['health_status' => 503] + $base, []],
            'recorded failure' => [['checks' => ['x' => ['status' => 'fail', 'detail' => 'd']]] + $base, []],
            'unsupported version' => [['evidence_version' => 'sentinel.v2'] + $base, []],
            'unparseable time' => [['verified_at' => 'yesterday-ish'] + $base, []],
            'uppercase hashes' => [['observed_commit_sha' => strtoupper($base['observed_commit_sha'])] + $base, []],
            'with file fields' => [['expected_file_manifest_hash' => str_repeat('d', 64), 'observed_file_manifest_hash' => str_repeat('d', 64)] + $base, []],
            'stale release' => [$base, ['release_id' => 'rel-0002']],
            'stale commit' => [$base, ['commit_sha' => str_repeat('e', 40)]],
            'other agent' => [$base, ['agent_id' => 'agent-9']],
            'other environment' => [$base, ['environment' => 'production']],
            'no release expected' => [$base, ['release_id' => null]],
            'path escape' => [['runtime_release_path' => 'releases/dep-0001/..'] + $base, []],
        ];
        $hub = new \App\Services\Sentinel\SentinelVerifier();
        $port = new EvidenceVerifier();
        foreach ($variants as $label => [$evidence, $override]) {
            $expectation = array_filter(array_replace(self::EXPECTATION, $override), static fn ($v) => $v !== null);
            $theirs = $hub->verifyArray($evidence, $expectation);
            $ours = $port->verify(EvidenceContract::fromArray($evidence), $expectation);
            $this->assertSame($theirs->result(), $ours['result'], "{$label}: verdict");
            $this->assertSame($theirs->checks(), $ours['checks'], "{$label}: checks");
            $this->assertSame($theirs->evidenceHash(), $ours['evidence_hash'], "{$label}: evidence hash");
        }
    }

    public function testContractsAcceptAndRefuseExactlyTheSameDocuments(): void
    {
        $base = Json::decodeFile($this->deploymentSystem.'/service/tests/Unit/Services/Sentinel/fixtures/sentinel-verifiable.json');
        $documents = [
            'unknown field' => ['cookie' => 'x'] + $base,
            'bearer token' => ['agent_id' => 'Bearer abcdefgh12345678'] + $base,
            'credentialed endpoint' => ['health_endpoint' => 'https://u:p@h.example/health?api_key=1'] + $base,
            'secret check id' => ['checks' => ['password_ok' => ['status' => 'pass']]] + $base,
            'bad check status' => ['checks' => ['x' => ['status' => 'PASS']]] + $base,
            'string status' => ['health_status' => '200'] + $base,
            'blank strings' => ['agent_id' => '  ', 'artifact_id' => ''] + $base,
        ];
        foreach ($documents as $label => $document) {
            $theirs = $this->attempt(fn () => \App\Services\Sentinel\SentinelEvidenceContract::fromArray($document)->hash());
            $ours = $this->attempt(fn () => EvidenceContract::fromArray($document)->hash());
            $this->assertSame($theirs, $ours, "sentinel.v1 {$label}");
        }

        $snapshot = [
            'evidence_version' => 'sentinel.runtime.v1', 'agent_id' => 'agent-1', 'agent_version' => '1.4.2',
            'project_key' => 'p', 'environment' => 'production', 'release_id' => 'rel-1', 'deployment_id' => 'dep-1',
            'current_release_path' => 'releases/dep-1', 'runtime_marker' => 'release:dep-1|commit:'.str_repeat('a', 40),
            'release_manifest_commit_sha' => str_repeat('a', 40), 'timestamp' => '2026-10-27T10:00:00+00:00',
            'sequence' => 1761559200, 'nonce' => str_repeat('f', 32), 'file_observation_status' => 'disabled',
        ];
        $snapshots = [
            'complete' => $snapshot,
            'derived incomplete' => array_diff_key($snapshot, ['nonce' => 1]),
            'traversal' => ['current_release_path' => '../etc'] + $snapshot,
            'absolute' => ['current_release_path' => 'C:/www/releases/dep-1'] + $snapshot,
            'zero sequence' => ['sequence' => 0] + $snapshot,
            'token value' => ['nonce' => 'token=abc123'] + $snapshot,
        ];
        foreach ($snapshots as $label => $document) {
            $theirs = $this->attempt(fn () => \App\Services\Sentinel\SentinelRuntimeEvidenceContract::fromArray($document)->hash());
            $ours = $this->attempt(fn () => RuntimeEvidenceContract::fromArray($document)->hash());
            $this->assertSame($theirs, $ours, "sentinel.runtime.v1 {$label}");
        }
    }

    public function testFileManifestHashIsTheServerAgentsArtifactManifestHash(): void
    {
        $source = $this->deploymentSystem.'/agent/lib/ArtifactFileManifest.php';
        if (! is_file($source)) {
            $this->skip('deployment-system agent/lib/ArtifactFileManifest.php is absent');
        }
        require_once $source;
        $dir = str_replace('\\', '/', sys_get_temp_dir()).'/agents-manifest-'.bin2hex(random_bytes(4));
        try {
            $files = ['.env.example' => "APP_ENV=production\n", 'public/index.php' => '<?php', 'a/b/c.txt' => str_repeat('x', 1000), '.deployment/release-manifest.json' => '{}'];
            $map = [];
            foreach ($files as $relative => $content) {
                @mkdir(dirname($dir.'/'.$relative), 0777, true);
                file_put_contents($dir.'/'.$relative, $content);
                $map[$relative] = $dir.'/'.$relative;
            }
            $theirs = \DeploymentAgent\ArtifactFileManifest::buildFromFiles($map);
            $ours = FileManifest::observe($dir, [], 100, 1 << 20)['manifest'];
            $this->assertSame($theirs->manifestHash(), $ours->hash());
            $this->assertSame($theirs->entries(), $ours->entries());
            $this->assertSame($theirs->manifestHash(), FileManifest::fromJson($theirs->toJson())->hash(), 'reads the manifest the artifact ships');
        } finally {
            WorkspaceFixture::remove($dir);
        }
    }

    /** The hash on success, or the exception class on refusal. */
    private function attempt(callable $build): string
    {
        try {
            return $build();
        } catch (InvalidArgumentException) {
            return 'refused';
        } catch (Throwable $other) {
            return $other::class;
        }
    }
}
