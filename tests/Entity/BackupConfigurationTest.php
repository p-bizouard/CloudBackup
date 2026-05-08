<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\BackupConfiguration;
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
}
