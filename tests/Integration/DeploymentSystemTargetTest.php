<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Integration;

use RuntimeException;
use Workspace\Agents\Agents\DependencyDeployment\DependencyDeploymentAgent;
use Workspace\Agents\Agents\DependencyDeployment\ReleaseReadinessAnalyzer;
use Workspace\Agents\Kernel\Finding;
use Workspace\Agents\Kernel\JournaledBus;
use Workspace\Agents\Kernel\Message;
use Workspace\Agents\Kernel\Outcome;
use Workspace\Agents\Kernel\Task;
use Workspace\Agents\Support\Json;
use Workspace\Agents\Support\Process;
use Workspace\Agents\Tests\Support\WorkspaceFixture;
use Workspace\Agents\Tests\TestCase;

/**
 * deployment-system as a third readiness target (self-deployment): its
 * Composer manifest lives under service/, its production branch is master,
 * and the release-prepare tool that checks its drafts is in its own tree.
 */
final class DeploymentSystemTargetTest extends TestCase
{
    private const P = WorkspaceFixture::PROVIDER;
    private const C = WorkspaceFixture::CONSUMER;
    private const D = WorkspaceFixture::DEPLOYMENT;

    private WorkspaceFixture $fixture;

    public function setUp(): void
    {
        $this->fixture = new WorkspaceFixture(withDeploymentSystem: true);
    }

    public function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function testAddingTheThirdTargetLeavesSaasPlatformAndCustomerSiteResultsUnchanged(): void
    {
        $observed = [];
        foreach ([new WorkspaceFixture(), $this->fixture] as $fixture) {
            try {
                $fixture->write(self::P, 'packages/core/src/Util.php', "<?php\n\nreturn 5;\n");
                $fixture->write(self::C, 'src/App.php', "<?php\n\nreturn 'both';\n");
                $outcome = $this->run([], $fixture);
                $payload = Json::decodeFile($outcome->artifacts[0]['path'])['payload'];
                $findings = array_values(array_filter(
                    array_map(static fn (Finding $f): string => "{$f->severity->value} {$f->code} {$f->subject}", $outcome->result->findings),
                    static fn (string $line): bool => ! str_contains($line, ' '.self::D),
                ));
                sort($findings);
                $observed[] = [
                    'verdict' => $outcome->result->data['verdict'],
                    'findings' => $findings,
                    'drafts' => array_map(static fn (array $d): array => [$d['owned_paths'], $d['risk_flags']], $payload['release_drafts']),
                    'shared_packages' => $payload['shared_packages'],
                    'release_order' => array_values(array_diff($payload['handoff']['release_order'], [self::D])),
                ];
            } finally {
                if ($fixture !== $this->fixture) {
                    $fixture->destroy();
                }
            }
        }

        $this->assertSame([self::P, self::C], array_keys($observed[0]['drafts']));
        $this->assertSame($observed[0], $observed[1]);
    }

    public function testDeploymentSystemIsMonitoredFromItsServiceManifestAndMasterBranch(): void
    {
        $outcome = $this->run();

        $this->assertSame('READY', $outcome->result->data['verdict']);
        $this->assertSame([], $this->codes($outcome, 'warning'));
        $this->assertContains('NO_RELEASE_CANDIDATE '.self::D, array_map(static fn (Finding $f): string => "{$f->code} {$f->subject}", $outcome->result->findings));

        $artifact = Json::decodeFile($outcome->artifacts[0]['path']);
        $record = $artifact['payload']['projects'][self::D];
        $this->assertSame('deployment', $record['role']);
        $this->assertSame('master', $record['branch']);
        $this->assertSame('origin/master', $record['base_ref']);
        $this->assertSame($this->fixture->git(self::D, 'rev-parse', 'origin/master'), $record['base_sha']);
        $this->assertSame(['manifest' => 'service/composer.json', 'lock_in_sync' => true, 'platform_php' => null], $record['composer']);
        $this->assertSame('composer.json', $artifact['payload']['projects'][self::C]['composer']['manifest']);
        $this->assertSame($this->fixture->git(self::D, 'rev-parse', 'HEAD'), $artifact['provenance']['projects'][self::D]['head']);
        $this->assertSame([self::P, self::C, self::D], $artifact['payload']['handoff']['release_order']);
    }

