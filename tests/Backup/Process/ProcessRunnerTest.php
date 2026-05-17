<?php

declare(strict_types=1);

namespace App\Tests\Backup\Process;

use App\Backup\Process\ProcessRunner;
use PHPUnit\Framework\TestCase;

final class ProcessRunnerTest extends TestCase
{
    public function testSuccessfulCommandReturnsSuccessOutcome(): void
    {
        $outcome = new ProcessRunner()->runShell('echo "${MSG}"', ['MSG' => 'hi'], 5);

        self::assertTrue($outcome->successful);
        self::assertSame(0, $outcome->exitCode);
        self::assertStringContainsString('hi', $outcome->output);
    }

    public function testFailingCommandReturnsFailureOutcome(): void
    {
        $outcome = new ProcessRunner()->runShell('exit 7', [], 5);

        self::assertFalse($outcome->successful);
        self::assertSame(7, $outcome->exitCode);
    }
}
