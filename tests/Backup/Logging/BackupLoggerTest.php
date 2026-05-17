<?php

declare(strict_types=1);

namespace App\Tests\Backup\Logging;

use App\Backup\Logging\BackupLogger;
use App\Entity\Backup;
use App\Entity\Log;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class BackupLoggerTest extends TestCase
{
    public function testLogPersistsLogEntityAndFlushes(): void
    {
        $backup = new Backup();
        $psr = $this->createMock(LoggerInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $psr->expects($this->once())->method('info')->with('hello');

        $em->expects($this->exactly(2))->method('persist')
            ->willReturnCallback(static function (object $entity) use ($backup): void {
                if ($entity instanceof Log) {
                    self::assertSame(Log::LOG_INFO, $entity->getLevel());
                    self::assertSame('hello', $entity->getMessage());

                    return;
                }
                self::assertSame($backup, $entity);
            });

        $em->expects($this->once())->method('flush');

        new BackupLogger($psr, $em)->log($backup, Log::LOG_INFO, 'hello');

        self::assertCount(1, $backup->getLogs());
    }

    public function testLogRoutesErrorLevelToPsrError(): void
    {
        $backup = new Backup();
        $psr = $this->createMock(LoggerInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $psr->expects($this->once())->method('error')->with('boom');

        new BackupLogger($psr, $em)->log($backup, Log::LOG_ERROR, 'boom');
    }

    public function testLogRejectsUnknownLevel(): void
    {
        $logger = new BackupLogger(
            $this->createMock(LoggerInterface::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $this->expectException(InvalidArgumentException::class);
        $logger->log(new Backup(), 'debug', 'never');
    }

    public function testFormatParametersReturnsJsonWithoutTags(): void
    {
        $logger = new BackupLogger(
            $this->createMock(LoggerInterface::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $out = $logger->formatParameters(['a' => '<b>x</b>']);

        self::assertStringNotContainsString('<b>', $out);
        self::assertStringContainsString('"a":', $out);
    }
}