    public function testDeploymentSystemReleaseDraftIsJournaledAndHandedToReleasePreparer(): void
    {
        $this->fixture->write(self::D, 'service/app/Hub.php', "<?php\n\nreturn 'hub v2';\n");
        $this->fixture->write(self::D, 'deployment/lib/Contract.php', "<?php\n");
        $runtime = $this->fixture->boot();
        $outcome = $runtime['orchestrator']->dispatch(Task::create(DependencyDeploymentAgent::TASK, ['projects' => [self::D], 'task_id' => 'WU-DS-1']))[0];

        $this->assertSame([self::D], $outcome->result->data['drafts']);
        $artifact = Json::decodeFile($outcome->artifacts[0]['path']);
        $draft = $artifact['payload']['release_drafts'][self::D];
        $this->assertSame('WU-DS-1', $draft['task_id']);
        $this->assertSame(['deployment/lib/Contract.php', 'service/app/Hub.php'], $draft['owned_paths']);
        $this->assertSame([], $draft['risk_flags']);
        $this->assertSame($this->fixture->git(self::D, 'rev-parse', 'origin/master'), $draft['base_sha']);
        $this->assertSame('monitored-only', $artifact['payload']['projects'][self::P]['scope']);
        $this->assertSame('monitored-only', $artifact['payload']['projects'][self::C]['scope']);

        // The sealed attachment is exactly the release-task contract.
        $attachment = Json::decodeFile(dirname($outcome->artifacts[0]['path']).'/release-task.'.self::D.'.json');
        $this->assertSame(['schema_version', 'task_id', 'summary', 'base_sha', 'owned_paths', 'risk_flags'], array_keys($attachment));
        $this->assertSame($draft, $attachment);

        // Hand-off to ReleasePreparer: the tool lives in deployment-system itself.
        $handoff = $artifact['payload']['handoff'];
        $this->assertTrue($handoff['deployment_system']['available']);
        $step = $handoff['steps'][self::D];
        $this->assertSame('release-task.'.self::D.'.json', $step['attachment']);
        $this->assertSame(ReleaseReadinessAnalyzer::TASK_FILE, $step['install_as']);
        $this->assertStringContains($draft['base_sha'], $step['in']);
        $this->assertStringContains('release-prepare.php', (string) $step['check_command']);

        // Journal: another process can replay the run and find the draft.
        $journal = JournaledBus::read($runtime['journal'], $outcome->task->correlationId);
        $this->assertSame(
            ['task.started', 'artifact.created', DependencyDeploymentAgent::EVENT_REPORTED, 'task.completed'],
            array_map(static fn (Message $m): string => $m->topic, $journal),
        );
        $this->assertSame([self::D], $journal[2]->payload['drafts']);
        $this->assertSame([self::P, self::C, self::D], $journal[2]->payload['projects']);

        $library = dirname(__DIR__, 3).'/deployment-system/deployment/lib/ReleasePreparer.php';
        if (! is_file($library)) {
            $this->skip('deployment-system is not checked out beside agents/');
        }
        require_once $library;
        $this->fixture->write(self::D, ReleaseReadinessAnalyzer::TASK_FILE, Json::encode($attachment));
        $summary = (new \DeploymentContract\ReleasePreparer($this->fixture->path(self::D)))->run(false);
        $this->assertSame('READY', $summary['status']);
        $this->assertSame($draft['owned_paths'], $summary['changed_files']);
        $this->assertSame($draft['base_sha'], $summary['base_sha']);
    }

