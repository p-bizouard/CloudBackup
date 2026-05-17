<?php

declare(strict_types=1);

namespace App\Service;

use App\Backup\Logging\BackupLogger;
use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\Log;
use App\Repository\BackupRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\WorkflowInterface;

final class BackupService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Registry $workflowRegistry,
        private readonly BackupRepository $backupRepository,
        private readonly BackupLogger $backupLogger,
    ) {
    }

    public function initBackup(BackupConfiguration $backupConfiguration): void
    {
        $now = new DateTime();
        $backup = $this->findOrCreateBackup($backupConfiguration);

        if ($this->isTerminalForToday($backup, $backupConfiguration, $now)) {
            return;
        }

        $workflow = $this->workflowRegistry->get($backup);

        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('call %s::%s. CurrentState : %s', self::class, __FUNCTION__, $backup->getCurrentPlace()));

        if ('backuped' === $backup->getCurrentPlace()) {
            $backup = new Backup();
            $backup->setBackupConfiguration($backupConfiguration);
        } elseif ('initialized' !== $backup->getCurrentPlace()) {
            $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('Resume backup with current state %s', $backup->getCurrentPlace()));

            $isStale = $backup->getCreatedAt()->format('Y-m-d') !== $now->format('Y-m-d')
                && BackupConfiguration::PERIODICITY_DAILY === $backupConfiguration->getPeriodicity();

            if ($isStale) {
                $this->backupLogger->log($backup, Log::LOG_NOTICE, 'Backup is not from today, force fail it');
                if ('failed' !== $backup->getCurrentPlace()) {
                    $workflow->apply($backup, 'failed');
                    $this->entityManager->persist($backup);
                    $this->entityManager->flush();
                }

                $backup = new Backup();
                $backup->setBackupConfiguration($backupConfiguration);
            }
        }

        try {
            if ($workflow->can($backup, 'start')) {
                $workflow->apply($backup, 'start');
            }

            if ($workflow->can($backup, 'upload')) {
                $workflow->apply($backup, 'upload');
            } elseif ($workflow->can($backup, 'dump')) {
                $workflow->apply($backup, 'dump');
            }
        } catch (Exception $e) {
            $this->failWorkflow($backup, $workflow, $e);
        }

        $this->entityManager->persist($backup);
        $this->entityManager->flush();
    }

    public function performBackup(BackupConfiguration $backupConfiguration): void
    {
        $backup = $this->findLatestBackupOrFail($backupConfiguration);

        if ($this->isTerminalForToday($backup, $backupConfiguration, new DateTime())) {
            return;
        }

        $workflow = $this->workflowRegistry->get($backup);

        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('call %s::%s. CurrentState : %s', self::class, __FUNCTION__, $backup->getCurrentPlace()));

        try {
            foreach (['download', 'upload', 'cleanup'] as $transition) {
                if ($workflow->can($backup, $transition)) {
                    $workflow->apply($backup, $transition);
                }
            }
        } catch (Exception $e) {
            $this->failWorkflow($backup, $workflow, $e);
        }

        $this->entityManager->persist($backup);
        $this->entityManager->flush();
    }

    public function completeBackup(BackupConfiguration $backupConfiguration): void
    {
        $backup = $this->findLatestBackupOrFail($backupConfiguration);

        if ($this->isTerminalForToday($backup, $backupConfiguration, new DateTime())) {
            return;
        }

        $workflow = $this->workflowRegistry->get($backup);

        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('call %s::%s. CurrentState : %s', self::class, __FUNCTION__, $backup->getCurrentPlace()));

        try {
            foreach (['health_check', 'forget', 'backuped'] as $transition) {
                if ($workflow->can($backup, $transition)) {
                    $workflow->apply($backup, $transition);
                }
            }
        } catch (Exception $e) {
            $this->failWorkflow($backup, $workflow, $e);
        }

        $this->entityManager->persist($backup);
        $this->entityManager->flush();
    }

    /**
     * A backup in a terminal place (failed/backuped) for today's daily run is "done":
     * the hourly poller should skip it silently rather than re-entering the workflow
     * and emitting a NOTICE log line each pass.
     */
    private function isTerminalForToday(Backup $backup, BackupConfiguration $backupConfiguration, DateTime $now): bool
    {
        if (BackupConfiguration::PERIODICITY_DAILY !== $backupConfiguration->getPeriodicity()) {
            return false;
        }

        if (!\in_array($backup->getCurrentPlace(), ['failed', 'backuped'], true)) {
            return false;
        }

        return $backup->getCreatedAt()?->format('Y-m-d') === $now->format('Y-m-d');
    }

    public function applyWorkflow(Backup $backup, string $transition): void
    {
        $workflow = $this->workflowRegistry->get($backup);
        $workflow->apply($backup, $transition);

        $this->entityManager->persist($backup);
        $this->entityManager->flush();
    }

    private function findOrCreateBackup(BackupConfiguration $backupConfiguration): Backup
    {
        $backup = $this->backupRepository->findOneBy(
            ['backupConfiguration' => $backupConfiguration],
            ['id' => 'DESC'],
        );

        if (null === $backup) {
            $backup = new Backup();
            $backup->setBackupConfiguration($backupConfiguration);
        }

        return $backup;
    }

    private function findLatestBackupOrFail(BackupConfiguration $backupConfiguration): Backup
    {
        $backup = $this->backupRepository->findOneBy(
            ['backupConfiguration' => $backupConfiguration],
            ['id' => 'DESC'],
        );

        if (null === $backup) {
            throw new Exception(\sprintf('No backup found: %s', $backupConfiguration->getName()));
        }

        return $backup;
    }

    private function failWorkflow(Backup $backup, WorkflowInterface $workflow, Exception $exception): void
    {
        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('An error occured : %s', $exception->getMessage()));

        if ($workflow->can($backup, 'failed')) {
            $workflow->apply($backup, 'failed');
        }
    }
}
