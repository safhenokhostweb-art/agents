<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\ServerDiskJanitor;

use Workspace\Agents\Kernel\AbstractAgent;
use Workspace\Agents\Kernel\AgentContext;
use Workspace\Agents\Kernel\Artifact;
use Workspace\Agents\Kernel\Finding;
use Workspace\Agents\Kernel\ProtocolException;
use Workspace\Agents\Kernel\Result;
use Workspace\Agents\Kernel\Task;
use Workspace\Agents\Support\Id;

/**
 * Server Disk Janitor: audits the host for disposable files (old and
 * rotated logs, temporary files, expired sessions and cache entries,
 * abandoned build directories, backups beyond retention) and, only when
 * asked explicitly, deletes them.
 *
 * Owner-approved exception to "agents are read-only towards the projects"
 * (README, Server Disk Janitor): it deletes, and it writes its audit trail
 * outside var/. It is bounded by agents/disk-janitor.json:
 *
 *  - only paths a policy rule selects are considered (allowlist);
 *  - ProtectionGuard vetoes .env files, databases, secrets, git
 *    repositories and tracked files, media uploads and project files;
 *  - every entry's metadata and reason is written to the report before
 *    the first deletion; no report, no deletion;
 *  - dry-run is the default. Deletion needs `"dry_run": false`, and the
 *    CLI's --dry-run forces a dry run whatever the payload says.
 */
final class ServerDiskJanitorAgent extends AbstractAgent
{
    public const ID = 'server-disk-janitor';
    public const TASK = 'disk.clean';
    public const TOPIC_REQUESTED = 'maintenance.disk-clean-requested';
    public const EVENT_REPORTED = 'disk.cleanup.reported';
    public const ARTIFACT_KIND = 'disk-janitor';
    public const ARTIFACT_NAME = 'cleanup';
    public const DEFAULT_POLICY = 'agents/disk-janitor.json';

    /** Payload keys this agent reads. Direct runs reject any other key. */
    private const SCHEMA = ['dry_run' => '?bool', 'policy' => '?string', 'rules' => '?list'];

    /** Entries listed per finding; the report always has all of them. */
    private const FINDING_SAMPLE = 20;

    public function id(): string
    {
        return self::ID;
    }

    public function handles(): array
    {
        return [self::TASK];
    }

    /**
     * Subscription-derived tasks carry the triggering event's payload (e.g.
     * deployment.completed's evidence paths), so validation happens in
     * perform(): strict for direct runs, foreign keys ignored for events.
     */
    protected function payloadSchema(string $taskType): ?array
    {
        return null;
    }

