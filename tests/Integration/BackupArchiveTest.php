<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Integration;

use RuntimeException;
use Workspace\Agents\Agents\ServerDiskJanitor\ArchivePipeline;
use Workspace\Agents\Agents\ServerDiskJanitor\ArchiveSettings;
use Workspace\Agents\Agents\ServerDiskJanitor\BackupArchiver;
use Workspace\Agents\Agents\ServerDiskJanitor\FilesystemRemoteStore;
use Workspace\Agents\Kernel\ArtifactStore;
use Workspace\Agents\Kernel\Outcome;
use Workspace\Agents\Kernel\Status;
use Workspace\Agents\Support\Json;
use Workspace\Agents\Tests\Support\ArchiveFixture;
use Workspace\Agents\Tests\Support\GpgTestKey;
use Workspace\Agents\Tests\Support\ScriptedRemoteStore;
use Workspace\Agents\Tests\TestCase;

final class BackupArchiveTest extends TestCase
{
    private ArchiveFixture $fixture;

    private ?GpgTestKey $key;

    /** @var resource|null */
    private $server = null;

    public function setUp(): void
    {
        $this->key = GpgTestKey::get();
        $this->fixture = new ArchiveFixture();
    }

    public function tearDown(): void
    {
        if ($this->server !== null) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->fixture->destroy();
    }

    public function testDryRunIsTheDefaultAndOnlyPlans(): void
    {
        $this->requireGpg();
        $outcome = $this->fixture->run([]);

        $this->assertSame(Status::Pass, $outcome->result->status, $outcome->result->summary);
        $report = $this->report($outcome);
        $this->assertSame('dry-run', $report['status']);
        $this->assertSame([ArchiveFixture::BOOTSTRAP, ArchiveFixture::OLDEST_BACKUP], $this->paths($report['entries']));
        $this->assertSame(['would-archive'], array_values(array_unique(array_column($report['entries'], 'status'))));
        $this->assertSame(['pass'], array_values(array_unique(array_column($report['preflight'], 'status'))), Json::encode($report['preflight']));
        $this->assertSame(['gpg:'.$this->key->fingerprint], $report['recipients']);

        $skipped = array_column(array_map(fn (array $e): array => ['path' => $this->relative($e['path']), 'problem' => $e['problem']], $report['skipped']), 'problem', 'path');
        $this->assertStringContains('GIT_REPOSITORY', $skipped['root/deployment-system-staging-20260816T171948Z']);
        $this->assertCount(2, $report['retained'], 'the two newest deployment backups stay local');

        $this->assertSame([], $this->fixture->files('remote'), 'a dry run uploads nothing');
        foreach (array_keys(ArchiveFixture::SOURCE_FILES) as $file) {
            $this->assertTrue(is_file($this->fixture->path($file)), "{$file} untouched");
        }
        $this->assertSame(array_sum(array_map('strlen', ArchiveFixture::SOURCE_FILES)), $report['summary']['original_bytes']);
        // The skipped git-repository snapshot is worth a look, so the dry run reads ATTENTION.
        $this->assertSame('ATTENTION', $outcome->result->data['verdict']);
        $this->assertSame(['ARCHIVE_PLANNED', 'ARCHIVE_SOURCE_SKIPPED'], array_map(static fn ($f): string => $f->code, $outcome->result->findings));
    }

