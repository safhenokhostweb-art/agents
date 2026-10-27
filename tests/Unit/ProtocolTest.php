<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Unit;

use Workspace\Agents\Kernel\AgentContext;
use Workspace\Agents\Kernel\Artifact;
use Workspace\Agents\Kernel\ArtifactStore;
use Workspace\Agents\Kernel\Finding;
use Workspace\Agents\Kernel\InMemoryBus;
use Workspace\Agents\Kernel\JournaledBus;
use Workspace\Agents\Kernel\Message;
use Workspace\Agents\Kernel\ProtocolException;
use Workspace\Agents\Kernel\Result;
use Workspace\Agents\Kernel\Status;
use Workspace\Agents\Kernel\Task;
use Workspace\Agents\Support\Json;
use Workspace\Agents\Tests\Support\ScriptedAgent;
use Workspace\Agents\Tests\Support\WorkspaceFixture;
use Workspace\Agents\Tests\TestCase;

final class ProtocolTest extends TestCase
{
    private string $dir;

    public function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/agents-proto-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    public function tearDown(): void
    {
        WorkspaceFixture::remove($this->dir);
    }

    public function testProjectComposerDirectoryDefaultsToTheRootAndStaysInsideIt(): void
    {
        mkdir($this->dir.'/app/service', 0777, true);
        file_put_contents($this->dir.'/workspace.json', Json::encode(['schema' => AgentContext::SCHEMA, 'projects' => [
            'root' => ['path' => 'app'],
            'nested' => ['path' => 'app', 'composer' => 'service'],
            'escape' => ['path' => 'app', 'composer' => '../other'],
            'absolute' => ['path' => 'app', 'composer' => $this->dir],
        ]]));
        $context = AgentContext::fromFile($this->dir.'/workspace.json');

        $this->assertSame($context->projectPath('root'), $context->composerPath('root'));
        $this->assertSame($context->projectPath('nested').'/service', $context->composerPath('nested'));
        $this->assertThrows(ProtocolException::class, static fn () => $context->composerPath('escape'), 'inside the project');
        $this->assertThrows(ProtocolException::class, static fn () => $context->composerPath('absolute'), 'inside the project');
        $this->assertThrows(ProtocolException::class, static fn () => $context->composerPath('nope'), 'not declared');
    }

    public function testTaskRoundTripsThroughItsContract(): void
    {
        $task = Task::create('deployment.readiness', ['projects' => ['a']], 'dependency-deployment');
        $copy = Task::fromArray(Json::decode(Json::encode($task->toArray())));
        $this->assertSame($task->toArray(), $copy->toArray());
        $this->assertSame($task->id, $task->correlationId);
        $this->assertSame(0, $task->hop);
    }

    public function testSpawnedTaskStaysInTheCorrelationChain(): void
    {
        $root = Task::create('a.b');
        $child = $root->spawn('c.d', ['x' => 1]);
        $this->assertSame($root->correlationId, $child->correlationId);
        $this->assertSame($root->id, $child->causationId);
        $this->assertSame(1, $child->hop);
    }

    public function testInvalidTasksAreRejected(): void
    {
        $this->assertThrows(ProtocolException::class, fn () => Task::create('NotDotted'));
        $this->assertThrows(ProtocolException::class, fn () => Task::create('a.b', [], 'Bad Agent'));
        $this->assertThrows(ProtocolException::class, fn () => Task::fromArray(['type' => 'a.b']), "'payload'");
        $this->assertThrows(ProtocolException::class, fn () => Task::fromArray(['schema' => 'agents.task/v9', 'type' => 'a.b', 'payload' => []]));
    }

    public function testMessageTopicPatterns(): void
    {
        $message = Message::create('dependency.drift.detected', [], 'x', 'corr');
        $this->assertTrue($message->matches('*'));
        $this->assertTrue($message->matches('dependency.*'));
        $this->assertTrue($message->matches('dependency.drift.detected'));
        $this->assertFalse($message->matches('dependency.drift'));
        $this->assertFalse($message->matches('deployment.*'));
    }

    public function testBusDeliversFifoWithoutRecursion(): void
    {
        $bus = new InMemoryBus();
        $seen = [];
        $bus->subscribe('*', function (Message $m) use (&$seen, $bus): void {
            $seen[] = 'start:'.$m->topic;
            if ($m->topic === 'a.first') {
                $bus->publish(Message::create('a.nested', [], 'x', 'c'));
            }
            $seen[] = 'end:'.$m->topic;
        });
        $bus->publish(Message::create('a.first', [], 'x', 'c'));
        $this->assertSame(['start:a.first', 'end:a.first', 'start:a.nested', 'end:a.nested'], $seen);
        $this->assertCount(2, $bus->history());
    }