    public function testServiceManifestWithoutLockBlocksDeploymentSystemRelease(): void
    {
        $manifest = Json::decodeFile($this->fixture->path(self::D, 'service/composer.json'));
        $manifest['require']['monolog/monolog'] = '^3.0';
        $this->fixture->write(self::D, 'service/composer.json', Json::encode($manifest));
        $outcome = $this->run(['projects' => [self::D]]);

        $this->assertSame('BLOCKED', $outcome->result->data['verdict']);
        $this->assertSame(['LOCK_OUT_OF_SYNC', 'PACKAGE_BOUNDARY'], $this->sorted($this->codes($outcome, 'blocker')));
        $lock = $this->finding($outcome, 'LOCK_OUT_OF_SYNC');
        $this->assertSame(self::D, $lock->subject);
        $this->assertSame(['manifest' => 'service/composer.json'], $lock->evidence);
        $this->assertStringContains('service/composer.json changed', $lock->message);
        $this->assertStringContains('service/composer.json changed without service/composer.lock', $this->finding($outcome, 'PACKAGE_BOUNDARY')->message);

        $draft = Json::decodeFile($outcome->artifacts[0]['path'])['payload']['release_drafts'][self::D];
        $this->assertSame(['service/composer.json'], $draft['owned_paths']);
        $this->assertSame(['LOCK_OUT_OF_SYNC', 'PACKAGE_BOUNDARY'], $draft['risk_flags']);
    }

    /** The pre-push gate installs into, and gates, deployment-system's own pushes. */
    public function testPrePushGateCoversDeploymentSystem(): void
    {
        $install = Process::run([PHP_BINARY, dirname(__DIR__, 2).'/bin/install-hooks', '--workspace='.$this->fixture->root.'/workspace.json'], $this->fixture->root);
        $this->assertSame(0, $install['code'], $install['stdout']);
        $this->assertStringContains('workspace-agents pre-push gate', (string) file_get_contents($this->fixture->path(self::D, '.git/hooks/pre-push')));

        $push = fn () => Process::run(['git', 'push', 'origin', 'HEAD:master'], $this->fixture->path(self::D));
        $this->fixture->write(self::D, 'service/app/Hub.php', "<?php\n\nreturn 'gated';\n");
        $this->fixture->commitAll(self::D, 'clean change', push: false);
        $clean = $push();
        $this->assertSame(0, $clean['code'], $clean['stderr']);
        $this->assertStringContains('agents gate: deployment-system: READY', $clean['stderr']);

        $manifest = Json::decodeFile($this->fixture->path(self::D, 'service/composer.json'));
        $manifest['require']['monolog/monolog'] = '^3.0';
        $this->fixture->write(self::D, 'service/composer.json', Json::encode($manifest));
        $this->fixture->commitAll(self::D, 'manifest without lock', push: false);
        $before = $this->fixture->git(self::D, 'ls-remote', 'origin', 'refs/heads/master');
        $refused = $push();
        $this->assertFalse($refused['code'] === 0, 'push must be refused');
        $this->assertStringContains('BLOCKER LOCK_OUT_OF_SYNC [deployment-system]', $refused['stderr']);
        $this->assertSame($before, $this->fixture->git(self::D, 'ls-remote', 'origin', 'refs/heads/master'));
    }

    private function run(array $payload = [], ?WorkspaceFixture $fixture = null): Outcome
    {
        $outcome = ($fixture ?? $this->fixture)->boot()['orchestrator']->dispatch(Task::create(DependencyDeploymentAgent::TASK, $payload))[0];
        if ($outcome->artifacts === []) {
            throw new RuntimeException('no artifact: '.$outcome->result->summary.' '.$outcome->result->error);
        }

        return $outcome;
    }

    /** @return list<string> */
    private function codes(Outcome $outcome, string $severity): array
    {
        $codes = [];
        foreach ($outcome->result->findings as $finding) {
            if ($finding->severity->value === $severity) {
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
        $this->fail("no {$code} finding");
    }
}
