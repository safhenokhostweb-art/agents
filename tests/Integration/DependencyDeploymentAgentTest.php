<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Integration;

use RuntimeException;
use Workspace\Agents\Agents\DependencyDeployment\DependencyDeploymentAgent;
use Workspace\Agents\Kernel\ArtifactStore;
use Workspace\Agents\Kernel\Finding;
use Workspace\Agents\Kernel\JournaledBus;
use Workspace\Agents\Kernel\Message;
use Workspace\Agents\Kernel\Outcome;
use Workspace\Agents\Kernel\Status;
use Workspace\Agents\Kernel\Task;
use Workspace\Agents\Support\Json;
use Workspace\Agents\Support\Process;
use Workspace\Agents\Tests\Support\ScriptedAgent;
use Workspace\Agents\Tests\Support\WorkspaceFixture;
use Workspace\Agents\Tests\TestCase;

final class DependencyDeploymentAgentTest extends TestCase
{
    private const P = WorkspaceFixture::PROVIDER;
    private const C = WorkspaceFixture::CONSUMER;

    private WorkspaceFixture $fixture;

    public function setUp(): void
    {
        $this->fixture = new WorkspaceFixture();
    }

    public function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function testCleanWorkspaceIsReadyAndProducesASealedArtifactWithFullLifecycle(): void
    {
        $runtime = $this->fixture->boot();
        $outcome = $runtime['orchestrator']->dispatch(Task::create(DependencyDeploymentAgent::TASK))[0];

        $this->assertSame(Status::Pass, $outcome->result->status, $outcome->result->summary.' '.$outcome->result->error);
        $this->assertSame('READY', $outcome->result->data['verdict']);
        $this->assertSame([], $this->codes($outcome, 'warning'));
        $this->assertSame([], $this->codes($outcome, 'blocker'));
        $this->assertSame(['NO_RELEASE_CANDIDATE', 'NO_RELEASE_CANDIDATE', 'RECOMMEND_GIT_DEPENDENCY'], $this->sorted($this->codes($outcome, 'info')));

        $this->assertCount(1, $outcome->artifacts);
        $this->assertTrue(ArtifactStore::verify($outcome->artifacts[0]['path'])['valid']);
        $artifact = Json::decodeFile($outcome->artifacts[0]['path']);
        $this->assertSame('deployment-readiness', $artifact['kind']);
        $this->assertSame($this->fixture->git(self::P, 'rev-parse', 'HEAD'), $artifact['provenance']['projects'][self::P]['head']);

        $package = $this->package($artifact, self::C);
        $this->assertSame('cross-project', $package['scope']);
        $this->assertSame(self::P, $package['provider']);
        $this->assertSame('mirror', $package['install']['mode']);
        $this->assertTrue($package['install']['in_sync']);
        $this->assertSame(['satisfied', 'satisfied'], array_column($package['requirements'], 'result'));

        $this->assertSame(
            ['task.started', 'artifact.created', DependencyDeploymentAgent::EVENT_REPORTED, 'task.completed'],
            array_map(static fn (Message $m): string => $m->topic, $runtime['bus']->history()),
        );
        $journal = JournaledBus::read($runtime['journal'], $outcome->task->correlationId);
        $this->assertCount(4, $journal);
    }

    public function testPackageEditDetectsMirrorDriftCouplingAndDraftsProviderRelease(): void
    {
        $this->fixture->write(self::P, 'packages/core/src/Util.php', "<?php\n\nreturn 2;\n");
        $outcome = $this->run();

        $this->assertSame('ATTENTION', $outcome->result->data['verdict']);
        $this->assertSame(['CROSS_REPO_COUPLING', 'MIRROR_DRIFT', 'SHARED_PACKAGE_UNCOMMITTED'], $this->sorted($this->codes($outcome, 'warning')));
        $drift = $this->finding($outcome, 'MIRROR_DRIFT');
        $this->assertSame(self::C.':'.WorkspaceFixture::PACKAGE, $drift->subject);
        $this->assertSame(['src/Util.php'], $drift->evidence['modified']);

        $artifact = Json::decodeFile($outcome->artifacts[0]['path']);
        $draft = $artifact['payload']['release_drafts'][self::P];
        $this->assertSame(['packages/core/src/Util.php'], $draft['owned_paths']);
        $this->assertSame(['CROSS_REPO_COUPLING'], $draft['risk_flags']);
        $this->assertSame($this->fixture->git(self::P, 'rev-parse', 'origin/main'), $draft['base_sha']);
        $this->assertFalse(isset($artifact['payload']['release_drafts'][self::C]));
        $this->assertSame([self::P, self::C], $artifact['payload']['handoff']['release_order']);

        // The attachment is exactly the release-task contract, nothing more.
        $attachment = Json::decodeFile(dirname($outcome->artifacts[0]['path']).'/release-task.'.self::P.'.json');
        $this->assertSame(['schema_version', 'task_id', 'summary', 'base_sha', 'owned_paths', 'risk_flags'], array_keys($attachment));
        $this->assertSame($draft, $attachment);

        // Re-mirroring (composer reinstall) clears the drift but not the coupling.
        $this->fixture->mirrorPackage();
        $after = $this->run();
        $this->assertNotContains('MIRROR_DRIFT', $this->codes($after));
        $this->assertContains('CROSS_REPO_COUPLING', $this->codes($after));
    }

