<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Backup\Logging\BackupLogger;
use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Repository\BackupRepository;
use App\Service\BackupService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\WorkflowInterface;

final class BackupServiceTest extends TestCase
{
    public function testInitBackupShortCircuitsWhenAlreadyBackupedTodayForDailyPeriodicity(): void
    {
        $configuration = new BackupConfiguration()
            ->setName('cfg')
            ->setSlug('cfg')
            ->setPeriodicity(BackupConfiguration::PERIODICITY_DAILY);

        $latest = new Backup()
            ->setBackupConfiguration($configuration)
            ->setCurrentPlace('backuped')
            ->setCreatedAt(new \DateTime());

        $repository = $this->createMock(BackupRepository::class);
        $repository->method('findOneBy')->willReturn($latest);

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects($this->never())->method('apply');

        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturn($workflow);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        new BackupService($em, $registry, $repository, $this->createMock(BackupLogger::class))
            ->initBackup($configuration);
    }

    public function testInitBackupCreatesNewBackupWhenNoneExists(): void
    {
        $configuration = new BackupConfiguration()
            ->setName('cfg')
            ->setSlug('cfg')
            ->setPeriodicity(BackupConfiguration::PERIODICITY_DAILY);

        $repository = $this->createMock(BackupRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->method('can')->willReturnCallback(static fn (Backup $b, string $transition): bool => \in_array($transition, ['start', 'dump'], true));

        $applied = [];
        $workflow->method('apply')->willReturnCallback(static function (Backup $b, string $transition) use (&$applied): Marking {
            $applied[] = $transition;

            return new Marking(['initialized' => 1]);
        });

        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturn($workflow);

        $em = $this->createMock(EntityManagerInterface::class);

        new BackupService($em, $registry, $repository, $this->createMock(BackupLogger::class))
            ->initBackup($configuration);

        self::assertSame(['start', 'dump'], $applied);
    }

    public function testInitBackupSilentSkipWhenLatestFailedTodayForDaily(): void
    {
        $configuration = new BackupConfiguration()
            ->setName('cfg')
            ->setSlug('cfg')
            ->setPeriodicity(BackupConfiguration::PERIODICITY_DAILY);

        $latest = new Backup()
            ->setBackupConfiguration($configuration)
            ->setCurrentPlace('failed')
            ->setCreatedAt(new \DateTime());

        $repository = $this->createMock(BackupRepository::class);
        $repository->method('findOneBy')->willReturn($latest);

        $registry = $this->createMock(Registry::class);
        $registry->expects($this->never())->method('get');

        $logger = $this->createMock(BackupLogger::class);
        $logger->expects($this->never())->method('log');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        new BackupService($em, $registry, $repository, $logger)->initBackup($configuration);
    }

    public function testPerformBackupSilentSkipWhenLatestFailedTodayForDaily(): void
    {
        $configuration = new BackupConfiguration()
            ->setName('cfg')
            ->setSlug('cfg')
            ->setPeriodicity(BackupConfiguration::PERIODICITY_DAILY);

        $latest = new Backup()
            ->setBackupConfiguration($configuration)
            ->setCurrentPlace('failed')
            ->setCreatedAt(new \DateTime());

        $repository = $this->createMock(BackupRepository::class);
        $repository->method('findOneBy')->willReturn($latest);

        $registry = $this->createMock(Registry::class);
        $registry->expects($this->never())->method('get');

        $logger = $this->createMock(BackupLogger::class);
        $logger->expects($this->never())->method('log');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        new BackupService($em, $registry, $repository, $logger)->performBackup($configuration);
    }

    public function testCompleteBackupSilentSkipWhenLatestFailedTodayForDaily(): void
    {
        $configuration = new BackupConfiguration()
            ->setName('cfg')
            ->setSlug('cfg')
            ->setPeriodicity(BackupConfiguration::PERIODICITY_DAILY);

        $latest = new Backup()
            ->setBackupConfiguration($configuration)
            ->setCurrentPlace('failed')
            ->setCreatedAt(new \DateTime());

        $repository = $this->createMock(BackupRepository::class);
        $repository->method('findOneBy')->willReturn($latest);

        $registry = $this->createMock(Registry::class);
        $registry->expects($this->never())->method('get');

        $logger = $this->createMock(BackupLogger::class);
        $logger->expects($this->never())->method('log');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        new BackupService($em, $registry, $repository, $logger)->completeBackup($configuration);
    }

    public function testPerformBackupThrowsWhenNoBackupFound(): void
    {
        $configuration = new BackupConfiguration()->setName('missing');

        $repository = $this->createMock(BackupRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $service = new BackupService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(Registry::class),
            $repository,
            $this->createMock(BackupLogger::class),
        );

        $this->expectException(Exception::class);
        $service->performBackup($configuration);
    }

    public function testCompleteBackupAppliesEachTransitionInOrder(): void
    {
        $configuration = new BackupConfiguration()->setName('cfg')->setSlug('cfg');
        $backup = new Backup()
            ->setBackupConfiguration($configuration)
            ->setCreatedAt(new \DateTime());

        $repository = $this->createMock(BackupRepository::class);
        $repository->method('findOneBy')->willReturn($backup);

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->method('can')->willReturn(true);

        $applied = [];
        $workflow->method('apply')->willReturnCallback(static function (Backup $b, string $transition) use (&$applied): Marking {
            $applied[] = $transition;

            return new Marking([$transition => 1]);
        });

        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturn($workflow);

        new BackupService(
            $this->createMock(EntityManagerInterface::class),
            $registry,
            $repository,
            $this->createMock(BackupLogger::class),
        )->completeBackup($configuration);

        self::assertSame(['health_check', 'forget', 'backuped'], $applied);
    }

    public function testPerformBackupFailsWorkflowOnException(): void
    {
        $configuration = new BackupConfiguration()->setName('cfg')->setSlug('cfg');
        $backup = new Backup()
            ->setBackupConfiguration($configuration)
            ->setCreatedAt(new \DateTime());

        $repository = $this->createMock(BackupRepository::class);
        $repository->method('findOneBy')->willReturn($backup);

        $applied = [];
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->method('can')->willReturn(true);
        $workflow->method('apply')->willReturnCallback(static function (Backup $b, string $transition) use (&$applied): Marking {
            $applied[] = $transition;
            if ('upload' === $transition) {
                throw new Exception('upload exploded');
            }

            return new Marking([$transition => 1]);
        });

        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturn($workflow);

        new BackupService(
            $this->createMock(EntityManagerInterface::class),
            $registry,
            $repository,
            $this->createMock(BackupLogger::class),
        )->performBackup($configuration);

        self::assertContains('failed', $applied, 'workflow must transition to failed on exception');
    }
}
