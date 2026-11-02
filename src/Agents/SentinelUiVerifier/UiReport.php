<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\SentinelUiVerifier;

use Workspace\Agents\Kernel\Finding;
use Workspace\Agents\Kernel\ProtocolException;
use Workspace\Agents\Kernel\Severity;

/**
 * Reads the suite's report.json (`sentinel.ui-report/v1`, written by
 * saas-platform tests/sentinel/support/sentinel-reporter.ts) and turns its
 * tasks into kernel findings. The verdict is recomputed from the findings,
 * never trusted from the file.
 */
final class UiReport
{
    public const SCHEMA = 'sentinel.ui-report/v1';
    public const FINDING_LIMIT = 100;

    /** @param array<string, mixed> $document */
    private function __construct(public readonly array $document)
    {
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        if (($document['schema'] ?? null) !== self::SCHEMA) {
            throw new ProtocolException('REPORT_INVALID', 'The suite report is not a '.self::SCHEMA.' document.');
        }
        foreach (['findings', 'tasks'] as $key) {
            if (! is_array($document[$key] ?? null) || ! array_is_list($document[$key])) {
                throw new ProtocolException('REPORT_INVALID', "The suite report has no '{$key}' list.");
            }
        }
        foreach ($document['findings'] as $i => $finding) {
            if (! is_array($finding) || ! is_string($finding['code'] ?? null) || ! in_array($finding['severity'] ?? null, ['info', 'warning', 'blocker'], true)) {
                throw new ProtocolException('REPORT_INVALID', "Suite finding #{$i} has no valid code/severity.");
            }
        }

        return new self($document);
    }

    /** @return array{blocker: int, warning: int, info: int} */
    public function counts(): array
    {
        $counts = ['blocker' => 0, 'warning' => 0, 'info' => 0];
        foreach ($this->document['findings'] as $finding) {
            $counts[$finding['severity']]++;
        }

        return $counts;
    }

    /** READY / ATTENTION / BLOCKED, the dashboard vocabulary. */
    public function verdict(): string
    {
        $counts = $this->counts();

        return $counts['blocker'] > 0 ? 'BLOCKED' : ($counts['warning'] > 0 ? 'ATTENTION' : 'READY');
    }

    /** @return list<array<string, mixed>> */
    public function tasks(): array
    {
        return array_values(array_filter($this->document['tasks'], 'is_array'));
    }

    /**
     * One finding per suite task (blocker/warning), plus one info finding
     * when the run needs no change.
     *
     * @return list<Finding>
     */
    public function findings(string $baseUrl): array
    {
        $out = [];
        foreach (array_slice($this->tasks(), 0, self::FINDING_LIMIT) as $task) {
            $severity = Severity::tryFrom((string) ($task['severity'] ?? '')) ?? Severity::Warning;
            $where = is_array($task['where'] ?? null) ? array_values($task['where']) : [];
            $code = preg_match('/^[A-Z][A-Z0-9_]*$/', (string) ($task['code'] ?? '')) === 1 ? (string) $task['code'] : 'UI_DEFECT';
            $out[] = new Finding(
                $code,
                $severity,
                sprintf('%s (%d×: %s)', (string) ($task['title'] ?? $code), (int) ($task['occurrences'] ?? count($where)), implode('; ', array_slice($where, 0, 5))),
                is_string($task['widget'] ?? null) && $task['widget'] !== '' ? $task['widget'] : $baseUrl,
                ['task_id' => $task['id'] ?? null, 'where' => $where, 'occurrences' => $task['occurrences'] ?? null],
                is_string($task['change'] ?? null) && $task['change'] !== '' ? $task['change'] : null,
            );
        }
        if (count($this->tasks()) > self::FINDING_LIMIT) {
            $out[] = Finding::info('SENTINEL_UI_TRUNCATED', sprintf('%d more task(s) are only in report.json.', count($this->tasks()) - self::FINDING_LIMIT), $baseUrl);
        }
        if ($out === []) {
            $out[] = Finding::info('SENTINEL_UI_READY', 'No UI change required on '.$baseUrl.'.', $baseUrl);
        }

        return $out;
    }
}
