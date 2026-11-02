<?php

/*
 * Stand-in for the Playwright suite in SentinelUiVerifierAgentTest. It reads
 * the scenario the test wrote into its working directory, records the
 * environment the agent passed, and writes report.json like the real
 * sentinel-reporter would (or nothing, for the no-report scenario).
 */

declare(strict_types=1);

$scenario = json_decode((string) file_get_contents(__DIR__.'/scenario.json'), true);
$out = getenv('SENTINEL_OUTPUT_DIR');
$keys = ['BASE_URL', 'SENTINEL_OUTPUT_DIR', 'SENTINEL_PAGES', 'SENTINEL_WIDTHS', 'SENTINEL_VISUAL', 'SENTINEL_UPDATE_BASELINES', 'SENTINEL_STORAGE_STATE', 'SENTINEL_EDITOR_PATHS', 'SENTINEL_PROJECT', 'SENTINEL_ENVIRONMENT'];
file_put_contents(__DIR__.'/env-seen.json', json_encode(array_combine($keys, array_map(static fn ($k) => getenv($k), $keys))));

if (($scenario['mode'] ?? '') === 'no-report') {
    fwrite(STDERR, "browser could not start\n");
    exit(1);
}

$findings = $scenario['findings'] ?? [];
$tasks = $scenario['tasks'] ?? [];
file_put_contents($out.'/report.json', json_encode([
    'schema' => $scenario['schema'] ?? 'sentinel.ui-report/v1',
    'base_url' => getenv('BASE_URL'),
    'verdict' => 'READY', // deliberately wrong when findings exist: the agent must recompute
    'tests' => ['passed' => 3, 'failed' => 0, 'skipped' => 2],
    'tasks' => $tasks,
    'findings' => $findings,
]));
file_put_contents($out.'/report.md', "# Sentinel UI report\n");
exit(0);
