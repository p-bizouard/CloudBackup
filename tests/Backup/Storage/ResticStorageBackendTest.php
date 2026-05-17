<?php

declare(strict_types=1);

namespace App\Tests\Backup\Storage;

use App\Backup\Logging\BackupLogger;
use App\Backup\Process\ProcessExecutionException;
use App\Backup\Process\ProcessOutcome;
use App\Backup\Process\ProcessRunnerInterface;
use App\Backup\Storage\ResticStorageBackend;
use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\Storage;
use PHPUnit\Framework\TestCase;

final class ResticStorageBackendTest extends TestCase
{
    public function testSupportsReturnsTrueOnlyForResticStorage(): void
    {
        $backend = new ResticStorageBackend(
            $this->createMock(ProcessRunnerInterface::class),
            $this->createMock(BackupLogger::class),
        );

        self::assertTrue($backend->supports(new Storage()->setType(Storage::TYPE_RESTIC)));
        self::assertFalse($backend->supports(new Storage()->setType(Storage::TYPE_RCLONE)));
        self::assertFalse($backend->supports(new Storage()->setType(Storage::TYPE_KOPIA)));
    }

    public function testInitRepositoryTreatsAlreadyInitialisedMarkerAsSuccess(): void
    {
        $runner = $this->createMock(ProcessRunnerInterface::class);
        $runner->method('runShell')->willReturn(new ProcessOutcome(
            successful: false,
            output: '',
            errorOutput: 'Fatal: create key in repository repository master key and config already initialized',
            exitCode: 1,
            command: 'restic init',
        ));

        $backend = new ResticStorageBackend($runner, $this->createMock(BackupLogger::class));

        $backend->initRepository($this->buildBackup());

        // No exception means the existing-repo marker was accepted.
        $this->addToAssertionCount(1);
    }

    public function testInitRepositoryRethrowsOnGenuineFailure(): void
    {
        $runner = $this->createMock(ProcessRunnerInterface::class);
        $runner->method('runShell')->willReturn(new ProcessOutcome(
            successful: false,
            output: '',
            errorOutput: 'permission denied',
            exitCode: 1,
            command: 'restic init',
        ));

        $backend = new ResticStorageBackend($runner, $this->createMock(BackupLogger::class));

        $this->expectException(ProcessExecutionException::class);
        $backend->initRepository($this->buildBackup());
    }

    public function testUploadLocalIssuesResticBackupWithTags(): void
    {
        $runner = $this->createMock(ProcessRunnerInterface::class);

        $runner->expects($this->once())
            ->method('runShell')
            ->willReturnCallback(function (string $command, array $env, ?int $timeout): ProcessOutcome {
                self::assertStringContainsString('--tag host="${RESTIC_TAG_VAL_0}"', $command);
                self::assertStringContainsString('--tag configuration="${RESTIC_TAG_VAL_1}"', $command);
                self::assertStringContainsString('--host cloudbackup', $command);
                self::assertSame('the-host', $env['RESTIC_TAG_VAL_0']);
                self::assertSame('the-config', $env['RESTIC_TAG_VAL_1']);
                self::assertSame('/tmp/payload', $env['DIRECTORY']);
                self::assertSame(ResticStorageBackend::UPLOAD_TIMEOUT, $timeout);

                return new ProcessOutcome(true, 'ok', '', 0, $command);
            });

        $backend = new ResticStorageBackend($runner, $this->createMock(BackupLogger::class));
        $backend->uploadLocal(
            $this->buildBackup(),
            '/tmp/payload',
            ['host' => 'the-host', 'configuration' => 'the-config'],
        );
    }

    public function testPruneRunsForgetWithKeepDailyAndKeepWeekly(): void
    {
        $runner = $this->createMock(ProcessRunnerInterface::class);

        $runner->expects($this->once())
            ->method('runShell')
            ->willReturnCallback(function (string $command): ProcessOutcome {
                self::assertStringContainsString('restic forget --prune', $command);
                self::assertStringContainsString('--keep-daily 7', $command);
                self::assertStringContainsString('--keep-weekly 4', $command);

                return new ProcessOutcome(true, '', '', 0, $command);
            });

        $backend = new ResticStorageBackend($runner, $this->createMock(BackupLogger::class));
        $backup = $this->buildBackup();
        $backup->getBackupConfiguration()->setKeepDaily(7);
        $backup->getBackupConfiguration()->setKeepWeekly(4);

        $backend->prune($backup);
    }

    private function buildBackup(): Backup
    {
        $storage = new Storage()
            ->setType(Storage::TYPE_RESTIC)
            ->setResticRepo('r')
            ->setResticPassword('p');

        $configuration = new BackupConfiguration()
            ->setName('cfg')
            ->setSlug('cfg')
            ->setStorage($storage)
            ->setType(BackupConfiguration::TYPE_MYSQL);

        return new Backup()
            ->setBackupConfiguration($configuration)
            ->setCreatedAt(new \DateTime('2026-05-16'));
    }
}
