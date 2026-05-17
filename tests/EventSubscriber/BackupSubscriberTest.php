<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Backup\Logging\BackupLogger;
use App\Backup\Source\BackupSourceInterface;
use App\Backup\Source\BackupSourceRegistry;
use App\Backup\Storage\ResticStorageBackend;
use App\Backup\Storage\StorageBackendInterface;
use App\Backup\Storage\StorageBackendRegistry;
use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\Storage;
use App\EventSubscriber\BackupSubscriber;
use App\Repository\BackupRepository;
use App\Service\MailerService;
use Exception;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Event\Event;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

final class BackupSubscriberTest extends TestCase
{
    public function testOnStartInitialisesResticRepository(): void
    {
        $backup = $this->buildBackup(Storage::TYPE_RESTIC, BackupConfiguration::TYPE_MYSQL);
        $event = $this->buildEvent($backup);

        $resticBackend = $this->createMock(ResticStorageBackend::class);
        $resticBackend->expects($this->once())->method('initRepository')->with($backup);

        $storageRegistry = $this->createMock(StorageBackendRegistry::class);
        $storageRegistry->method('forStorage')->willReturn($resticBackend);

        $this->buildSubscriber(storageRegistry: $storageRegistry)->onStart($event);
    }

    public function testOnStartIsNoopForRcloneStorage(): void
    {
        $backup = $this->buildBackup(Storage::TYPE_RCLONE, BackupConfiguration::TYPE_RCLONE);

        $storageRegistry = $this->createMock(StorageBackendRegistry::class);
        $storageRegistry->expects($this->never())->method('forStorage');

        $this->buildSubscriber(storageRegistry: $storageRegistry)->onStart($this->buildEvent($backup));
    }

    public function testOnDownloadDelegatesToSource(): void
    {
        $backup = $this->buildBackup(Storage::TYPE_RESTIC, BackupConfiguration::TYPE_MYSQL);

        $source = $this->createMock(BackupSourceInterface::class);
        $source->expects($this->once())->method('download')->with($backup);

        $sourceRegistry = $this->createMock(BackupSourceRegistry::class);
        $sourceRegistry->method('forConfiguration')->willReturn($source);

        $this->buildSubscriber(sourceRegistry: $sourceRegistry)->onDownload($this->buildEvent($backup));
    }

    public function testOnUploadDelegatesToSource(): void
    {
        $backup = $this->buildBackup(Storage::TYPE_RESTIC, BackupConfiguration::TYPE_MYSQL);

        $source = $this->createMock(BackupSourceInterface::class);
        $source->expects($this->once())->method('upload')->with($backup);

        $sourceRegistry = $this->createMock(BackupSourceRegistry::class);
        $sourceRegistry->method('forConfiguration')->willReturn($source);

        $this->buildSubscriber(sourceRegistry: $sourceRegistry)->onUpload($this->buildEvent($backup));
    }

    public function testOnHealthCheckDelegatesToStorageBackend(): void
    {
        $backup = $this->buildBackup(Storage::TYPE_RESTIC, BackupConfiguration::TYPE_MYSQL);

        $backend = $this->createMock(StorageBackendInterface::class);
        $backend->expects($this->once())->method('healthCheck')->with($backup, true);

        $registry = $this->createMock(StorageBackendRegistry::class);
        $registry->method('forStorage')->willReturn($backend);

        $this->buildSubscriber(storageRegistry: $registry)->onHealthCheck($this->buildEvent($backup));
    }

    public function testOnForgetSkipsResticPruneForReadResticType(): void
    {
        $backup = $this->buildBackup(Storage::TYPE_RESTIC, BackupConfiguration::TYPE_READ_RESTIC);

        $registry = $this->createMock(StorageBackendRegistry::class);
        $registry->expects($this->never())->method('forStorage');

        $this->buildSubscriber(storageRegistry: $registry)->onForget($this->buildEvent($backup));
    }

