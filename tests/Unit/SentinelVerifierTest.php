<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use Workspace\Agents\Agents\SentinelVerifier\Checks;
use Workspace\Agents\Agents\SentinelVerifier\EvidenceContract;
use Workspace\Agents\Agents\SentinelVerifier\EvidenceGuard;
use Workspace\Agents\Agents\SentinelVerifier\EvidenceVerifier;
use Workspace\Agents\Agents\SentinelVerifier\FileManifest;
use Workspace\Agents\Agents\SentinelVerifier\FileManifestComparison;
use Workspace\Agents\Agents\SentinelVerifier\RuntimeEvidenceContract;
use Workspace\Agents\Agents\SentinelVerifier\RuntimeSnapshotInspector;
use Workspace\Agents\Support\Json;
use Workspace\Agents\Tests\Support\SentinelFixture;
use Workspace\Agents\Tests\TestCase;

final class SentinelVerifierTest extends TestCase
{
    private SentinelFixture $fixture;

    public function setUp(): void
    {
        $this->fixture = new SentinelFixture();
    }

    public function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function testAggregationIsFailClosed(): void
    {
        $this->assertSame(Checks::VERIFIED, Checks::aggregate(['a' => Checks::pass('x'), 'b' => Checks::pass('y')]));
        $this->assertSame(Checks::INDETERMINATE, Checks::aggregate(['a' => Checks::pass('x'), 'b' => Checks::unknown('y')]));
        $this->assertSame(Checks::FAILED, Checks::aggregate(['a' => Checks::unknown('x'), 'b' => Checks::fail('y')]));
        $this->assertSame(Checks::INDETERMINATE, Checks::aggregate([]), 'no evidence is never a pass');
        $this->assertSame(Checks::INDETERMINATE, Checks::aggregate(['a' => ['status' => 'PASS']]), 'statuses are exact');
        $this->assertSame(Checks::FAILED, Checks::combine(Checks::VERIFIED, Checks::INDETERMINATE, Checks::FAILED));
        $this->assertSame(Checks::INDETERMINATE, Checks::combine());
    }

    public function testEvidenceContractIsCanonicalAndRefusesSecretsAndUnknownFields(): void
    {
        $document = $this->evidence();
        $shuffled = array_reverse($document, true);
        $this->assertSame(EvidenceContract::fromArray($document)->hash(), EvidenceContract::fromArray($shuffled)->hash());
        $this->assertSame(64, strlen(EvidenceContract::fromArray($document)->hash()));
        $this->assertTrue(EvidenceContract::fromArray($document)->hash() !== EvidenceContract::fromArray(['health_status' => 503] + $document)->hash());

        $clean = EvidenceContract::fromArray(['health_endpoint' => 'https://user:pass@site.example.com/health?key=1#x'] + $document);
        $this->assertSame('https://site.example.com/health', $clean->get('health_endpoint'));

        $this->assertThrows(InvalidArgumentException::class, fn () => EvidenceContract::fromArray(['cookie' => 'x'] + $document), 'not part of the contract schema');
        $this->assertThrows(InvalidArgumentException::class, fn () => EvidenceContract::fromArray(['agent_id' => 'Bearer abcdefghijkl'] + $document), 'credential-shaped');
        $this->assertThrows(InvalidArgumentException::class, fn () => EvidenceContract::fromArray(['checks' => ['api_key' => ['status' => 'pass']]] + $document), 'credential-bearing');
        $this->assertThrows(InvalidArgumentException::class, fn () => EvidenceContract::fromArray(['checks' => ['x' => ['status' => 'ok']]] + $document), 'pass, fail or unknown');
        $this->assertThrows(InvalidArgumentException::class, fn () => EvidenceContract::fromJson('[1,2]'), 'JSON object');
    }

    public function testRuntimeContractRejectsPathEscapesAndDerivesCompleteness(): void
    {
        $snapshot = Json::decodeFile($this->fixture->root.'/snapshot.json');
        foreach (['../releases/x', '/var/www/releases/x', 'C:\\releases\\x', '\\\\host\\share'] as $path) {
            $this->assertThrows(InvalidArgumentException::class, fn () => RuntimeEvidenceContract::fromArray(['current_release_path' => $path] + $snapshot), 'path traversal');
        }
        unset($snapshot['snapshot_status']);
        $this->assertTrue(RuntimeEvidenceContract::fromArray($snapshot)->isComplete());
        unset($snapshot['nonce']);
        $this->assertFalse(RuntimeEvidenceContract::fromArray($snapshot)->isComplete());
        $this->assertThrows(InvalidArgumentException::class, fn () => RuntimeEvidenceContract::fromArray(['sequence' => 0] + $snapshot), 'positive integer');
    }

