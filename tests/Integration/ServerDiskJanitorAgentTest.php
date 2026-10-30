<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Integration;

use Workspace\Agents\Agents\ServerDiskJanitor\DiskScanner;
use Workspace\Agents\Agents\ServerDiskJanitor\JanitorPolicy;
use Workspace\Agents\Agents\ServerDiskJanitor\ProtectionGuard;
use Workspace\Agents\Agents\ServerDiskJanitor\Reclaimer;
use Workspace\Agents\Agents\ServerDiskJanitor\ServerDiskJanitorAgent;
use Workspace\Agents\Kernel\ArtifactStore;
use Workspace\Agents\Kernel\Outcome;
use Workspace\Agents\Kernel\Status;
use Workspace\Agents\Kernel\Task;
use Workspace\Agents\Support\Json;
use Workspace\Agents\Support\Process;
use Workspace\Agents\Tests\Support\JanitorFixture;
use Workspace\Agents\Tests\TestCase;

final class ServerDiskJanitorAgentTest extends TestCase
{
    private JanitorFixture $fixture;

    public function setUp(): void
    {
        $this->fixture = new JanitorFixture();
    }

    public function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function testDryRunIsTheDefaultPlansExactlyTheDisposableFilesAndDeletesNothing(): void
    {
        $before = $this->fixture->files();
        $outcome = $this->run([]);

        $this->assertSame(Status::Pass, $outcome->result->status, $outcome->result->summary);
        $this->assertTrue($outcome->result->data['dry_run'], 'an empty payload (the canvas Run button) never deletes');
        $this->assertSame($before, $this->fixture->files(), 'a dry run removes nothing');

        $report = $this->report($outcome);
        $this->assertSame('dry-run', $report['status']);
        $this->assertSame($this->expectedPlan(), $this->relativePaths($report['entries']));
        $this->assertSame(['would-delete'], array_values(array_unique(array_column($report['entries'], 'outcome'))));

        $expectedBytes = array_sum(array_map('strlen', JanitorFixture::DISPOSABLE)) + filesize($this->fixture->path(JanitorFixture::EXPIRED_CACHE));
        $this->assertSame($expectedBytes, $report['summary']['bytes_planned'], 'reclaimable size is exact, units included');
        $this->assertSame(0, $report['summary']['bytes_freed']);

        $unit = $this->entry($report['entries'], 'host/tmp/build-old');
        $this->assertSame('directory', $unit['type']);
        $this->assertSame(2, $unit['files']);
        $this->assertSame('abandoned-tmp', $unit['rule']);
        foreach ($report['entries'] as $entry) {
            foreach (['path', 'bytes', 'modified_at', 'changed_at', 'reason', 'rule'] as $key) {
                $this->assertTrue(isset($entry[$key]), "every entry records {$key}");
            }
            $this->assertFalse(isset($entry['identity']), 'internal stat identity stays out of the report');
        }
        $this->assertStringContains('expired', $this->entry($report['entries'], JanitorFixture::EXPIRED_CACHE)['reason']);
    }

    public function testProtectionListWinsOverEveryRuleAndRetentionKeepsTheNewestBackups(): void
    {
        $report = $this->report($this->run([]));
        $protected = array_column(array_map(fn (array $e): array => ['path' => $this->relative($e['path']), 'protection' => $e['protection']], $report['protected']), 'protection', 'path');

        $this->assertSame(ProtectionGuard::GIT_TRACKED, $protected['app/storage/logs/tracked.log'], 'a file git tracks is never disposable');
        $this->assertSame('PROTECTED_DATABASE', $protected['app/storage/app/tmp/cache.sqlite']);
        $this->assertSame('PROTECTED_PLACEHOLDER', $protected['app/storage/framework/sessions/.gitignore']);
        $this->assertSame('PROTECTED_ENVIRONMENT (.env)', $protected['host/tmp/build-env'], 'a unit holding a protected file is skipped whole');

        $retained = array_map(fn (array $r): string => $this->relative($r['path']), $report['retained']);
        sort($retained);
        $this->assertSame(['www/site.backup-20260201-000000-bbbbbb', 'www/site.backup-20260301-000000-cccccc'], $retained);
        $this->assertNotContains('host/tmp/build-fresh', $this->relativePaths($report['entries']), 'a directory with a recent file is not abandoned');
        $this->assertNotContains('host/tmp/keep-me.txt', $this->relativePaths($report['entries']), 'names outside the rule are never touched');
    }