    protected function perform(Task $task, AgentContext $context): Result
    {
        $options = $this->options($task);
        $now = time();
        $policyFile = $options['policy'] ?? (string) ($context->setting('disk_janitor', [])['policy'] ?? self::DEFAULT_POLICY);
        $policy = JanitorPolicy::load($policyFile, $context);
        if ($options['rules'] !== null && ($unknown = array_diff($options['rules'], $policy->ruleIds())) !== []) {
            throw new ProtocolException('INVALID_PAYLOAD', 'Unknown janitor rule(s): '.implode(', ', $unknown).'. Policy rules: '.implode(', ', $policy->ruleIds()));
        }

        $runId = Id::new('janitor');
        $report = new JanitorReport($policy->reportsDir, $runId, $now);
        $report->prepare();

        $guard = new ProtectionGuard($policy);
        $plan = (new DiskScanner($policy, $guard, $now))->scan($options['rules']);
        $entries = array_map(static fn (array $c): array => $c + ['outcome' => 'pending', 'error' => null], $plan['candidates']);

        $document = [
            'schema' => JanitorReport::SCHEMA,
            'run_id' => $runId,
            'status' => $options['dry_run'] ? 'dry-run' : 'planned',
            'dry_run' => $options['dry_run'],
            'started_at' => gmdate('c', $now),
            'finished_at' => null,
            'task' => ['id' => $task->id, 'correlation_id' => $task->correlationId],
            'operator' => self::operator(),
            'policy' => ['file' => $policy->file, 'sha256' => $policy->sha256, 'rules' => $options['rules'] ?? $policy->ruleIds()],
            'summary' => [],
            'roots' => $plan['roots'],
            'entries' => [],
            'protected' => array_map(self::reported(...), $plan['protected']),
            'retained' => $plan['retained'],
            'scan' => ['entries_scanned' => $plan['entries_scanned'], 'truncated' => $plan['truncated']],
        ];

        if ($options['dry_run']) {
            $entries = array_map(static fn (array $e): array => ['outcome' => 'would-delete'] + $e, $entries);
        } else {
            // Write-ahead: the full plan is on disk before anything is removed.
            $document['entries'] = array_map(self::reported(...), $entries);
            $document['summary'] = self::summary($document['entries'], $document['protected']);
            $report->write($document);

            $reclaimer = new Reclaimer($guard);
            foreach ($entries as $index => $entry) {
                $entries[$index] = $reclaimer->reclaim($entry) + $entry;
            }
            $document['status'] = 'completed';
        }
        $document['finished_at'] = gmdate('c');
        $document['entries'] = array_map(self::reported(...), $entries);
        $document['summary'] = self::summary($document['entries'], $document['protected']);
        $report->write($document);

        $findings = $this->findings($document, $plan);
        $summary = $document['summary'];
        $verdict = array_filter($findings, static fn (Finding $f): bool => $f->severity->value !== 'info') === [] ? 'READY' : 'ATTENTION';
        $bytes = $options['dry_run'] ? $summary['bytes_planned'] : $summary['bytes_freed'];
        $text = $options['dry_run']
            ? "Dry run: {$summary['entries_planned']} entr".($summary['entries_planned'] === 1 ? 'y' : 'ies').' ('.JanitorReport::bytes($bytes).') would be deleted; nothing was removed.'
            : "Deleted {$summary['entries_deleted']} of {$summary['entries_planned']} planned entr".($summary['entries_planned'] === 1 ? 'y' : 'ies').', freeing '.JanitorReport::bytes($bytes).'.';
        $text .= " {$summary['entries_protected']} protected entr".($summary['entries_protected'] === 1 ? 'y was' : 'ies were').' left untouched.';

        $files = ['json' => $report->jsonPath(), 'markdown' => $report->markdownPath()];
        $event = [
            'run_id' => $runId,
            'dry_run' => $options['dry_run'],
            'status' => $document['status'],
            'verdict' => $verdict,
            'summary' => array_diff_key($summary, ['by_rule' => true]),
            'report' => $files,
        ];

        return Result::pass(
            $text,
            findings: $findings,
            artifacts: [new Artifact(
                self::ARTIFACT_KIND,
                ['schema' => JanitorReport::SCHEMA, 'run_id' => $runId, 'status' => $document['status'], 'dry_run' => $options['dry_run'], 'verdict' => $verdict, 'summary' => $summary, 'report' => $files],
                ['policy' => $document['policy'], 'operator' => $document['operator'], 'roots' => $plan['roots']],
                ['report.json' => $document],
                self::ARTIFACT_NAME,
            )],
            events: [['topic' => self::EVENT_REPORTED, 'payload' => $event]],
            data: $event + ['by_rule' => $summary['by_rule']],
        );
    }

