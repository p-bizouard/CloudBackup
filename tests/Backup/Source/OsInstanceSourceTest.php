<?php

declare(strict_types=1);

namespace App\Tests\Backup\Source;

use App\Backup\Logging\BackupLogger;
use App\Backup\Path\TemporaryPathResolver;
use App\Backup\Process\ProcessOutcome;
use App\Backup\Process\ProcessRunnerInterface;
use App\Backup\Source\OsInstanceSource;
use App\Backup\Storage\StorageBackendRegistry;
use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\OSInstance;
use App\Entity\OSProject;
use App\Entity\Storage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class OsInstanceSourceTest extends TestCase
{
    public function testSupportsOnlyOsInstance(): void
    {
        $source = $this->buildSource($this->createMock(ProcessRunnerInterface::class));

        self::assertTrue($source->supports(new BackupConfiguration()->setType(BackupConfiguration::TYPE_OS_INSTANCE)));
        self::assertFalse($source->supports(new BackupConfiguration()->setType(BackupConfiguration::TYPE_MYSQL)));
    }

    public function testGetSnapshotStatusReturnsNullWhenImageMissing(): void
    {
        $runner = $this->createMock(ProcessRunnerInterface::class);
        $runner->method('runShell')->willReturn(new ProcessOutcome(true, '[]', '', 0, 'openstack image list'));

        $source = $this->buildSource($runner);
        $backup = $this->buildBackup();

        self::assertNull($source->getSnapshotStatus($backup));
    }

    public function testGetSnapshotStatusPersistsAttributesWhenImageFound(): void
    {
        $runner = $this->createMock(ProcessRunnerInterface::class);
        $runner->method('runShell')->willReturn(new ProcessOutcome(
            true,
            json_encode([['ID' => 'img-1', 'Checksum' => 'abc', 'Size' => 42, 'Status' => 'active']]),
            '',
            0,
            'openstack image list',
        ));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $source = $this->buildSource($runner, $em);
        $backup = $this->buildBackup();

        self::assertSame('active', $source->getSnapshotStatus($backup));
        self::assertSame('img-1', $backup->getOsImageId());
        self::assertSame('abc', $backup->getChecksum());
        self::assertSame(42, $backup->getSize());
    }

    private function buildSource(
        ProcessRunnerInterface $runner,
        ?EntityManagerInterface $entityManager = null,
    ): OsInstanceSource {
        return new OsInstanceSource(
            $runner,
            $this->createMock(BackupLogger::class),
            new TemporaryPathResolver(sys_get_temp_dir()),
            $this->createMock(StorageBackendRegistry::class),
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
        );
    }

    private function buildBackup(): Backup
    {
        $project = new OSProject()
            ->setName('proj')
            ->setSlug('proj');
        $instance = new OSInstance()
            ->setName('inst')
            ->setSlug('inst')
            ->setId('os-id')
            ->setOSProject($project);

        $configuration = new BackupConfiguration()
            ->setName('cfg')
            ->setSlug('cfg')
            ->setType(BackupConfiguration::TYPE_OS_INSTANCE)
            ->setStorage(new Storage()->setType(Storage::TYPE_RESTIC))
            ->setOsInstance($instance);

        return new Backup()
            ->setBackupConfiguration($configuration)
            ->setCreatedAt(new \DateTime('2026-05-16'));
    }
}
