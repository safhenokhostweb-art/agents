<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Integration;

use Workspace\Agents\Agents\SentinelUiVerifier\SentinelUiVerifierAgent;
use Workspace\Agents\Agents\SentinelUiVerifier\UiReport;
use Workspace\Agents\Kernel\AgentContext;
use Workspace\Agents\Kernel\AgentRegistry;
use Workspace\Agents\Kernel\ArtifactStore;
use Workspace\Agents\Kernel\InMemoryBus;
use Workspace\Agents\Kernel\Orchestrator;
use Workspace\Agents\Kernel\Outcome;
use Workspace\Agents\Kernel\ProtocolException;
use Workspace\Agents\Kernel\Status;
use Workspace\Agents\Kernel\Task;
use Workspace\Agents\Support\Json;
use Workspace\Agents\Tests\Support\WorkspaceFixture;
use Workspace\Agents\Tests\TestCase;

/**
 * The Sentinel UI verifier runs the configured suite with the target in its
 * environment, recomputes the verdict from the report, turns tasks into
 * findings and seals the report. A PHP stand-in replaces Playwright.
 */
final class SentinelUiVerifierAgentTest extends TestCase
{
    private string $root;

    private InMemoryBus $bus;

    private const BLOCKING = [
        'findings' => [
            ['code' => 'PAGE_HORIZONTAL_OVERFLOW', 'severity' => 'blocker', 'page' => '/', 'width' => 390, 'widget' => null, 'message' => 'The page scrolls sideways by 40px.', 'remedy' => 'Fit it.'],
            ['code' => 'FEATURE_MISSING_LOGO', 'severity' => 'warning', 'page' => '/', 'width' => 390, 'widget' => 'nav_header#b1', 'message' => 'No logo.', 'remedy' => 'Upload a logo.'],
            ['code' => 'NO_WIDGETS_FOUND', 'severity' => 'info', 'page' => '/about', 'width' => null, 'widget' => null, 'message' => 'None.', 'remedy' => ''],
        ],
        'tasks' => [
            ['id' => 'T-001', 'severity' => 'blocker', 'code' => 'PAGE_HORIZONTAL_OVERFLOW', 'widget' => null, 'title' => 'The page scrolls sideways by 40px.', 'change' => 'Fit it.', 'where' => ['/ @ 390px'], 'occurrences' => 1],
            ['id' => 'T-002', 'severity' => 'warning', 'code' => 'FEATURE_MISSING_LOGO', 'widget' => 'nav_header', 'title' => 'No logo.', 'change' => 'Upload a logo.', 'where' => ['/ @ 390px'], 'occurrences' => 1],
        ],
    ];

    public function setUp(): void
    {
        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/agents-ui-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/suite', 0777, true);
        copy(dirname(__DIR__).'/Support/SentinelUiFixture/fake-runner.php', $this->root.'/suite/fake-runner.php');
        file_put_contents($this->root.'/workspace.json', Json::encode([
            'schema' => AgentContext::SCHEMA,
            'projects' => ['suite' => ['path' => 'suite'], 'shop' => ['path' => 'suite']],
            'runtime' => ['artifacts' => 'var/artifacts', 'journal' => 'var/bus/journal.jsonl'],
            'sentinel_ui' => [
                'runner' => ['project' => 'suite', 'command' => [PHP_BINARY, 'fake-runner.php']],
                'output' => 'var/sentinel-ui',
                'targets' => ['shop' => ['local' => 'http://shop.test', 'production' => 'https://shop.example.com']],
                'pages' => ['shop' => ['/', '/about']],
                'widths' => [390, 1280],
            ],
        ]));
        $this->bus = new InMemoryBus();
    }

    public function tearDown(): void
    {
        WorkspaceFixture::remove($this->root);
    }

