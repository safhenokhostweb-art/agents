<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\ServerDiskJanitor;

use Closure;
use RuntimeException;
use Throwable;
use Workspace\Agents\Kernel\Artifact;
use Workspace\Agents\Kernel\Finding;
use Workspace\Agents\Kernel\ProtocolException;
use Workspace\Agents\Kernel\Result;
use Workspace\Agents\Kernel\Task;
use Workspace\Agents\Support\Glob;
use Workspace\Agents\Support\Id;
use Workspace\Agents\Support\Json;

/**
 * backup.archive: move old backup directories off the server, encrypted.
 *
 *   plan     sources older than the policy age, beyond retention, safe to delete as a whole
 *   preflight settings, binaries, public keys only, remote reachable, staging space
 *   phase A  per source: tar → compress → encrypt (streamed) → SHA-256 → upload →
 *            handshake (destination-computed digest must equal ours) → manifest likewise
 *   phase B  only when every source passed phase A: delete each local source,
 *            provided its tree is still exactly what was archived
 *
 * Any failure in phase A aborts the run before phase B: nothing is deleted.
 * The report is rewritten after every step, so the audit trail always shows
 * how far a run got.
 */
final class BackupArchiver
{
    public const SCHEMA = 'disk-janitor.archive-report/v1';
    public const ARTIFACT_KIND = 'backup-archive';
    public const ARTIFACT_NAME = 'archive';
    public const EVENT_REPORTED = 'backup.archive.reported';

    private array $document = [];

    private JanitorReport $report;

    /** @param Closure(ArchiveSettings): RemoteStore|null $storeFactory */
    public function __construct(
        private readonly JanitorPolicy $policy,
        private readonly ProtectionGuard $guard,
        private readonly ?Closure $storeFactory,
        private readonly int $now,
    ) {
    }

    /** @param array{dry_run: bool, policy: ?string, sources: ?list<string>} $options */
    public function run(Task $task, array $options): Result
    {
        $archive = $this->policy->archive ?? throw new ProtocolException('ARCHIVE_NOT_CONFIGURED', 'The janitor policy has no archive section; backup.archive has nothing to do.');
        $sourceIds = array_column($archive['sources'], 'id');
        if ($options['sources'] !== null && ($unknown = array_diff($options['sources'], $sourceIds)) !== []) {
            throw new ProtocolException('INVALID_PAYLOAD', 'Unknown archive source(s): '.implode(', ', $unknown).'. Policy sources: '.implode(', ', $sourceIds));
        }

        $runId = Id::new('archive');
        $this->report = new JanitorReport($this->policy->reportsDir, $runId, $this->now);
        $this->report->prepare();

        $settings = ArchiveSettings::load(getenv('JANITOR_ARCHIVE_SETTINGS') ?: $archive['settings']);
        $plan = $this->plan($archive, $options['sources']);
        $preflight = $this->preflight($archive, $settings, $plan['entries'], $runId);

        $this->document = [
            'schema' => self::SCHEMA,
            'run_id' => $runId,
            'status' => $options['dry_run'] ? 'dry-run' : 'planned',
            'dry_run' => $options['dry_run'],
            'started_at' => gmdate('c', $this->now),
            'finished_at' => null,
            'task' => ['id' => $task->id, 'correlation_id' => $task->correlationId],
            'policy' => ['file' => $this->policy->file, 'sha256' => $this->policy->sha256, 'sources' => $options['sources'] ?? $sourceIds],
            'pipeline' => ['compression' => $archive['compression'], 'compression_level' => $archive['compression_level'], 'encryption' => $settings->tool(), 'staging' => $archive['staging'], 'max_archive_bytes' => $archive['max_archive_bytes']],
            'settings' => $settings->describe(),
            'remote' => $preflight['remote'],
            'recipients' => $preflight['recipients'],
            'preflight' => $preflight['checks'],
            'summary' => [],
            'entries' => array_map(static fn (array $e): array => $e + ['status' => 'pending'], $plan['entries']),
            'skipped' => $plan['skipped'],
            'retained' => $plan['retained'],
            'roots' => $plan['roots'],
            'error' => null,
        ];
        $failed = array_values(array_filter($preflight['checks'], static fn (array $c): bool => $c['status'] === 'fail'));

        if ($options['dry_run']) {
            foreach ($this->document['entries'] as $i => $entry) {
                $this->document['entries'][$i]['status'] = 'would-archive';
            }

            return $this->finish($task, $failed);
        }
        if ($failed !== []) {
            $this->document['status'] = 'blocked';
            $this->document['error'] = ['step' => 'preflight', 'message' => implode('; ', array_column($failed, 'detail'))];
            $this->save();
            throw new ProtocolException('ARCHIVE_PREFLIGHT_FAILED', 'Archive preflight failed, nothing was archived or deleted: '.$this->document['error']['message'].'. Report: '.$this->report->jsonPath());
        }
        if ($this->document['entries'] === []) {
            $this->document['status'] = 'completed';

            return $this->finish($task, []);
        }

        // Write-ahead: the plan is on disk before anything is created or removed.
        $this->save();
        $store = $preflight['store'];
        $pipeline = $preflight['pipeline'];
        $workDir = $archive['staging'].'/'.$runId;
        if (! @mkdir($workDir, 0700, true) && ! is_dir($workDir)) {
            return $this->abort($task, null, 'staging', "cannot create {$workDir}");
        }

        try {
            $this->document['status'] = 'archiving';
            foreach ($this->document['entries'] as $i => $entry) {
                $error = $this->archiveOne($i, $archive, $pipeline, $store, $workDir, $runId);
                if ($error !== null) {
                    return $this->abort($task, $i, $error['step'], $error['message']);
                }
            }

            // Phase B: every archive is off-site and verified.
            $this->document['status'] = 'deleting';
            $this->save();
            foreach ($this->document['entries'] as $i => $entry) {
                $error = $this->deleteOne($i);
                if ($error !== null) {
                    return $this->abort($task, $i, 'delete', $error);
                }
            }
            $this->document['status'] = 'completed';

            return $this->finish($task, []);
        } finally {
            ArchivePipeline::removeTree($workDir);
        }
    }

