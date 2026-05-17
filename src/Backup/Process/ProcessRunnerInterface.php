<?php

declare(strict_types=1);

namespace App\Backup\Process;

interface ProcessRunnerInterface
{
    /**
     * Run a shell command with the given environment and timeout.
     *
     * @param array<string, scalar|null> $env
     */
    public function runShell(string $command, array $env = [], ?int $timeoutSeconds = null): ProcessOutcome;
}
