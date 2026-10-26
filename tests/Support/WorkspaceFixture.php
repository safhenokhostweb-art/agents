<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Workspace\Agents\Agents\DependencyDeployment\DependencyDeploymentAgent;
use Workspace\Agents\Kernel\Agent;
use Workspace\Agents\Kernel\AgentContext;
use Workspace\Agents\Kernel\AgentRegistry;
use Workspace\Agents\Kernel\ArtifactStore;
use Workspace\Agents\Kernel\InMemoryBus;
use Workspace\Agents\Kernel\JournaledBus;
use Workspace\Agents\Kernel\Orchestrator;
use Workspace\Agents\Support\ComposerProject;
use Workspace\Agents\Support\Json;
use Workspace\Agents\Support\Paths;
use Workspace\Agents\Support\Process;

/**
 * A disposable two-project workspace mirroring the real topology:
 *
 *   saas-platform/  provider; serves packages/core as saas-platform/core-utils
 *   customer-site/  consumer; installs it through a `path` repository with
 *                   symlink:false, so vendor/ holds a mirrored copy
 *   remotes/        bare origins, so origin/main refs and fetches are real
 *   deployment-system/deployment/release-prepare.php  (handoff tool stub)
 */
final class WorkspaceFixture
{
    public const PROVIDER = 'saas-platform';
    public const CONSUMER = 'customer-site';
    public const PACKAGE = 'saas-platform/core-utils';

    public readonly string $root;

    public function __construct()
    {
        $root = sys_get_temp_dir().'/agents-ws-'.bin2hex(random_bytes(5));
        mkdir($root, 0777, true);
        $this->root = Paths::normalize($root);
        mkdir($this->root.'/remotes');

        $this->write(self::PROVIDER, 'packages/core/composer.json', Json::encode([
            'name' => self::PACKAGE,
            'require' => ['php' => '^8.1', 'laravel/framework' => '^10.0|^11.0|^12.0'],
        ]));
        $this->write(self::PROVIDER, 'packages/core/src/Util.php', "<?php\n\nreturn 1;\n");
        $this->composer(self::PROVIDER, [
            'name' => 'fixture/platform',
            'repositories' => [['type' => 'path', 'url' => 'packages/core']],
            'require' => ['php' => '^8.1', 'laravel/framework' => '^10.10'],
            'config' => ['platform' => ['php' => '8.3.0']],
        ], ['laravel/framework' => '10.x-dev']);
        $this->policy(self::PROVIDER);
        $this->initRepository(self::PROVIDER);

        $this->composer(self::CONSUMER, [
            'name' => 'fixture/customer',
            'repositories' => [['type' => 'path', 'url' => '../saas-platform/packages/core', 'options' => ['symlink' => false]]],
            'require' => ['php' => '^8.2', 'laravel/framework' => '^12.0', self::PACKAGE => '@dev'],
            'config' => ['platform' => ['php' => '8.3.0']],
        ], ['laravel/framework' => 'v12.1.0', self::PACKAGE => 'dev-main']);
        $this->write(self::CONSUMER, 'src/App.php', "<?php\n\nreturn 'app';\n");
        $this->policy(self::CONSUMER);
        $this->initRepository(self::CONSUMER);
        $this->mirrorPackage();

        $this->write('deployment-system', 'deployment/release-prepare.php', "<?php\n// fixture stub\n");
        file_put_contents($this->root.'/workspace.json', Json::encode([
            'schema' => AgentContext::SCHEMA,
            'root' => '.',
            'projects' => [
                self::PROVIDER => ['path' => self::PROVIDER, 'role' => 'provider'],
                self::CONSUMER => ['path' => self::CONSUMER, 'role' => 'consumer'],
            ],
            'deployment_system' => ['path' => 'deployment-system', 'release_prepare' => 'deployment-system/deployment/release-prepare.php'],
            'runtime' => ['artifacts' => 'var/artifacts', 'journal' => 'var/bus/journal.jsonl'],
        ]));
    }

    public function path(string $project, string $relative = ''): string
    {
        return $this->root.'/'.$project.($relative === '' ? '' : '/'.$relative);
    }

    public function write(string $project, string $relative, string $content): void
    {
        $file = $this->path($project, $relative);
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
    }

    /** Writes composer.json and a composer.lock whose content-hash matches it. */
    public function composer(string $project, array $manifest, array $locked): void
    {
        $this->write($project, 'composer.json', Json::encode($manifest));
        $this->lock($project, $manifest, $locked);
    }

