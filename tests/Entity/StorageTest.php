<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Storage;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    public function testIsKopiaTrueWhenTypeIsKopia(): void
    {
        $storage = new Storage()->setType(Storage::TYPE_KOPIA);

        self::assertTrue($storage->isKopia());
        self::assertFalse($storage->isRestic());
        self::assertFalse($storage->isRclone());
    }

    public function testIsKopiaFalseForOtherTypes(): void
    {
        $restic = new Storage()->setType(Storage::TYPE_RESTIC);
        $rclone = new Storage()->setType(Storage::TYPE_RCLONE);

        self::assertFalse($restic->isKopia());
        self::assertFalse($rclone->isKopia());
    }

    public function testGetAvailableTypesIncludesKopia(): void
    {
        self::assertContains(Storage::TYPE_KOPIA, Storage::getAvailableTypes());
    }

    public function testKopiaFieldRoundTrip(): void
    {
        $storage = new Storage();

        self::assertSame($storage, $storage->setKopiaBackend('s3'));
        self::assertSame($storage, $storage->setKopiaConnectArgs('--bucket=mybucket'));
        self::assertSame($storage, $storage->setKopiaPassword('secret'));

        self::assertSame('s3', $storage->getKopiaBackend());
        self::assertSame('--bucket=mybucket', $storage->getKopiaConnectArgs());
        self::assertSame('secret', $storage->getKopiaPassword());
    }
}
