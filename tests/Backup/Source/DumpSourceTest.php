<?php

declare(strict_types=1);

namespace App\Tests\Backup\Source;

use App\Backup\Logging\BackupLogger;
use App\Backup\Path\TemporaryPathResolver;
use App\Backup\Process\ProcessExecutionException;
use App\Backup\Process\ProcessOutcome;
use App\Backup\Process\ProcessRunnerInterface;
use App\Backup\Source\DumpSource;
use App\Backup\Ssh\SshKeyMaterializer;
use App\Backup\Ssh\SshOptionsBuilder;
use App\Backup\Storage\ResticStorageBackend;
use App\Backup\Storage\StorageBackendRegistry;
use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\Storage;
use PHPUnit\Framework\TestCase;

final class DumpSourceTest extends TestCase
{
    public function testSupportsAllDumpTypes(): void
    {
        $source = $this->buildSource(
            $this->createMock(ProcessRunnerInterface::class),
            $this->createMock(BackupLogger::class),
            $this->createMock(StorageBackendRegistry::class),
        );

        foreach (DumpSource::SUPPORTED_TYPES as $type) {
            $configuration = new BackupConfiguration()->setType($type);
            self::assertTrue($source->supports($configuration), $type);
        }

        self::assertFalse($source->supports(new BackupConfiguration()->setType(BackupConfiguration::TYPE_RCLONE)));
    }

    public function testDownloadWithoutHostUsesLocalShell(): void
    {
        $runner = $this->createMock(ProcessRunnerInterface::class);
        $runner->expects($this->once())
            ->method('runShell')
            ->willReturnCallback(function (string $command, array $env): ProcessOutcome {
                self::assertStringStartsWith('sh -c "${DUMP_COMMAND}"', $command);
                self::assertSame('mysqldump db', $env['DUMP_COMMAND']);

                return new ProcessOutcome(true, '', '', 0, $command);
            });

        $tempDir = sys_get_temp_dir();
        $pathResolver = new TemporaryPathResolver($tempDir);

        $source = new DumpSource(
            $runner,
            $this->createMock(BackupLogger::class),
            $pathResolver,
            $this->createMock(StorageBackendRegistry::class),
            new SshOptionsBuilder(),
            new SshKeyMaterializer(),
        );

        $configuration = new BackupConfiguration()
            ->setName('db')
            ->setSlug('db')
            ->setType(BackupConfiguration::TYPE_MYSQL)
            ->setDumpCommand('mysqldump db')
            ->setCustomExtension('sql');
        $backup = new Backup()
            ->setBackupConfiguration($configuration)
            ->setCreatedAt(new \DateTime('2026-05-16'));

        $destination = $pathResolver->resolve($backup);
        @unlink($destination);
        file_put_contents($destination, 'fake-dump-bytes');

        try {
            $source->download($backup);
            self::assertSame(\strlen('fake-dump-bytes'), $backup->getSize());
        } finally {
            @unlink($destination);
        }
    }

    public function testDownloadThrowsWhenProcessFails(): void
    {
        $runner = $this->createMock(ProcessRunnerInterface::class);
        $runner->method('runShell')->willReturn(new ProcessOutcome(false, '', 'no perms', 1, 'sh -c'));

        $source = $this->buildSource($runner, $this->createMock(BackupLogger::class), $this->createMock(StorageBackendRegistry::class));

        $configuration = new BackupConfiguration()
            ->setName('db')
            ->setSlug('db')
            ->setType(BackupConfiguration::TYPE_MYSQL)
            ->setDumpCommand('mysqldump db');
        $backup = new Backup()
            ->setBackupConfiguration($configuration)
            ->setCreatedAt(new \DateTime('2026-05-16'));

        $this->expectException(ProcessExecutionException::class);
        $source->download($backup);
    }

    public function testUploadDelegatesToResticBackendWithTags(): void
    {
        $resticBackend = $this->createMock(ResticStorageBackend::class);
        $resticBackend->method('supports')->willReturn(true);
        $resticBackend->expects($this->once())
            ->method('uploadLocal')
            ->with(
                $this->isInstanceOf(Backup::class),
                $this->isType('string'),
                self::callback(static fn (array $tags): bool => 'direct' === $tags['host'] && 'cfg' === $tags['configuration']),
            );

        $registry = $this->createMock(StorageBackendRegistry::class);
        $registry->method('forStorage')->willReturn($resticBackend);

        $source = $this->buildSource(
            $this->createMock(ProcessRunnerInterface::class),
            $this->createMock(BackupLogger::class),
            $registry,
        );

        $storage = new Storage()->setType(Storage::TYPE_RESTIC);
        $configuration = new BackupConfiguration()
            ->setName('cfg')
            ->setSlug('cfg')
            ->setStorage($storage)
            ->setType(BackupConfiguration::TYPE_MYSQL);
        $backup = new Backup()
            ->setBackupConfiguration($configuration)
            ->setCreatedAt(new \DateTime('2026-05-16'));

        $source->upload($backup);
    }

    public function testUploadOnNonResticBackendIsNoop(): void
    {
        $registry = $this->createMock(StorageBackendRegistry::class);
        $registry->method('forStorage')->willReturn(new class implements \App\Backup\Storage\StorageBackendInterface {
            public function supports(Storage $storage): bool
            {
                return true;
            }

            public function initRepository(Backup $backup): void
            {
            }

            public function healthCheck(Backup $backup, bool $tryRepair = true): void
            {
            }

            public function prune(Backup $backup): void
            {
            }
        });

        $source = $this->buildSource(
            $this->createMock(ProcessRunnerInterface::class),
            $this->createMock(BackupLogger::class),
            $registry,
        );

        $storage = new Storage()->setType(Storage::TYPE_RCLONE);
        $configuration = new BackupConfiguration()
            ->setName('cfg')
            ->setSlug('cfg')
            ->setStorage($storage)
            ->setType(BackupConfiguration::TYPE_MYSQL);
        $backup = new Backup()
            ->setBackupConfiguration($configuration)
            ->setCreatedAt(new \DateTime('2026-05-16'));

        $source->upload($backup);
        // Reaching here means the source did not delegate when the backend is wrong.
        $this->addToAssertionCount(1);
    }

    private function buildSource(
        ProcessRunnerInterface $runner,
        BackupLogger $logger,
        StorageBackendRegistry $registry,
    ): DumpSource {
        return new DumpSource(
            $runner,
            $logger,
            new TemporaryPathResolver(sys_get_temp_dir()),
            $registry,
            new SshOptionsBuilder(),
            new SshKeyMaterializer(),
        );
    }
}