    /** @return array{entries: list<array>, skipped: list<array>, retained: list<array>, roots: list<array>} */
    private function plan(array $archive, ?array $only): array
    {
        $scanner = new DiskScanner($this->policy, $this->guard, $this->now);
        $entries = $skipped = $retained = [];
        foreach ($archive['sources'] as $source) {
            if ($only !== null && ! in_array($source['id'], $only, true)) {
                continue;
            }
            foreach ($scanner->expandRoots($source) as $root) {
                $items = [];
                foreach (@scandir($root) ?: [] as $name) {
                    $path = $root.'/'.$name;
                    if ($name === '.' || $name === '..' || ! Glob::matches($name, $source['match']) || Glob::matches($name, $source['exclude'])
                        || is_link($path) || DiskScanner::isJunction($path) || ! is_dir($path)) {
                        continue;
                    }
                    $items[] = ['path' => $path, 'stat' => (array) @lstat($path)];
                }
                [$items, $kept] = DiskScanner::retain($source, $items);
                array_push($retained, ...$kept);
                foreach ($items as $item) {
                    if ($this->now - (int) $item['stat']['mtime'] < $source['min_age_days'] * 86400) {
                        continue;
                    }
                    $tree = TreeFingerprint::of($item['path']);
                    if ($this->now - $tree['newest'] < $source['min_age_days'] * 86400) {
                        continue;
                    }
                    $entry = [
                        'source' => $source['id'],
                        'path' => $item['path'],
                        'reason' => $source['reason'],
                        'original_bytes' => $tree['bytes'],
                        'files' => $tree['files'],
                        'modified_at' => gmdate('c', $tree['newest']),
                        'fingerprint' => $tree['fingerprint'],
                    ];
                    // The source directory itself must be deletable; its contents (.env, databases) are why it is encrypted.
                    $problem = $tree['problem'] ?? $this->guard->reason($item['path']);
                    if ($problem !== null) {
                        $skipped[] = $entry + ['problem' => $problem];
                    } else {
                        $entries[] = $entry;
                    }
                }
            }
        }

        return ['entries' => $entries, 'skipped' => $skipped, 'retained' => $retained, 'roots' => $scanner->roots()];
    }

