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
}
