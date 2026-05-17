<?php

declare(strict_types=1);

namespace App\Backup\Process;

use Symfony\Component\Process\Exception\RuntimeException as SymfonyProcessRuntimeException;
use Symfony\Component\Process\Process;

final class ProcessRunner implements ProcessRunnerInterface
{
    public function runShell(string $command, array $env = [], ?int $timeoutSeconds = null): ProcessOutcome
    {
        $process = Process::fromShellCommandline($command, null, $env);
        if (null !== $timeoutSeconds) {
            $process->setTimeout($timeoutSeconds);
        }

        try {
            $process->run();
        } catch (SymfonyProcessRuntimeException $e) {
            $processOutcome = new ProcessOutcome(
                successful: false,
                output: $process->getOutput(),
                errorOutput: $process->getErrorOutput() ?: $e->getMessage(),
                exitCode: (int) ($process->getExitCode() ?? -1),
                command: $command,
            );
            throw new ProcessExecutionException($processOutcome, \sprintf('Process launch/runtime failure: %s', $e->getMessage()));
        }

        return new ProcessOutcome(
            successful: $process->isSuccessful(),
            output: $process->getOutput(),
            errorOutput: $process->getErrorOutput(),
            exitCode: (int) ($process->getExitCode() ?? -1),
            command: $command,
        );
    }
}