    /**
     * Everything a real run needs, checked without archiving anything. A
     * dry run reports these checks; a real run refuses to start on any fail.
     *
     * @return array{checks: list<array{check: string, status: string, detail: string}>, remote: array, recipients: list<string>, store: ?RemoteStore, pipeline: ?ArchivePipeline}
     */
    private function preflight(array $archive, ArchiveSettings $settings, array $entries, string $runId): array
    {
        $checks = [];
        $check = static function (string $name, ?string $problem, string $ok) use (&$checks): bool {
            $checks[] = ['check' => $name, 'status' => $problem === null ? 'pass' : 'fail', 'detail' => $problem ?? $ok];

            return $problem === null;
        };
        $settingsOk = $check('settings', $settings->problems === [] ? null : implode('; ', $settings->problems), 'settings '.($settings->file ?? '(environment only)').' are complete');

        $posixOnWindows = PHP_OS_FAMILY === 'Windows' && str_starts_with($archive['staging'], '/');
        $stagingOk = $check('staging', $posixOnWindows ? "staging {$archive['staging']} is a POSIX path and does not apply to this host" : (! is_dir($archive['staging']) && ! @mkdir($archive['staging'], 0700, true) && ! is_dir($archive['staging']) ? "cannot create staging directory {$archive['staging']}" : null), "staging {$archive['staging']} is usable");
        if ($stagingOk) {
            $largest = $entries === [] ? 0 : max(array_column($entries, 'original_bytes'));
            $free = @disk_free_space($archive['staging']);
            $check('staging_space', $free !== false && $free < $largest ? sprintf('staging has %s free, the largest source is %s', JanitorReport::bytes((int) $free), JanitorReport::bytes($largest)) : null, 'staging can hold the largest source uncompressed ('.JanitorReport::bytes($largest).')');
        }

        $resolved = ArchivePipeline::resolve($settings, $archive['compression']);
        $toolsOk = $check('binaries', $resolved['missing'] === [] ? null : 'missing: '.implode(', ', $resolved['missing']), implode(', ', $resolved['binaries']));

        $pipeline = null;
        $recipients = [];
        if ($settingsOk && $toolsOk) {
            $pipeline = new ArchivePipeline($resolved['binaries'], $settings->tool(), (array) $settings->get('encryption.recipient_files', []), (array) $settings->get('encryption.recipients', []), $archive['compression'], $archive['compression_level']);
            try {
                $recipients = $pipeline->recipientFingerprints($stagingOk ? $archive['staging'] : sys_get_temp_dir());
                $check('recipients', null, 'public key(s) '.implode(', ', $recipients));
            } catch (Throwable $exception) {
                $check('recipients', $exception->getMessage(), '');
                $pipeline = null;
            }
        }

        $store = null;
        $remote = (array) ($settings->describe()['remote'] ?? []);
        if ($settingsOk) {
            try {
                $store = $this->storeFactory !== null ? ($this->storeFactory)($settings) : self::defaultStore($settings);
                $remote = $store->describe();
                // A probe for an object that cannot exist proves the endpoint answers and the credentials are accepted.
                $probe = $store->stat('.janitor-probe/'.$runId);
                $check('remote', $probe === null ? null : 'probe object unexpectedly exists', 'remote store answered');
            } catch (Throwable $exception) {
                $check('remote', 'remote store unusable: '.$exception->getMessage(), '');
                $store = null;
            }
        }

        return ['checks' => $checks, 'remote' => $remote, 'recipients' => $recipients, 'store' => $store, 'pipeline' => $pipeline];
    }

    public static function defaultStore(ArchiveSettings $settings): RemoteStore
    {
        return match ($settings->get('remote.driver')) {
            's3' => S3RemoteStore::fromSettings($settings),
            'filesystem' => new FilesystemRemoteStore((string) $settings->get('remote.path')),
            default => throw new RuntimeException('unknown remote driver'),
        };
    }

