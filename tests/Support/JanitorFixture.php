<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Support;

use RuntimeException;
use Workspace\Agents\Agents\ServerDiskJanitor\ServerDiskJanitorAgent;
use Workspace\Agents\Kernel\AgentContext;
use Workspace\Agents\Kernel\AgentRegistry;
use Workspace\Agents\Kernel\ArtifactStore;
use Workspace\Agents\Kernel\InMemoryBus;
use Workspace\Agents\Kernel\Orchestrator;
use Workspace\Agents\Support\Git;
use Workspace\Agents\Support\Json;
use Workspace\Agents\Support\Paths;
use Workspace\Agents\Support\Process;

/**
 * A disposable host for the janitor:
 *
 *   app/                 a project (git work tree) with storage/ churn, .env, media, a database
 *   host/var/log/nginx/  rotated and live Nginx logs
 *   host/tmp/            abandoned and live build directories
 *   www/                 deployment backups (retention: 2 newest)
 *   hub/storage/logs/    where reports go
 *
 * Every path in DISPOSABLE is what the policy should delete; everything
 * else must survive an applying run.
 */
final class JanitorFixture
{
    public const OLD = 40 * 86400;

    /** Relative paths the policy selects for deletion, with their content. */
    public const DISPOSABLE = [
        'app/storage/logs/laravel-2026-01-01.log' => "old daily log\n",
        'app/storage/logs/worker.log.3.gz' => 'gz-bytes',
        'app/storage/framework/sessions/OLDSESSION0000000000000000000000000000000' => 'a:0:{}',
        'app/storage/app/tmp/upload-1.part' => 'partial upload',
        'host/var/log/nginx/access.log.2.gz' => 'rotated nginx',
        'host/tmp/build-old/out/app.js' => 'console.log(1)',
        'host/tmp/build-old/manifest.json' => '{}',
        'www/site.backup-20260101-000000-aaaaaa/index.php' => '<?php // oldest backup',
    ];

    /** Selected by content (its expiry prefix), not by age. */
    public const EXPIRED_CACHE = 'app/storage/framework/cache/data/aa/bb/expired';

    public readonly string $root;

    public readonly InMemoryBus $bus;

    public function __construct()
    {
        $root = sys_get_temp_dir().'/agents-janitor-'.bin2hex(random_bytes(5));
        mkdir($root, 0777, true);
        $this->root = Paths::normalize($root);
        $this->bus = new InMemoryBus();

        // The project: source, secrets, media and a database must survive.
        $this->write('app/composer.json', '{"name":"fixture/app"}', false);
        $this->write('app/src/Main.php', "<?php\n", true);
        $this->write('app/.env', "APP_KEY=base64:secret\n", true);
        $this->write('app/storage/logs/laravel.log', "live log\n", false);
        $this->write('app/storage/logs/tracked.log', "tracked by git\n", true);
        $this->write('app/storage/framework/sessions/.gitignore', "*\n!.gitignore\n", true);
        $this->write('app/storage/framework/sessions/FRESHSESSION000000000000000000000000000000', 'a:0:{}', false);
        $this->write(self::EXPIRED_CACHE, (time() - 60).'s:3:"old";', false);
        $this->write('app/storage/framework/cache/data/aa/cc/future', (time() + 3600).'s:3:"new";', false);
        $this->write('app/storage/framework/cache/data/aa/dd/forever', '9999999999s:3:"all";', true);
        $this->write('app/storage/app/tmp/upload-2.part', 'in-flight upload', false);
        $this->write('app/storage/app/public/photo.jpg', 'jpeg', true);
        $this->write('app/storage/app/tmp/cache.sqlite', 'sqlite', true);
        $this->initRepository();

        $this->write('host/var/log/nginx/access.log', 'live', true);
        $this->write('host/var/log/nginx/error.log.1', 'recent rotation', false);
        $this->write('host/tmp/build-env/.env', 'DB_PASSWORD=x', true);
        $this->write('host/tmp/build-env/app.js', 'x', true);
        $this->write('host/tmp/build-fresh/app.js', 'still building', false);
        $this->write('host/tmp/keep-me.txt', 'not matched by the rule', true);
        $this->write('www/site.backup-20260201-000000-bbbbbb/index.php', '<?php // kept', true);
        $this->write('www/site.backup-20260301-000000-cccccc/index.php', '<?php // kept', true);
        $this->write('hub/storage/logs/.gitignore', "*\n", false);
        foreach (self::DISPOSABLE as $relative => $content) {
            $this->write($relative, $content, true);
        }
        // Creating children refreshes directory mtimes; age the whole tree last.
        foreach (['host/tmp/build-old/out', 'host/tmp/build-old', 'host/tmp/build-env', 'www/site.backup-20260101-000000-aaaaaa', 'www/site.backup-20260201-000000-bbbbbb', 'www/site.backup-20260301-000000-cccccc'] as $directory) {
            touch($this->root.'/'.$directory, time() - self::OLD);
        }

        file_put_contents($this->root.'/workspace.json', Json::encode([
            'schema' => AgentContext::SCHEMA,
            'root' => '.',
            'projects' => ['app' => ['path' => 'app', 'role' => 'provider']],
            'disk_janitor' => ['policy' => 'janitor.json'],
            'runtime' => ['artifacts' => 'var/artifacts', 'journal' => 'var/bus/journal.jsonl'],
        ]));
        file_put_contents($this->root.'/agents.json', Json::encode([
            'schema' => AgentRegistry::SCHEMA,
            'agents' => [[
                'id' => ServerDiskJanitorAgent::ID,
                'class' => ServerDiskJanitorAgent::class,
                'subscriptions' => [
                    ['topic' => ServerDiskJanitorAgent::TOPIC_REQUESTED, 'task' => ServerDiskJanitorAgent::TASK],
                    ['topic' => 'deployment.completed', 'task' => ServerDiskJanitorAgent::TASK],
                ],
            ]],
        ]));
        $this->policy();
    }