    public function testDriftEventReachesASubscribedAgentInTheSameCorrelationChain(): void
    {
        $this->fixture->write(self::P, 'packages/core/src/New.php', "<?php\n");
        $syncAgent = new ScriptedAgent('mirror-sync', ['dependency.mirror-sync']);
        $runtime = $this->fixture->boot([[$syncAgent, [['topic' => DependencyDeploymentAgent::EVENT_DRIFT, 'task' => 'dependency.mirror-sync']]]]);
        $outcomes = $runtime['orchestrator']->dispatch(Task::create(DependencyDeploymentAgent::TASK));

        $this->assertSame(['dependency-deployment', 'mirror-sync'], array_map(static fn (Outcome $o): ?string => $o->agentId, $outcomes));
        $task = $syncAgent->received[0];
        $this->assertSame($outcomes[0]->task->correlationId, $task->correlationId);
        $this->assertSame(1, $task->hop);
        $this->assertSame([[
            'package' => WorkspaceFixture::PACKAGE,
            'consumer' => self::C,
            'provider' => self::P,
            'modified' => 0,
            'missing' => 1,
            'extra' => 0,
        ]], $task->payload['packages']);
    }

    public function testManifestEditWithoutLockBlocksRelease(): void
    {
        $manifest = Json::decodeFile($this->fixture->path(self::C, 'composer.json'));
        $manifest['require']['monolog/monolog'] = '^3.0';
        $this->fixture->write(self::C, 'composer.json', Json::encode($manifest));
        $outcome = $this->run();

        $this->assertSame('BLOCKED', $outcome->result->data['verdict']);
        $this->assertSame(['LOCK_OUT_OF_SYNC', 'PACKAGE_BOUNDARY'], $this->sorted($this->codes($outcome, 'blocker')));
        $draft = $this->draft($outcome, self::C);
        $this->assertSame(['composer.json'], $draft['owned_paths']);
        $this->assertSame(['LOCK_OUT_OF_SYNC', 'PACKAGE_BOUNDARY'], $draft['risk_flags']);
    }

    public function testPackageRequirementTheConsumerCannotSatisfyIsABlocker(): void
    {
        $this->fixture->write(self::P, 'packages/core/composer.json', Json::encode([
            'name' => WorkspaceFixture::PACKAGE,
            'require' => ['php' => '^8.4', 'laravel/framework' => '^13.0'],
        ]));
        $this->fixture->mirrorPackage();
        $outcome = $this->run();

        $violations = array_values(array_filter($outcome->result->findings, static fn (Finding $f): bool => $f->code === 'CONSTRAINT_UNSATISFIED'));
        $this->assertCount(2, $violations);
        $this->assertSame(
            [['dependency' => 'php', 'constraint' => '^8.4', 'provided' => '8.3.0'], ['dependency' => 'laravel/framework', 'constraint' => '^13.0', 'provided' => 'v12.1.0']],
            array_map(static fn (Finding $f): array => $f->evidence, $violations),
        );
        $this->assertSame('BLOCKED', $outcome->result->data['verdict']);
    }

