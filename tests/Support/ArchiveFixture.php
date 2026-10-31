<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Support;

use Closure;
use Workspace\Agents\Agents\ServerDiskJanitor\ServerDiskJanitorAgent;
use Workspace\Agents\Kernel\AgentContext;
use Workspace\Agents\Kernel\AgentRegistry;
use Workspace\Agents\Kernel\ArtifactStore;
use Workspace\Agents\Kernel\InMemoryBus;
use Workspace\Agents\Kernel\Orchestrator;
use Workspace\Agents\Kernel\Outcome;
use Workspace\Agents\Kernel\Task;
use Workspace\Agents\Support\Json;
use Workspace\Agents\Support\Paths;

/**
 * A disposable host for backup.archive:
 *
 *   www/site.backup-2026{01,02,03}…   deployment backups; the 2 newest stay (retention)
 *   root/deployment-system-bootstrap-…  an old snapshot with service.env and a database
 *   root/deployment-system-bootstrap-<fresh>, root/…-staging-<git>  not archivable
 *   remote/                             the "off-site" mount (filesystem driver)
 *   s/                                  staging (short: gpg-agent socket paths are limited)
 */
final class ArchiveFixture
{
    public const OLD = 40 * 86400;
    public const OLDEST_BACKUP = 'www/site.backup-20260101-000000-aaaaaa';
    public const BOOTSTRAP = 'root/deployment-system-bootstrap-20260815T232714Z';

    /** Files of the two archivable sources: relative path => content. */
    public const SOURCE_FILES = [
        self::OLDEST_BACKUP.'/.env' => "APP_KEY=base64:oldest-secret\nDB_PASSWORD=hunter2\n",
        self::OLDEST_BACKUP.'/public/index.php' => "<?php echo 'site';\n",
        self::OLDEST_BACKUP.'/storage/app/public/logo.png' => 'PNGDATA',
        self::BOOTSTRAP.'/service.env' => "HUB_SECRET=bootstrap-secret\n",
        self::BOOTSTRAP.'/database.sqlite' => "SQLite format 3\0hub rows",
        self::BOOTSTRAP.'/repository-head.txt' => "abc123\n",
    ];

    public readonly string $root;

    public readonly InMemoryBus $bus;

    public function __construct()
    {
        $root = str_replace('\\', '/', sys_get_temp_dir()).'/ja'.bin2hex(random_bytes(3));
        mkdir($root, 0777, true);
        $this->root = Paths::normalize($root);
        $this->bus = new InMemoryBus();

        foreach (self::SOURCE_FILES as $relative => $content) {
            $this->write($relative, $content);
        }
        $this->write('www/site.backup-20260201-000000-bbbbbb/.env', 'APP_KEY=kept');
        $this->write('www/site.backup-20260301-000000-cccccc/.env', 'APP_KEY=kept');
        $this->write('root/deployment-system-bootstrap-20260901T000000Z/service.env', 'fresh', false);
        $this->write('root/deployment-system-staging-20260816T171948Z/repo/.git/HEAD', "ref: refs/heads/main\n");
        $this->write('root/unrelated-dir/file.txt', 'not matched');
        mkdir($this->root.'/remote');
        mkdir($this->root.'/hub/logs', 0777, true);
        $this->ageDirectories();

        file_put_contents($this->root.'/workspace.json', Json::encode([
            'schema' => AgentContext::SCHEMA,
            'root' => '.',
            'projects' => ['site' => ['path' => 'site']],
            'disk_janitor' => ['policy' => 'janitor.json'],
            'runtime' => ['artifacts' => 'var/artifacts', 'journal' => 'var/bus/journal.jsonl'],
        ]));
        $this->policy();
        $this->settings();
    }

    public function policy(array $archive = []): void
    {
        $shipped = Json::decodeFile(dirname(__DIR__, 2).'/disk-janitor.json');
        file_put_contents($this->root.'/janitor.json', Json::encode([
            'schema' => $shipped['schema'],
            'reports' => 'hub/logs/janitor-reports',
            'protect' => $shipped['protect'],
            'projects' => ['disposable' => []],
            'rules' => [['id' => 'noop', 'kind' => 'files', 'reason' => 'unused', 'roots' => ['nowhere'], 'match' => ['x'], 'min_age_days' => 1]],
            'archive' => array_replace([
                'settings' => $this->root.'/archive-settings.json',
                'staging' => $this->root.'/s',
                'compression' => 'gzip',
                'sources' => [
                    ['id' => 'deploy-backups', 'reason' => 'old deployment backup', 'roots' => ['www'], 'match' => ['*.backup-*'], 'retention' => $shipped['archive']['sources'][0]['retention'], 'min_age_days' => 14],
                    ['id' => 'bootstrap-dirs', 'reason' => 'old bootstrap snapshot', 'roots' => ['root'], 'match' => ['deployment-system-bootstrap-20*', 'deployment-system-staging-20*'], 'min_age_days' => 14],
                ],
            ], $archive),
        ]));
    }

    /** @param array<string, mixed> $override deep-merged into the default settings */
    public function settings(array $override = []): void
    {
        $key = GpgTestKey::get();
        $settings = array_replace_recursive([
            'encryption' => ['tool' => 'gpg', 'recipient_files' => [$key?->publicKeyFile ?? $this->root.'/missing-key.asc']],
            'remote' => ['driver' => 'filesystem', 'path' => $this->root.'/remote'],
        ], $override);
        file_put_contents($this->root.'/archive-settings.json', Json::encode($settings));
    }

    /** @param Closure|null $storeFactory replaces the remote store the settings describe */
    public function run(array $payload, ?Closure $storeFactory = null): Outcome
    {
        $registry = new AgentRegistry();
        $registry->register(new ServerDiskJanitorAgent($storeFactory));
        $orchestrator = new Orchestrator($registry, $this->bus, new ArtifactStore($this->root.'/var/artifacts'), AgentContext::fromFile($this->root.'/workspace.json'));

        return $orchestrator->dispatch(Task::create(ServerDiskJanitorAgent::TASK_ARCHIVE, $payload))[0];
    }

    public function path(string $relative): string
    {
        return $this->root.'/'.$relative;
    }

    /** @return list<string> files under $relative, relative to it */
    public function files(string $relative): array
    {
        $base = $this->path($relative);
        if (! is_dir($base)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($base) + 1);
        }
        sort($files);

        return $files;
    }

    public function write(string $relative, string $content, bool $old = true): void
    {
        $path = $this->path($relative);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
        if ($old) {
            touch($path, time() - self::OLD);
        }
    }

    /** Creating children refreshes directory mtimes; age every old tree bottom-up. */
    public function ageDirectories(): void
    {
        foreach (['www', 'root'] as $top) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->path($top), \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                if ($item->isDir() && ! str_contains(str_replace('\\', '/', $item->getPathname()), 'bootstrap-20260901')) {
                    touch($item->getPathname(), time() - self::OLD);
                }
            }
        }
    }

    public function destroy(): void
    {
        WorkspaceFixture::remove($this->root);
    }
}