    /** @return array{step: string, message: string}|null */
    private function archiveOne(int $i, array $archive, ArchivePipeline $pipeline, RemoteStore $store, string $workDir, string $runId): ?array
    {
        $entry = $this->document['entries'][$i];
        $name = basename($entry['path']);
        $extension = '.tar.'.($archive['compression'] === 'zstd' ? 'zst' : 'gz').'.'.$this->document['pipeline']['encryption'];
        $key = gmdate('Y/m', $this->now).'/'.$name.'--'.$runId.$extension;
        $output = $workDir.'/'.$name.$extension;
        $step = 'fingerprint';
        try {
            if (TreeFingerprint::of($entry['path'])['fingerprint'] !== $entry['fingerprint']) {
                throw new RuntimeException('the source changed after it was planned');
            }

            $step = 'compress-encrypt';
            $started = microtime(true);
            $result = $pipeline->run($entry['path'], $output, $workDir);
            $this->document['entries'][$i]['archive'] = [
                'key' => $key,
                'compression' => $archive['compression'],
                'encryption' => $this->document['pipeline']['encryption'],
                'compressed_bytes' => $result['compressed_bytes'],
                'encrypted_bytes' => $result['encrypted_bytes'],
                'sha256' => $result['sha256'],
                'md5' => $result['md5'],
                'seconds' => round(microtime(true) - $started, 1),
            ];
            $this->document['entries'][$i]['status'] = 'archived';
            $this->save();

            $step = 'fingerprint';
            if (TreeFingerprint::of($entry['path'])['fingerprint'] !== $entry['fingerprint']) {
                throw new RuntimeException('the source changed while it was being archived');
            }
            if ($result['encrypted_bytes'] > $archive['max_archive_bytes']) {
                throw new RuntimeException(sprintf('archive is %s, above max_archive_bytes (%s)', JanitorReport::bytes($result['encrypted_bytes']), JanitorReport::bytes($archive['max_archive_bytes'])));
            }

            $step = 'upload';
            $digests = ['sha256' => $result['sha256'], 'md5' => $result['md5'], 'size' => $result['encrypted_bytes']];
            $this->document['entries'][$i]['remote'] = $this->transfer($store, $output, $key, $digests);
            $this->document['entries'][$i]['status'] = 'uploaded';

            $step = 'verify';
            $verification = self::handshake($store->stat($key), $digests);
            $this->document['entries'][$i]['remote']['verification'] = $verification;
            if (! $verification['verified']) {
                throw new RuntimeException('handshake failed: '.$verification['reason']);
            }

            $step = 'manifest';
            $manifestPath = $output.'.manifest.json';
            file_put_contents($manifestPath, Json::encode($this->manifest($i, $runId)).PHP_EOL);
            $manifest = ['sha256' => (string) hash_file('sha256', $manifestPath), 'md5' => (string) hash_file('md5', $manifestPath), 'size' => (int) filesize($manifestPath)];
            $this->transfer($store, $manifestPath, $key.'.manifest.json', $manifest);
            $manifestCheck = self::handshake($store->stat($key.'.manifest.json'), $manifest);
            if (! $manifestCheck['verified']) {
                throw new RuntimeException('manifest handshake failed: '.$manifestCheck['reason']);
            }
            $this->document['entries'][$i]['manifest'] = ['key' => $key.'.manifest.json', 'sha256' => $manifest['sha256'], 'verified' => true];
            $this->document['entries'][$i]['status'] = 'verified';
            $this->save();

            return null;
        } catch (Throwable $exception) {
            return ['step' => $step, 'message' => $exception->getMessage()];
        } finally {
            @unlink($output);
            @unlink($output.'.manifest.json');
        }
    }

    private function transfer(RemoteStore $store, string $local, string $key, array $digests): array
    {
        if ($store->stat($key) !== null) {
            throw new RuntimeException("remote object {$key} already exists; refusing to overwrite it");
        }

        return ['location' => $store->describe() + ['key' => $key], 'transfer' => $store->put($local, $key, $digests)];
    }

    /**
     * The upload counts as delivered only when the destination reports the
     * same size and a digest it computed itself equals ours. SHA-256 wins
     * when available (an ETag is not an MD5 under SSE-KMS); with neither,
     * the copy is unverified and the source stays.
     *
     * @param  array{size: ?int, sha256: ?string, md5: ?string, source: string}|null  $remote
     * @return array{verified: bool, method: ?string, reason: string, remote: ?array}
     */
    public static function handshake(?array $remote, array $local): array
    {
        $result = static fn (bool $ok, ?string $method, string $reason): array => ['verified' => $ok, 'method' => $method, 'reason' => $reason, 'remote' => $remote];
        if ($remote === null) {
            return $result(false, null, 'the object is not at the destination');
        }
        if ($remote['size'] !== $local['size']) {
            return $result(false, null, "destination holds {$remote['size']} bytes, expected {$local['size']}");
        }
        if ($remote['sha256'] !== null) {
            return hash_equals($local['sha256'], strtolower($remote['sha256']))
                ? $result(true, "sha256 ({$remote['source']})", 'destination SHA-256 matches')
                : $result(false, null, 'destination SHA-256 differs from the local archive');
        }
        if ($remote['md5'] !== null) {
            return hash_equals($local['md5'], strtolower($remote['md5']))
                ? $result(true, "md5 ({$remote['source']})", 'destination MD5 matches')
                : $result(false, null, 'destination MD5 differs from the local archive');
        }

        return $result(false, null, 'the destination reported no digest of its own, so the copy cannot be verified');
    }

