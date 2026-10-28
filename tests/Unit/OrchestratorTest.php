<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Unit;

use RuntimeException;
use Workspace\Agents\Kernel\Agent;
use Workspace\Agents\Kernel\AgentContext;
use Workspace\Agents\Kernel\AgentRegistry;
use Workspace\Agents\Kernel\Artifact;
use Workspace\Agents\Kernel\ArtifactStore;
use Workspace\Agents\Kernel\InMemoryBus;
use Workspace\Agents\Kernel\Message;
use Workspace\Agents\Kernel\Orchestrator;
use Workspace\Agents\Kernel\Result;
use Workspace\Agents\Kernel\Status;
use Workspace\Agents\Kernel\Task;
use Workspace\Agents\Support\Json;
use Workspace\Agents\Tests\Support\ScriptedAgent;
use Workspace\Agents\Tests\Support\WorkspaceFixture;
use Workspace\Agents\Tests\TestCase;

final class OrchestratorTest extends TestCase
{
    private string $dir;

    private InMemoryBus $bus;

    public function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/agents-orch-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir.'/workspace.json', Json::encode(['schema' => AgentContext::SCHEMA, 'projects' => ['p' => ['path' => '.']]]));
        $this->bus = new InMemoryBus();
    }

    public function tearDown(): void
    {
        WorkspaceFixture::remove($this->dir);
    }

    public function testRoutesByTypePublishesLifecycleAndPersistsArtifacts(): void
    {
        $agent = new ScriptedAgent('reporter', ['report.make'], fn (Task $t) => Result::pass(
            'made',
            artifacts: [new Artifact('report', ['n' => 1])],
            events: [['topic' => 'report.made', 'payload' => ['n' => 1]]],
        ));
        $outcomes = $this->orchestrator([[$agent, []]])->dispatch(Task::create('report.make'));

        $this->assertCount(1, $outcomes);
        $this->assertSame('reporter', $outcomes[0]->agentId);
        $this->assertSame(Status::Pass, $outcomes[0]->result->status);
        $this->assertCount(1, $outcomes[0]->artifacts);
        $this->assertTrue(ArtifactStore::verify($outcomes[0]->artifacts[0]['path'])['valid']);
        $this->assertSame(['task.started', 'artifact.created', 'report.made', 'task.completed'], $this->topics());

        $made = $this->bus->history()[2];
        $this->assertSame('reporter', $made->source);
        $this->assertSame($outcomes[0]->task->id, $made->causationId);
        $this->assertSame($outcomes[0]->task->correlationId, $made->correlationId);
        $this->assertSame('PASS', $this->bus->history()[3]->payload['status']);
    }

    public function testSubscriptionsTurnEventsIntoTasksForOtherAgents(): void
    {
        $producer = new ScriptedAgent('producer', ['work.do'], fn () => Result::pass('done', events: [['topic' => 'work.finished', 'payload' => ['items' => 3]]]));
        $listener = new ScriptedAgent('listener', ['work.follow']);
        $outcomes = $this->orchestrator([[$producer, []], [$listener, [['topic' => 'work.*', 'task' => 'work.follow']]]])->dispatch(Task::create('work.do'));

        $this->assertSame(['producer', 'listener'], array_map(static fn ($o) => $o->agentId, $outcomes));
        $received = $listener->received[0];
        $finished = $this->message('work.finished');
        $this->assertSame(['items' => 3], $received->payload);
        $this->assertSame($outcomes[0]->task->correlationId, $received->correlationId);
        $this->assertSame($finished->id, $received->causationId);
        $this->assertSame(1, $received->hop);
    }

    public function testAgentsNeverTriggerThemselvesAndHopLimitStopsPingPong(): void
    {
        $self = new ScriptedAgent('echo', ['echo.run'], fn () => Result::pass('x', events: [['topic' => 'echo.done', 'payload' => []]]));
        $this->assertCount(1, $this->orchestrator([[$self, [['topic' => 'echo.done', 'task' => 'echo.run']]]])->dispatch(Task::create('echo.run')));

        $this->bus = new InMemoryBus();
        $ping = new ScriptedAgent('ping', ['ping.run'], fn () => Result::pass('ping', events: [['topic' => 'ping.sent', 'payload' => []]]));
        $pong = new ScriptedAgent('pong', ['pong.run'], fn () => Result::pass('pong', events: [['topic' => 'pong.sent', 'payload' => []]]));
        $outcomes = $this->orchestrator([
            [$ping, [['topic' => 'pong.sent', 'task' => 'ping.run']]],
            [$pong, [['topic' => 'ping.sent', 'task' => 'pong.run']]],
        ], maxHops: 3)->dispatch(Task::create('ping.run'));

        $this->assertCount(5, $outcomes, 'hops 0..3 run, hop 4 is rejected');
        $last = end($outcomes);
        $this->assertSame(Status::Blocked, $last->result->status);
        $this->assertSame('HOP_LIMIT', $last->result->findings[0]->code);
        $this->assertSame('HOP_LIMIT', $this->message('task.rejected')->payload['reason']);
    }

    public function testFollowUpTasksRunInTheSameDispatch(): void
    {
        $planner = new ScriptedAgent('planner', ['plan.make'], fn (Task $t) => Result::pass('planned', followUps: [$t->spawn('plan.execute', ['step' => 1])]));
        $worker = new ScriptedAgent('worker', ['plan.execute']);
        $outcomes = $this->orchestrator([[$planner, []], [$worker, []]])->dispatch(Task::create('plan.make'));
        $this->assertSame(['planner', 'worker'], array_map(static fn ($o) => $o->agentId, $outcomes));
        $this->assertSame(['step' => 1], $worker->received[0]->payload);
    }

    public function testUnroutableAndAmbiguousTasksAreRejected(): void
    {
        $a = new ScriptedAgent('a', ['shared.type']);
        $b = new ScriptedAgent('b', ['shared.type']);
        $orchestrator = $this->orchestrator([[$a, []], [$b, []]]);

        $unknown = $orchestrator->dispatch(Task::create('nobody.handles'))[0];
        $this->assertSame('NO_AGENT', $unknown->result->findings[0]->code);
        $ambiguous = $orchestrator->dispatch(Task::create('shared.type'))[0];
        $this->assertSame('AMBIGUOUS_ROUTE', $ambiguous->result->findings[0]->code);
        $targeted = $orchestrator->dispatch(Task::create('shared.type', [], 'b'))[0];
        $this->assertSame('b', $targeted->agentId);
        $this->assertCount(0, $a->received);
    }

    public function testContractViolationsBecomeFailedResultsWithoutSideEffects(): void
    {
        $rogue = new class implements Agent {
            public function id(): string { return 'rogue'; }
            public function version(): string { return '0.0.1'; }
            public function handles(): array { return ['rogue.run']; }
            public function handle(Task $task, AgentContext $context): Result { throw new RuntimeException('raw throw'); }
        };
        $outcome = $this->orchestrator([[$rogue, []]])->dispatch(Task::create('rogue.run'))[0];
        $this->assertSame(Status::Failed, $outcome->result->status);
        $this->assertStringContains('raw throw', (string) $outcome->result->error);

        $this->bus = new InMemoryBus();
        $blocked = new ScriptedAgent('blocker', ['b.run'], fn () => Result::blocked('nope'));
        $this->orchestrator([[$blocked, []]])->dispatch(Task::create('b.run'));
        $this->assertNotContains('artifact.created', $this->topics());
    }

    public function testRegistryRejectsInvalidRegistrations(): void
    {
        $registry = new AgentRegistry();
        $registry->register(new ScriptedAgent('one', ['a.b']));
        $this->assertThrows(RuntimeException::class, fn () => $registry->register(new ScriptedAgent('one', ['a.b'])), 'already registered');
        $this->assertThrows(RuntimeException::class, fn () => $registry->register(new ScriptedAgent('two', ['a.b']), [['topic' => 'x.y', 'task' => 'c.d']]), 'does not handle');
        // A topic no Message can carry is a subscription that would never fire.
        foreach (['sentinel.audit_requested', 'Deployment.completed', 'single', 'a.*.b', ''] as $topic) {
            $this->assertThrows(RuntimeException::class, fn () => (new AgentRegistry())->register(new ScriptedAgent('three', ['a.b']), [['topic' => $topic, 'task' => 'a.b']]), 'not a valid dotted lowercase topic');
        }
        foreach (['*', 'a.*', 'deployment.completed', 'sentinel.audit-requested'] as $topic) {
            (new AgentRegistry())->register(new ScriptedAgent('four', ['a.b']), [['topic' => $topic, 'task' => 'a.b']]);
        }
    }

    public function testExternalPublishTriggersSubscribersInOneCorrelationChain(): void
    {
        $listener = new ScriptedAgent('listener', ['work.follow']);
        $orchestrator = $this->orchestrator([[$listener, [['topic' => 'deploy.done', 'task' => 'work.follow']]]]);

        $outcomes = $orchestrator->publish('deploy.done', ['release' => 'r1']);
        $this->assertSame(['listener'], array_map(static fn ($o) => $o->agentId, $outcomes));
        $published = $this->message('deploy.done');
        $this->assertSame(Orchestrator::EXTERNAL, $published->source);
        $this->assertSame(['release' => 'r1'], $listener->received[0]->payload);
        $this->assertSame($published->correlationId, $listener->received[0]->correlationId);
        $this->assertSame($published->id, $listener->received[0]->causationId);
        $this->assertSame(1, $listener->received[0]->hop);

        $this->assertSame([], $orchestrator->publish('nobody.listens', []), 'an unsubscribed topic runs nothing');
        $this->assertSame('nobody.listens', $this->topics()[count($this->topics()) - 1], 'but is still journaled');
        $this->assertThrows(\Workspace\Agents\Kernel\ProtocolException::class, fn () => $orchestrator->publish('Bad_Topic', []));
    }

    public function testRegistryLoadsTheShippedAgentsJson(): void
    {
        $registry = AgentRegistry::fromFile(dirname(__DIR__, 2).'/agents.json');
        $this->assertSame(['dependency-deployment', 'sentinel-verifier'], array_keys($registry->all()));
        $this->assertSame([
            ['agent' => 'dependency-deployment', 'topic' => 'release.requested', 'task' => 'deployment.readiness'],
            ['agent' => 'sentinel-verifier', 'topic' => 'deployment.completed', 'task' => 'sentinel.verify'],
            ['agent' => 'sentinel-verifier', 'topic' => 'sentinel.audit-requested', 'task' => 'sentinel.verify'],
        ], $registry->subscriptions());
    }

    /** @param list<array{0: Agent, 1: list<array>}> $agents */
    private function orchestrator(array $agents, int $maxHops = 8): Orchestrator
    {
        $registry = new AgentRegistry();
        foreach ($agents as [$agent, $subscriptions]) {
            $registry->register($agent, $subscriptions);
        }

        return new Orchestrator($registry, $this->bus, new ArtifactStore($this->dir.'/artifacts'), AgentContext::fromFile($this->dir.'/workspace.json'), $maxHops);
    }

    /** @return list<string> */
    private function topics(): array
    {
        return array_map(static fn (Message $m): string => $m->topic, $this->bus->history());
    }

    private function message(string $topic): Message
    {
        foreach ($this->bus->history() as $message) {
            if ($message->topic === $topic) {
                return $message;
            }
        }
        $this->fail("no {$topic} message published");
    }
}