    public function testBlockingReportIsSealedAsFailedWithTasksAsFindings(): void
    {
        $this->scenario(self::BLOCKING);
        $outcome = $this->run(['project' => 'shop', 'environment' => 'production']);

        $this->assertSame(Status::Pass, $outcome->result->status);
        $this->assertSame('FAILED', $outcome->result->data['status']);
        $this->assertSame('BLOCKED', $outcome->result->data['verdict'], 'recomputed from the findings, not the file\'s READY');
        $this->assertSame(['blocker' => 1, 'warning' => 1, 'info' => 1], $outcome->result->data['counts']);

        $codes = array_map(static fn ($f) => [$f->code, $f->severity->value, $f->subject, $f->remedy], $outcome->result->findings);
        $this->assertSame([['PAGE_HORIZONTAL_OVERFLOW', 'blocker', 'https://shop.example.com', 'Fit it.'], ['FEATURE_MISSING_LOGO', 'warning', 'nav_header', 'Upload a logo.']], $codes);

        $env = Json::decodeFile($this->root.'/suite/env-seen.json');
        $this->assertSame('https://shop.example.com', $env['BASE_URL']);
        $this->assertSame('/,/about', $env['SENTINEL_PAGES']);
        $this->assertSame('390,1280', $env['SENTINEL_WIDTHS']);
        $this->assertSame('0', $env['SENTINEL_VISUAL']);
        $this->assertSame('0', $env['SENTINEL_UPDATE_BASELINES'], 'the agent never writes baselines');
        $this->assertSame('shop', $env['SENTINEL_PROJECT']);
        $this->assertSame('production', $env['SENTINEL_ENVIRONMENT']);
        $this->assertStringContains('/var/sentinel-ui/ui-', str_replace('\\', '/', $env['SENTINEL_OUTPUT_DIR']));

        $this->assertCount(1, $outcome->artifacts);
        $this->assertTrue(ArtifactStore::verify($outcome->artifacts[0]['path'])['valid']);
        $envelope = Json::decodeFile($outcome->artifacts[0]['path']);
        $this->assertSame(SentinelUiVerifierAgent::REPORT_SCHEMA, $envelope['payload']['schema']);
        $this->assertSame('sentinel-ui', $outcome->artifacts[0]['kind']);
        $this->assertSame(['report.json'], array_keys($envelope['attachments']));
        $this->assertSame('https://shop.example.com', $envelope['payload']['target']['base_url']);

        $this->assertSame(['task.started', 'artifact.created', SentinelUiVerifierAgent::EVENT_FAILED, 'task.completed'], $this->topics());
    }

    public function testCleanReportPassesWithOneReadyNote(): void
    {
        $this->scenario(['findings' => [], 'tasks' => []]);
        $outcome = $this->run(['project' => 'shop', 'base_url' => 'http://127.0.0.1:8000/', 'pages' => ['/'], 'widths' => [1440]]);

        $this->assertSame('READY', $outcome->result->data['verdict']);
        $this->assertSame('PASSED', $outcome->result->data['status']);
        $this->assertSame(['SENTINEL_UI_READY'], array_map(static fn ($f) => $f->code, $outcome->result->findings));
        $this->assertSame('http://127.0.0.1:8000', Json::decodeFile($this->root.'/suite/env-seen.json')['BASE_URL'], 'trailing slash trimmed');
        $this->assertContains(SentinelUiVerifierAgent::EVENT_PASSED, $this->topics());
    }

    public function testIntegrityPassChainsIntoTheUiAudit(): void
    {
        $this->scenario(['findings' => [], 'tasks' => []]);
        $outcomes = $this->orchestrator()->publish('sentinel.verification.passed', [
            'project_key' => 'shop', 'environment' => 'production', 'release_id' => 'rel-9', 'commit_sha' => str_repeat('a', 40),
            'deployment_id' => 'dep-9', 'status' => 'PASSED', 'sentinel_verdict' => 'VERIFIED', 'verdict' => 'READY',
            'evidence_hash' => str_repeat('b', 64), 'snapshot_hash' => null, 'non_conformant_files' => 0, 'reasons' => [], 'reaudit_of' => null,
        ]);

        $this->assertCount(1, $outcomes);
        $this->assertSame(SentinelUiVerifierAgent::ID, $outcomes[0]->agentId);
        $this->assertSame(Status::Pass, $outcomes[0]->result->status);
        $this->assertSame('rel-9', $outcomes[0]->result->data['release_id']);
        $this->assertSame('https://shop.example.com', $outcomes[0]->result->data['base_url']);
    }

    public function testReauditIsSkippedWithoutRunningTheSuite(): void
    {
        $this->scenario(self::BLOCKING);
        $outcome = $this->run(['project_key' => 'shop', 'reaudit_of' => 'audit-20261101T000000-aaaaaaaa']);

        $this->assertSame('SKIPPED', $outcome->result->data['status']);
        $this->assertSame([], $outcome->artifacts);
        $this->assertFalse(is_file($this->root.'/suite/env-seen.json'), 'the suite never ran');
    }