    public function testVerifierJudgesEveryLinkOfTheChain(): void
    {
        $verifier = new EvidenceVerifier();
        $expectation = ['release_id' => SentinelFixture::RELEASE, 'deployment_id' => SentinelFixture::DEPLOYMENT, 'project_key' => SentinelFixture::PROJECT, 'environment' => SentinelFixture::ENVIRONMENT];
        $this->assertSame(Checks::VERIFIED, $verifier->verify(EvidenceContract::fromArray($this->evidence()), $expectation)['result']);

        $cases = [
            'artifact_checksum_match' => [['observed_artifact_checksum_sha256' => str_repeat('0', 64)], [], Checks::FAILED],
            'runtime_marker_match' => [['runtime_marker' => 'release:dep-9|commit:'.SentinelFixture::COMMIT], [], Checks::FAILED],
            'health_response' => [['health_status' => 502], [], Checks::FAILED],
            'runtime_release_path' => [['runtime_release_path' => 'releases/dep-0001/../dep-0002'], [], Checks::FAILED],
            'recorded_checks' => [['checks' => ['freshness' => ['status' => 'unknown']]], [], Checks::INDETERMINATE],
            'release_identity' => [[], ['release_id' => 'rel-0002'], Checks::FAILED],
            'expectation_alignment' => [[], ['environment' => 'staging'], Checks::FAILED],
            'agent_identity' => [[], ['agent_id' => 'agent-9'], Checks::FAILED],
            'evidence_version' => [['evidence_version' => 'sentinel.v0'], [], Checks::INDETERMINATE],
        ];
        foreach ($cases as $check => [$evidence, $wanted, $verdict]) {
            $result = $verifier->verify(EvidenceContract::fromArray($evidence + $this->evidence()), $wanted + $expectation);
            $this->assertSame($verdict, $result['result'], $check);
            $this->assertTrue($result['checks'][$check]['status'] !== Checks::PASS, "{$check} must not pass");
        }
        $this->assertThrows(InvalidArgumentException::class, fn () => $verifier->verify(EvidenceContract::fromArray($this->evidence()), ['release' => 'x']), 'verification schema');
    }

    public function testFileVerificationFlagAddsTheManifestChecks(): void
    {
        $evidence = EvidenceContract::fromArray($this->evidence());
        $this->assertFalse(isset((new EvidenceVerifier())->verify($evidence, ['release_id' => SentinelFixture::RELEASE])['checks']['file_comparison']));

        $checks = (new EvidenceVerifier(true))->verify($evidence, ['release_id' => SentinelFixture::RELEASE, 'deployment_id' => SentinelFixture::DEPLOYMENT])['checks'];
        $this->assertSame(Checks::PASS, $checks['file_manifest_hash_match']['status']);
        // Without a recorded comparison only hash aggregates exist: per-file proof is impossible, as on the Hub.
        $this->assertSame(Checks::UNKNOWN, $checks['file_comparison']['status']);
    }

    public function testManifestSealDetectsTamperingAndMatchesAnObservedTree(): void
    {
        $json = (string) file_get_contents($this->fixture->root.'/expected-manifest.json');
        $manifest = FileManifest::fromJson($json);
        $this->assertSame($this->fixture->manifestHash, $manifest->hash());
        $this->assertSame(count(SentinelFixture::FILES), $manifest->entryCount());

        $observed = FileManifest::observe($this->fixture->root.'/release', ['.deployment/runtime-marker'], 100, 1 << 20);
        $this->assertSame($manifest->hash(), $observed['manifest']->hash());
        $this->assertSame(1, $observed['excluded']);

        $limited = FileManifest::observe($this->fixture->root.'/release', [], 2, 1 << 20);
        $this->assertTrue($limited['limit_exceeded']);

        $tampered = Json::decode($json);
        $tampered['entries']['composer.json']['size']++;
        $this->assertThrows(InvalidArgumentException::class, fn () => FileManifest::fromJson(Json::encode($tampered)), 'does not match canonical manifest content');
        $this->assertThrows(InvalidArgumentException::class, fn () => FileManifest::fromJson(Json::encode(['format' => FileManifest::FORMAT, 'version' => 1, 'entries' => ['../x' => ['size' => 1, 'sha256' => str_repeat('a', 64)]], 'manifest_hash' => str_repeat('a', 64)])), 'Invalid normalized path');
    }