    public function testJournalPersistsAndFiltersByCorrelation(): void
    {
        $journal = $this->dir.'/bus/journal.jsonl';
        $bus = new JournaledBus(new InMemoryBus(), $journal);
        $bus->publish(Message::create('a.one', ['k' => 'v'], 'x', 'c1'));
        $bus->publish(Message::create('a.two', [], 'x', 'c2'));
        $all = JournaledBus::read($journal);
        $this->assertCount(2, $all);
        $this->assertSame(['k' => 'v'], $all[0]->payload);
        $this->assertSame(['a.two'], array_map(static fn (Message $m): string => $m->topic, JournaledBus::read($journal, 'c2')));
    }

    public function testArtifactEnvelopeIsSealedAndTamperEvident(): void
    {
        $store = new ArtifactStore($this->dir.'/artifacts');
        $agent = new ScriptedAgent('producer', ['x.y']);
        $ref = $store->persist(new Artifact('report', ['b' => 2, 'a' => 1], ['head' => 'abc'], ['draft.json' => ['k' => 'v']]), Task::create('x.y'), $agent);

        $this->assertTrue(ArtifactStore::verify($ref['path'])['valid']);
        $envelope = Json::decodeFile($ref['path']);
        $this->assertSame('agents.artifact/v1', $envelope['schema']);
        $this->assertSame(['agent' => 'producer', 'version' => '1.0.0'], $envelope['producer']);
        $this->assertSame($ref['digest'], $envelope['integrity']['digest']);

        $tampered = $envelope;
        $tampered['payload']['a'] = 999;
        file_put_contents($ref['path'], Json::encode($tampered));
        $this->assertContains('Envelope digest mismatch: artifact.json was modified after it was sealed.', ArtifactStore::verify($ref['path'])['errors']);

        file_put_contents($ref['path'], Json::encode($envelope));
        file_put_contents(dirname($ref['path']).'/draft.json', '{"k":"changed"}');
        $this->assertContains('Attachment digest mismatch: draft.json', ArtifactStore::verify($ref['path'])['errors']);
    }

    public function testArtifactRejectsUnsafeNames(): void
    {
        $this->assertThrows(ProtocolException::class, fn () => new Artifact('Bad Kind', []));
        $this->assertThrows(ProtocolException::class, fn () => new Artifact('report', [], [], ['../escape.json' => []]));
        $this->assertThrows(ProtocolException::class, fn () => new Artifact('report', [], [], ['notes.txt' => []]));
    }

    public function testBaseAgentValidatesPayloadAndContainsFailures(): void
    {
        file_put_contents($this->dir.'/workspace.json', Json::encode(['schema' => AgentContext::SCHEMA, 'projects' => ['p' => ['path' => '.']]]));
        $context = AgentContext::fromFile($this->dir.'/workspace.json');
        $strict = new ScriptedAgent('strict', ['x.y'], schema: ['name' => 'string', 'tags' => '?list']);

        $this->assertSame(Status::Pass, $strict->handle(Task::create('x.y', ['name' => 'n', 'tags' => ['a']]), $context)->status);
        foreach ([
            [['name' => 'n', 'typo' => 1], "Unknown payload key 'typo'"],
            [[], "'name' is required"],
            [['name' => 5], "'name' must be of type string"],
            [['name' => 'n', 'tags' => ['k' => 'v']], "'tags' must be of type list"],
        ] as [$payload, $expected]) {
            $result = $strict->handle(Task::create('x.y', $payload), $context);
            $this->assertSame(Status::Blocked, $result->status);
            $this->assertSame('INVALID_PAYLOAD', $result->findings[0]->code);
            $this->assertStringContains($expected, $result->summary);
        }

        $this->assertSame('UNSUPPORTED_TASK', $strict->handle(Task::create('other.type', ['name' => 'n']), $context)->findings[0]->code);

        $precondition = new ScriptedAgent('pre', ['x.y'], fn () => throw new ProtocolException('NEEDS_THING', 'thing missing'));
        $blocked = $precondition->handle(Task::create('x.y'), $context);
        $this->assertSame(Status::Blocked, $blocked->status);
        $this->assertSame('NEEDS_THING', $blocked->findings[0]->code);

        $defect = new ScriptedAgent('bug', ['x.y'], fn () => throw new \LogicException('boom'));
        $failed = $defect->handle(Task::create('x.y'), $context);
        $this->assertSame(Status::Failed, $failed->status);
        $this->assertSame('LogicException: boom', $failed->error);
    }

    public function testResultReportsHighestSeverity(): void
    {
        $this->assertNull(Result::pass('ok')->highestSeverity());
        $result = Result::pass('x', [Finding::info('A', 'a'), Finding::blocker('B', 'b'), Finding::warning('C', 'c')]);
        $this->assertSame('blocker', $result->highestSeverity()->value);
    }
}
