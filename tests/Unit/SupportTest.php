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

    public function testPathsRelative(): void
    {
        $base = Paths::normalize(sys_get_temp_dir());
        $this->assertSame('a/b', Paths::relative($base, $base.'/a/b'));
        $this->assertSame('', Paths::relative($base, $base));
        $this->assertNull(Paths::relative($base.'/a', $base.'/ab'));
    }
}