    public function testRequiringAProviderPackageWithoutARepositoryIsUnresolvable(): void
    {
        $this->fixture->write(self::P, 'packages/extra/composer.json', Json::encode(['name' => 'saas-platform/extra']));
        $providerManifest = Json::decodeFile($this->fixture->path(self::P, 'composer.json'));
        $providerManifest['repositories'][] = ['type' => 'path', 'url' => 'packages/extra'];
        $this->fixture->composer(self::P, $providerManifest, ['laravel/framework' => '10.x-dev']);
        $this->fixture->commitAll(self::P, 'add extra package');

        $consumerManifest = Json::decodeFile($this->fixture->path(self::C, 'composer.json'));
        $consumerManifest['require']['saas-platform/extra'] = '@dev';
        $this->fixture->composer(self::C, $consumerManifest, ['laravel/framework' => 'v12.1.0', WorkspaceFixture::PACKAGE => 'dev-main']);
        $outcome = $this->run();

        $finding = $this->finding($outcome, 'UNRESOLVABLE_SHARED_DEPENDENCY');
        $this->assertSame(self::C.':saas-platform/extra', $finding->subject);
        $this->assertSame('BLOCKED', $outcome->result->data['verdict']);
    }

    public function testMigrationReviewStrayFilesAndRuntimePathsAreClassifiedLikeReleasePrepare(): void
    {
        $this->fixture->write(self::C, 'database/migrations/2026_01_01_000000_trim.php', "<?php\n\$table->dropColumn('legacy');\n");
        $this->fixture->write(self::C, 'src/App.php', "<?php\n\nreturn 'v2';\n");
        $this->fixture->write(self::C, 'notes.txt', 'stray');
        $this->fixture->write(self::C, 'storage/cache.bin', 'runtime');
        $outcome = $this->run();

        $draft = $this->draft($outcome, self::C);
        $this->assertSame(['database/migrations/2026_01_01_000000_trim.php', 'src/App.php'], $draft['owned_paths']);
        $this->assertSame(['MIGRATION_REVIEW_REQUIRED'], $draft['risk_flags']);
        $stray = $this->finding($outcome, 'PATHS_OUTSIDE_RELEASE_POLICY');
        $this->assertSame(['notes.txt'], $stray->evidence['paths']);
        $record = Json::decodeFile($outcome->artifacts[0]['path'])['payload']['projects'][self::C]['delta'];
        $this->assertSame(['changed' => 4, 'candidate' => 2, 'excluded_runtime' => 1, 'outside_policy' => 1], $record);
    }

    public function testBranchDeltaIsMeasuredFromTheMergeBaseNotProductionHead(): void
    {
        $this->fixture->git(self::C, 'checkout', '-q', '-b', 'feature');
        $this->fixture->write(self::C, 'src/Feature.php', "<?php\n");
        $this->fixture->commitAll(self::C, 'feature work', push: false);
        $this->fixture->git(self::C, 'checkout', '-q', 'main');
        $this->fixture->write(self::C, 'src/Hotfix.php', "<?php\n");
        $this->fixture->commitAll(self::C, 'production hotfix');
        $this->fixture->git(self::C, 'checkout', '-q', 'feature');
        $outcome = $this->run();

        $this->assertContains('BRANCH_BEHIND_PRODUCTION', $this->codes($outcome, 'warning'));
        $draft = $this->draft($outcome, self::C);
        $this->assertSame(['src/Feature.php'], $draft['owned_paths'], 'the production hotfix must not appear as a branch change');
        $this->assertSame($this->fixture->git(self::C, 'rev-parse', 'origin/main'), $draft['base_sha']);
    }

    public function testMissingProductionRefIsReportedNotGuessed(): void
    {
        $this->fixture->git(self::C, 'update-ref', '-d', 'refs/remotes/origin/main');
        $outcome = $this->run();
        $this->assertStringContains('fetch origin main', (string) $this->finding($outcome, 'BASE_UNRESOLVED')->remedy);
        $this->assertFalse(isset(Json::decodeFile($outcome->artifacts[0]['path'])['payload']['release_drafts'][self::C]));
        $this->assertNull(Json::decodeFile($outcome->artifacts[0]['path'])['payload']['projects'][self::C]['base_sha']);
    }

    public function testPreconditionFailuresAreBlockedWithoutAnArtifact(): void
    {
        $unknown = $this->run(['projects' => ['nope']]);
        $this->assertSame(Status::Blocked, $unknown->result->status);
        $this->assertSame('PROJECT_UNKNOWN', $unknown->result->findings[0]->code);
        $this->assertSame([], $unknown->artifacts);

        $this->assertSame('INVALID_PAYLOAD', $this->run(['projet' => ['x']])->result->findings[0]->code);
        $this->assertSame('INVALID_PAYLOAD', $this->run(['task_id' => '../evil'])->result->findings[0]->code);

        WorkspaceFixture::remove($this->fixture->path(self::C));
        $missing = $this->run();
        $this->assertSame(Status::Blocked, $missing->result->status);
        $this->assertSame('PROJECT_MISSING', $missing->result->findings[0]->code);
    }

