<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Integration;

use Workspace\Agents\Agents\SentinelVerifier\SentinelVerifierAgent;
use Workspace\Agents\Kernel\ArtifactStore;
use Workspace\Agents\Kernel\Outcome;
use Workspace\Agents\Kernel\Status;
use Workspace\Agents\Kernel\Task;
use Workspace\Agents\Support\Json;
use Workspace\Agents\Tests\Support\SentinelFixture;
use Workspace\Agents\Tests\TestCase;

final class SentinelVerifierAgentTest extends TestCase
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

    public function testCoherentDeploymentIsVerifiedAndSealedAsASentinelArtifact(): void
    {
        $outcome = $this->run($this->fixture->payload());

        $this->assertSame(Status::Pass, $outcome->result->status);
        $this->assertSame('VERIFIED', $outcome->result->data['sentinel_verdict']);
        $this->assertSame('READY', $outcome->result->data['verdict']);
        $this->assertSame([], $this->codes($outcome, 'warning'));
        $this->assertSame([], $this->codes($outcome, 'blocker'));

        $this->assertCount(1, $outcome->artifacts);
        $path = $outcome->artifacts[0]['path'];
        $this->assertTrue(preg_match('#/var/artifacts/sentinel/audit-\d{8}T\d{6}-[0-9a-f]{8}/artifact\.json$#', str_replace('\\', '/', $path)) === 1, 'audit reports are named audit-<timestamp>-<hex>');
        $this->assertTrue(ArtifactStore::verify($path)['valid']);

        $envelope = Json::decodeFile($path);
        $report = $envelope['payload'];
        $this->assertSame('sentinel.audit-report/v1', $report['schema']);
        $this->assertSame('PASSED', $report['status']);
        $this->assertSame('PASSED', $outcome->result->data['status']);
        $this->assertSame('VERIFIED', $report['sentinel_verdict']);
        $this->assertSame(SentinelFixture::COMMIT, $report['target']['commit_sha']);
        $this->assertSame(SentinelFixture::DEPLOYMENT, $report['target']['deployment_id']);
        $this->assertSame(['verdict' => 'VERIFIED', 'snapshot_status' => 'complete', 'agent_version' => '1.4.3', 'deployment_marker' => 'pass', 'snapshot_hash' => $report['evidence_hash']['snapshot']], $report['runtime_evidence']);
        $this->assertSame([], $report['non_conformant_files']);
        $this->assertSame(0, $report['non_conformant_total']);
        $this->assertTrue(preg_match('/^\d{4}-\d{2}-\d{2}T/', $report['checked_at']) === 1, 'report records when it was checked');
        $this->assertSame(64, strlen($report['evidence_hash']['evidence']));
        $this->assertSame(64, strlen($report['evidence_hash']['snapshot']));
        $this->assertSame(hash('sha256', (string) file_get_contents($this->fixture->root.'/evidence.json')), $report['evidence_hash']['inputs']['evidence']);
        $this->assertSame(['evidence', 'snapshot', 'agreement', 'files'], array_keys($report['sections']));
        $this->assertSame($this->fixture->manifestHash, $report['files']['observed_manifest_hash'], 'the runtime marker written at activation is excluded by default');
        $this->assertSame(['evidence.json', 'runtime-snapshot.json'], array_keys($envelope['attachments']));
        $this->assertSame('release', $envelope['provenance']['inputs']['release_dir']['path']);

        $this->assertSame(['task.started', 'artifact.created', SentinelVerifierAgent::EVENT_PASSED, 'task.completed'], $this->topics());
        $passed = $this->fixture->bus->history()[2];
        $this->assertSame(SentinelVerifierAgent::ID, $passed->source);
        $this->assertSame(SentinelFixture::RELEASE, $passed->payload['release_id']);
        $this->assertSame('PASSED', $passed->payload['status']);
        $this->assertSame($report['evidence_hash']['evidence'], $passed->payload['evidence_hash']);
    }

    public function testTamperedMissingAndExtraFilesAreListedAndFailTheVerdict(): void
    {
        $this->fixture->writeFile('release/public/index.php', "<?php echo 'pwned';\n");
        unlink($this->fixture->root.'/release/composer.json');
        $this->fixture->writeFile('release/public/shell.php', '<?php system($_GET["c"]);');
        $this->fixture->writeFile('release/.env.backup', 'APP_KEY=base64:xyz');

        $outcome = $this->run($this->fixture->payload());
        $report = $this->report($outcome);

        $this->assertSame('FAILED', $outcome->result->data['sentinel_verdict']);
        $this->assertSame('BLOCKED', $outcome->result->data['verdict']);
        $this->assertSame('FAILED', $report['status']);
        $this->assertSame(4, $report['non_conformant_total']);
        $byPath = array_column($report['non_conformant_files'], null, 'path');
        $this->assertSame('HASH_MISMATCH', $byPath['public/index.php']['outcome']);
        $this->assertSame(hash('sha256', SentinelFixture::FILES['public/index.php']), $byPath['public/index.php']['expected']['sha256']);
        $this->assertSame(hash('sha256', "<?php echo 'pwned';\n"), $byPath['public/index.php']['observed']['sha256']);
        $this->assertSame('MISSING', $byPath['composer.json']['outcome']);
        $this->assertSame('EXTRA', $byPath['public/shell.php']['outcome']);

        // A credential-like file is reported, but never by name or hash.
        $redacted = array_values(array_filter($report['non_conformant_files'], static fn (array $f): bool => ($f['redacted'] ?? false) === true));
        $this->assertCount(1, $redacted);
        $this->assertSame('EXTRA', $redacted[0]['outcome']);
        $this->assertFalse(str_contains((string) file_get_contents($outcome->artifacts[0]['path']), '.env.backup'));

        $failed = $this->finding($outcome, 'files.file_comparison');
        $this->assertSame('SENTINEL_CHECK_FAILED', $failed['code']);
        $this->assertSame(4, $failed['evidence']['non_conformant_total']);
        $this->assertContains(SentinelVerifierAgent::EVENT_FAILED, $this->topics());
        $this->assertNotContains(SentinelVerifierAgent::EVENT_PASSED, $this->topics());
    }

    public function testExtraFilesAloneAreUnknownUntilAnExclusionPolicyCoversThem(): void
    {
        $this->fixture->writeFile('release/storage/logs/laravel.log', 'log line');

        $unknown = $this->run($this->fixture->payload());
        $this->assertSame('UNKNOWN', $unknown->result->data['sentinel_verdict']);
        $this->assertSame('ATTENTION', $unknown->result->data['verdict']);
        $this->assertSame('SENTINEL_CHECK_UNKNOWN', $this->finding($unknown, 'files.file_comparison')['code']);

        $excluded = $this->run($this->fixture->payload(['exclude' => ['storage/**']]));
        $this->assertSame('VERIFIED', $excluded->result->data['sentinel_verdict']);
        $this->assertSame(['.deployment/runtime-marker', 'storage/**'], $this->envelope($excluded)['provenance']['options']['exclude']);
    }

    public function testStaleIncoherentOrForeignSnapshotsAreNeverAPass(): void
    {
        $this->fixture->snapshot(['timestamp' => gmdate('c', time() - 3600)]);
        $stale = $this->run($this->fixture->payload());
        $this->assertSame('UNKNOWN', $stale->result->data['sentinel_verdict']);
        $this->assertStringContains('stale', $this->finding($stale, 'snapshot.freshness')['message']);

        $this->fixture->snapshot(['runtime_marker' => 'release:dep-0002|commit:'.SentinelFixture::COMMIT]);
        $marker = $this->run($this->fixture->payload(['expected_manifest' => null, 'release_dir' => null]));
        $this->assertSame('FAILED', $marker->result->data['sentinel_verdict']);
        $this->assertStringContains('marker names deployment dep-0002', $this->finding($marker, 'snapshot.deployment_marker')['message']);
        $this->assertStringContains('runtime_marker', $this->finding($marker, 'agreement.evidence_snapshot_agreement')['message']);

        $this->fixture->snapshot(['release_id' => 'rel-0002']);
        $foreign = $this->run($this->fixture->payload(['expected_manifest' => null, 'release_dir' => null]));
        $this->assertSame('FAILED', $foreign->result->data['sentinel_verdict']);
        $this->assertStringContains('release_id', $this->finding($foreign, 'snapshot.attribution')['message']);

        $this->fixture->snapshot(['snapshot_status' => 'incomplete'], ['runtime_marker']);
        $incomplete = $this->run($this->fixture->payload(['expected_manifest' => null, 'release_dir' => null]));
        $this->assertSame('UNKNOWN', $incomplete->result->data['sentinel_verdict']);
        $this->assertSame('FAILED', $incomplete->result->data['status'], 'an incomplete heartbeat snapshot fails closed');
        $this->assertStringContains('runtime_marker', $this->finding($incomplete, 'snapshot.snapshot_complete')['message']);
        $runtime = $this->report($incomplete)['runtime_evidence'];
        $this->assertSame('incomplete', $runtime['snapshot_status']);
        $this->assertSame('UNKNOWN', $runtime['verdict']);
        $this->assertSame([SentinelVerifierAgent::EVENT_FAILED], array_slice($this->topics(), -2, 1));
    }

    public function testServerAgentVersionFloorIsEnforcedOnlyWhenRequested(): void
    {
        $this->fixture->snapshot(['agent_version' => '1.4.2']);
        $this->fixture->evidence(['agent_version' => '1.4.2']);
        $this->assertSame('VERIFIED', $this->run($this->fixture->payload())->result->data['sentinel_verdict']);

        $floor = $this->run($this->fixture->payload(['min_agent_version' => '1.4.3']));
        $this->assertSame('FAILED', $floor->result->data['sentinel_verdict']);
        $this->assertStringContains('1.4.2 is older than the required 1.4.3', $this->finding($floor, 'snapshot.agent_version')['message']);
    }

    public function testEvidenceVerdictIsTheHubVerifiersAndNeedsAnExpectedRelease(): void
    {
        $this->fixture->evidence(['observed_commit_sha' => str_repeat('b', 40)]);
        $wrong = $this->run($this->fixture->payload(['snapshot' => null, 'expected_manifest' => null, 'release_dir' => null]));
        $this->assertSame('FAILED', $wrong->result->data['sentinel_verdict']);
        $this->assertStringContains('does not match expected commit', $this->finding($wrong, 'evidence.commit_match')['message']);

        $this->fixture->evidence();
        $unattributed = $this->run($this->fixture->payload(['snapshot' => null, 'expected_manifest' => null, 'release_dir' => null], ['release_id']));
        $this->assertSame('UNKNOWN', $unattributed->result->data['sentinel_verdict']);
        $this->assertStringContains('cannot be attributed', $this->finding($unattributed, 'evidence.release_identity')['message']);
    }

    public function testSnapshotAloneIsReportedButCannotProveTheChain(): void
    {
        $outcome = $this->run($this->fixture->payload(['evidence' => null, 'expected_manifest' => null, 'release_dir' => null]));
        $report = $this->report($outcome);

        $this->assertSame('UNKNOWN', $outcome->result->data['sentinel_verdict']);
        $this->assertSame('VERIFIED', $report['sections']['snapshot']['verdict'], 'the snapshot itself is healthy');
        $this->assertSame('SENTINEL_CHECK_UNKNOWN', $this->finding($outcome, 'evidence.evidence_document')['code']);
    }

    public function testSecretBearingEvidenceIsRejectedWithoutLeakingTheSecret(): void
    {
        $secret = 'ghp_'.str_repeat('A1b2', 6);
        $this->fixture->evidence(['health_endpoint' => "https://site.example.com/health?token={$secret}"]);
        $sanitized = $this->run($this->fixture->payload(['snapshot' => null, 'expected_manifest' => null, 'release_dir' => null]));
        $this->assertSame('VERIFIED', $sanitized->result->data['sentinel_verdict'], 'query strings are stripped from the endpoint, as on the Hub');

        $this->fixture->evidence(['artifact_id' => $secret]);
        $rejected = $this->run($this->fixture->payload(['snapshot' => null, 'expected_manifest' => null, 'release_dir' => null]));
        $this->assertSame('UNKNOWN', $rejected->result->data['sentinel_verdict']);
        $this->assertStringContains('credential-shaped', $this->finding($rejected, 'evidence.evidence_contract')['message']);

        $this->fixture->writeJson('evidence.json', [$secret => 'x'] + ['evidence_version' => 'sentinel.v1']);
        $keyed = $this->run($this->fixture->payload(['snapshot' => null, 'expected_manifest' => null, 'release_dir' => null]));
        $this->assertStringContains('(redacted)', $this->finding($keyed, 'evidence.evidence_contract')['message']);

        foreach ([$rejected, $keyed] as $outcome) {
            $this->assertFalse(str_contains((string) file_get_contents($outcome->artifacts[0]['path']), $secret));
        }
        $this->assertFalse(str_contains(Json::encode(array_map(static fn ($m) => $m->payload, $this->fixture->bus->history())), $secret), 'nothing secret reaches the bus');
    }

    public function testManifestMustBeBoundToTheDeploymentAndUntampered(): void
    {
        $this->fixture->evidence([], ['expected_file_manifest_hash', 'observed_file_manifest_hash']);
        $this->fixture->snapshot([], ['file_manifest_hash']);
        $unbound = $this->run($this->fixture->payload());
        $this->assertSame('UNKNOWN', $unbound->result->data['sentinel_verdict']);
        $this->assertStringContains('not bound to this deployment', $this->finding($unbound, 'files.manifest_binding')['message']);

        $this->fixture->evidence(['expected_file_manifest_hash' => str_repeat('c', 64)]);
        $this->fixture->snapshot();
        $other = $this->run($this->fixture->payload(['snapshot' => null]));
        $this->assertSame('FAILED', $other->result->data['sentinel_verdict']);
        $this->assertSame('SENTINEL_CHECK_FAILED', $this->finding($other, 'files.manifest_binding')['code']);

        $manifest = Json::decodeFile($this->fixture->root.'/expected-manifest.json');
        $manifest['entries']['public/index.php']['size'] = 999;
        $this->fixture->writeJson('expected-manifest.json', $manifest);
        $tampered = $this->run($this->fixture->payload());
        $this->assertStringContains('does not match canonical manifest content', $this->finding($tampered, 'files.expected_manifest')['message']);
    }

    public function testObservedManifestCanStandInForTheReleaseDirectory(): void
    {
        copy($this->fixture->root.'/expected-manifest.json', $this->fixture->root.'/observed-manifest.json');
        $outcome = $this->run($this->fixture->payload(['release_dir' => null, 'observed_manifest' => 'observed-manifest.json']));
        $this->assertSame('VERIFIED', $outcome->result->data['sentinel_verdict']);
    }

    public function testInvalidRequestsAreBlockedBeforeAnythingIsEvaluated(): void
    {
        $cases = [
            'EVIDENCE_MISSING' => ['release_id' => 'r'],
            'INVALID_PAYLOAD' => $this->fixture->payload(['expected_manifest' => null]),
            'INPUT_UNREADABLE' => $this->fixture->payload(['evidence' => 'nope.json']),
        ];
        $cases['INVALID_PAYLOAD (unknown key)'] = $this->fixture->payload(['evidense' => 'evidence.json']);
        $cases['INVALID_PAYLOAD (not json)'] = $this->fixture->payload(['evidence' => 'release/public/index.php']);
        $cases['INVALID_PAYLOAD (traversing exclude)'] = $this->fixture->payload(['exclude' => ['../secrets/**']]);
        $cases['INVALID_PAYLOAD (version)'] = $this->fixture->payload(['min_agent_version' => 'latest']);
        $cases['INVALID_PAYLOAD (empty expectation)'] = $this->fixture->payload(['release_id' => ' ']);
        foreach ($cases as $label => $payload) {
            $outcome = $this->run($payload);
            $this->assertSame(Status::Blocked, $outcome->result->status, $label);
            $this->assertSame(strtok($label, ' '), $outcome->result->findings[0]->code, $label);
            $this->assertSame([], $outcome->artifacts, $label);
        }
        $this->assertNotContains(SentinelVerifierAgent::EVENT_FAILED, $this->topics(), 'a blocked task emits no verification event');
    }

    public function testDeploymentCompletedAndAuditRequestsTriggerTheVerifier(): void
    {
        foreach ([SentinelVerifierAgent::TOPIC_DEPLOYMENT_COMPLETED, SentinelVerifierAgent::TOPIC_AUDIT_REQUESTED] as $topic) {
            $outcomes = $this->fixture->orchestrator()->publish($topic, $this->fixture->payload());
            $this->assertCount(1, $outcomes, $topic);
            $this->assertSame(SentinelVerifierAgent::ID, $outcomes[0]->agentId);
            $this->assertSame(1, $outcomes[0]->task->hop);
            $this->assertSame('VERIFIED', $outcomes[0]->result->data['sentinel_verdict']);
        }
    }

    public function testRegistryDeclaresWhatTheAgentProducesForTheDashboard(): void
    {
        $entry = array_values(array_filter(Json::decodeFile(dirname(__DIR__, 2).'/agents.json')['agents'], static fn (array $a): bool => $a['id'] === SentinelVerifierAgent::ID))[0];
        $this->assertSame([SentinelVerifierAgent::EVENT_PASSED, SentinelVerifierAgent::EVENT_FAILED], $entry['produces']['events']);
        $this->assertSame([SentinelVerifierAgent::ARTIFACT_KIND], $entry['produces']['artifacts']);
        $this->assertSame(
            [SentinelVerifierAgent::TOPIC_DEPLOYMENT_COMPLETED, SentinelVerifierAgent::TOPIC_AUDIT_REQUESTED],
            array_column($entry['subscriptions'], 'topic'),
            'the canvas input port lists exactly these topics',
        );
    }

    public function testLinksInTheReleaseAreNeverFollowed(): void
    {
        $outside = $this->fixture->root.'/outside';
        mkdir($outside);
        file_put_contents($outside.'/Kernel.php', SentinelFixture::FILES['app/Http/Kernel.php']);
        $this->rmTree($this->fixture->root.'/release/app/Http');
        $link = $this->fixture->root.'/release/app/Http';
        if (! @symlink($outside, $link) && PHP_OS_FAMILY === 'Windows') {
            // Unprivileged Windows cannot symlink, but a junction needs no privilege.
            exec('cmd /c mklink /J '.escapeshellarg(str_replace('/', '\\', $link)).' '.escapeshellarg(str_replace('/', '\\', $outside)).' 2>NUL');
        }
        if (! is_dir($link)) {
            $this->skip('this host cannot create directory links');
        }
        try {
            $outcome = $this->run($this->fixture->payload());
        } finally {
            // rmdir removes a link or junction itself, never its target.
            @rmdir($link) || @unlink($link);
        }
        $byPath = array_column($this->report($outcome)['non_conformant_files'], null, 'path');
        $this->assertSame('UNREADABLE', $byPath['app/Http/Kernel.php']['outcome'] ?? null, 'a file behind a link is not observed through it');
        $this->assertSame('UNKNOWN', $outcome->result->data['sentinel_verdict']);
    }

    private function run(array $payload): Outcome
    {
        $payload = array_filter($payload, static fn (mixed $value): bool => $value !== null);

        return $this->fixture->orchestrator()->dispatch(Task::create(SentinelVerifierAgent::TASK, $payload, SentinelVerifierAgent::ID))[0];
    }

    private function envelope(Outcome $outcome): array
    {
        return Json::decodeFile($outcome->artifacts[0]['path']);
    }

    private function report(Outcome $outcome): array
    {
        return $this->envelope($outcome)['payload'];
    }

    /** @return list<string> */
    private function codes(Outcome $outcome, string $severity): array
    {
        return array_values(array_map(static fn ($f) => $f->code, array_filter($outcome->result->findings, static fn ($f) => $f->severity->value === $severity)));
    }

    private function finding(Outcome $outcome, string $subject): array
    {
        foreach ($outcome->result->findings as $finding) {
            if ($finding->subject === $subject) {
                return $finding->toArray();
            }
        }
        $this->fail("no finding for {$subject}; got ".implode(', ', array_map(static fn ($f) => (string) $f->subject, $outcome->result->findings)));
    }

    /** @return list<string> */
    private function topics(): array
    {
        return array_map(static fn ($m): string => $m->topic, $this->fixture->bus->history());
    }

    private function rmTree(string $path): void
    {
        \Workspace\Agents\Tests\Support\WorkspaceFixture::remove($path);
    }
}