    /** @return string|null error */
    private function deleteOne(int $i): ?string
    {
        $entry = $this->document['entries'][$i];
        $tree = TreeFingerprint::of($entry['path']);
        if ($tree['fingerprint'] !== $entry['fingerprint']) {
            $this->document['entries'][$i]['deletion'] = ['outcome' => 'changed', 'bytes_freed' => 0, 'files_deleted' => 0];
            $this->save();

            return 'the source changed after it was archived; it was kept';
        }
        $progress = ['bytes' => 0, 'files' => 0];
        $error = self::removeSource($entry['path'], $progress);
        $this->document['entries'][$i]['deletion'] = ['outcome' => $error === null ? 'deleted' : 'partial', 'bytes_freed' => $progress['bytes'], 'files_deleted' => $progress['files'], 'error' => $error];
        $this->document['entries'][$i]['status'] = $error === null ? 'deleted' : 'delete-failed';
        $this->save();

        return $error;
    }

    /** Depth-first removal; links are removed, never followed. */
    private static function removeSource(string $directory, array &$progress): ?string
    {
        foreach (@scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory.'/'.$name;
            if (is_link($path) || DiskScanner::isJunction($path)) {
                if (! @unlink($path) && ! @rmdir($path)) {
                    return "cannot remove link {$path}";
                }
                continue;
            }
            if (is_dir($path)) {
                if (($error = self::removeSource($path, $progress)) !== null) {
                    return $error;
                }
                continue;
            }
            $size = (int) @filesize($path);
            if (! @unlink($path)) {
                return "cannot delete {$path}: ".(error_get_last()['message'] ?? 'unknown error');
            }
            $progress['bytes'] += $size;
            $progress['files']++;
        }

        return @rmdir($directory) ? null : "cannot remove directory {$directory}";
    }

    /** What a restore needs to trust the archive; uploaded beside it. No secrets, no plaintext. */
    private function manifest(int $i, string $runId): array
    {
        $entry = $this->document['entries'][$i];

        return [
            'schema' => 'disk-janitor.archive-manifest/v1',
            'run_id' => $runId,
            'source' => ['name' => basename($entry['path']), 'path' => $entry['path'], 'original_bytes' => $entry['original_bytes'], 'files' => $entry['files'], 'tree_fingerprint' => $entry['fingerprint'], 'modified_at' => $entry['modified_at']],
            'archive' => ['format' => 'tar', 'compression' => $entry['archive']['compression'], 'encryption' => $entry['archive']['encryption'], 'recipients' => $this->document['recipients'], 'bytes' => $entry['archive']['encrypted_bytes'], 'sha256' => $entry['archive']['sha256']],
            'created_at' => gmdate('c'),
        ];
    }

    private function abort(Task $task, ?int $index, string $step, string $message): Result
    {
        if ($index !== null) {
            $this->document['entries'][$index]['status'] = 'failed';
            $this->document['entries'][$index]['error'] = ['step' => $step, 'message' => $message];
        }
        foreach ($this->document['entries'] as $i => $entry) {
            if ($entry['status'] === 'pending') {
                $this->document['entries'][$i]['status'] = 'not-started';
            }
        }
        $this->document['status'] = 'aborted';
        $this->document['error'] = ['step' => $step, 'source' => $index !== null ? $this->document['entries'][$index]['path'] : null, 'message' => $message];

        return $this->finish($task, []);
    }

    private function save(): void
    {
        $this->document['summary'] = $this->summary();
        $this->report->write($this->document);
    }

    private function summary(): array
    {
        $entries = $this->document['entries'];
        $sum = static fn (string $section, string $key): int => array_sum(array_map(static fn (array $e): int => (int) ($e[$section][$key] ?? 0), $entries));

        return [
            'sources_planned' => count($entries),
            'original_bytes' => array_sum(array_column($entries, 'original_bytes')),
            'archived' => count(array_filter($entries, static fn (array $e): bool => isset($e['archive']))),
            'compressed_bytes' => $sum('archive', 'compressed_bytes'),
            'encrypted_bytes' => $sum('archive', 'encrypted_bytes'),
            'verified' => count(array_filter($entries, static fn (array $e): bool => ($e['remote']['verification']['verified'] ?? false) === true)),
            'deleted' => count(array_filter($entries, static fn (array $e): bool => ($e['deletion']['outcome'] ?? null) === 'deleted')),
            'bytes_freed' => $sum('deletion', 'bytes_freed'),
            'skipped' => count($this->document['skipped']),
        ];
    }

