<?php

declare(strict_types=1);

namespace Workspace\Agents\Support;

use RuntimeException;

final class Process
{
    /**
     * Runs a command without a shell (array form), so no argument is ever
     * shell-interpreted.
     *
     * @param  list<string>  $command
     * @return array{code: int, stdout: string, stderr: string}
     */
    public static function run(array $command, string $cwd): array
    {
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (! is_resource($process)) {
            $reason = error_get_last()['message'] ?? 'unknown error';
            throw new RuntimeException('Cannot start process: '.implode(' ', $command)." ({$reason})");
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }

    /**
     * Runs a long command without a shell, with extra environment variables
     * on top of the current environment, writing stdout and stderr to files
     * (a full pipe can never stall a long run, as sequential pipe reads can).
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env  variables added to (or replacing in) the inherited environment
     */
    public static function runToFiles(array $command, string $cwd, array $env, string $stdoutFile, string $stderrFile): int
    {
        $environment = array_merge(getenv(), $env);
        $process = @proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']], $pipes, $cwd, $environment);
        if (! is_resource($process)) {
            $reason = error_get_last()['message'] ?? 'unknown error';
            throw new RuntimeException('Cannot start process: '.implode(' ', $command)." ({$reason})");
        }

        return proc_close($process);
    }
}