    public function testComparisonOutcomesFollowTheHubPolicy(): void
    {
        $a = ['size' => 1, 'sha256' => str_repeat('a', 64)];
        $b = ['size' => 1, 'sha256' => str_repeat('b', 64)];
        $compare = static function (array $expected, array $observed, array $unreadable = [], array $exclude = []): FileManifestComparison {
            $e = FileManifest::fromEntries($expected);
            $o = FileManifest::fromEntries($observed);

            return new FileManifestComparison($expected, $observed, $e->hash(), $o->hash(), count($expected), count($observed), $e->totalSize(), $o->totalSize(), 'full', $unreadable, $exclude);
        };

        $this->assertSame('PASS', $compare(['x' => $a], ['x' => $a])->verdict());
        $this->assertSame('FAIL', $compare(['x' => $a, 'y' => $a], ['x' => $a])->verdict());
        $this->assertSame('FAIL', $compare(['x' => $a], ['x' => $b])->verdict());
        $this->assertSame('UNKNOWN', $compare(['x' => $a], ['x' => $a, 'z' => $a])->verdict(), 'EXTRA is policy-dependent');
        $this->assertSame('PASS', $compare(['x' => $a], ['x' => $a, 'logs/z' => $a], [], ['logs/**'])->verdict(), 'unless excluded');
        $this->assertSame('PASS', $compare(['x' => $a, 'cache/k' => $a], ['x' => $a], [], ['cache/**'])->verdict(), 'exclusions apply to both sides');
        $unreadable = $compare(['dir/x' => $a, 'y' => $a], ['y' => $a], ['dir']);
        $this->assertSame('UNKNOWN', $unreadable->verdict());
        $this->assertSame(['UNREADABLE', 'UNREADABLE'], array_column($unreadable->compare()['discrepancies'], 'outcome'));
        $this->assertSame('UNKNOWN', $compare([], [])->verdict(), 'an empty inventory proves nothing');
        $hashOnly = new FileManifestComparison([], [], str_repeat('a', 64), str_repeat('a', 64), 3, 3, 9, 9);
        $this->assertSame('UNKNOWN', $hashOnly->verdict(), 'hash aggregates alone are incomplete');
    }

    public function testSnapshotInspectorJudgesFreshnessAgainstTheReferenceTime(): void
    {
        $snapshot = RuntimeEvidenceContract::fromArray(['timestamp' => '2026-10-01T10:00:00+00:00'] + Json::decodeFile($this->fixture->root.'/snapshot.json'));
        $inspector = new RuntimeSnapshotInspector(300);
        $this->assertSame(Checks::PASS, $inspector->inspect($snapshot, [], new DateTimeImmutable('2026-10-01T10:04:00+00:00'))['freshness']['status']);
        $this->assertSame(Checks::UNKNOWN, $inspector->inspect($snapshot, [], new DateTimeImmutable('2026-10-01T10:06:00+00:00'))['freshness']['status']);
        $this->assertSame(Checks::UNKNOWN, $inspector->inspect($snapshot, [], new DateTimeImmutable('2026-10-01T09:58:00+00:00'))['freshness']['status'], 'future snapshots beyond the skew');
        $this->assertSame(Checks::UNKNOWN, $inspector->inspect($snapshot, [], new DateTimeImmutable('2026-10-01T10:01:00+00:00'))['attribution']['status'], 'no expected release, no attribution');
        $this->assertFalse(isset($inspector->inspect($snapshot, [], new DateTimeImmutable())['agent_version']), 'no floor unless one is requested');
    }

    public function testSensitivePathsAreRecognised(): void
    {
        foreach (['.env', 'config/.env.production', 'storage/oauth-private.key', 'certs/site.pem', 'auth.json', 'keys/id_ed25519', 'secrets.yml', 'app/Http/TokenGuard.php'] as $path) {
            $this->assertTrue(EvidenceGuard::isSensitivePath($path), $path);
        }
        foreach (['public/index.php', 'app/Models/Environment.php', 'resources/js/keyboard.js'] as $path) {
            $this->assertFalse(EvidenceGuard::isSensitivePath($path), $path);
        }
        $this->assertSame('the document contains a credential-shaped key (redacted).', EvidenceGuard::safeMessage("field 'ghp_abcdefghijklmnop' is not part of the contract schema."));
    }

    private function evidence(): array
    {
        return Json::decodeFile($this->fixture->root.'/evidence.json');
    }
}