    /** The repository policy shape, pointed at this fixture's host directories. */
    public function policy(array $override = []): void
    {
        $shipped = Json::decodeFile(dirname(__DIR__, 2).'/disk-janitor.json');
        $policy = array_replace([
            'schema' => $shipped['schema'],
            'reports' => 'hub/storage/logs/janitor-reports',
            'protect' => $shipped['protect'],
            'projects' => ['extra_roots' => [], 'disposable' => $shipped['projects']['disposable']],
            'rules' => [
                ['id' => 'laravel-logs', 'kind' => 'files', 'reason' => 'old log', 'roots' => ['*/storage/logs'], 'match' => ['**.log', '**.log.*'], 'exclude' => ['janitor-reports/**'], 'min_age_days' => 14],
                ['id' => 'nginx-logs', 'kind' => 'files', 'reason' => 'rotated nginx log', 'roots' => [$this->root.'/host/var/log/nginx'], 'match' => ['**.log.*', '**.gz'], 'min_age_days' => 14],
                ['id' => 'laravel-temp', 'kind' => 'files', 'reason' => 'stale temp file', 'roots' => ['*/storage/app/tmp'], 'match' => ['**'], 'min_age_days' => 1],
                ['id' => 'laravel-sessions', 'kind' => 'files', 'reason' => 'idle session', 'roots' => ['*/storage/framework/sessions'], 'match' => ['*'], 'min_age_days' => 1],
                ['id' => 'laravel-cache', 'kind' => 'expired-cache', 'reason' => 'expired cache entry', 'roots' => ['*/storage/framework/cache/data'], 'match' => ['**'], 'min_age_days' => 0],
                ['id' => 'abandoned-tmp', 'kind' => 'entries', 'reason' => 'abandoned build', 'roots' => [$this->root.'/host/tmp'], 'match' => ['build-*'], 'min_age_days' => 14],
                ['id' => 'deploy-backups', 'kind' => 'entries', 'reason' => 'backup beyond retention', 'roots' => ['www'], 'match' => ['*.backup-*'], 'retention' => array_column($shipped['rules'], null, 'id')['deploy-backups']['retention'], 'min_age_days' => 7],
            ],
        ], $override);
        file_put_contents($this->root.'/janitor.json', Json::encode($policy));
    }

    public function context(): AgentContext
    {
        return AgentContext::fromFile($this->root.'/workspace.json');
    }

    public function orchestrator(): Orchestrator
    {
        return new Orchestrator(AgentRegistry::fromFile($this->root.'/agents.json'), $this->bus, new ArtifactStore($this->root.'/var/artifacts'), $this->context());
    }

    public function write(string $relative, string $content, bool $old): void
    {
        $path = $this->root.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
        if ($old) {
            touch($path, time() - self::OLD);
        }
    }

    public function path(string $relative): string
    {
        return $this->root.'/'.$relative;
    }

    /** @return list<string> every regular file of the simulated host, relative; runtime output and reports excluded */
    public function files(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($this->root) + 1);
            if (! str_starts_with($relative, 'app/.git/') && ! str_starts_with($relative, 'var/') && ! str_starts_with($relative, 'hub/storage/logs/janitor-reports/')) {
                $files[] = $relative;
            }
        }
        sort($files);

        return $files;
    }

    public function destroy(): void
    {
        WorkspaceFixture::remove($this->root);
    }

    private function initRepository(): void
    {
        $git = static function (string $cwd, string ...$arguments): void {
            $result = Process::run(array_merge([Git::binary(), '-c', 'user.name=fixture', '-c', 'user.email=fixture@example.invalid', '-c', 'commit.gpgsign=false', '-c', 'core.autocrlf=false'], $arguments), $cwd);
            if ($result['code'] !== 0) {
                throw new RuntimeException('git '.implode(' ', $arguments).' failed: '.$result['stderr']);
            }
        };
        $repository = $this->root.'/app';
        $git($repository, 'init', '-q', '-b', 'main');
        // A tracked log under storage/ is project history, not disposable output.
        $git($repository, 'add', '--', 'composer.json', 'src/Main.php', 'storage/logs/tracked.log', 'storage/framework/sessions/.gitignore');
        $git($repository, 'commit', '-q', '-m', 'fixture');
        touch($repository.'/storage/logs/tracked.log', time() - self::OLD);
    }
}
