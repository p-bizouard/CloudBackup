<?php

declare(strict_types=1);

namespace App\Tests\Backup\Path;

use App\Backup\Path\TemporaryPathResolver;
use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use PHPUnit\Framework\TestCase;

final class TemporaryPathResolverTest extends TestCase
{
    public function testSshfsTypeOmitsExtensionEvenWhenSet(): void
    {
        $resolver = new TemporaryPathResolver('/tmp/bkp');

        $configuration = new BackupConfiguration()
            ->setName('shareA')
            ->setSlug('share-a')
            ->setType(BackupConfiguration::TYPE_SSHFS)
            ->setCustomExtension('tar.gz');
        $backup = new Backup()->setBackupConfiguration($configuration);

        self::assertSame('/tmp/bkp/share-a', $resolver->resolve($backup));
    }

    public function testDefaultPathAppendsExtensionWhenSet(): void
    {
        $resolver = new TemporaryPathResolver('/tmp/bkp');

        $configuration = new BackupConfiguration()
            ->setName('db')
            ->setSlug('db')
            ->setType(BackupConfiguration::TYPE_MYSQL)
            ->setCustomExtension('sql.gz');
        $backup = new Backup()->setBackupConfiguration($configuration);

        self::assertSame('/tmp/bkp/db.sql.gz', $resolver->resolve($backup));
    }

    public function testDefaultPathWithoutCustomExtensionFallsBackToTypeDefault(): void
    {
        $resolver = new TemporaryPathResolver('/var/cache/bkp');

        $configuration = new BackupConfiguration()
            ->setName('vm')
            ->setSlug('vm')
            ->setType(BackupConfiguration::TYPE_OS_INSTANCE);
        $backup = new Backup()->setBackupConfiguration($configuration);

        self::assertSame('/var/cache/bkp/vm.qcow2', $resolver->resolve($backup));
    }

    public function testDefaultPathFallsBackToBareSlugForTypesWithoutDefaultExtension(): void
    {
        $resolver = new TemporaryPathResolver('/var/cache/bkp');

        $configuration = new BackupConfiguration()
            ->setName('shr')
            ->setSlug('shr')
            ->setType(BackupConfiguration::TYPE_RCLONE);
        $backup = new Backup()->setBackupConfiguration($configuration);

        self::assertSame('/var/cache/bkp/shr', $resolver->resolve($backup));
    }
}