    public function testCustomTaskIdentityFlowsIntoTheDraft(): void
    {
        $this->fixture->write(self::P, 'src/Feature.php', "<?php\n");
        $draft = $this->draft($this->run(['projects' => [self::P], 'task_id' => 'WU-42', 'summary' => 'Ship feature']), self::P);
        $this->assertSame('WU-42', $draft['task_id']);
        $this->assertSame('Ship feature', $draft['summary']);
    }

    public function testScopingDraftsNeverHidesCrossProjectFindings(): void
    {
        $this->fixture->write(self::P, 'packages/core/src/Util.php', "<?php\n\nreturn 4;\n");
        $this->fixture->write(self::C, 'src/App.php', "<?php\n\nreturn 'scoped';\n");
        $outcome = $this->run(['projects' => [self::P]]);

        $this->assertContains('MIRROR_DRIFT', $this->codes($outcome));
        $this->assertSame(['CROSS_REPO_COUPLING'], $this->draft($outcome, self::P)['risk_flags']);
        $payload = Json::decodeFile($outcome->artifacts[0]['path'])['payload'];
        $this->assertSame([self::P], array_keys($payload['release_drafts']));
        $this->assertSame('monitored-only', $payload['projects'][self::C]['scope']);
    }

    /**
     * Contract test against the real deployment-system: the draft the agent
     * produces must be accepted by ReleasePreparer `check` unchanged, and a
     * stray file the agent warned about must fail it exactly as predicted.
     */
    public function testDraftIsAcceptedByTheRealReleasePreparer(): void
    {
        $library = dirname(__DIR__, 3).'/deployment-system/deployment/lib/ReleasePreparer.php';
        if (! is_file($library)) {
            $this->skip('deployment-system is not checked out beside agents/');
        }
        require_once $library;

        $this->fixture->write(self::P, 'packages/core/src/Util.php', "<?php\n\nreturn 3;\n");
        $draft = $this->draft($this->run(['projects' => [self::P], 'task_id' => 'WU-7']), self::P);
        $this->fixture->write(self::P, '.deployment/release-task.json', Json::encode($draft));

        $summary = (new \DeploymentContract\ReleasePreparer($this->fixture->path(self::P)))->run(false);
        $this->assertSame('READY', $summary['status']);
        $this->assertSame($draft['owned_paths'], $summary['changed_files']);
        $this->assertSame($draft['base_sha'], $summary['base_sha']);
        $this->assertSame(['CROSS_REPO_COUPLING'], $summary['risk_flags']);

        $this->fixture->write(self::P, 'src/stray.php', "<?php\n");
        $this->fixture->write(self::P, 'stray.txt', 'x');
        $outcome = $this->run(['projects' => [self::P], 'task_id' => 'WU-7']);
        $this->assertSame(['stray.txt'], $this->finding($outcome, 'PATHS_OUTSIDE_RELEASE_POLICY')->evidence['paths']);
        $this->assertThrows(RuntimeException::class, fn () => (new \DeploymentContract\ReleasePreparer($this->fixture->path(self::P)))->run(false), 'MIXED WORKTREE');
    }

    /**
     * Pins the owner-approved customer-site git_dependencies entry (read from
     * the real policy file) to its effect in the real ReleasePreparer: the
     * provider HEAD is recorded, and a dirty or off-branch provider blocks.
     */
    public function testCustomerSiteGitDependencyPinsTheProviderCommit(): void
    {
        $library = dirname(__DIR__, 3).'/deployment-system/deployment/lib/ReleasePreparer.php';
        $realPolicy = dirname(__DIR__, 3).'/customer-site/.deployment/release-policy.json';
        if (! is_file($library) || ! is_file($realPolicy)) {
            $this->skip('deployment-system or customer-site is not checked out beside agents/');
        }
        require_once $library;
        $entry = Json::decodeFile($realPolicy)['git_dependencies'] ?? [];
        $this->assertSame([['name' => 'saas-platform', 'path' => '{project_root}/../saas-platform', 'branch' => 'main']], $entry);

        $this->assertContains('RECOMMEND_GIT_DEPENDENCY', $this->codes($this->run()));
        $policy = Json::decodeFile($this->fixture->path(self::C, '.deployment/release-policy.json'));
        $policy['git_dependencies'] = $entry;
        $this->fixture->write(self::C, '.deployment/release-policy.json', Json::encode($policy));
        $this->fixture->commitAll(self::C, 'pin saas-platform');

        $this->fixture->write(self::C, 'src/App.php', "<?php\n\nreturn 'pinned';\n");
        $outcome = $this->run(['projects' => [self::C], 'task_id' => 'WU-9']);
        $this->assertNotContains('RECOMMEND_GIT_DEPENDENCY', $this->codes($outcome));
        $this->fixture->write(self::C, '.deployment/release-task.json', Json::encode($this->draft($outcome, self::C)));
        $prepare = fn (): array => (new \DeploymentContract\ReleasePreparer($this->fixture->path(self::C)))->run(false);

        $summary = $prepare();
        $this->assertSame('READY', $summary['status']);
        $this->assertSame(['saas-platform' => $this->fixture->git(self::P, 'rev-parse', 'HEAD')], $summary['dependency_commits']);

        $this->fixture->write(self::P, 'scratch.txt', 'uncommitted');
        $this->assertThrows(RuntimeException::class, $prepare, 'DIRTY DEPENDENCY WORKTREE');
        unlink($this->fixture->path(self::P, 'scratch.txt'));

        $this->fixture->git(self::P, 'checkout', '-q', '-b', 'feature');
        $this->assertThrows(RuntimeException::class, $prepare, 'DEPENDENCY BRANCH MISMATCH');
    }

