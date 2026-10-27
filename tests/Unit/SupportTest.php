<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Unit;

use ReflectionClass;
use RuntimeException;
use Workspace\Agents\Support\ComposerProject;
use Workspace\Agents\Support\Git;
use Workspace\Agents\Support\Glob;
use Workspace\Agents\Support\Json;
use Workspace\Agents\Support\Paths;
use Workspace\Agents\Support\Process;
use Workspace\Agents\Support\TreeHasher;
use Workspace\Agents\Tests\Support\WorkspaceFixture;
use Workspace\Agents\Tests\TestCase;

final class SupportTest extends TestCase
{
    private const GLOB_CASES = [
        ['app/Http/Kernel.php', 'app/**'],
        ['app', 'app/**'],
        ['composer.json', 'composer.json'],
        ['sub/composer.json', 'composer.json'],
        ['storage/logs/laravel.log', '**/*.log'],
        ['laravel.log', '**/*.log'],
        ['laravel.log', '*.log'],
        ['logs/laravel.log', '*.log'],
        ['.env.production', '.env.*'],
        ['a/b.php', 'a/?.php'],
        ['a/bc.php', 'a/?.php'],
        ['database/migrations/x.php', 'database/migrations/**'],
    ];

    public function testGlobMatchesDeploymentSystemReleasePreparerExactly(): void
    {
        $library = dirname(__DIR__, 3).'/deployment-system/deployment/lib/ReleasePreparer.php';
        if (! is_file($library)) {
            $this->skip('deployment-system is not checked out beside agents/');
        }
        require_once $library;
        $reflection = new ReflectionClass('DeploymentContract\\ReleasePreparer');
        $preparer = $reflection->newInstanceWithoutConstructor();
        $matches = $reflection->getMethod('matches');
        foreach (self::GLOB_CASES as [$path, $pattern]) {
            $this->assertSame($matches->invoke($preparer, $path, [$pattern]), Glob::matches($path, [$pattern]), "{$path} vs {$pattern}");
        }
    }

    public function testGlobSemantics(): void
    {
        $this->assertTrue(Glob::matches('app/Http/Kernel.php', 'app/**'));
        $this->assertFalse(Glob::matches('sub/composer.json', 'composer.json'));
        $this->assertFalse(Glob::matches('logs/laravel.log', '*.log'));
        $this->assertTrue(Glob::matches('storage\\logs\\x.log', 'storage/**'));
    }

    public function testCanonicalJsonIgnoresKeyOrderButKeepsListOrder(): void
    {
        $this->assertSame(Json::canonical(['b' => 1, 'a' => ['y' => 2, 'x' => 1]]), Json::canonical(['a' => ['x' => 1, 'y' => 2], 'b' => 1]));
        $this->assertFalse(Json::canonical([1, 2]) === Json::canonical([2, 1]));
    }

    public function testContentHashMatchesRealWorkspaceLockFiles(): void
    {
        $checked = 0;
        foreach (['saas-platform', 'customer-site', 'deployment-system/service'] as $project) {
            $root = dirname(__DIR__, 3).'/'.$project;
            if (is_file($root.'/composer.json') && is_file($root.'/composer.lock')) {
                $lock = Json::decodeFile($root.'/composer.lock');
                $this->assertSame($lock['content-hash'], ComposerProject::contentHash(Json::decodeFile($root.'/composer.json')), "{$project} content-hash");
                $checked++;
            }
        }
        if ($checked === 0) {
            $this->skip('no workspace projects with composer.lock beside agents/');
        }
    }

    public function testLockSyncDetectsManifestEditAndPathRepositoriesResolve(): void
    {
        $fixture = new WorkspaceFixture();
        try {
            $consumer = ComposerProject::load($fixture->path(WorkspaceFixture::CONSUMER));
            $this->assertTrue($consumer->lockInSync());
            $this->assertSame('v12.1.0', $consumer->lockedVersion('laravel/framework'));
            $this->assertSame('8.3.0', $consumer->platformPhp());
            $repositories = $consumer->pathRepositories();
            $this->assertCount(1, $repositories);
            $this->assertSame(Paths::normalize($fixture->path(WorkspaceFixture::PROVIDER, 'packages/core')), $repositories[0]['directory']);
            $this->assertFalse($repositories[0]['symlink']);

            $manifest = $consumer->manifest;
            $manifest['require']['monolog/monolog'] = '^3.0';
            $fixture->write(WorkspaceFixture::CONSUMER, 'composer.json', Json::encode($manifest));
            $this->assertFalse(ComposerProject::load($fixture->path(WorkspaceFixture::CONSUMER))->lockInSync());
        } finally {
            $fixture->destroy();
        }
    }