    /** @param list<array> $preflightFailures */
    private function finish(Task $task, array $preflightFailures): Result
    {
        $this->document['finished_at'] = gmdate('c');
        $this->save();
        $doc = $this->document;
        $s = $doc['summary'];
        $findings = [];

        if ($doc['status'] === 'aborted') {
            $e = $doc['error'];
            $findings[] = Finding::blocker('ARCHIVE_ABORTED', "Archive run aborted at step '{$e['step']}': {$e['message']}. No local backup was deleted.", $e['source'], ['report' => $this->report->jsonPath()], 'Fix the cause and run again; uploaded objects of this run stay at the destination under its run id.');
            if ($s['deleted'] > 0) {
                // Only reachable when phase B itself fails part-way.
                $findings[0] = Finding::blocker('ARCHIVE_ABORTED', "Deletion stopped at {$e['source']}: {$e['message']}. {$s['deleted']} verified source(s) had already been deleted.", $e['source'], ['report' => $this->report->jsonPath()]);
            }
        } elseif ($doc['dry_run']) {
            $findings[] = Finding::info('ARCHIVE_PLANNED', "Dry run: {$s['sources_planned']} backup director".($s['sources_planned'] === 1 ? 'y' : 'ies').' ('.JanitorReport::bytes($s['original_bytes']).') would be archived off-site and then deleted.', null, ['sources' => array_column($doc['entries'], 'path')], $s['sources_planned'] > 0 && $preflightFailures === [] ? 'Review the report, then run with {"dry_run": false}.' : null);
        } else {
            $findings[] = Finding::info('ARCHIVE_COMPLETED', "Archived and verified {$s['verified']} source(s); deleted {$s['deleted']}, freeing ".JanitorReport::bytes($s['bytes_freed']).'.', null, ['objects' => array_map(static fn (array $e): ?string => $e['archive']['key'] ?? null, $doc['entries'])]);
        }
        if ($preflightFailures !== []) {
            $findings[] = Finding::warning('ARCHIVE_PREFLIGHT_FAILED', count($preflightFailures).' preflight check(s) fail; a real run would refuse to start.', null, ['checks' => $preflightFailures], 'Create the settings file named by archive.settings (see disk-janitor.archive.example.json) with a public key and the remote store.');
        }
        if ($doc['skipped'] !== []) {
            $findings[] = Finding::warning('ARCHIVE_SOURCE_SKIPPED', count($doc['skipped']).' old backup director'.(count($doc['skipped']) === 1 ? 'y is' : 'ies are').' not safe to archive-and-delete and were skipped.', null, ['sources' => array_map(static fn (array $e): array => ['path' => $e['path'], 'problem' => $e['problem']], $doc['skipped'])]);
        }

        $blocked = array_filter($findings, static fn (Finding $f): bool => $f->severity->value === 'blocker') !== [];
        $warned = array_filter($findings, static fn (Finding $f): bool => $f->severity->value === 'warning') !== [];
        $verdict = $blocked ? 'BLOCKED' : ($warned ? 'ATTENTION' : 'READY');
        $files = ['json' => $this->report->jsonPath(), 'markdown' => $this->report->markdownPath()];
        $event = ['run_id' => $doc['run_id'], 'dry_run' => $doc['dry_run'], 'status' => $doc['status'], 'verdict' => $verdict, 'summary' => $s, 'report' => $files];
        $text = match ($doc['status']) {
            'aborted' => "Archive aborted at {$doc['error']['step']}: {$doc['error']['message']}",
            'dry-run' => "Dry run: {$s['sources_planned']} source(s), ".JanitorReport::bytes($s['original_bytes']).' would be archived'.($preflightFailures !== [] ? '; '.count($preflightFailures).' preflight check(s) fail.' : '.'),
            default => "Archived {$s['verified']} source(s) off-site (".JanitorReport::bytes($s['encrypted_bytes']).' encrypted), deleted '.$s['deleted'].', freed '.JanitorReport::bytes($s['bytes_freed']).'.',
        };

        return Result::pass(
            $text,
            findings: $findings,
            artifacts: [new Artifact(self::ARTIFACT_KIND, ['schema' => self::SCHEMA, 'run_id' => $doc['run_id'], 'status' => $doc['status'], 'dry_run' => $doc['dry_run'], 'verdict' => $verdict, 'summary' => $s, 'report' => $files], ['policy' => $doc['policy'], 'remote' => $doc['remote'], 'recipients' => $doc['recipients']], ['report.json' => $doc], self::ARTIFACT_NAME)],
            events: [['topic' => self::EVENT_REPORTED, 'payload' => $event]],
            data: $event,
        );
    }
}