    public function testArchivesEncryptsUploadsVerifiesThenDeletesAndOnlyThePrivateKeyCanRestore(): void
    {
        $this->requireGpg();
        $outcome = $this->fixture->run(['dry_run' => false]);

        $this->assertSame(Status::Pass, $outcome->result->status, $outcome->result->summary);
        $report = $this->report($outcome);
        $this->assertSame('completed', $report['status'], Json::encode($report['error']));
        $this->assertSame(['deleted'], array_values(array_unique(array_column($report['entries'], 'status'))));
        $this->assertFalse(is_dir($this->fixture->path(ArchiveFixture::OLDEST_BACKUP)));
        $this->assertFalse(is_dir($this->fixture->path(ArchiveFixture::BOOTSTRAP)));
        $this->assertTrue(is_file($this->fixture->path('www/site.backup-20260301-000000-cccccc/.env')), 'retained backups stay');
        $this->assertTrue(is_dir($this->fixture->path('root/deployment-system-staging-20260816T171948Z')), 'skipped sources stay');
        $this->assertSame([], array_values(array_diff(scandir($this->fixture->path('s')), ['.', '..'])), 'staging is left empty');

        $summary = $report['summary'];
        $this->assertSame(2, $summary['verified']);
        $this->assertSame(2, $summary['deleted']);
        $this->assertSame(array_sum(array_map('strlen', ArchiveFixture::SOURCE_FILES)), $summary['bytes_freed']);

        $remote = $this->fixture->files('remote');
        $this->assertCount(4, $remote, 'an archive and a manifest per source: '.implode(', ', $remote));
        foreach ($report['entries'] as $entry) {
            $archive = $entry['archive'];
            $stored = $this->fixture->path('remote/'.$archive['key']);
            $this->assertTrue(preg_match('#^\d{4}/\d{2}/[^/]+--archive-\d{8}T\d{6}-[0-9a-f]{8}\.tar\.gz\.gpg$#', $archive['key']) === 1, $archive['key']);
            $this->assertSame(hash_file('sha256', $stored), $archive['sha256'], 'the reported SHA-256 is the stored object\'s');
            $this->assertSame(filesize($stored), $archive['encrypted_bytes']);
            $this->assertTrue($entry['remote']['verification']['verified']);
            $this->assertSame('sha256 (read-back)', $entry['remote']['verification']['method']);
            $this->assertTrue($archive['compressed_bytes'] > 0 && $entry['original_bytes'] > 0);

            $bytes = (string) file_get_contents($stored);
            $this->assertFalse(str_contains($bytes, 'secret') || str_contains($bytes, 'hunter2'), 'no plaintext in the stored archive');

            $manifest = Json::decodeFile($this->fixture->path('remote/'.$entry['manifest']['key']));
            $this->assertSame($archive['sha256'], $manifest['archive']['sha256']);
            $this->assertSame($entry['fingerprint'], $manifest['source']['tree_fingerprint']);
        }

        // Restore with the private key: the backup's .env comes back byte for byte.
        $entry = array_values(array_filter($report['entries'], static fn (array $e): bool => str_ends_with($e['path'], 'aaaaaa')))[0];
        $this->assertSame("APP_KEY=base64:oldest-secret\nDB_PASSWORD=hunter2\n", $this->restore($entry['archive']['key'], 'site.backup-20260101-000000-aaaaaa/.env'));

        $this->assertTrue(ArtifactStore::verify($outcome->artifacts[0]['path'])['valid']);
        $this->assertSame(['task.started', 'artifact.created', BackupArchiver::EVENT_REPORTED, 'task.completed'], array_map(static fn ($m): string => $m->topic, $this->fixture->bus->history()));
    }

    public function testZstdCompressionStreamsThroughAnExternalCompressor(): void
    {
        $this->requireGpg();
        $resolved = ArchivePipeline::resolve(ArchiveSettings::load('', []), 'zstd');
        if (in_array('zstd', $resolved['missing'], true)) {
            $this->skip('zstd is not installed');
        }
        $this->fixture->policy(['compression' => 'zstd', 'compression_level' => 3]);
        $report = $this->report($this->fixture->run(['dry_run' => false, 'sources' => ['bootstrap-dirs']]));

        $this->assertSame('completed', $report['status'], Json::encode($report['error']));
        $key = $report['entries'][0]['archive']['key'];
        $this->assertTrue(str_ends_with($key, '.tar.zst.gpg'), $key);
        $plain = $this->fixture->path('zstd-plain');
        $this->key->decrypt($this->fixture->path('remote/'.$key), $plain);
        $this->assertSame("\x28\xb5\x2f\xfd", substr((string) file_get_contents($plain), 0, 4), 'the decrypted payload is a zstd frame');
    }

    public function testAFailedUploadAbortsTheRunAndDeletesNothing(): void
    {
        $this->requireGpg();
        $store = fn (): ScriptedRemoteStore => new ScriptedRemoteStore(new FilesystemRemoteStore($this->fixture->path('remote')), onPut: static function (string $key, int $put): void {
            if ($put === 3) {
                throw new RuntimeException('connection reset by peer');
            }
        });
        $outcome = $this->fixture->run(['dry_run' => false], $store);

        $report = $this->report($outcome);
        $this->assertSame('aborted', $report['status']);
        $this->assertSame('upload', $report['error']['step']);
        $this->assertSame(['verified', 'failed'], array_column($report['entries'], 'status'), 'the first source was verified, the second failed');
        $this->assertSame('BLOCKED', $outcome->result->data['verdict']);
        $this->assertSame('ARCHIVE_ABORTED', $outcome->result->findings[0]->code);
        $this->assertSourcesIntact();
        $this->assertSame(0, $report['summary']['bytes_freed']);
    }

    public function testADestinationDigestMismatchKeepsEverySource(): void
    {
        $this->requireGpg();
        $store = fn (): ScriptedRemoteStore => new ScriptedRemoteStore(new FilesystemRemoteStore($this->fixture->path('remote')), onStat: static fn (string $key, ?array $stat): ?array => $stat === null || str_starts_with($key, '.janitor-probe') ? $stat : ['sha256' => str_repeat('0', 64)] + $stat);
        $report = $this->report($this->fixture->run(['dry_run' => false], $store));

        $this->assertSame('aborted', $report['status']);
        $this->assertSame('verify', $report['error']['step']);
        $this->assertStringContains('SHA-256 differs', $report['error']['message']);
        $this->assertSourcesIntact();
    }