    public function testOnForgetRunsResticPruneForResticStorage(): void
    {
        $backup = $this->buildBackup(Storage::TYPE_RESTIC, BackupConfiguration::TYPE_MYSQL);

        $backend = $this->createMock(StorageBackendInterface::class);
        $backend->expects($this->once())->method('prune')->with($backup);

        $registry = $this->createMock(StorageBackendRegistry::class);
        $registry->method('forStorage')->willReturn($backend);

        $this->buildSubscriber(storageRegistry: $registry)->onForget($this->buildEvent($backup));
    }

    public function testOnFailedSendsEmailWhenThresholdReached(): void
    {
        $backup = $this->buildBackup(Storage::TYPE_RESTIC, BackupConfiguration::TYPE_MYSQL);
        $backup->getBackupConfiguration()->setNotifyEvery(3);

        $repo = $this->createMock(BackupRepository::class);
        $repo->method('countFailedBackupsSinceLastSuccess')->willReturn(2);

        $mailer = $this->createMock(MailerService::class);
        $mailer->expects($this->once())->method('sendFailedBackupReport')->with($backup);

        $this->buildSubscriber(mailer: $mailer, repository: $repo)->onFailed($this->buildEvent($backup));
    }

    public function testOnFailedSilentWhenNotifyEveryIsZero(): void
    {
        $backup = $this->buildBackup(Storage::TYPE_RESTIC, BackupConfiguration::TYPE_MYSQL);
        $backup->getBackupConfiguration()->setNotifyEvery(0);

        $mailer = $this->createMock(MailerService::class);
        $mailer->expects($this->never())->method('sendFailedBackupReport');

        $this->buildSubscriber(mailer: $mailer)->onFailed($this->buildEvent($backup));
    }

    public function testGuardStartBlocksWhenBeforeAllowedHour(): void
    {
        $backup = $this->buildBackup(Storage::TYPE_RESTIC, BackupConfiguration::TYPE_MYSQL);
        $backup->getBackupConfiguration()->setNotBefore(99);

        $guard = $this->buildGuardEvent($backup);

        $this->buildSubscriber()->guardStart($guard);

        self::assertTrue($guard->isBlocked());
    }

    public function testGuardStartAllowsWhenHourPermits(): void
    {
        $backup = $this->buildBackup(Storage::TYPE_RESTIC, BackupConfiguration::TYPE_MYSQL);
        $backup->getBackupConfiguration()->setNotBefore(0);

        $guard = $this->buildGuardEvent($backup);

        $this->buildSubscriber()->guardStart($guard);

        self::assertFalse($guard->isBlocked());
    }

    public function testGuardDownloadBlocksWhenSnapshotNotFoundForOsInstance(): void
    {
        $backup = $this->buildBackup(Storage::TYPE_RESTIC, BackupConfiguration::TYPE_OS_INSTANCE);

        $source = $this->createMock(BackupSourceInterface::class);
        $source->method('getSnapshotStatus')->willReturn(null);

        $registry = $this->createMock(BackupSourceRegistry::class);
        $registry->method('forConfiguration')->willReturn($source);

        $guard = $this->buildGuardEvent($backup);
        $this->buildSubscriber(sourceRegistry: $registry)->guardDownload($guard);

        self::assertTrue($guard->isBlocked());
    }

    public function testGuardDownloadAllowsForNonOsInstance(): void
    {
        $backup = $this->buildBackup(Storage::TYPE_RESTIC, BackupConfiguration::TYPE_MYSQL);

        $source = $this->createMock(BackupSourceInterface::class);
        $source->expects($this->never())->method('getSnapshotStatus');

        $registry = $this->createMock(BackupSourceRegistry::class);
        $registry->method('forConfiguration')->willReturn($source);

        $guard = $this->buildGuardEvent($backup);
        $this->buildSubscriber(sourceRegistry: $registry)->guardDownload($guard);

        self::assertFalse($guard->isBlocked());
    }

    public function testGuardDownloadBlocksOnSourceException(): void
    {
        $backup = $this->buildBackup(Storage::TYPE_RESTIC, BackupConfiguration::TYPE_OS_INSTANCE);

        $source = $this->createMock(BackupSourceInterface::class);
        $source->method('getSnapshotStatus')->willThrowException(new Exception('openstack down'));

        $registry = $this->createMock(BackupSourceRegistry::class);
        $registry->method('forConfiguration')->willReturn($source);

        $guard = $this->buildGuardEvent($backup);
        $this->buildSubscriber(sourceRegistry: $registry)->guardDownload($guard);

        self::assertTrue($guard->isBlocked());
    }

