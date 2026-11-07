<?php
declare(strict_types=1);

namespace Bootstrap;

use RuntimeException;

/**
 * Runs a command without a shell, with a hard timeout and captured output.
 *
 * Passing the command as an argv array means no shell interpolation happens,
 * so repository names and paths cannot inject commands.
 */
final class Process
{
    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{exit: int, stdout: string, stderr: string, timed_out: bool}
     */
    public static function run(array $command, array $env = [], ?string $cwd = null, int $timeout = 120): array
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('proc_open() is disabled; the hosting panel must allow it for git deployments.');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start process: ' . implode(' ', $command));
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $deadline = microtime(true) + max(1, $timeout);
        $exitCode = -1;

        while (true) {
            $status = proc_get_status($process);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }

            if (microtime(true) > $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }

            usleep(15000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if ($timedOut) {
            proc_close($process);
            throw new RuntimeException('Command timed out after ' . $timeout . 's: ' . implode(' ', $command));
        }

        $closed = proc_close($process);
        if ($exitCode < 0) {
            $exitCode = $closed;
        }

        return [
            'exit' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timed_out' => false,
        ];
    }

    /** @return array{exit: int, stdout: string, stderr: string, timed_out: bool} */
    public static function runShell(string $commandLine, array $env = [], ?string $cwd = null, int $timeout = 120): array
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('proc_open() is disabled.');
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($commandLine, $descriptors, $pipes, $cwd, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start command.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + max(1, $timeout);
        $exitCode = -1;

        while (true) {
            $status = proc_get_status($process);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                proc_close($process);

                throw new RuntimeException('Command timed out after ' . $timeout . 's.');
            }
            usleep(15000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);

        return [
            'exit' => $exitCode >= 0 ? $exitCode : $closed,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timed_out' => false,
        ];
    }
}