    public function testApplyingRunWritesThePlanFirstThenDeletesExactlyItAndRecordsEveryOutcome(): void
    {
        $before = $this->fixture->files();
        $outcome = $this->run(['dry_run' => false]);

        $this->assertSame(Status::Pass, $outcome->result->status, $outcome->result->summary);
        $report = $this->report($outcome);
        $this->assertSame('completed', $report['status']);
        $this->assertSame(['deleted'], array_values(array_unique(array_column($report['entries'], 'outcome'))));

        $removed = array_values(array_diff($before, $this->fixture->files()));
        $expected = array_merge(array_keys(JanitorFixture::DISPOSABLE), [JanitorFixture::EXPIRED_CACHE]);
        sort($expected);
        $this->assertSame($expected, $removed, 'exactly the planned files are gone');
        $this->assertFalse(is_dir($this->fixture->path('host/tmp/build-old')), 'an abandoned unit is removed with its directories');
        foreach (['app/.env', 'app/src/Main.php', 'app/composer.json', 'app/storage/logs/tracked.log', 'app/storage/logs/laravel.log', 'app/storage/app/public/photo.jpg', 'host/tmp/build-env/.env', 'www/site.backup-20260301-000000-cccccc/index.php', 'host/var/log/nginx/access.log'] as $kept) {
            $this->assertTrue(is_file($this->fixture->path($kept)), "{$kept} survives");
        }

        $summary = $report['summary'];
        $this->assertSame($summary['bytes_planned'], $summary['bytes_freed']);
        $this->assertSame(count(JanitorFixture::DISPOSABLE) + 1, $summary['files_deleted']);
        $this->assertSame(0, $summary['entries_failed']);
        $this->assertSame('READY', $outcome->result->data['verdict']);

        $markdown = (string) file_get_contents($outcome->result->data['report']['markdown']);
        $this->assertStringContains('| Freed | ', $markdown);
        $this->assertStringContains('site.backup-20260101-000000-aaaaaa', $markdown);

        $this->assertTrue(ArtifactStore::verify($outcome->artifacts[0]['path'])['valid']);
        $this->assertSame(['task.started', 'artifact.created', ServerDiskJanitorAgent::EVENT_REPORTED, 'task.completed'], array_map(static fn ($m): string => $m->topic, $this->fixture->bus->history()));
        $event = $this->fixture->bus->history()[2]->payload;
        $this->assertFalse($event['dry_run']);
        $this->assertSame($summary['bytes_freed'], $event['summary']['bytes_freed']);
    }

    public function testNoReportMeansNoDeletion(): void
    {
        $this->fixture->policy(['reports' => 'missing-parent/janitor-reports']);
        $before = $this->fixture->files();

        $outcome = $this->run(['dry_run' => false]);

        $this->assertSame(Status::Blocked, $outcome->result->status);
        $this->assertSame('REPORT_DIR_MISSING', $outcome->result->findings[0]->code);
        $this->assertSame($before, $this->fixture->files());
    }

    public function testEntriesThatChangeAfterPlanningAreLeftAlone(): void
    {
        $policy = JanitorPolicy::load('janitor.json', $this->fixture->context());
        $guard = new ProtectionGuard($policy);
        $plan = (new DiskScanner($policy, $guard, time()))->scan(['laravel-logs', 'abandoned-tmp']);
        $byPath = array_column($plan['candidates'], null, 'path');

        $log = $this->fixture->path('app/storage/logs/laravel-2026-01-01.log');
        file_put_contents($log, "rewritten after the plan\n");
        $unit = $this->fixture->path('host/tmp/build-old');
        file_put_contents($unit.'/manifest.json', '{"rebuilt": true}');

        $reclaimer = new Reclaimer($guard);
        $this->assertSame(Reclaimer::CHANGED, $reclaimer->reclaim($byPath[$log])['outcome']);
        $this->assertTrue(is_file($log));

        $result = $reclaimer->reclaim($byPath[$unit]);
        $this->assertTrue(in_array($result['outcome'], [Reclaimer::CHANGED, Reclaimer::PARTIAL], true), 'a unit that gained a newer file stops');
        $this->assertTrue(is_file($unit.'/manifest.json'));
        $this->assertStringContains('modified after the plan', (string) $result['error']);

        $gz = $this->fixture->path('app/storage/logs/worker.log.3.gz');
        unlink($gz);
        $this->assertSame(Reclaimer::VANISHED, $reclaimer->reclaim($byPath[$gz])['outcome']);
    }

