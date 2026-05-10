<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\BackupConfiguration;
use App\Entity\Storage;
use PHPUnit\Framework\TestCase;

final class BackupConfigurationTest extends TestCase
{
    public function testSkipRcloneCheckDefaultsToFalse(): void
    {
        $config = new BackupConfiguration();

        self::assertFalse($config->isSkipRcloneCheck());
    }

    public function testSkipRcloneCheckSetterReturnsSelfAndPersistsValue(): void
    {
        $config = new BackupConfiguration();

        $result = $config->setSkipRcloneCheck(true);

        self::assertSame($config, $result);
        self::assertTrue($config->isSkipRcloneCheck());

        $config->setSkipRcloneCheck(false);
        self::assertFalse($config->isSkipRcloneCheck());
    }

    public function testGetKopiaEnvReturnsExpectedShape(): void
    {
        $storage = new Storage()
            ->setType(Storage::TYPE_KOPIA)
            ->setKopiaPassword('secret');

        $config = new BackupConfiguration()
            ->setStorage($storage);

        self::assertSame(['KOPIA_PASSWORD' => 'secret'], $config->getKopiaEnv());
    }

    public function testGetKopiaEnvReturnsEmptyArrayWhenNoStorage(): void
    {
        $config = new BackupConfiguration();

        self::assertSame([], $config->getKopiaEnv());
    }

    public function testGetKopiaEnvReturnsEmptyArrayWhenPasswordMissing(): void
    {
        $storage = new Storage()
            ->setType(Storage::TYPE_KOPIA);

        $config = new BackupConfiguration()
            ->setStorage($storage);

        self::assertSame([], $config->getKopiaEnv());
    }

    public function testKopiaCheckTagsRoundTrip(): void
    {
        $config = new BackupConfiguration();

        self::assertNull($config->getKopiaCheckTags());

        $result = $config->setKopiaCheckTags('workload:db');

        self::assertSame($config, $result);
        self::assertSame('workload:db', $config->getKopiaCheckTags());

        $config->setKopiaCheckTags(null);
        self::assertNull($config->getKopiaCheckTags());
    }
}
