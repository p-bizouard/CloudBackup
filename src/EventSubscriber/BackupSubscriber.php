<?php

namespace App\EventSubscriber;

use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\Log;
use App\Service\BackupService;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\Event;
use Symfony\Component\Workflow\Event\GuardEvent;

class BackupSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private BackupService $backupService,
    ) {
    }

    public function onStart(Event $event)
    {
        /** @var Backup */
        $backup = $event->getSubject();

        $this->backupService->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        if ($backup->getBackupConfiguration()->getStorage()->isRestic()) {
            try {
                $this->backupService->resticInitRepo($backup);
                throw new Exception();
            } catch (Exception $e) {
                $event->getWorkflow()->apply($backup, 'failed');
            }
        }
    }

    public function onDump(Event $event)
    {
        /** @var Backup */
        $backup = $event->getSubject();

        $this->backupService->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        try {
            switch ($backup->getBackupConfiguration()->getType()) {
                case BackupConfiguration::TYPE_OS_INSTANCE:
                        $this->backupService->snapshotOSInstance($backup);
                    break;
                case BackupConfiguration::TYPE_MYSQL:
                    break;
                default:
                    throw new Exception(sprintf('Backup configuration type not found : %s', $backup->getBackupConfiguration()->getType()));
                    break;
            }
        } catch (Exception $e) {
            $event->getWorkflow()->apply($backup, 'failed');
            throw $e;
        }
    }

    public function onDownload(Event $event)
    {
        /** @var Backup */
        $backup = $event->getSubject();

        $this->backupService->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        try {
            switch ($backup->getBackupConfiguration()->getType()) {
                case BackupConfiguration::TYPE_OS_INSTANCE:
                    $this->backupService->downloadOSSnapshot($backup);
                    break;
                case BackupConfiguration::TYPE_MYSQL:
                    case BackupConfiguration::TYPE_OS_INSTANCE:
                        $this->backupService->downloadCommandResult($backup);
                    break;
                default:
                    throw new Exception(sprintf('Backup configuration type not found : %s', $backup->getBackupConfiguration()->getType()));
                    break;
            }
        } catch (Exception $e) {
            $event->getWorkflow()->apply($backup, 'failed');
            throw $e;
        }
    }

    public function onUpload(Event $event)
    {
        /** @var Backup */
        $backup = $event->getSubject();

        $this->backupService->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        try {
            switch ($backup->getBackupConfiguration()->getType()) {
                case BackupConfiguration::TYPE_OS_INSTANCE:
                    $this->backupService->uploadBackup($backup);
                    break;
                case BackupConfiguration::TYPE_MYSQL:
                    $this->backupService->uploadBackup($backup);
                    break;
                default:
                    throw new Exception(sprintf('Backup configuration type not found : %s', $backup->getBackupConfiguration()->getType()));
                    break;
            }
        } catch (Exception $e) {
            $event->getWorkflow()->apply($backup, 'failed');
            throw $e;
        }
    }

    public function onCleanup(Event $event)
    {
        /** @var Backup */
        $backup = $event->getSubject();

        $this->backupService->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        try {
            $this->backupService->cleanBackup($backup);
        } catch (Exception $e) {
            $event->getWorkflow()->apply($backup, 'failed');
            throw $e;
        }
    }

    public function onFailed(Event $event)
    {
        /** @var Backup */
        $backup = $event->getSubject();

        $this->backupService->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $this->backupService->log($backup, Log::LOG_ERROR, 'Backup failed');
    }

    public function onEnterAll(Event $event)
    {
        /** @var Backup */
        $backup = $event->getSubject();

        $this->backupService->log($backup, Log::LOG_NOTICE, sprintf('Transition from %s to %s', $backup->getCurrentPlace(), $event->getTransition()->getName()));
    }

    public function guardDownload(GuardEvent $event)
    {
        /** @var Backup */
        $backup = $event->getSubject();

        $this->backupService->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        try {
            switch ($backup->getBackupConfiguration()->getType()) {
                case BackupConfiguration::TYPE_OS_INSTANCE:
                    $status = $this->backupService->getSnapshotOsInstanceStatus($backup);
                    if (null === $status) {
                        $message = 'Snaphot not found';

                        $event->setBlocked(true, $message);
                        $this->backupService->log($backup, Log::LOG_WARNING, $message, $status);
                    } elseif ('active' !== $status) {
                        $message = sprintf('Snapshot not ready : %s', $status);

                        $event->setBlocked(true, $message);
                        $this->backupService->log($backup, Log::LOG_NOTICE, $message);
                    }
                    break;
                case BackupConfiguration::TYPE_MYSQL:
                    break;
                default:
                    throw new Exception(sprintf('Backup configuration type not found : %s', $backup->getBackupConfiguration()->getType()));
                    break;
            }
        } catch (Exception $e) {
            $this->backupService->log($backup, Log::LOG_ERROR, $e->getMessage());

            $event->setBlocked(true, $e->getMessage());
            $event->getWorkflow()->apply($backup, 'failed');
            throw $e;
        }
    }

    public function guardUpload(GuardEvent $event)
    {
        /** @var Backup */
        $backup = $event->getSubject();

        $this->backupService->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        try {
            switch ($backup->getBackupConfiguration()->getType()) {
                case BackupConfiguration::TYPE_OS_INSTANCE:
                    if (!$this->backupService->checkDownloadedOSSnapshot($backup)) {
                        $message = 'Download not completed';

                        $event->setBlocked(true, $message);
                        $this->backupService->log($backup, Log::LOG_ERROR, $message);
                    }
                    break;
                case BackupConfiguration::TYPE_MYSQL:
                    break;
                default:
                    throw new Exception(sprintf('Backup configuration type not found : %s', $backup->getBackupConfiguration()->getType()));
                    break;
            }
        } catch (Exception $e) {
            $message = 'Cannot upload, download not completed';

            $event->setBlocked(true, $message);
            $this->backupService->log($backup, Log::LOG_ERROR, $message);
            throw $e;
        }
    }

    public function guardCleanup(GuardEvent $event)
    {
        /** @var Backup */
        $backup = $event->getSubject();

        $this->backupService->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        /**
         * @TODO ?
         */
    }

    public function guardBackuped(GuardEvent $event)
    {
        /** @var Backup */
        $backup = $event->getSubject();

        $this->backupService->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        if (!$this->backupService->isBackupCleaned($backup)) {
            $message = 'Temporary backup still exists';

            $event->setBlocked(true, $message);
            $this->backupService->log($backup, Log::LOG_ERROR, $message);

            // We retry the cleanup
            $this->backupService->cleanBackup($backup);
        }
    }

    public static function getSubscribedEvents()
    {
        return [
            'workflow.backup.enter.start' => 'onStart',
            'workflow.backup.enter.dump' => 'onDump',
            'workflow.backup.enter.download' => 'onDownload',
            'workflow.backup.enter.upload' => 'onUpload',
            'workflow.backup.enter.cleanup' => 'onCleanup',
            'workflow.backup.enter.failed' => 'onFailed',

            'workflow.backup.enter' => 'onEnterAll',

            'workflow.backup.guard.download' => 'guardDownload',
            'workflow.backup.guard.upload' => 'guardUpload',
            'workflow.backup.guard.cleanup' => 'guardCleanup',
            'workflow.backup.guard.backuped' => 'guardBackuped',
        ];
    }
}
