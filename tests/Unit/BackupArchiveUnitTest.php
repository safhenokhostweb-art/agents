<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Unit;

use Workspace\Agents\Agents\ServerDiskJanitor\ArchivePipeline;
use Workspace\Agents\Agents\ServerDiskJanitor\ArchiveSettings;
use Workspace\Agents\Agents\ServerDiskJanitor\BackupArchiver;
use Workspace\Agents\Agents\ServerDiskJanitor\JanitorPolicy;
use Workspace\Agents\Agents\ServerDiskJanitor\S3RemoteStore;
use Workspace\Agents\Agents\ServerDiskJanitor\ServerDiskJanitorAgent;
use Workspace\Agents\Agents\ServerDiskJanitor\TreeFingerprint;
use Workspace\Agents\Kernel\AgentContext;
use Workspace\Agents\Kernel\AgentRegistry;
use Workspace\Agents\Kernel\ProtocolException;
use Workspace\Agents\Support\Json;
use Workspace\Agents\Tests\Support\WorkspaceFixture;
use Workspace\Agents\Tests\TestCase;

final class BackupArchiveUnitTest extends TestCase
{
    private string $dir;

    public function setUp(): void
    {
        $dir = str_replace('\\', '/', sys_get_temp_dir()).'/agents-archive-unit-'.bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $this->dir = str_replace('\\', '/', (string) realpath($dir));
        file_put_contents($this->dir.'/workspace.json', Json::encode(['schema' => AgentContext::SCHEMA, 'projects' => ['site' => ['path' => 'site']]]));
    }

    public function tearDown(): void
    {
        WorkspaceFixture::remove($this->dir);
    }