    public function testGuardUploadBlocksWhenDownloadIncomplete(): void
    {
        $backup = $this->buildBackup(Storage::TYPE_RESTIC, BackupConfiguration::TYPE_MYSQL);

        $source = $this->createMock(BackupSourceInterface::class);
        $source->method('isDownloadComplete')->willReturn(false);

        $registry = $this->createMock(BackupSourceRegistry::class);
        $registry->method('forConfiguration')->willReturn($source);

        $guard = $this->buildGuardEvent($backup);
        $this->buildSubscriber(sourceRegistry: $registry)->guardUpload($guard);

        self::assertTrue($guard->isBlocked());
    }

    public function testGuardHealthCheckRetriesCleanupWhenNotCleaned(): void
    {
        $backup = $this->buildBackup(Storage::TYPE_RESTIC, BackupConfiguration::TYPE_MYSQL);

        $source = $this->createMock(BackupSourceInterface::class);
        $source->method('isLocallyCleaned')->willReturn(false);
        $source->expects($this->once())->method('cleanupLocal')->with($backup);

        $registry = $this->createMock(BackupSourceRegistry::class);
        $registry->method('forConfiguration')->willReturn($source);

        $guard = $this->buildGuardEvent($backup);
        $this->buildSubscriber(sourceRegistry: $registry)->guardHealthCheck($guard);

        self::assertTrue($guard->isBlocked());
    }

    public function testGuardForgetRetriesHealthCheckWhenResticSizeMissing(): void
    {
        $backup = $this->buildBackup(Storage::TYPE_RESTIC, BackupConfiguration::TYPE_MYSQL);
        $backup->setResticSize(null);

        $backend = $this->createMock(StorageBackendInterface::class);
        $backend->expects($this->once())->method('healthCheck')->with($backup, true);

        $registry = $this->createMock(StorageBackendRegistry::class);
        $registry->method('forStorage')->willReturn($backend);

        $guard = $this->buildGuardEvent($backup);
        $this->buildSubscriber(storageRegistry: $registry)->guardForget($guard);

        self::assertTrue($guard->isBlocked());
    }

    public function testSubscribedEventsContainsHealthCheckGuardWithCorrectedSpelling(): void
    {
        $events = BackupSubscriber::getSubscribedEvents();

        self::assertSame('guardHealthCheck', $events['workflow.backup.guard.health_check']);
        self::assertSame('onHealthCheck', $events['workflow.backup.enter.health_check']);
    }

    private function buildBackup(string $storageType, string $backupType): Backup
    {
        $storage = new Storage()->setType($storageType);
        $configuration = new BackupConfiguration()
            ->setName('cfg')
            ->setStorage($storage)
            ->setType($backupType);

        return new Backup()->setBackupConfiguration($configuration);
    }

    private function buildEvent(Backup $backup): Event
    {
        return new Event(
            $backup,
            new Marking([$backup->getCurrentPlace() => 1]),
            new Transition('upload', 'download', 'upload'),
            $this->createMock(WorkflowInterface::class),
        );
    }

    private function buildGuardEvent(Backup $backup): GuardEvent
    {
        return new GuardEvent(
            $backup,
            new Marking([$backup->getCurrentPlace() => 1]),
            new Transition('start', 'initialized', 'start'),
            $this->createMock(WorkflowInterface::class),
        );
    }

    private function buildSubscriber(
        ?BackupSourceRegistry $sourceRegistry = null,
        ?StorageBackendRegistry $storageRegistry = null,
        ?MailerService $mailer = null,
        ?BackupRepository $repository = null,
    ): BackupSubscriber {
        return new BackupSubscriber(
            $sourceRegistry ?? $this->createMock(BackupSourceRegistry::class),
            $storageRegistry ?? $this->createMock(StorageBackendRegistry::class),
            $this->createMock(BackupLogger::class),
            $mailer ?? $this->createMock(MailerService::class),
            $repository ?? $this->createMock(BackupRepository::class),
        );
    }
}