    public function testCliRunsTheAgentAndHonoursStrictMode(): void
    {
        $agent = dirname(__DIR__, 2).'/bin/agent';
        $workspace = '--workspace='.$this->fixture->root.'/workspace.json';

        $clean = Process::run([PHP_BINARY, $agent, 'run', DependencyDeploymentAgent::TASK, $workspace, '--strict'], $this->fixture->root);
        $this->assertSame(0, $clean['code'], $clean['stdout'].$clean['stderr']);
        $this->assertStringContains('[PASS] dependency-deployment deployment.readiness', $clean['stdout']);

        $this->fixture->write(self::P, 'packages/core/src/Util.php', "<?php\n\nreturn 9;\n");
        $json = Process::run([PHP_BINARY, $agent, 'run', DependencyDeploymentAgent::TASK, $workspace, '--json'], $this->fixture->root);
        $this->assertSame(0, $json['code']);
        $outcomes = Json::decode($json['stdout']);
        $this->assertSame('ATTENTION', $outcomes[0]['result']['data']['verdict']);
        $this->assertSame(3, Process::run([PHP_BINARY, $agent, 'run', DependencyDeploymentAgent::TASK, $workspace, '--strict'], $this->fixture->root)['code']);

        $verify = Process::run([PHP_BINARY, $agent, 'verify', $outcomes[0]['artifacts'][0]['path']], $this->fixture->root);
        $this->assertSame("VALID\n", $verify['stdout']);

        $taskFile = $this->fixture->root.'/task.json';
        file_put_contents($taskFile, Json::encode(['type' => 'release.requested.unknown', 'payload' => []]));
        $this->assertSame(2, Process::run([PHP_BINARY, $agent, 'dispatch', $taskFile, $workspace], $this->fixture->root)['code']);
    }

    private function run(array $payload = []): Outcome
    {
        return $this->fixture->boot()['orchestrator']->dispatch(Task::create(DependencyDeploymentAgent::TASK, $payload))[0];
    }

    /** @return list<string> */
    private function codes(Outcome $outcome, ?string $severity = null): array
    {
        $codes = [];
        foreach ($outcome->result->findings as $finding) {
            if ($severity === null || $finding->severity->value === $severity) {
                $codes[] = $finding->code;
            }
        }

        return $codes;
    }

    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function finding(Outcome $outcome, string $code): Finding
    {
        foreach ($outcome->result->findings as $finding) {
            if ($finding->code === $code) {
                return $finding;
            }
        }
        $this->fail("no {$code} finding; got ".implode(', ', $this->codes($outcome)));
    }

    private function draft(Outcome $outcome, string $project): array
    {
        $drafts = Json::decodeFile($outcome->artifacts[0]['path'] ?? $this->fail('no artifact: '.$outcome->result->summary))['payload']['release_drafts'];

        return $drafts[$project] ?? $this->fail("no draft for {$project}; drafts: ".implode(', ', array_keys($drafts)));
    }

    private function package(array $artifact, string $consumer): array
    {
        foreach ($artifact['payload']['shared_packages'] as $package) {
            if ($package['consumer'] === $consumer) {
                return $package;
            }
        }
        $this->fail("no package record for {$consumer}");
    }
}