    public function testTreeHasherReportsMissingExtraAndModifiedAndIgnoresGit(): void
    {
        $root = sys_get_temp_dir().'/agents-tree-'.bin2hex(random_bytes(4));
        try {
            foreach (['src/a.php' => 'a', 'src/b.php' => 'b', 'c.txt' => 'c', '.git/HEAD' => 'x'] as $file => $content) {
                @mkdir(dirname("{$root}/source/{$file}"), 0777, true);
                file_put_contents("{$root}/source/{$file}", $content);
            }
            foreach (['src/a.php' => 'a', 'src/b.php' => 'B', 'd.txt' => 'd'] as $file => $content) {
                @mkdir(dirname("{$root}/mirror/{$file}"), 0777, true);
                file_put_contents("{$root}/mirror/{$file}", $content);
            }
            $source = TreeHasher::manifest("{$root}/source");
            $this->assertSame(['c.txt', 'src/a.php', 'src/b.php'], array_keys($source));
            $diff = TreeHasher::compare($source, TreeHasher::manifest("{$root}/mirror"));
            $this->assertSame(['c.txt'], $diff['missing']);
            $this->assertSame(['d.txt'], $diff['extra']);
            $this->assertSame(['src/b.php'], $diff['modified']);
        } finally {
            WorkspaceFixture::remove($root);
        }
    }

    public function testGitBinaryPrefersOverrideThenPathThenFallbacks(): void
    {
        $windows = PHP_OS_FAMILY === 'Windows';
        $separator = $windows ? '\\' : '/';
        $root = sys_get_temp_dir().$separator.'agents-git-'.bin2hex(random_bytes(4));
        $onPath = $root.$separator.'path'.$separator.($windows ? 'git.exe' : 'git');
        $fallback = $root.$separator.'fallback'.$separator.'git';
        try {
            foreach ([$onPath, $fallback] as $file) {
                @mkdir(dirname($file), 0777, true);
                file_put_contents($file, '');
                chmod($file, 0755);
            }
            $missing = $root.$separator.'missing';
            $pathList = implode($windows ? ';' : ':', [$missing, '"'.dirname($onPath).$separator.'"']);
            $this->assertSame('/opt/git', Git::findBinary('/opt/git', $pathList, $windows, [$fallback]));
            $this->assertSame($onPath, Git::findBinary('', $pathList, $windows, [$fallback]));
            $this->assertSame($fallback, Git::findBinary('', $missing, $windows, [$missing.$separator.'git', $fallback]));
            $this->assertNull(Git::findBinary('', '', $windows, [$missing.$separator.'git']));
        } finally {
            WorkspaceFixture::remove($root);
        }
    }

