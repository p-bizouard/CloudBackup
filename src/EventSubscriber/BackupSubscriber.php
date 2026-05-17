<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Backup\Logging\BackupLogger;
use App\Backup\Source\BackupSourceInterface;
use App\Backup\Source\BackupSourceRegistry;
use App\Backup\Storage\StorageBackendRegistry;
use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\Log;
use App\Repository\BackupRepository;
use App\Service\MailerService;
use Exception;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\Event;
use Symfony\Component\Workflow\Event\GuardEvent;
use Throwable;

final class BackupSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly BackupSourceRegistry $sourceRegistry,
        private readonly StorageBackendRegistry $storageRegistry,
        private readonly BackupLogger $backupLogger,
        private readonly MailerService $mailerService,
        private readonly BackupRepository $backupRepository,
    ) {
    }

    public function onStart(Event $event): void
    {
        $backup = $this->extractBackup($event);
        $storage = $backup->getBackupConfiguration()->getStorage();

        if ($storage->isRestic()) {
            $this->storageRegistry->forStorage($storage)->initRepository($backup);

            return;
        }

        if ($storage->isRclone()) {
            return;
        }

        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('%s : Nothing to do', $backup->getCurrentPlace()));
    }

    public function onDump(Event $event): void
    {
        $backup = $this->extractBackup($event);
        $this->sourceFor($backup)->onDump($backup);
    }

    public function onDownload(Event $event): void
    {
        $backup = $this->extractBackup($event);
        $this->sourceFor($backup)->download($backup);
    }

    public function onUpload(Event $event): void
    {
        $backup = $this->extractBackup($event);
        $this->sourceFor($backup)->upload($backup);
    }

    public function onCleanup(Event $event): void
    {
        $backup = $this->extractBackup($event);
        $this->sourceFor($backup)->cleanupLocal($backup);
    }

    public function onHealthCheck(Event $event): void
    {
        $backup = $this->extractBackup($event);
        $this->storageRegistry->forStorage($backup->getBackupConfiguration()->getStorage())
            ->healthCheck($backup, true);
    }

    public function onForget(Event $event): void
    {
        $backup = $this->extractBackup($event);
        $configuration = $backup->getBackupConfiguration();
        $storage = $configuration->getStorage();

        if ($storage->isRestic() && BackupConfiguration::TYPE_READ_RESTIC !== $configuration->getType()) {
            $this->storageRegistry->forStorage($storage)->prune($backup);
        }

        if ($storage->isRclone()) {
            $this->storageRegistry->forStorage($storage)->prune($backup);
        }
    }

    public function onFailed(Event $event): void
    {
        $backup = $this->extractBackup($event);
        $this->backupLogger->log($backup, Log::LOG_ERROR, 'Backup failed');

        $notifyEvery = $backup->getBackupConfiguration()->getNotifyEvery();
        if ($notifyEvery <= 0) {
            return;
        }

        $countFailedBackupsSinceLastSuccess = $this->backupRepository->countFailedBackupsSinceLastSuccess($backup);
        if (0 === ($countFailedBackupsSinceLastSuccess + 1) % $notifyEvery) {
            $this->mailerService->sendFailedBackupReport($backup);
        }
    }

    public function onEnterAll(Event $event): void
    {
        $backup = $this->extractBackup($event);
        $this->backupLogger->log(
            $backup,
            Log::LOG_INFO,
            \sprintf(
                'Transition %s from %s to %s',
                $backup->getBackupConfiguration()->getName(),
                $backup->getCurrentPlace(),
                $event->getTransition()->getName(),
            ),
        );
    }

    public function guardStart(GuardEvent $guardEvent): void
    {
        $backup = $this->extractBackup($guardEvent);
        $notBefore = $backup->getBackupConfiguration()->getNotBefore();

        if (null !== $notBefore && $notBefore > (int) date('H')) {
            $message = \sprintf('Cannot start backup before %s', $notBefore);
            $this->backupLogger->log($backup, Log::LOG_NOTICE, $message);
            $guardEvent->setBlocked(true, $message);
        }
    }

    public function guardDownload(GuardEvent $guardEvent): void
    {
        $backup = $this->extractBackup($guardEvent);
        $source = $this->sourceFor($backup);

        try {
            if (BackupConfiguration::TYPE_OS_INSTANCE !== $backup->getBackupConfiguration()->getType()) {
                return;
            }

            $status = $source->getSnapshotStatus($backup);
            if (null === $status) {
                $message = 'Snapshot not found';
                $guardEvent->setBlocked(true, $message);
                $this->backupLogger->log($backup, Log::LOG_WARNING, $message);

                return;
            }

            if ('active' !== $status) {
                $message = \sprintf('Snapshot not ready : %s', $status);
                $guardEvent->setBlocked(true, $message);
                $this->backupLogger->log($backup, Log::LOG_NOTICE, $message);
            }
        } catch (Exception $e) {
            $this->backupLogger->log($backup, Log::LOG_WARNING, \sprintf('Guard download error : %s', $e->getMessage()));
            $guardEvent->setBlocked(true, $e->getMessage());
        }
    }

    public function guardUpload(GuardEvent $guardEvent): void
    {
        $backup = $this->extractBackup($guardEvent);
        $source = $this->sourceFor($backup);

        try {
            if (!$source->isDownloadComplete($backup)) {
                $message = 'Download not completed';
                $guardEvent->setBlocked(true, $message);
                $this->backupLogger->log($backup, Log::LOG_WARNING, $message);
            }
        } catch (Exception $e) {
            $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Guard upload error : %s', $e->getMessage()));
            $guardEvent->setBlocked(true, $e->getMessage());
        }
    }

    public function guardCleanup(GuardEvent $guardEvent): void
    {
        // No precondition.
    }

    public function guardHealthCheck(GuardEvent $guardEvent): void
    {
        $backup = $this->extractBackup($guardEvent);
        $source = $this->sourceFor($backup);

        try {
            $isCleaned = $source->isLocallyCleaned($backup);
        } catch (Throwable $e) {
            $this->backupLogger->log($backup, Log::LOG_WARNING, \sprintf('Guard health check error : %s', $e->getMessage()));
            $guardEvent->setBlocked(true, $e->getMessage());

            return;
        }

        if (!$isCleaned) {
            $message = 'Temporary backup still exists';
            $guardEvent->setBlocked(true, $message);
            $this->backupLogger->log($backup, Log::LOG_ERROR, $message);

            try {
                $source->cleanupLocal($backup);
            } catch (Throwable $cleanupError) {
                $this->backupLogger->log($backup, Log::LOG_WARNING, \sprintf('Retry cleanup failed: %s', $cleanupError->getMessage()));
            }
        }
    }

    public function guardForget(GuardEvent $guardEvent): void
    {
        $backup = $this->extractBackup($guardEvent);
        $storage = $backup->getBackupConfiguration()->getStorage();

        if ($storage->isRestic() && (null === $backup->getResticSize() || 0 === $backup->getResticSize())) {
            $this->retryHealthCheck($backup, $guardEvent, 'Restic size not set from health check. Retry health check');

            return;
        }

        if ($storage->isRclone() && (null === $backup->getSize() || 0 === $backup->getSize())) {
            $this->retryHealthCheck($backup, $guardEvent, 'Rclone size not set from health check. Retry health check');
        }
    }

    private function retryHealthCheck(Backup $backup, GuardEvent $guardEvent, string $message): void
    {
        $guardEvent->setBlocked(true, $message);
        $this->backupLogger->log($backup, Log::LOG_ERROR, $message);

        try {
            $this->storageRegistry->forStorage($backup->getBackupConfiguration()->getStorage())
                ->healthCheck($backup, true);
        } catch (Throwable $retryError) {
            $this->backupLogger->log($backup, Log::LOG_WARNING, \sprintf('Retry health check failed: %s', $retryError->getMessage()));
        }
    }

    public function onGuardAll(GuardEvent $guardEvent): void
    {
        $backup = $this->extractBackup($guardEvent);
        $this->backupLogger->log(
            $backup,
            Log::LOG_INFO,
            \sprintf('GuardAll from %s to %s', $backup->getCurrentPlace(), $guardEvent->getTransition()->getName()),
        );
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.backup.enter.start' => 'onStart',
            'workflow.backup.enter.dump' => 'onDump',
            'workflow.backup.enter.download' => 'onDownload',
            'workflow.backup.enter.upload' => 'onUpload',
            'workflow.backup.enter.cleanup' => 'onCleanup',
            'workflow.backup.enter.health_check' => 'onHealthCheck',
            'workflow.backup.enter.forget' => 'onForget',
            'workflow.backup.enter.failed' => 'onFailed',

            'workflow.backup.enter' => 'onEnterAll',

            'workflow.backup.guard.start' => 'guardStart',
            'workflow.backup.guard.download' => 'guardDownload',
            'workflow.backup.guard.upload' => 'guardUpload',
            'workflow.backup.guard.cleanup' => 'guardCleanup',
            'workflow.backup.guard.health_check' => 'guardHealthCheck',
            'workflow.backup.guard.forget' => 'guardForget',

            'workflow.backup.guard' => 'onGuardAll',
        ];
    }

    private function extractBackup(Event $event): Backup
    {
        $subject = $event->getSubject();
        \assert($subject instanceof Backup);

        return $subject;
    }

    private function sourceFor(Backup $backup): BackupSourceInterface
    {
        return $this->sourceRegistry->forConfiguration($backup->getBackupConfiguration());
    }
}