    /** @return array{dry_run: bool, policy: ?string, rules: ?list<string>} */
    private function options(Task $task): array
    {
        $payload = $task->payload;
        // A direct run is strict like every other agent; an event's foreign keys are not ours to judge.
        if ($task->causationId === null) {
            foreach (array_keys($payload) as $key) {
                if (! array_key_exists($key, self::SCHEMA)) {
                    throw new ProtocolException('INVALID_PAYLOAD', "Unknown payload key '{$key}' for ".self::TASK.'. Allowed: '.implode(', ', array_keys(self::SCHEMA)));
                }
            }
        }
        $dryRun = $payload['dry_run'] ?? true;
        $policy = $payload['policy'] ?? null;
        $rules = $payload['rules'] ?? null;
        if (! is_bool($dryRun)) {
            throw new ProtocolException('INVALID_PAYLOAD', "Payload key 'dry_run' must be of type bool.");
        }
        if ($policy !== null && (! is_string($policy) || $policy === '')) {
            throw new ProtocolException('INVALID_PAYLOAD', "Payload key 'policy' must be a non-empty string.");
        }
        if ($rules !== null && (! is_array($rules) || ! array_is_list($rules) || array_filter($rules, 'is_string') !== $rules || $rules === [])) {
            throw new ProtocolException('INVALID_PAYLOAD', "Payload key 'rules' must be a non-empty list of rule ids.");
        }

        return ['dry_run' => $dryRun, 'policy' => $policy, 'rules' => $rules];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  list<array<string, mixed>>  $protected
     */
    private static function summary(array $entries, array $protected): array
    {
        $summary = [
            'entries_planned' => count($entries),
            'files_planned' => array_sum(array_column($entries, 'files')),
            'bytes_planned' => array_sum(array_column($entries, 'bytes')),
            'entries_deleted' => 0,
            'files_deleted' => 0,
            'bytes_freed' => 0,
            'entries_failed' => 0,
            'entries_protected' => count($protected),
            'bytes_protected' => array_sum(array_column($protected, 'bytes')),
            'by_rule' => [],
        ];
        foreach ($entries as $entry) {
            $summary['files_deleted'] += $entry['files_deleted'] ?? 0;
            $summary['bytes_freed'] += $entry['bytes_freed'] ?? 0;
            if ($entry['outcome'] === Reclaimer::DELETED) {
                $summary['entries_deleted']++;
            } elseif (in_array($entry['outcome'], [Reclaimer::FAILED, Reclaimer::PARTIAL, Reclaimer::CHANGED], true)) {
                $summary['entries_failed']++;
            }
            $summary['by_rule'][$entry['rule']] ??= ['entries' => 0, 'bytes' => 0];
            $summary['by_rule'][$entry['rule']]['entries']++;
            $summary['by_rule'][$entry['rule']]['bytes'] += $entry['bytes'];
        }

        return $summary;
    }

    /** @return list<Finding> */
    private function findings(array $document, array $plan): array
    {
        $summary = $document['summary'];
        $findings = [];
        $sample = static fn (array $entries, string $key = 'path'): array => array_slice(array_column($entries, $key), 0, self::FINDING_SAMPLE);

        $code = $document['dry_run'] ? 'DISK_CLEANUP_PLANNED' : 'DISK_CLEANUP_APPLIED';
        $findings[] = Finding::info($code, $document['dry_run']
            ? 'Dry run: '.JanitorReport::bytes($summary['bytes_planned'])." in {$summary['entries_planned']} entries would be reclaimed."
            : 'Freed '.JanitorReport::bytes($summary['bytes_freed'])." ({$summary['files_deleted']} files).", null, [
                'report' => $document['run_id'],
                'by_rule' => $summary['by_rule'],
            ], $document['dry_run'] && $summary['entries_planned'] > 0 ? 'Review the report, then run with {"dry_run": false} to delete.' : null);

        $failed = array_values(array_filter($document['entries'], static fn (array $e): bool => in_array($e['outcome'], [Reclaimer::FAILED, Reclaimer::PARTIAL], true)));
        if ($failed !== []) {
            $findings[] = Finding::warning('DISK_CLEANUP_FAILED', count($failed).' planned entr'.(count($failed) === 1 ? 'y' : 'ies').' could not be fully deleted.', null, [
                'entries' => array_map(static fn (array $e): array => ['path' => $e['path'], 'error' => $e['error']], array_slice($failed, 0, self::FINDING_SAMPLE)),
            ], 'Usually a permission problem: run the janitor as the files\' owner.');
        }
        $changed = array_values(array_filter($document['entries'], static fn (array $e): bool => $e['outcome'] === Reclaimer::CHANGED));
        if ($changed !== []) {
            $findings[] = Finding::info('DISK_ENTRY_CHANGED', count($changed).' entr'.(count($changed) === 1 ? 'y' : 'ies').' changed after planning and were left alone.', null, ['paths' => $sample($changed)]);
        }
        if ($document['protected'] !== []) {
            $findings[] = Finding::info('DISK_ENTRY_PROTECTED', "{$summary['entries_protected']} selected entr".($summary['entries_protected'] === 1 ? 'y is' : 'ies are').' protected and were not deleted ('.JanitorReport::bytes($summary['bytes_protected']).').', null, [
                'entries' => array_map(static fn (array $e): array => ['path' => $e['path'], 'protection' => $e['protection']], array_slice($document['protected'], 0, self::FINDING_SAMPLE)),
            ]);
        }
        $unreadable = array_values(array_filter($plan['roots'], static fn (array $r): bool => $r['status'] === 'unreadable'));
        if ($unreadable !== []) {
            $findings[] = Finding::warning('DISK_ROOT_UNREADABLE', count($unreadable).' root(s) could not be read by this user and were not audited.', null, ['roots' => $sample($unreadable, 'root')], 'Run the janitor as a user that can read them (e.g. from root\'s crontab).');
        }
        $missing = array_values(array_filter($plan['roots'], static fn (array $r): bool => $r['status'] === 'missing'));
        if ($missing !== []) {
            $findings[] = Finding::info('DISK_ROOT_MISSING', count($missing).' policy root(s) do not exist on this host.', null, ['roots' => $sample($missing, 'root')]);
        }
        if ($plan['truncated']) {
            $findings[] = Finding::warning('DISK_SCAN_LIMIT', 'The scan stopped at a policy limit; the plan is partial (incompletely scanned directories are never deleted).', null, ['entries_scanned' => $plan['entries_scanned']], 'Raise limits in the policy or narrow the run with "rules".');
        }

        return $findings;
    }

    /** Report form of an entry: internal identity fields are dropped. */
    private static function reported(array $entry): array
    {
        unset($entry['identity']);

        return $entry;
    }

    /** @return array{user: ?string, uid: ?int} */
    private static function operator(): array
    {
        if (function_exists('posix_geteuid')) {
            $uid = posix_geteuid();

            return ['user' => posix_getpwuid($uid)['name'] ?? null, 'uid' => $uid];
        }

        return ['user' => getenv('USERNAME') ?: (getenv('USER') ?: null), 'uid' => null];
    }
}