    public function testGitStartsWhenServerPathLacksGit(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->skip('CreateProcess PATH lookup is Windows-specific');
        }
        $path = (string) getenv('PATH');
        try {
            // A web-server SAPI under Laragon: PHP is reachable, git is not.
            putenv('PATH='.dirname(PHP_BINARY).';'.getenv('SystemRoot').'\\System32');
            $this->assertThrows(RuntimeException::class, static fn () => Process::run(['git', '--version'], sys_get_temp_dir()), 'Cannot start process: git');
            $binary = Git::findBinary('', (string) getenv('PATH'), true);
            if ($binary === null) {
                $this->skip('git is not installed in a standard Windows/Laragon location');
            }
            $result = Process::run([$binary, '--version'], sys_get_temp_dir());
            $this->assertSame(0, $result['code'], $result['stderr']);
            $this->assertStringContains('git version', $result['stdout']);
        } finally {
            putenv('PATH='.$path);
        }
    }

    public function testPathsNormalizeDriveLetterAndSlashes(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->skip('drive letters are Windows-specific');
        }
        $existing = Paths::normalize(sys_get_temp_dir());
        $variant = str_replace('/', '\\', strtolower($existing[0]).substr($existing, 1));
        $this->assertSame($existing, Paths::normalize($variant));
        $this->assertSame('C:/nope/x', Paths::normalize('c:\\nope\\x\\'));
        $this->assertSame('C:', Paths::normalize('c:'));
        $this->assertSame('bar', Paths::relative('c:\\Foo', 'C:/foo/bar'));
        $this->assertSame('', Paths::relative('c:\\laragon\\www\\saas-platform', 'C:/laragon/www/saas-platform'));
    }

    public function testGitReportsWhyARootIsNotTheRepositoryTopLevel(): void
    {
        $root = Paths::normalize(sys_get_temp_dir()).'/agents-gitroot-'.bin2hex(random_bytes(4));
        try {
            $this->assertSame('directory does not exist', (new Git($root))->notRepositoryReason());
            mkdir($root.'/sub', 0777, true);
            $this->assertStringContains('not a git repository', (string) (new Git($root))->notRepositoryReason());

            Process::run([Git::binary(), 'init', '-q', $root], dirname($root));
            $this->assertNull((new Git($root))->notRepositoryReason());
            $this->assertTrue((new Git($root))->isRepository());
            $this->assertStringContains('top level as '.$root, (string) (new Git($root.'/sub'))->notRepositoryReason());
            if (PHP_OS_FAMILY === 'Windows') {
                // How shells and IDEs spell the same root.
                $this->assertTrue((new Git(str_replace('/', '\\', strtolower($root[0]).substr($root, 1))))->isRepository());
            }
        } finally {
            WorkspaceFixture::remove($root);
        }
    }

    public function testWindowsFallbacksPreferSystemGitOverLaragonBundledGit(): void
    {
        $order = array_map(static fn (string $p): bool => str_contains($p, 'Program Files'), Git::WINDOWS_FALLBACKS);
        $this->assertSame([true, true, false, false], $order);
    }

    /**
     * The canvas failure: the dashboard spawns bin/agent from the web server,
     * whose environment has no git on PATH and no home directory, so git
     * cannot read the global safe.directory that trusts repositories owned by
     * BUILTIN\Administrators. Every real workspace project must still resolve.
     */
    public function testWorkspaceProjectsAreRepositoriesUnderAWebServerEnvironment(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->skip('web-server environment simulation is Windows-specific');
        }
        $workspace = dirname(__DIR__, 2).'/workspace.json';
        $script = 'require '.var_export(dirname(__DIR__, 2).'/bootstrap.php', true).';'
            .'$c = Workspace\Agents\Kernel\AgentContext::fromFile('.var_export($workspace, true).'); $r = [];'
            .'foreach (array_keys($c->projects()) as $n) { $p = $c->projectPath($n); $r[$n] = is_dir($p) ? (new Workspace\Agents\Support\Git($p))->notRepositoryReason() : "absent"; }'
            .'echo json_encode([Workspace\Agents\Support\Git::binary(), $r]);';
        $env = ['SystemRoot' => (string) getenv('SystemRoot'), 'PATH' => dirname(PHP_BINARY).';'.getenv('SystemRoot').'\\System32', 'TEMP' => sys_get_temp_dir()];
        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, sys_get_temp_dir(), $env, ['bypass_shell' => true]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        proc_close($process);

        [$binary, $reasons] = Json::decode($stdout);
        if ($binary === 'git') {
            $this->skip('git is not installed in a standard Windows/Laragon location');
        }
        $reasons = array_filter($reasons, static fn (?string $reason): bool => $reason !== 'absent');
        if ($reasons === []) {
            $this->skip('no workspace projects are checked out beside agents/');
        }
        $this->assertSame(array_fill_keys(array_keys($reasons), null), $reasons, $stderr);
    }

    public function testPathsRelative(): void
    {
        $base = Paths::normalize(sys_get_temp_dir());
        $this->assertSame('a/b', Paths::relative($base, $base.'/a/b'));
        $this->assertSame('', Paths::relative($base, $base));
        $this->assertNull(Paths::relative($base.'/a', $base.'/ab'));
    }
}