    public function testADestinationThatCannotProveWhatItHoldsKeepsEverySource(): void
    {
        $this->requireGpg();
        $store = fn (): ScriptedRemoteStore => new ScriptedRemoteStore(new FilesystemRemoteStore($this->fixture->path('remote')), onStat: static fn (string $key, ?array $stat): ?array => $stat === null ? null : ['sha256' => null, 'md5' => null] + $stat);
        $report = $this->report($this->fixture->run(['dry_run' => false], $store));

        $this->assertSame('verify', $report['error']['step']);
        $this->assertStringContains('cannot be verified', $report['error']['message']);
        $this->assertSourcesIntact();
    }

    public function testASourceChangedAfterArchivingIsNotDeleted(): void
    {
        $this->requireGpg();
        $bootstrap = $this->fixture->path(ArchiveFixture::BOOTSTRAP);
        // Something writes into the snapshot while the archives are in transit.
        $store = fn (): ScriptedRemoteStore => new ScriptedRemoteStore(new FilesystemRemoteStore($this->fixture->path('remote')), onPut: static function (string $key, int $put) use ($bootstrap): void {
            if ($put === 4) {
                file_put_contents($bootstrap.'/late-write.log', 'written after archiving');
            }
        });
        $report = $this->report($this->fixture->run(['dry_run' => false], $store));

        $this->assertSame('aborted', $report['status']);
        $this->assertSame('delete', $report['error']['step']);
        $this->assertStringContains('changed after it was archived', $report['error']['message']);
        $this->assertTrue(is_file($bootstrap.'/late-write.log'));
        $this->assertTrue(is_file($bootstrap.'/service.env'), 'the changed source is kept');
    }

    public function testPreflightFailuresBlockARealRunAndAreReportedByADryRun(): void
    {
        $this->fixture->settings(['remote' => ['driver' => 'ftp']]);

        $dry = $this->fixture->run([]);
        $this->assertSame(Status::Pass, $dry->result->status);
        $this->assertSame('ATTENTION', $dry->result->data['verdict']);
        $this->assertContains('ARCHIVE_PREFLIGHT_FAILED', array_map(static fn ($f): string => $f->code, $dry->result->findings));

        $real = $this->fixture->run(['dry_run' => false]);
        $this->assertSame(Status::Blocked, $real->result->status);
        $this->assertSame('ARCHIVE_PREFLIGHT_FAILED', $real->result->findings[0]->code);
        $this->assertStringContains('remote.driver must be s3 or filesystem', $real->result->summary);
        $this->assertSourcesIntact();
        $this->assertSame([], $this->fixture->files('remote'));
    }

    public function testAPrivateKeyOnTheServerIsRefused(): void
    {
        $this->requireGpg();
        $this->fixture->settings(['encryption' => ['recipient_files' => [$this->key->secretKeyFile]]]);
        $report = $this->report($this->fixture->run([]));

        $recipients = array_column($report['preflight'], null, 'check')['recipients'];
        $this->assertSame('fail', $recipients['status']);
        $this->assertStringContains('private key', $recipients['detail']);
    }

    public function testDirectRunsRejectUnknownKeysAndSources(): void
    {
        $this->assertSame('INVALID_PAYLOAD', $this->fixture->run(['rules' => ['x']])->result->findings[0]->code);
        $unknown = $this->fixture->run(['sources' => ['everything']]);
        $this->assertSame(Status::Blocked, $unknown->result->status);
        $this->assertStringContains('Unknown archive source(s): everything', $unknown->result->summary);
    }

    public function testS3DriverUploadsSignedAndVerifiesTheServerChecksum(): void
    {
        $this->requireGpg();
        if (! function_exists('curl_init')) {
            $this->skip('the curl extension is not loaded');
        }
        $port = $this->startFakeS3();
        $this->fixture->settings(['remote' => ['driver' => 's3', 'endpoint' => "http://127.0.0.1:{$port}", 'region' => 'test-region', 'bucket' => 'backups', 'prefix' => 'prod/', 'access_key' => 'TESTKEY', 'secret_key' => 'test-secret', 'path_style' => true]]);
        // The settings file holds a secret: it must be private (POSIX only).
        @chmod($this->fixture->path('archive-settings.json'), 0600);

        $outcome = $this->fixture->run(['dry_run' => false]);
        $report = $this->report($outcome);
        $this->assertSame('completed', $report['status'], Json::encode($report['error']).Json::encode($report['preflight']));
        foreach ($report['entries'] as $entry) {
            $this->assertSame('sha256 (head)', $entry['remote']['verification']['method']);
            $this->assertSame(200, $entry['remote']['transfer']['http_status']);
        }
        $this->assertFalse(str_contains((string) file_get_contents($outcome->result->data['report']['json']), 'test-secret'), 'credentials never reach the report');
        $this->assertFalse(isset($report['settings']['remote']['secret_key']));

        $requests = array_map(static fn (string $l): array => Json::decode($l), array_filter(explode("\n", (string) file_get_contents($this->fixture->path('s3/requests.log')))));
        $puts = array_values(array_filter($requests, static fn (array $r): bool => $r['method'] === 'PUT'));
        $this->assertCount(4, $puts);
        $this->assertTrue(str_starts_with($puts[0]['path'], '/backups/prod/'), $puts[0]['path']);
    }

