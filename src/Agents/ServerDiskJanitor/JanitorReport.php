<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\ServerDiskJanitor;

use Workspace\Agents\Kernel\ProtocolException;
use Workspace\Agents\Support\Json;

/**
 * The janitor's audit trail, outside the agent runtime so operators find it
 * next to the Hub's logs:
 *
 *   <reports>/<YYYY-MM-DD>/<run_id>.json   machine-readable, written before any deletion
 *   <reports>/<YYYY-MM-DD>/<run_id>.md     human summary with every path
 *
 * The JSON is written atomically twice in an applying run: first as the
 * plan (status `planned`, every entry `pending`), then with outcomes. If the
 * plan cannot be written, nothing is deleted.
 */
final class JanitorReport
{
    public const SCHEMA = 'disk-janitor.report/v1';

    public readonly string $directory;

    public function __construct(private readonly string $reportsDir, public readonly string $runId, int $now)
    {
        $this->directory = rtrim($reportsDir, '/').'/'.gmdate('Y-m-d', $now);
    }

    public function jsonPath(): string
    {
        return $this->directory.'/'.$this->runId.'.json';
    }

    public function markdownPath(): string
    {
        return $this->directory.'/'.$this->runId.'.md';
    }

    /**
     * Only the report directory and its date folder are ever created: a
     * missing parent means the configured location is wrong for this host.
     */
    public function prepare(): void
    {
        $parent = dirname(rtrim($this->reportsDir, '/'));
        if (! is_dir($parent)) {
            throw new ProtocolException('REPORT_DIR_MISSING', "Report location {$this->reportsDir} is unusable: its parent {$parent} does not exist on this host.");
        }
        foreach ([$this->reportsDir, $this->directory] as $directory) {
            if (is_dir($directory)) {
                continue;
            }
            if (! @mkdir($directory, 02775) && ! is_dir($directory)) {
                throw new ProtocolException('REPORT_UNWRITABLE', "Cannot create report directory {$directory}.");
            }
            // The umask would drop group write; root (cron) and www-data (canvas) share these folders.
            @chmod($directory, 02775);
        }
    }

    public function write(array $report): void
    {
        $this->atomic($this->jsonPath(), Json::encode($report).PHP_EOL);
        $this->atomic($this->markdownPath(), ($report['schema'] ?? null) === BackupArchiver::SCHEMA ? self::archiveMarkdown($report) : self::markdown($report));
    }