    public function testPreconditionsBlock(): void
    {
        $this->scenario(['mode' => 'no-report']);
        $cases = [
            'RUNNER_NO_REPORT' => ['project' => 'shop'],
            'TARGET_UNKNOWN' => ['project' => 'shop', 'environment' => 'staging'],
            'TARGET_INVALID' => ['project' => 'shop', 'base_url' => 'https://shop.example.com/path?x=1'],
            'PROJECT_REQUIRED' => ['environment' => 'local'],
            'STORAGE_STATE_MISSING' => ['project' => 'shop', 'storage_state' => 'nope.json'],
        ];
        foreach ($cases as $code => $payload) {
            $outcome = $this->run($payload);
            $this->assertSame(Status::Blocked, $outcome->result->status, $code);
            $this->assertSame($code, $outcome->result->findings[0]->code, $code);
        }
        $bad = $this->run(['project' => 'shop', 'pages' => ['https://evil.example/']]);
        $this->assertSame('INVALID_PAYLOAD', $bad->result->findings[0]->code);
        $this->assertStringContains('browser could not start', $this->run(['project' => 'shop'])->result->findings[0]->message);
    }

    public function testReportShapeIsValidated(): void
    {
        $this->assertThrows(ProtocolException::class, static fn () => UiReport::fromArray(['schema' => 'other', 'findings' => [], 'tasks' => []]));
        $this->assertThrows(ProtocolException::class, static fn () => UiReport::fromArray(['schema' => UiReport::SCHEMA, 'findings' => [['code' => 'X', 'severity' => 'fatal']], 'tasks' => []]));
        $this->assertSame('ATTENTION', UiReport::fromArray(['schema' => UiReport::SCHEMA, 'findings' => [['code' => 'X', 'severity' => 'warning']], 'tasks' => []])->verdict());
    }

    public function testShippedRegistryEntryMatchesTheAgent(): void
    {
        $shipped = Json::decodeFile(dirname(__DIR__, 2).'/agents.json');
        $entry = array_values(array_filter($shipped['agents'], static fn (array $a): bool => $a['id'] === SentinelUiVerifierAgent::ID))[0];
        $this->assertSame(SentinelUiVerifierAgent::class, $entry['class']);
        $this->assertSame([SentinelUiVerifierAgent::TOPIC_VERIFICATION_PASSED, SentinelUiVerifierAgent::TOPIC_AUDIT_REQUESTED], array_column($entry['subscriptions'], 'topic'));
        $this->assertSame([SentinelUiVerifierAgent::EVENT_PASSED, SentinelUiVerifierAgent::EVENT_FAILED], $entry['produces']['events']);
        $workspace = Json::decodeFile(dirname(__DIR__, 2).'/workspace.json');
        $this->assertSame('saas-platform', $workspace['sentinel_ui']['runner']['project']);
        foreach ($workspace['sentinel_ui']['targets'] as $project => $targets) {
            foreach ($targets as $env => $url) {
                $this->assertTrue(preg_match('#^https?://[A-Za-z0-9.-]+(:\d+)?$#', $url) === 1, "{$project}/{$env}");
            }
        }
    }

    private function scenario(array $scenario): void
    {
        file_put_contents($this->root.'/suite/scenario.json', Json::encode($scenario));
        @unlink($this->root.'/suite/env-seen.json');
    }

    private function orchestrator(): Orchestrator
    {
        $registry = new AgentRegistry();
        $shipped = Json::decodeFile(dirname(__DIR__, 2).'/agents.json');
        $entry = array_values(array_filter($shipped['agents'], static fn (array $a): bool => $a['id'] === SentinelUiVerifierAgent::ID))[0];
        $registry->register(new SentinelUiVerifierAgent(), $entry['subscriptions']);

        return new Orchestrator($registry, $this->bus, new ArtifactStore($this->root.'/var/artifacts'), AgentContext::fromFile($this->root.'/workspace.json'));
    }

    private function run(array $payload): Outcome
    {
        $this->bus = new InMemoryBus();

        return $this->orchestrator()->dispatch(Task::create(SentinelUiVerifierAgent::TASK, $payload, SentinelUiVerifierAgent::ID))[0];
    }

    /** @return list<string> */
    private function topics(): array
    {
        return array_map(static fn ($m): string => $m->topic, $this->bus->history());
    }
}
