<?php

declare(strict_types=1);

namespace App\Tests\Restore\Destination;

use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\RestoreDestination;
use App\Entity\Storage;
use App\Restore\Destination\BundleBuilder;
use App\Restore\Destination\RcloneRestoreDestinationStrategy;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

final class RcloneRestoreDestinationStrategyTest extends TestCase
{
    public function testSupportsOnlyRcloneType(): void
    {
        $strategy = new RcloneRestoreDestinationStrategy(new BundleBuilder());

        self::assertTrue($strategy->supports(new RestoreDestination()->setType(RestoreDestination::TYPE_RCLONE)));
        self::assertFalse($strategy->supports(new RestoreDestination()->setType(RestoreDestination::TYPE_MYSQL)));
    }

    public function testRenderBundleProducesExpectedFiles(): void
    {
        $strategy = new RcloneRestoreDestinationStrategy(new BundleBuilder());

        $sourceStorage = new Storage()
            ->setType(Storage::TYPE_RCLONE)
            ->setName('source-store')
            ->setRcloneConfiguration("[backup-prod]\ntype = s3\naccess_key_id = AAAA\n");

        $targetStorage = new Storage()
            ->setType(Storage::TYPE_RCLONE)
            ->setName('target-store')
            ->setRcloneConfiguration("[dr-bucket]\ntype = s3\naccess_key_id = BBBB\n");

        $config = new BackupConfiguration();
        $config->setType(BackupConfiguration::TYPE_RCLONE);
        $configSlugProperty = new ReflectionProperty(BackupConfiguration::class, 'slug');
        $configSlugProperty->setValue($config, 'prod-files');
        $config->setStorage($sourceStorage);

        $reflStorageSub = new ReflectionProperty(BackupConfiguration::class, 'storageSubPath');
        $reflStorageSub->setValue($config, 'backup-prod:/data/2026/05');

        $backup = new Backup();
        $idProp = new ReflectionProperty(Backup::class, 'id');
        $idProp->setValue($backup, 42);
        $backup->setBackupConfiguration($config);

        $destination = new RestoreDestination()
            ->setName('DR rclone')
            ->setType(RestoreDestination::TYPE_RCLONE)
            ->setStorage($targetStorage)
            ->setTargetPath('dr-bucket:/restored/2026/05');
        $destSlug = new ReflectionProperty(RestoreDestination::class, 'slug');
        $destSlug->setValue($destination, 'dr-rclone');

        $bundle = $strategy->renderBundle($destination, $backup);

        self::assertSame('restore-dr-rclone-42', $bundle->name);
        self::assertArrayHasKey('restore.sh', $bundle->files);
        self::assertArrayHasKey('rclone.conf', $bundle->files);
        self::assertArrayHasKey('MANIFEST.txt', $bundle->files);

        $script = $bundle->files['restore.sh']->contents;
        self::assertStringContainsString('rclone --config rclone.conf copy "backup-prod:/data/2026/05" "dr-bucket:/restored/2026/05"', $script);

        $rclone = $bundle->files['rclone.conf']->contents;
        self::assertStringContainsString('[backup-prod]', $rclone);
        self::assertStringContainsString('[dr-bucket]', $rclone);
        self::assertStringNotContainsString('[source]', $rclone);
        self::assertStringNotContainsString('[target]', $rclone);
        self::assertStringContainsString('AAAA', $rclone);
        self::assertStringContainsString('BBBB', $rclone);

        self::assertSame(0o700, $bundle->files['restore.sh']->mode);
        self::assertSame(0o600, $bundle->files['rclone.conf']->mode);
        self::assertSame(0o644, $bundle->files['MANIFEST.txt']->mode);
    }

    public function testRejectsNonRcloneSourceStorage(): void
    {
        $strategy = new RcloneRestoreDestinationStrategy(new BundleBuilder());

        $sourceStorage = new Storage()->setType(Storage::TYPE_RESTIC)->setName('restic-src');
        $targetStorage = new Storage()
            ->setType(Storage::TYPE_RCLONE)
            ->setName('target-store')
            ->setRcloneConfiguration("[t]\ntype=s3\n");

        $config = new BackupConfiguration();
        $config->setType(BackupConfiguration::TYPE_RCLONE);
        $config->setStorage($sourceStorage);
        $reflStorageSub = new ReflectionProperty(BackupConfiguration::class, 'storageSubPath');
        $reflStorageSub->setValue($config, 'x:/y');
        $backup = new Backup();
        $idProp = new ReflectionProperty(Backup::class, 'id');
        $idProp->setValue($backup, 1);
        $backup->setBackupConfiguration($config);

        $destination = new RestoreDestination()
            ->setName('d')
            ->setType(RestoreDestination::TYPE_RCLONE)
            ->setStorage($targetStorage)
            ->setTargetPath('t:/r');
        $destSlug = new ReflectionProperty(RestoreDestination::class, 'slug');
        $destSlug->setValue($destination, 'd');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source Storage to be of type "rclone"');
        $strategy->renderBundle($destination, $backup);
    }

    public function testTargetMustBeRclone(): void
    {
        $strategy = new RcloneRestoreDestinationStrategy(new BundleBuilder());

        $sourceStorage = new Storage()
            ->setType(Storage::TYPE_RCLONE)
            ->setName('src')
            ->setRcloneConfiguration("[r]\ntype=s3\n");
        $targetStorage = new Storage()->setType(Storage::TYPE_RESTIC)->setName('not-rclone');

        $config = new BackupConfiguration();
        $config->setType(BackupConfiguration::TYPE_RCLONE);
        $config->setStorage($sourceStorage);
        $reflStorageSub = new ReflectionProperty(BackupConfiguration::class, 'storageSubPath');
        $reflStorageSub->setValue($config, 'r:/x');
        $backup = new Backup();
        $idProp = new ReflectionProperty(Backup::class, 'id');
        $idProp->setValue($backup, 1);
        $backup->setBackupConfiguration($config);

        $destination = new RestoreDestination()
            ->setName('d')
            ->setType(RestoreDestination::TYPE_RCLONE)
            ->setStorage($targetStorage)
            ->setTargetPath('r:/y');
        $destSlug = new ReflectionProperty(RestoreDestination::class, 'slug');
        $destSlug->setValue($destination, 'd');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('target Storage to be of type "rclone"');
        $strategy->renderBundle($destination, $backup);
    }
}
