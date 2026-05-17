<?php

declare(strict_types=1);

namespace App\Backup\Process;

use RuntimeException;

final class ProcessExecutionException extends RuntimeException
{
    public function __construct(public readonly ProcessOutcome $outcome, ?string $message = null)
    {
        parent::__construct($message ?? \sprintf(
            'Process failed (exit %d): %s | stderr: %s',
            $outcome->exitCode,
            $outcome->command,
            $outcome->errorOutput,
        ));
    }
}