    public function testSignatureV4MatchesTheAwsReferenceExample(): void
    {
        // AWS S3 documentation, "Signature Calculations for the Authorization Header", GET Object example.
        $empty = hash('sha256', '');
        $authorization = S3RemoteStore::authorization('GET', '/test.txt', '', [
            'host' => 'examplebucket.s3.amazonaws.com',
            'range' => 'bytes=0-9',
            'x-amz-content-sha256' => $empty,
            'x-amz-date' => '20130524T000000Z',
        ], $empty, 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1', '20130524T000000Z');

        $this->assertSame('AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request,SignedHeaders=host;range;x-amz-content-sha256;x-amz-date,Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $authorization);
    }

    public function testHandshakeTrustsOnlyDigestsTheDestinationComputed(): void
    {
        $local = ['size' => 10, 'sha256' => str_repeat('a', 64), 'md5' => str_repeat('b', 32)];
        $cases = [
            'missing object' => [null, false, 'not at the destination'],
            'short object' => [['size' => 9, 'sha256' => $local['sha256'], 'md5' => null, 'source' => 'head'], false, 'holds 9 bytes'],
            'sha256 match' => [['size' => 10, 'sha256' => strtoupper($local['sha256']), 'md5' => null, 'source' => 'head'], true, 'SHA-256 matches'],
            'sha256 mismatch' => [['size' => 10, 'sha256' => str_repeat('c', 64), 'md5' => $local['md5'], 'source' => 'head'], false, 'SHA-256 differs'],
            'sha256 wins over a non-md5 etag (SSE-KMS)' => [['size' => 10, 'sha256' => $local['sha256'], 'md5' => str_repeat('d', 32), 'source' => 'head'], true, 'SHA-256 matches'],
            'md5 etag match' => [['size' => 10, 'sha256' => null, 'md5' => $local['md5'], 'source' => 'head'], true, 'MD5 matches'],
            'md5 etag mismatch' => [['size' => 10, 'sha256' => null, 'md5' => str_repeat('e', 32), 'source' => 'head'], false, 'MD5 differs'],
            'no digest at all' => [['size' => 10, 'sha256' => null, 'md5' => null, 'source' => 'head'], false, 'cannot be verified'],
        ];
        foreach ($cases as $name => [$remote, $verified, $reason]) {
            $result = BackupArchiver::handshake($remote, $local);
            $this->assertSame($verified, $result['verified'], $name);
            $this->assertStringContains($reason, $result['reason']);
        }
    }

    public function testSettingsMergeEnvironmentValidateAndNeverExposeCredentials(): void
    {
        file_put_contents($this->dir.'/settings.json', Json::encode(['encryption' => ['tool' => 'gpg', 'recipient_files' => ['/k.asc']], 'remote' => ['driver' => 's3', 'endpoint' => 'https://s3.example.com', 'region' => 'r', 'bucket' => 'b', 'access_key' => 'FILEKEY']]));
        @chmod($this->dir.'/settings.json', 0600);
        $settings = ArchiveSettings::load($this->dir.'/settings.json', ['JANITOR_ARCHIVE_S3_SECRET_KEY' => 'env-secret', 'JANITOR_ARCHIVE_S3_PATH_STYLE' => 'false', 'UNRELATED' => 'x']);

        $this->assertSame([], $settings->problems, implode('; ', $settings->problems));
        $this->assertSame('env-secret', $settings->get('remote.secret_key'));
        $this->assertFalse($settings->get('remote.path_style'));
        $this->assertSame(['JANITOR_ARCHIVE_S3_SECRET_KEY', 'JANITOR_ARCHIVE_S3_PATH_STYLE'], $settings->environment);
        $described = Json::encode($settings->describe());
        $this->assertFalse(str_contains($described, 'env-secret') || str_contains($described, 'FILEKEY'), 'describe() carries no credentials');

        $empty = ArchiveSettings::load($this->dir.'/none.json', []);
        $this->assertStringContains('no encryption recipient', implode('; ', $empty->problems));
        $this->assertStringContains('remote.driver must be s3 or filesystem', implode('; ', $empty->problems));

        $keyring = ArchiveSettings::load($this->dir.'/none.json', ['JANITOR_ARCHIVE_RECIPIENTS' => 'someone@example.com', 'JANITOR_ARCHIVE_REMOTE_DRIVER' => 'filesystem', 'JANITOR_ARCHIVE_REMOTE_PATH' => '/mnt/x']);
        $this->assertStringContains('gpg takes public key files only', implode('; ', $keyring->problems));

        $endpoint = ArchiveSettings::load($this->dir.'/settings.json', ['JANITOR_ARCHIVE_S3_SECRET_KEY' => 's', 'JANITOR_ARCHIVE_S3_ENDPOINT' => 'https://s3.example.com/bucket']);
        $this->assertStringContains('bare http(s)://host', implode('; ', $endpoint->problems));
    }

    public function testASettingsFileWithASecretMustBePrivate(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->skip('POSIX permissions');
        }
        file_put_contents($this->dir.'/settings.json', Json::encode(['encryption' => ['recipient_files' => ['/k.asc']], 'remote' => ['driver' => 's3', 'endpoint' => 'https://s3.example.com', 'region' => 'r', 'bucket' => 'b', 'access_key' => 'a', 'secret_key' => 's']]));
        chmod($this->dir.'/settings.json', 0644);
        $this->assertStringContains('chmod 600', implode('; ', ArchiveSettings::load($this->dir.'/settings.json', [])->problems));
        chmod($this->dir.'/settings.json', 0600);
        $this->assertSame([], ArchiveSettings::load($this->dir.'/settings.json', [])->problems);
    }

    public function testPolicyArchiveSectionIsValidated(): void
    {
        $shipped = JanitorPolicy::load(dirname(__DIR__, 2).'/disk-janitor.json', AgentContext::fromFile(dirname(__DIR__, 2).'/workspace.json'));
        $this->assertSame(['deploy-backups', 'bootstrap-dirs'], array_column($shipped->archive['sources'], 'id'));
        $this->assertSame(14.0, $shipped->archive['sources'][0]['min_age_days']);
        $this->assertSame(2, $shipped->archive['sources'][0]['retention']['keep_newest'], 'the newest deployment backups stay local for rollback');
        $this->assertSame('gzip', $shipped->archive['compression']);

        $source = ['id' => 'b', 'reason' => 'old', 'roots' => ['x'], 'match' => ['*'], 'min_age_days' => 14];
        $cases = [
            'needs at least one source' => ['sources' => []],
            'takes no kind' => ['sources' => [$source + ['kind' => 'files']]],
            'protected system location' => ['sources' => [array_replace($source, ['roots' => ['/etc']])]],
            'archive.staging must be a path' => ['staging' => ''],
            'gzip or zstd' => ['compression' => 'xz'],
            'out of range' => ['compression' => 'gzip', 'compression_level' => 12],
        ];
        foreach ($cases as $message => $override) {
            $this->assertThrows(ProtocolException::class, fn () => $this->policy(array_replace(['settings' => 's.json', 'staging' => 'st', 'sources' => [$source]], $override)), $message);
        }
    }

    public function testTreeFingerprintSeesEveryChangeAndFlagsUnsafeTrees(): void
    {
        mkdir($this->dir.'/tree/sub', 0777, true);
        file_put_contents($this->dir.'/tree/a.txt', 'one');
        file_put_contents($this->dir.'/tree/sub/b.env', 'two');
        $first = TreeFingerprint::of($this->dir.'/tree');
        $this->assertNull($first['problem']);
        $this->assertSame(2, $first['files']);
        $this->assertSame(6, $first['bytes']);
        $this->assertSame($first['fingerprint'], TreeFingerprint::of($this->dir.'/tree')['fingerprint'], 'stable when nothing changes');

        file_put_contents($this->dir.'/tree/sub/b.env', 'TWO');
        touch($this->dir.'/tree/sub/b.env', time() + 5);
        $this->assertFalse($first['fingerprint'] === TreeFingerprint::of($this->dir.'/tree')['fingerprint'], 'a rewritten file changes it');

        mkdir($this->dir.'/tree/repo/.git', 0777, true);
        $this->assertStringContains('GIT_REPOSITORY', (string) TreeFingerprint::of($this->dir.'/tree')['problem']);
        $this->assertSame('NOT_A_DIRECTORY', TreeFingerprint::of($this->dir.'/missing')['problem']);
    }

    public function testMsysToolsGetPosixPathsAndNativeToolsDoNot(): void
    {
        $msys = new ArchivePipeline(['gpg' => 'C:/Program Files/Git/usr/bin/gpg.exe', 'tar' => '/usr/bin/tar'], 'gpg', [], [], 'gzip', 6);
        $expected = PHP_OS_FAMILY === 'Windows' ? '/c/Temp/x.asc' : 'C:/Temp/x.asc';
        $this->assertSame($expected, $msys->toolPath('gpg', 'C:\\Temp\\x.asc'));
        $native = new ArchivePipeline(['gpg' => 'C:/Program Files/GnuPG/bin/gpg.exe'], 'gpg', [], [], 'gzip', 6);
        $this->assertSame('C:/Temp/x.asc', $native->toolPath('gpg', 'C:/Temp/x.asc'));
    }

    public function testRegistryDeclaresTheArchiveTaskWithDryRunByDefault(): void
    {
        $agents = dirname(__DIR__, 2);
        $registry = AgentRegistry::fromFile($agents.'/agents.json');
        $this->assertContains(ServerDiskJanitorAgent::TASK_ARCHIVE, $registry->get(ServerDiskJanitorAgent::ID)->handles());
        $this->assertContains(['agent' => ServerDiskJanitorAgent::ID, 'topic' => ServerDiskJanitorAgent::TOPIC_ARCHIVE_REQUESTED, 'task' => ServerDiskJanitorAgent::TASK_ARCHIVE], $registry->subscriptions());

        $config = Json::decodeFile($agents.'/agents.json');
        $entry = array_column($config['agents'], null, 'id')[ServerDiskJanitorAgent::ID];
        // The declaration documents the agent's built-in default; this test keeps them equal.
        $this->assertSame(['disk.clean' => ['dry_run' => true], 'backup.archive' => ['dry_run' => true]], $entry['defaults']);
        $this->assertContains(BackupArchiver::EVENT_REPORTED, $entry['produces']['events']);
        $this->assertContains(BackupArchiver::ARTIFACT_KIND, $entry['produces']['artifacts']);
        $this->assertSame(['disk.clean', 'backup.archive'], $config['dashboard']['tasks'][ServerDiskJanitorAgent::ID], 'the canvas offers both tasks');
    }

    private function policy(array $archive): JanitorPolicy
    {
        file_put_contents($this->dir.'/policy.json', Json::encode([
            'schema' => JanitorPolicy::SCHEMA,
            'reports' => 'reports',
            'rules' => [['id' => 'logs', 'kind' => 'files', 'reason' => 'old logs', 'roots' => ['site'], 'match' => ['**.log'], 'min_age_days' => 14]],
            'archive' => $archive,
        ]));

        return JanitorPolicy::load('policy.json', AgentContext::fromFile($this->dir.'/workspace.json'));
    }
}
