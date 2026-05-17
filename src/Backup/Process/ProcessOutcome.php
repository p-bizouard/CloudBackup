<?php

declare(strict_types=1);

namespace App\Backup\Process;

final readonly class ProcessOutcome
{
    public function __construct(
        public bool $successful,
        public string $output,
        public string $errorOutput,
        public int $exitCode,
        public string $command,
    ) {
    }
}
