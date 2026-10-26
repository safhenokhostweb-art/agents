<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests;

use Throwable;

abstract class TestCase
{
    public int $assertions = 0;

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $this->fail(($message !== '' ? $message."\n" : '').'expected '.var_export($expected, true).', got '.var_export($actual, true));
        }
    }

    protected function assertTrue(mixed $actual, string $message = ''): void
    {
        $this->assertSame(true, $actual, $message);
    }

    protected function assertFalse(mixed $actual, string $message = ''): void
    {
        $this->assertSame(false, $actual, $message);
    }

    protected function assertNull(mixed $actual, string $message = ''): void
    {
        $this->assertSame(null, $actual, $message);
    }

    protected function assertCount(int $expected, array $actual, string $message = ''): void
    {
        $this->assertSame($expected, count($actual), $message);
    }

    protected function assertContains(mixed $needle, array $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (! in_array($needle, $haystack, true)) {
            $this->fail(($message !== '' ? $message."\n" : '').var_export($needle, true).' not found in '.json_encode($haystack));
        }
    }

    protected function assertNotContains(mixed $needle, array $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (in_array($needle, $haystack, true)) {
            $this->fail(($message !== '' ? $message."\n" : '').var_export($needle, true).' unexpectedly found in '.json_encode($haystack));
        }
    }

    protected function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertions++;
        if (! str_contains($haystack, $needle)) {
            $this->fail("'{$needle}' not found in '{$haystack}'");
        }
    }

    /** @param class-string<Throwable> $class */
    protected function assertThrows(string $class, callable $callback, ?string $messageContains = null): Throwable
    {
        $this->assertions++;
        try {
            $callback();
        } catch (Throwable $exception) {
            if (! $exception instanceof $class) {
                $this->fail("expected {$class}, got ".$exception::class.': '.$exception->getMessage());
            }
            if ($messageContains !== null && ! str_contains($exception->getMessage(), $messageContains)) {
                $this->fail("exception message '{$exception->getMessage()}' does not contain '{$messageContains}'");
            }

            return $exception;
        }
        $this->fail("expected {$class} to be thrown");
    }

    protected function fail(string $message): never
    {
        throw new AssertionFailed($message);
    }

    protected function skip(string $reason): never
    {
        throw new Skipped($reason);
    }
}
