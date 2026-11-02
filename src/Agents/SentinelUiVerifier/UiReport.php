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

    /** P1 (blocker) → P2 (warning) → P3 (UX friction); the suite's own priority when valid. */
    public static function priority(array $task): string
    {
        $priority = $task['priority'] ?? null;
        if (in_array($priority, ['P1', 'P2', 'P3'], true)) {
            return $priority;
        }

        return ($task['severity'] ?? null) === 'blocker' ? 'P1' : 'P2';
    }

    /** A task's identity across runs: the suite's key, else a hash of what it asks. */
    public static function taskKey(array $task): string
    {
        $key = $task['key'] ?? null;
        if (is_string($key) && preg_match('/^[0-9a-f]{8,64}$/', $key) === 1) {
            return $key;
        }

        return substr(hash('sha256', implode('|', [(string) ($task['code'] ?? ''), (string) ($task['widget'] ?? ''), (string) ($task['change'] ?? '')])), 0, 16);
    }

    /** @return list<array<string, mixed>> the tasks, most urgent first (stable within a priority) */
    public function prioritizedTasks(): array
    {
        $tasks = $this->tasks();
        $order = array_keys($tasks);
        usort($order, static fn (int $a, int $b): int => strcmp(self::priority($tasks[$a]), self::priority($tasks[$b])) ?: $a <=> $b);

        return array_map(static fn (int $i): array => $tasks[$i], $order);
    }

    /**
     * One finding per suite task (blocker/warning), plus one info finding
     * when the run needs no change.
     *
     * @return list<Finding>
     */
    public function findings(string $baseUrl): array
    {
        return self::findingsFor($this->tasks(), $baseUrl, true);
    }

    /**
     * Findings for the given tasks only (the agent passes the capped,
     * not-yet-reported ones).
     *
     * @param  list<array<string, mixed>>  $tasks
     * @return list<Finding>
     */
    public static function findingsFor(array $tasks, string $baseUrl, bool $noteWhenEmpty = false): array
    {
        $out = [];
        foreach (array_slice($tasks, 0, self::FINDING_LIMIT) as $task) {
            $severity = Severity::tryFrom((string) ($task['severity'] ?? '')) ?? Severity::Warning;
            $where = is_array($task['where'] ?? null) ? array_values($task['where']) : [];
            $code = preg_match('/^[A-Z][A-Z0-9_]*$/', (string) ($task['code'] ?? '')) === 1 ? (string) $task['code'] : 'UI_DEFECT';
            $out[] = new Finding(
                $code,
                $severity,
                sprintf('%s (%d×: %s)', (string) ($task['title'] ?? $code), (int) ($task['occurrences'] ?? count($where)), implode('; ', array_slice($where, 0, 5))),
                is_string($task['widget'] ?? null) && $task['widget'] !== '' ? $task['widget'] : $baseUrl,
                ['task_id' => $task['id'] ?? null, 'task_key' => self::taskKey($task), 'priority' => self::priority($task), 'where' => $where, 'occurrences' => $task['occurrences'] ?? null],
                is_string($task['change'] ?? null) && $task['change'] !== '' ? $task['change'] : null,
            );
        }
        if (count($tasks) > self::FINDING_LIMIT) {
            $out[] = Finding::info('SENTINEL_UI_TRUNCATED', sprintf('%d more task(s) are only in report.json.', count($tasks) - self::FINDING_LIMIT), $baseUrl);
        }
        if ($out === [] && $noteWhenEmpty) {
            $out[] = Finding::info('SENTINEL_UI_READY', 'No UI change required on '.$baseUrl.'.', $baseUrl);
        }

        return $out;
    }
}