    public function lock(string $project, array $manifest, array $locked): void
    {
        $packages = [];
        foreach ($locked as $name => $version) {
            $packages[] = ['name' => $name, 'version' => $version];
        }
        $this->write($project, 'composer.lock', Json::encode([
            'content-hash' => ComposerProject::contentHash($manifest),
            'packages' => $packages,
            'packages-dev' => [],
        ]));
    }

    /** What `composer reinstall` does for a symlink:false path package. */
    public function mirrorPackage(): void
    {
        $target = $this->path(self::CONSUMER, 'vendor/'.self::PACKAGE);
        self::remove($target);
        $source = $this->path(self::PROVIDER, 'packages/core');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($source) + 1);
            $destination = $target.'/'.$relative;
            if (! is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0777, true);
            }
            copy($file->getPathname(), $destination);
        }
    }

    public function git(string $project, string ...$arguments): string
    {
        $result = Process::run(array_merge(['git'], $arguments), $this->path($project));
        if ($result['code'] !== 0) {
            throw new RuntimeException('git '.implode(' ', $arguments)." failed in {$project}: ".$result['stderr']);
        }

        return trim($result['stdout']);
    }

    public function commitAll(string $project, string $message, bool $push = true): string
    {
        $this->git($project, 'add', '-A');
        $this->git($project, 'commit', '-q', '-m', $message);
        if ($push) {
            $this->git($project, 'push', '-q', 'origin', 'HEAD');
        }

        return $this->git($project, 'rev-parse', 'HEAD');
    }

    public function context(): AgentContext
    {
        return AgentContext::fromFile($this->root.'/workspace.json');
    }

    /**
     * @param  list<array{0: Agent, 1: list<array{topic: string, task: string}>}>  $extraAgents
     * @return array{orchestrator: Orchestrator, bus: InMemoryBus, registry: AgentRegistry, journal: string}
     */
    public function boot(array $extraAgents = []): array
    {
        $registry = new AgentRegistry();
        $registry->register(new DependencyDeploymentAgent());
        foreach ($extraAgents as [$agent, $subscriptions]) {
            $registry->register($agent, $subscriptions);
        }
        $bus = new InMemoryBus();
        $journal = $this->root.'/var/bus/journal.jsonl';
        $orchestrator = new Orchestrator($registry, new JournaledBus($bus, $journal), new ArtifactStore($this->root.'/var/artifacts'), $this->context());

        return ['orchestrator' => $orchestrator, 'bus' => $bus, 'registry' => $registry, 'journal' => $journal];
    }

    public function destroy(): void
    {
        self::remove($this->root);
    }

    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0666);
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            // git marks object files read-only, which blocks unlink on Windows.
            @chmod($item->getPathname(), 0777);
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    private function policy(string $project): void
    {
        $this->write($project, '.deployment/release-policy.json', Json::encode([
            'schema_version' => 1,
            'project_key' => $project,
            'production_branch' => 'main',
            'remote' => 'origin',
            'allowed_release_paths' => ['.deployment/**', 'packages/**', 'src/**', 'database/**', 'composer.json', 'composer.lock'],
            'excluded_runtime_paths' => ['vendor/**', 'storage/**', '**/*.log'],
            'required_clean_state' => ['no_pre_staged_changes' => true],
            'checks' => [],
            'migration_policy' => ['paths' => ['database/migrations/**'], 'review_required_patterns' => ['dropColumn\\s*\\(', 'Schema::drop\\s*\\(']],
            'dependency_rules' => [['when' => 'composer.json', 'requires' => 'composer.lock']],
            'build' => ['required' => true, 'artifact' => 'server-managed-exact-commit'],
            'health' => ['required' => true, 'endpoint_source' => 'fixture'],
            'metadata' => ['local_directory' => '.git/deployment/releases'],
        ]));
    }

    private function initRepository(string $project): void
    {
        $this->write($project, '.gitignore', "/vendor/\n/.deployment/release-task.json\n");
        $this->git($project, 'init', '-q', '-b', 'main');
        $this->git($project, 'config', 'user.name', 'Agents Fixture');
        $this->git($project, 'config', 'user.email', 'agents@example.test');
        $this->git($project, 'config', 'core.autocrlf', 'false');
        $remote = $this->root.'/remotes/'.$project.'.git';
        $result = Process::run(['git', 'init', '-q', '--bare', '-b', 'main', $remote], $this->root);
        if ($result['code'] !== 0) {
            throw new RuntimeException('Cannot create bare remote: '.$result['stderr']);
        }
        $this->git($project, 'remote', 'add', 'origin', $remote);
        $this->git($project, 'add', '-A');
        $this->git($project, 'commit', '-q', '-m', 'baseline');
        $this->git($project, 'push', '-q', '-u', 'origin', 'main');
    }
}
