<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Unit;

use Workspace\Agents\Agents\ServerDiskJanitor\DiskScanner;
use Workspace\Agents\Agents\ServerDiskJanitor\JanitorPolicy;
use Workspace\Agents\Agents\ServerDiskJanitor\JanitorReport;
use Workspace\Agents\Agents\ServerDiskJanitor\ProtectionGuard;
use Workspace\Agents\Agents\ServerDiskJanitor\ServerDiskJanitorAgent;
use Workspace\Agents\Kernel\AgentContext;
use Workspace\Agents\Kernel\AgentRegistry;
use Workspace\Agents\Kernel\ProtocolException;
use Workspace\Agents\Support\Json;
use Workspace\Agents\Tests\Support\WorkspaceFixture;
use Workspace\Agents\Tests\TestCase;

final class ServerDiskJanitorTest extends TestCase
{
    private string $dir;

    public function setUp(): void
    {
        $this->dir = str_replace('\\', '/', sys_get_temp_dir()).'/agents-janitor-unit-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/site/storage/logs', 0777, true);
        file_put_contents($this->dir.'/workspace.json', Json::encode(['schema' => AgentContext::SCHEMA, 'projects' => ['site' => ['path' => 'site']]]));
        $this->dir = str_replace('\\', '/', (string) realpath($this->dir));
    }

    public function tearDown(): void
    {
        WorkspaceFixture::remove($this->dir);
    }

    public function testShippedPolicyAndRegistryEntryAreValid(): void
    {
        $agents = dirname(__DIR__, 2);
        $policy = JanitorPolicy::load($agents.'/disk-janitor.json', AgentContext::fromFile($agents.'/workspace.json'));
        $this->assertSame(
            ['laravel-logs', 'nginx-logs', 'system-logs', 'laravel-temp', 'laravel-sessions', 'laravel-cache', 'abandoned-tmp', 'abandoned-root-builds', 'deploy-backups', 'hub-service-backups', 'db-dump-backups'],
            $policy->ruleIds(),
        );
        $this->assertTrue(str_ends_with($policy->reportsDir, '/deployment-system/service/storage/logs/janitor-reports'));
        foreach ($policy->rules as $rule) {
            $this->assertTrue($rule['min_age_days'] >= 0 && $rule['reason'] !== '', "{$rule['id']} states its reason");
        }

        $registry = AgentRegistry::fromFile($agents.'/agents.json');
        $this->assertSame(ServerDiskJanitorAgent::ID, $registry->get(ServerDiskJanitorAgent::ID)->id());
        $subscriptions = array_values(array_filter($registry->subscriptions(), static fn (array $s): bool => $s['agent'] === ServerDiskJanitorAgent::ID));
        $this->assertSame([ServerDiskJanitorAgent::TOPIC_REQUESTED, 'deployment.completed'], array_column($subscriptions, 'topic'));
        $this->assertSame([ServerDiskJanitorAgent::TASK], array_values(array_unique(array_column($subscriptions, 'task'))));

        $entry = array_column(Json::decodeFile($agents.'/agents.json')['agents'], null, 'id')[ServerDiskJanitorAgent::ID];
        $this->assertSame([ServerDiskJanitorAgent::EVENT_REPORTED], $entry['produces']['events']);
        $this->assertSame([ServerDiskJanitorAgent::ARTIFACT_KIND], $entry['produces']['artifacts']);
    }

    public function testPolicyRefusesSystemRootsWeakRetentionAndUnexplainedRules(): void
    {
        $rule = ['id' => 'logs', 'kind' => 'files', 'reason' => 'old logs', 'roots' => ['site/storage/logs'], 'match' => ['**.log'], 'min_age_days' => 14];
        $this->assertSame(['logs'], $this->load(['rules' => [$rule]])->ruleIds());

        $cases = [
            'protected system location' => ['roots' => ['/etc']],
            'protected system location ' => ['roots' => ['/']],
            'protected system location  ' => ['roots' => ['/var/lib/mysql']],
            'kind must be one of' => ['kind' => 'everything'],
            'must state the reason' => ['reason' => ' '],
            'min_age_days must be a number' => ['min_age_days' => -1],
            'retention applies to kind entries only' => ['retention' => ['group_pattern' => '/^(?<group>x)$/', 'keep_newest' => 1]],
            'never deletes the newest copy' => ['kind' => 'entries', 'retention' => ['group_pattern' => '/^(?<group>x)$/', 'keep_newest' => 0]],
            '(?<group>…) capture' => ['kind' => 'entries', 'retention' => ['group_pattern' => '/^x$/', 'keep_newest' => 1]],
        ];
        foreach ($cases as $message => $override) {
            $this->assertThrows(ProtocolException::class, fn () => $this->load(['rules' => [array_replace($rule, $override)]]), trim($message));
        }
        $this->assertThrows(ProtocolException::class, fn () => $this->load(['rules' => [$rule, $rule]]), 'declared twice');
        $this->assertThrows(ProtocolException::class, fn () => $this->load(['rules' => []]), 'at least one rule');
        $this->assertThrows(ProtocolException::class, fn () => $this->load(['schema' => 'other']), 'schema must be');

        $missing = $this->assertThrows(ProtocolException::class, fn () => JanitorPolicy::load('nope.json', AgentContext::fromFile($this->dir.'/workspace.json')));
        $this->assertSame('POLICY_MISSING', $missing->reason);
    }

    public function testGuardProtectsSecretsDatabasesRepositoriesMediaAndProjectFiles(): void
    {
        $guard = new ProtectionGuard($this->load(['protect' => Json::decodeFile(dirname(__DIR__, 2).'/disk-janitor.json')['protect'], 'projects' => ['disposable' => ['storage/logs/**']]]));
        $site = $this->dir.'/site';

        $expected = [
            '/tmp/build/.env' => 'PROTECTED_ENVIRONMENT',
            '/tmp/build/.ENV.production' => 'PROTECTED_ENVIRONMENT',
            '/root/customer-site.env.backup-20260820' => 'PROTECTED_ENVIRONMENT',
            '/tmp/build/database.sqlite' => 'PROTECTED_DATABASE',
            '/tmp/dump/dump.rdb' => 'PROTECTED_DATABASE',
            '/tmp/build/id_ed25519' => 'PROTECTED_SECRET',
            '/tmp/build/storage/oauth-private.key' => 'PROTECTED_SECRET',
            '/tmp/repo/.git/objects/ab/cdef' => 'PROTECTED_SEGMENT:.git',
            '/tmp/build/storage/app/public/avatar.png' => 'PROTECTED_MEDIA',
            '/var/www/x/public/uploads/a.jpg' => 'PROTECTED_MEDIA',
            '/var/lib/mysql/ibtmp1' => 'PROTECTED_ROOT',
            '/etc/nginx/nginx.conf' => 'PROTECTED_ROOT',
            $site.'/app/Http/Kernel.php' => ProtectionGuard::PROJECT_FILE,
            $site.'/composer.json' => ProtectionGuard::PROJECT_FILE,
            $site => ProtectionGuard::PROJECT_FILE,
            $site.'/storage/logs/.gitignore' => 'PROTECTED_PLACEHOLDER',
        ];
        foreach ($expected as $path => $reason) {
            $this->assertSame($reason, $guard->reason($path), $path);
        }
        foreach (['/tmp/build/app.js', '/var/log/nginx/access.log.3.gz', $site.'/storage/logs/laravel-2026-01-01.log'] as $disposable) {
            $this->assertNull($guard->reason($disposable), $disposable);
        }
    }

    public function testGuardFindsGitWorkTreesAndTerminatesAtTheFilesystemRoot(): void
    {
        $guard = new ProtectionGuard($this->load());
        mkdir($this->dir.'/repo/.git/objects', 0777, true);
        mkdir($this->dir.'/repo/storage/logs', 0777, true);

        $this->assertSame($this->dir.'/repo', $guard->workTree($this->dir.'/repo/storage/logs'));
        $this->assertNull($guard->workTree($this->dir.'/site/storage/logs'));
        $this->assertNull($guard->workTree(PHP_OS_FAMILY === 'Windows' ? 'C:/' : '/'), 'the upward search ends at the drive or filesystem root');
    }

    public function testExpiredCacheEntriesAreRecognisedByTheirLaravelExpiryPrefix(): void
    {
        $cases = ['expired' => [(string) (time() - 5).'s:1:"x";', true], 'live' => [(string) (time() + 600).'s:1:"x";', false], 'forever' => ['9999999999s:1:"x";', null], 'foreign' => ['not-a-cache-file', null]];
        foreach ($cases as $name => [$content, $expired]) {
            file_put_contents($this->dir.'/'.$name, $content);
            $expiry = DiskScanner::cacheExpiry($this->dir.'/'.$name);
            $this->assertSame($expired, $expiry === null ? null : $expiry <= time(), $name);
        }
        $this->assertSame(null, DiskScanner::cacheExpiry($this->dir.'/forever'), 'forever entries never expire');
    }

    public function testPosixRootsNeverResolveToADriveOnWindows(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->skip('Windows-only: /tmp resolves against the current drive there');
        }
        $policy = $this->load(['rules' => [['id' => 'tmp', 'kind' => 'entries', 'reason' => 'abandoned', 'roots' => ['/tmp'], 'match' => ['*'], 'min_age_days' => 0]]]);
        $plan = (new DiskScanner($policy, new ProtectionGuard($policy), time()))->scan();

        $this->assertSame([], $plan['candidates']);
        $this->assertSame([['rule' => 'tmp', 'root' => '/tmp', 'status' => 'missing']], $plan['roots']);
    }

    public function testReportFormattingIsExactAndMarkdownSafe(): void
    {
        $this->assertSame('0 B', JanitorReport::bytes(0));
        $this->assertSame('1023 B', JanitorReport::bytes(1023));
        $this->assertSame('1.00 KiB', JanitorReport::bytes(1024));
        $this->assertSame('1.50 GiB', JanitorReport::bytes(1610612736));

        $markdown = JanitorReport::markdown([
            'run_id' => 'janitor-1', 'status' => 'completed', 'dry_run' => false, 'started_at' => 's', 'finished_at' => 'f',
            'policy' => ['file' => 'p.json', 'sha256' => str_repeat('a', 64)],
            'summary' => ['bytes_planned' => 10, 'bytes_freed' => 10, 'entries_planned' => 1, 'entries_deleted' => 1, 'files_planned' => 1, 'files_deleted' => 1, 'entries_protected' => 0, 'entries_failed' => 0, 'by_rule' => ['r' => ['entries' => 1, 'bytes' => 10]]],
            'entries' => [['outcome' => 'deleted', 'path' => '/tmp/a|b', 'type' => 'file', 'bytes' => 10, 'modified_at' => 'm', 'rule' => 'r', 'reason' => 'why', 'error' => null]],
            'protected' => [],
        ]);
        $this->assertStringContains('| Freed | 10 B (10 bytes) |', $markdown);
        $this->assertStringContains('`/tmp/a\\|b`', $markdown);
    }

    private function load(array $override = []): JanitorPolicy
    {
        $policy = array_replace([
            'schema' => JanitorPolicy::SCHEMA,
            'reports' => 'reports',
            'rules' => [['id' => 'logs', 'kind' => 'files', 'reason' => 'old logs', 'roots' => ['site/storage/logs'], 'match' => ['**.log'], 'min_age_days' => 14]],
        ], $override);
        file_put_contents($this->dir.'/policy.json', Json::encode($policy));

        return JanitorPolicy::load('policy.json', AgentContext::fromFile($this->dir.'/workspace.json'));
    }
}