    public function testS3HandshakeMismatchAbortsWithoutDeleting(): void
    {
        $this->requireGpg();
        if (! function_exists('curl_init')) {
            $this->skip('the curl extension is not loaded');
        }
        $port = $this->startFakeS3();
        touch($this->fixture->path('s3/lie'));
        $this->fixture->settings(['remote' => ['driver' => 's3', 'endpoint' => "http://127.0.0.1:{$port}", 'region' => 'test-region', 'bucket' => 'backups', 'access_key' => 'TESTKEY', 'secret_key' => 'test-secret']]);
        @chmod($this->fixture->path('archive-settings.json'), 0600);

        $report = $this->report($this->fixture->run(['dry_run' => false]));
        $this->assertSame('verify', $report['error']['step'] ?? null, Json::encode($report['error']).Json::encode($report['preflight']));
        $this->assertSourcesIntact();
    }

    private function requireGpg(): void
    {
        if ($this->key === null) {
            $this->skip('gpg is not installed');
        }
    }

    private function startFakeS3(): int
    {
        mkdir($this->fixture->path('s3'));
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);
        $env = array_merge(getenv(), ['FAKE_S3_DIR' => $this->fixture->path('s3')]);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $this->server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", dirname(__DIR__).'/Support/FakeS3/router.php'], [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, $this->fixture->root, $env);
        for ($i = 0; $i < 50; $i++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($probe !== false) {
                fclose($probe);

                return $port;
            }
            usleep(100000);
        }
        $this->fail('the fake S3 server did not start');
    }

    /** Decrypt and unpack a stored archive; return one member's content. */
    private function restore(string $key, string $member): string
    {
        $work = $this->fixture->path('restore');
        mkdir($work);
        $this->key->decrypt($this->fixture->path('remote/'.$key), $work.'/archive.tar.gz');
        $tar = gzdecode((string) file_get_contents($work.'/archive.tar.gz'));
        $this->assertTrue(is_string($tar), 'the decrypted payload is gzip');
        // Read the member straight from the tar stream (ustar headers of 512-byte blocks).
        for ($offset = 0; $offset + 512 <= strlen($tar);) {
            $header = substr($tar, $offset, 512);
            $name = rtrim(substr($header, 345, 155), "\0");
            $name = ($name !== '' ? $name.'/' : '').rtrim(substr($header, 0, 100), "\0");
            $size = octdec(rtrim(substr($header, 124, 12), "\0 "));
            if ($name === $member) {
                return substr($tar, $offset + 512, (int) $size);
            }
            if (trim($header, "\0") === '') {
                break;
            }
            $offset += 512 + (int) (ceil($size / 512) * 512);
        }
        $this->fail("{$member} is not in the archive");
    }

    private function assertSourcesIntact(): void
    {
        foreach (array_keys(ArchiveFixture::SOURCE_FILES) as $file) {
            $this->assertTrue(is_file($this->fixture->path($file)), "{$file} must survive a failed run");
        }
        $this->assertSame([], array_values(array_diff((array) @scandir($this->fixture->path('s')), ['.', '..'])), 'staging is cleaned up');
    }

    private function report(Outcome $outcome): array
    {
        $path = $outcome->result->data['report']['json'] ?? null;
        $this->assertTrue(is_string($path), 'the run produced a report: '.$outcome->result->summary);
        $this->assertTrue(preg_match('#/hub/logs/janitor-reports/\d{4}-\d{2}-\d{2}/archive-\d{8}T\d{6}-[0-9a-f]{8}\.json$#', $path) === 1, $path);
        $this->assertTrue(is_file(substr($path, 0, -5).'.md'));

        return Json::decodeFile($path);
    }

    /** @return list<string> */
    private function paths(array $entries): array
    {
        $paths = array_map(fn (array $e): string => $this->relative($e['path']), $entries);
        sort($paths);

        return $paths;
    }

    private function relative(string $path): string
    {
        return substr(str_replace('\\', '/', $path), strlen($this->fixture->root) + 1);
    }
}
