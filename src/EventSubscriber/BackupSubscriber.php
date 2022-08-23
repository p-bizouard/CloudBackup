<?php

namespace App\EventSubscriber;

use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\Log;
use App\Service\BackupService;
use App\Service\MailerService;
use Exception;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\Event;
use Symfony\Component\Workflow\Event\GuardEvent;

class BackupSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private BackupService $backupService,
        private MailerService $mailerService,
    ) {
    }

    public function onStart(Event $event): void
    {
        /** @var Backup */
        $backup = $event->getSubject();

        $this->backupService->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        if ($backup->getBackupConfiguration()->getStorage()->isRestic()) {
            $this->backupService->resticInitRepo($backup);
        }
    }

    public function onDump(Event $event): void
    {
        /** @var Backup */
        $backup = $event->getSubject();

        $this->backupService->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        switch ($backup->getBackupConfiguration()->getType()) {
            case BackupConfiguration::TYPE_OS_INSTANCE:
                $this->backupService->snapshotOSInstance($backup);
                break;
            default:
                break;
        }
    }

    public function onDownload(Event $event): void
    {
        /** @var Backup */
        $backup = $event->getSubject();

        $this->backupService->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $this->backupService->downloadBackup($backup);
    }

    public function onUpload(Event $event): void
    {
        /** @var Backup */
        $backup = $event->getSubject();

        $this->backupService->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $this->backupService->uploadBackup($backup);
    }

    public function onCleanup(Event $event): void
    {
        /** @var Backup */
        $backup = $event->getSubject();

        $this->backupService->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $this->backupService->cleanBackup($backup);
    }

    public function onHealthCheck(Event $event): void
    {
        /** @var Backup */
        $backup = $event->getSubject();

        $this->backupService->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $this->backupService->healhCheckBackup($backup);
    }

    public function onForget(Event $event): void
    {
        /** @var Backup */
        $backup = $event->getSubject();

        $this->backupService->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        // Restic forget
        if ($backup->getBackupConfiguration()->getStorage()->isRestic() && BackupConfiguration::TYPE_READ_RESTIC !== $backup->getBackupConfiguration()->getType()) {
            $this->backupService->cleanBackupRestic($backup);
        }
    }

    public function onFailed(Event $event): void
    {
        /** @var Backup */
        $backup = $event->getSubject();

        $this->backupService->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $this->backupService->log($backup, Log::LOG_ERROR, 'Backup failed');

        $this->mailerService->sendFailedBackupReport($backup);
    }

    public function onEnterAll(Event $event): void
    {
        /** @var Backup */
        $backup = $event->getSubject();

        $this->backupService->log($backup, Log::LOG_NOTICE, sprintf('Transition from %s to %s', $backup->getCurrentPlace(), $event->getTransition()->getName()));
    }

    public function guardStart(GuardEvent $event): void
    {
        /** @var Backup */
        $backup = $event->getSubject();

        if ($backup->getBackupConfiguration()->getNotBefore() > date('H')) {
            $message = sprintf('Cannot start backup before %s', $backup->getBackupConfiguration()->getNotBefore());

            $this->backupService->log(
                $backup,
                Log::LOG_NOTICE,
                $message
            );
            $event->setBlocked(true, $message);
        }
    }

    public function guardDownload(GuardEvent $event): void
    {
        /** @var Backup */
        $backup = $event->getSubject();

        try {
            switch ($backup->getBackupConfiguration()->getType()) {
                case BackupConfiguration::TYPE_OS_INSTANCE:
                    $status = $this->backupService->getSnapshotOsInstanceStatus($backup);
                    if (null === $status) {
                        $message = 'Snaphot not found';

                        $event->setBlocked(true, $message);
                        $this->backupService->log($backup, Log::LOG_WARNING, $message);
                    } elseif ('active' !== $status) {
                        $message = sprintf('Snapshot not ready : %s', $status);

                        $event->setBlocked(true, $message);
                        $this->backupService->log($backup, Log::LOG_NOTICE, $message);
                    }
                    break;
                default:
                    break;
            }
        } catch (Exception $e) {
            $this->backupService->log($backup, Log::LOG_WARNING, sprintf('Guard download error : %s', $e->getMessage()));

            $event->setBlocked(true, $e->getMessage());
        }
    }

    public function guardUpload(GuardEvent $event): void
    {
        /** @var Backup */
        $backup = $event->getSubject();

        try {
            switch ($backup->getBackupConfiguration()->getType()) {
                case BackupConfiguration::TYPE_OS_INSTANCE:
                    if (!$this->backupService->checkDownloadedOSSnapshot($backup)) {
                        $message = 'Download not completed';

                        $event->setBlocked(true, $message);
                        $this->backupService->log($backup, Log::LOG_WARNING, $message);
                    }
                    break;
                case BackupConfiguration::TYPE_SSHFS:
                    if (!$this->backupService->checkDownloadedSSHFS($backup)) {
                        $message = 'Download not completed';

                        $event->setBlocked(true, $message);
                        $this->backupService->log($backup, Log::LOG_WARNING, $message);
                    }
                    break;
                case BackupConfiguration::TYPE_MYSQL:
                case BackupConfiguration::TYPE_POSTGRESQL:
                case BackupConfiguration::TYPE_SQL_SERVER:
                case BackupConfiguration::TYPE_SSH_CMD:
                case BackupConfiguration::TYPE_SFTP:
                    if (!$this->backupService->checkDownloadedDump($backup)) {
                        $message = 'Download not completed';

                        $event->setBlocked(true, $message);
                        $this->backupService->log($backup, Log::LOG_WARNING, $message);
                    }
                    break;
                default:
                    break;
            }
        } catch (Exception $e) {
            $this->backupService->log($backup, Log::LOG_ERROR, sprintf('Guard upload error : %s', $e->getMessage()));

            $event->setBlocked(true, $e->getMessage());
        }
    }

    public function guardCleanup(GuardEvent $event): void
    {
        /** @var Backup */
        $backup = $event->getSubject();

        /*
         * @TODO ?
         */
    }

    public function guardHealhCheck(GuardEvent $event): void
    {
        /** @var Backup */
        $backup = $event->getSubject();

        if (!$this->backupService->isBackupCleaned($backup)) {
            $message = 'Temporary backup still exists';

            $event->setBlocked(true, $message);
            $this->backupService->log($backup, Log::LOG_ERROR, $message);

            // We retry the cleanup
            $this->backupService->cleanBackup($backup);
        }
    }

    public function guardForget(GuardEvent $event): void
    {
        /** @var Backup */
        $backup = $event->getSubject();

        if (null === $backup->getResticSize() || 0 === $backup->getResticSize()) {
            $message = 'Restic size not set from health check';

            $event->setBlocked(true, $message);
            $this->backupService->log($backup, Log::LOG_ERROR, $message);

            // We retry the health check
            $this->backupService->healhCheckBackup($backup);
        }
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
            'workflow.backup.guard.health_check' => 'guardHealhCheck',
            'workflow.backup.guard.forget' => 'guardForget',
        ];
    }
}