    public static function archiveMarkdown(array $report): string
    {
        $s = $report['summary'];
        $lines = [
            "# Backup archive report `{$report['run_id']}`",
            '',
            '| | |',
            '|---|---|',
            "| Status | `{$report['status']}` |",
            '| Mode | '.($report['dry_run'] ? 'dry-run (nothing archived or deleted)' : 'apply').' |',
            "| Started | {$report['started_at']} |",
            '| Finished | '.($report['finished_at'] ?? '—').' |',
            "| Pipeline | tar → {$report['pipeline']['compression']} → {$report['pipeline']['encryption']} |",
            '| Recipients | '.($report['recipients'] === [] ? '—' : '`'.implode('`, `', $report['recipients']).'`').' |',
            '| Remote | '.self::cell(implode(' ', array_map(static fn ($k, $v): string => "{$k}=".(is_bool($v) ? ($v ? 'true' : 'false') : (string) json_encode($v, JSON_UNESCAPED_SLASHES)), array_keys($report['remote']), $report['remote']))).' |',
            "| Sources | {$s['sources_planned']} planned, {$s['verified']} verified off-site, {$s['deleted']} deleted, {$s['skipped']} skipped |",
            '| Original size | '.self::bytes($s['original_bytes']).' |',
            '| Compressed / encrypted | '.self::bytes($s['compressed_bytes']).' / '.self::bytes($s['encrypted_bytes']).' |',
            '| Freed | '.self::bytes($s['bytes_freed']).' ('.number_format($s['bytes_freed']).' bytes) |',
        ];
        if ($report['error'] !== null) {
            $lines[] = "| Error | step `{$report['error']['step']}`: ".self::cell($report['error']['message']).' |';
        }
        $lines[] = '';
        $lines[] = '## Preflight';
        $lines[] = '';
        foreach ($report['preflight'] as $c) {
            $lines[] = '- '.($c['status'] === 'pass' ? '✅' : '❌')." `{$c['check']}` — ".self::cell($c['detail']);
        }
        $lines[] = '';
        $lines[] = '## Sources';
        $lines[] = '';
        if ($report['entries'] === []) {
            $lines[] = '_None._';
        } else {
            $lines[] = '| Status | Path | Original | Compressed | Encrypted | SHA-256 | Remote verification | Freed |';
            $lines[] = '|---|---|---:|---:|---:|---|---|---:|';
            foreach ($report['entries'] as $e) {
                $a = $e['archive'] ?? null;
                $v = $e['remote']['verification'] ?? null;
                $lines[] = "| {$e['status']} | `".self::cell($e['path']).'` | '.self::bytes($e['original_bytes']).' | '.($a ? self::bytes($a['compressed_bytes']) : '—').' | '.($a ? self::bytes($a['encrypted_bytes']) : '—').' | '.($a ? '`'.$a['sha256'].'`' : '—').' | '.($v ? ($v['verified'] ? "✅ {$v['method']}" : '❌ '.self::cell($v['reason'])) : '—').' | '.(isset($e['deletion']) ? self::bytes($e['deletion']['bytes_freed']) : '—').' |';
                if (isset($e['error'])) {
                    $lines[] = "| | ↳ failed at `{$e['error']['step']}`: ".self::cell($e['error']['message']).' | | | | | | |';
                }
            }
        }
        if ($report['skipped'] !== []) {
            $lines[] = '';
            $lines[] = '## Skipped (not safe to archive-and-delete)';
            $lines[] = '';
            foreach ($report['skipped'] as $e) {
                $lines[] = '- `'.self::cell($e['path']).'` ('.self::bytes($e['original_bytes']).') — '.self::cell($e['problem']);
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function atomic(string $path, string $bytes): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new ProtocolException('REPORT_UNWRITABLE', "Cannot write report file {$path}.");
        }
        $written = fwrite($handle, $bytes);
        fflush($handle);
        if (function_exists('fsync')) {
            fsync($handle);
        }
        fclose($handle);
        if ($written !== strlen($bytes) || ! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new ProtocolException('REPORT_UNWRITABLE', "Cannot write report file {$path}.");
        }
        @chmod($path, 0664);
    }

    public static function markdown(array $report): string
    {
        $s = $report['summary'];
        $mode = $report['dry_run'] ? 'dry-run (nothing deleted)' : 'apply';
        $lines = [
            "# Disk janitor report `{$report['run_id']}`",
            '',
            '| | |',
            '|---|---|',
            "| Status | `{$report['status']}` |",
            "| Mode | {$mode} |",
            "| Started | {$report['started_at']} |",
            '| Finished | '.($report['finished_at'] ?? '—').' |',
            "| Policy | `{$report['policy']['file']}` (sha256 `".substr($report['policy']['sha256'], 0, 12).'…`) |',
            '| '.($report['dry_run'] ? 'Reclaimable' : 'Freed').' | '.self::bytes($report['dry_run'] ? $s['bytes_planned'] : $s['bytes_freed']).' ('.number_format($report['dry_run'] ? $s['bytes_planned'] : $s['bytes_freed']).' bytes) |',
            '| Entries | '.($report['dry_run'] ? "{$s['entries_planned']} planned" : "{$s['entries_deleted']} deleted of {$s['entries_planned']} planned").' |',
            '| Files | '.($report['dry_run'] ? "{$s['files_planned']} planned" : "{$s['files_deleted']} deleted").' |',
            "| Protected (skipped) | {$s['entries_protected']} |",
            "| Failed or changed | {$s['entries_failed']} |",
            '',
            '## By rule',
            '',
            '| Rule | Entries | Bytes |',
            '|---|---:|---:|',
        ];
        foreach ($s['by_rule'] as $rule => $counts) {
            $lines[] = "| `{$rule}` | {$counts['entries']} | ".self::bytes($counts['bytes']).' |';
        }
        $lines[] = '';
        $lines[] = '## Entries';
        $lines[] = '';
        if ($report['entries'] === []) {
            $lines[] = '_None._';
        } else {
            $lines[] = '| Outcome | Path | Type | Bytes | Modified | Rule | Reason |';
            $lines[] = '|---|---|---|---:|---|---|---|';
            foreach ($report['entries'] as $e) {
                $lines[] = "| {$e['outcome']} | `".self::cell($e['path']).'` | '.$e['type'].' | '.number_format($e['bytes']).' | '.$e['modified_at'].' | `'.$e['rule'].'` | '.self::cell($e['reason'].($e['error'] !== null ? ' — '.$e['error'] : '')).' |';
            }
        }
        $lines[] = '';
        $lines[] = '## Protected (never deleted)';
        $lines[] = '';
        if ($report['protected'] === []) {
            $lines[] = '_None._';
        } else {
            $lines[] = '| Path | Bytes | Rule | Protection |';
            $lines[] = '|---|---:|---|---|';
            foreach ($report['protected'] as $e) {
                $lines[] = '| `'.self::cell($e['path']).'` | '.number_format($e['bytes'])." | `{$e['rule']}` | ".self::cell($e['protection']).' |';
            }
        }

        return implode("\n", $lines)."\n";
    }

    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return $unit === 0 ? "{$bytes} B" : sprintf('%.2f %s', $value, $units[$unit]);
    }

    private static function cell(string $value): string
    {
        return str_replace(['|', "\n", '`'], ['\\|', ' ', "'"], $value);
    }
}