    public function testMaintenanceEventCleansAndDeploymentEventsOnlyAudit(): void
    {
        $outcomes = $this->fixture->orchestrator()->publish(ServerDiskJanitorAgent::TOPIC_REQUESTED, ['dry_run' => false, 'rules' => ['laravel-sessions']]);
        $this->assertCount(1, $outcomes);
        $this->assertSame(Status::Pass, $outcomes[0]->result->status);
        $this->assertFalse(is_file($this->fixture->path('app/storage/framework/sessions/OLDSESSION0000000000000000000000000000000')));
        $this->assertTrue(is_file($this->fixture->path('app/storage/logs/laravel-2026-01-01.log')), 'rules narrows the run');

        // deployment.completed carries Sentinel's payload: foreign keys are ignored, and without dry_run nothing is deleted.
        $before = $this->fixture->files();
        $audit = $this->fixture->orchestrator()->publish('deployment.completed', ['evidence' => 'evidence.json', 'release_id' => 'rel-1']);
        $this->assertSame(Status::Pass, $audit[0]->result->status, $audit[0]->result->summary);
        $this->assertTrue($audit[0]->result->data['dry_run']);
        $this->assertSame($before, $this->fixture->files());
    }

    public function testDirectRunsRejectUnknownKeysAndRules(): void
    {
        $unknown = $this->run(['dryrun' => false]);
        $this->assertSame(Status::Blocked, $unknown->result->status);
        $this->assertSame('INVALID_PAYLOAD', $unknown->result->findings[0]->code);

        $rule = $this->run(['rules' => ['everything']]);
        $this->assertSame(Status::Blocked, $rule->result->status);
        $this->assertStringContains('Unknown janitor rule(s): everything', $rule->result->summary);

        $type = $this->run(['dry_run' => 'no']);
        $this->assertSame('INVALID_PAYLOAD', $type->result->findings[0]->code);
    }

    public function testCliDryRunFlagOverridesAnApplyingPayload(): void
    {
        $before = $this->fixture->files();
        $result = Process::run([
            PHP_BINARY, dirname(__DIR__, 2).'/bin/agent', 'run', ServerDiskJanitorAgent::TASK,
            '--workspace='.$this->fixture->root.'/workspace.json', '--registry='.$this->fixture->root.'/agents.json',
            '--payload={"dry_run":false}', '--dry-run', '--json',
        ], $this->fixture->root);

        $this->assertSame(0, $result['code'], $result['stderr'].$result['stdout']);
        $outcomes = Json::decode($result['stdout']);
        $this->assertTrue($outcomes[0]['result']['data']['dry_run']);
        $this->assertTrue($outcomes[0]['task']['payload']['dry_run']);
        $this->assertSame($before, $this->fixture->files());
    }

    private function run(array $payload): Outcome
    {
        return $this->fixture->orchestrator()->dispatch(Task::create(ServerDiskJanitorAgent::TASK, $payload))[0];
    }

    private function report(Outcome $outcome): array
    {
        $path = $outcome->result->data['report']['json'];
        $this->assertTrue(preg_match('#/hub/storage/logs/janitor-reports/\d{4}-\d{2}-\d{2}/janitor-\d{8}T\d{6}-[0-9a-f]{8}\.json$#', $path) === 1, "reports are filed by date: {$path}");
        $this->assertTrue(is_file(substr($path, 0, -5).'.md'));

        return Json::decodeFile($path);
    }

    /** @return list<string> */
    private function expectedPlan(): array
    {
        $plan = [JanitorFixture::EXPIRED_CACHE, 'host/tmp/build-old', 'www/site.backup-20260101-000000-aaaaaa'];
        foreach (array_keys(JanitorFixture::DISPOSABLE) as $file) {
            if (! str_starts_with($file, 'host/tmp/build-old/') && ! str_starts_with($file, 'www/')) {
                $plan[] = $file;
            }
        }
        sort($plan);

        return $plan;
    }

    /** @return list<string> */
    private function relativePaths(array $entries): array
    {
        $paths = array_map(fn (array $e): string => $this->relative($e['path']), $entries);
        sort($paths);

        return $paths;
    }

    private function entry(array $entries, string $relative): array
    {
        foreach ($entries as $entry) {
            if ($this->relative($entry['path']) === $relative) {
                return $entry;
            }
        }
        $this->fail("no entry for {$relative}");
    }

    private function relative(string $path): string
    {
        return substr(str_replace('\\', '/', $path), strlen($this->fixture->root) + 1);
    }
}
