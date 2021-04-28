<?php

namespace App\Service;

use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\Log;
use App\Repository\BackupRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Workflow\Registry;

class BackupService
{
    const RESTIC_INIT_TIMEOUT = 60;
    const RESTIC_INIT_REGEX = '/Fatal\: create key in repository.*repository master key and config already initialized.*/';
    const RESTIC_UPLOAD_TIMEOUT = 3600 * 4;

    const OS_INSTANCE_SNAPSHOT_TIMEOUT = 60;
    const OS_IMAGE_LIST_TIMEOUT = 60;
    const OS_DOWNLOAD_TIMEOUT = 3600 * 4;

    public function __construct(
        private string $temporaryDownloadDirectory,
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
        private Registry $workflowRegistry,
        private BackupRepository $backupRepository,
    ) {
    }

    public function log(Backup $backup, string $level, string $message): void
    {
        switch ($level) {
            case Log::LOG_ERROR:
                $this->logger->error($message);
                break;
            case Log::LOG_WARNING:
                $this->logger->warning($message);
                break;
            case Log::LOG_INFO:
                $this->logger->info($message);
                break;
            case Log::LOG_NOTICE:
                $this->logger->notice($message);
                break;
            default:
                throw new Exception('Log level not found');
        }

        $log = new Log();
        $log->setLevel($level);
        $log->setMessage($message);

        $backup->addLog($log);

        $this->entityManager->persist($log);
        $this->entityManager->persist($backup);
        $this->entityManager->flush();
    }

    public function snapshotOSInstance(Backup $backup)
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $status = $this->getSnapshotOsInstanceStatus($backup);
        if (null !== $status) {
            $this->log($backup, Log::LOG_NOTICE, sprintf('Snapshot already found with %s', $status));

            return;
        }

        $env = $backup->getBackupConfiguration()->getOsInstance()->getOSEnv();

        $command = sprintf('openstack server image create --name %s %s', $backup->getName(), $backup->getBackupConfiguration()->getOsInstance()->getId());

        $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
        $process = Process::fromShellCommandline($command, null, $env);
        $process->setTimeout(self::OS_INSTANCE_SNAPSHOT_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing backup - openstack server image create - %s', $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        }
    }

    public function getSnapshotOsInstanceStatus(Backup $backup): ?string
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $env = $backup->getBackupConfiguration()->getOsInstance()->getOSEnv();

        $command = sprintf('openstack image list --private --name %s --long -f json', $backup->getName());

        $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
        $process = Process::fromShellCommandline($command, null, $env);
        $process->setTimeout(self::OS_IMAGE_LIST_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing snapshot - openstack image list - %s', $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        }

        $output = json_decode($process->getOutput(), true);
        if (null === $output) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing snapshot - openstack image list - %s - %s', $process->getOutput(), $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        }

        if (!\count($output)) {
            return null;
        }

        return $output[0]['Status'];
    }

    public function downloadOSSnapshot(Backup $backup)
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $env = $backup->getBackupConfiguration()->getOsInstance()->getOSEnv();

        if (!$backup->getOsImageId()) {
            $command = sprintf('openstack image list --private --name %s --long -f json', $backup->getName());

            $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
            $process = Process::fromShellCommandline($command, null, $env);
            $process->setTimeout(self::OS_IMAGE_LIST_TIMEOUT);
            $process->run();

            if (!$process->isSuccessful()) {
                $this->log($backup, Log::LOG_ERROR, sprintf('Error executing download - openstack image list - %s', $process->getErrorOutput()));
                throw new ProcessFailedException($process);
            }

            $output = json_decode($process->getOutput(), true);
            if (null === $output) {
                $this->log($backup, Log::LOG_ERROR, sprintf('Error executing download - openstack image list - %s - %s', $process->getOutput(), $process->getErrorOutput()));
                throw new ProcessFailedException($process);
            }

            if (!\count($output)) {
                return null;
            }

            $backup->setOsImageId($output[0]['ID']);
            $backup->setChecksum($output[0]['Checksum']);
            $backup->setSize($output[0]['Size']);

            $this->entityManager->persist($backup);
            $this->entityManager->flush();
        }

        $imageDestination = $this->getTemporaryBackupDestination($backup);

        if (file_exists($imageDestination) && filesize($imageDestination) === $backup->getSize() && hash_file('md5', $imageDestination) === $backup->getChecksum()) {
            $this->log($backup, Log::LOG_NOTICE, 'Openstack image already downloaded');

            return;
        }

        $command = sprintf('openstack image save --file %s %s', $imageDestination, $backup->getOsImageId());

        $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
        $process = Process::fromShellCommandline($command, null, $env);
        $process->setTimeout(self::OS_DOWNLOAD_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing download - openstack image save - %s', $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        }

        if (hash_file('md5', $imageDestination) !== $backup->getChecksum()) {
            $message = 'Error executing download - md5 checksum failed';
            $this->log($backup, Log::LOG_ERROR, $message);
            throw new Exception($message);
        }

        $this->log($backup, Log::LOG_NOTICE, 'Openstack image downloaded');
    }

    public function downloadCommandResult(Backup $backup)
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $filesystem = new Filesystem();
        $privateKeypath = $filesystem->tempnam('/tmp', 'key_');
        $backupDestination = $this->getTemporaryBackupDestination($backup);
        $filesystem->appendToFile($privateKeypath, str_replace("\r", '', $backup->getBackupConfiguration()->getHost()->getPrivateKey()."\n"));

        $command = sprintf(
            'ssh %s@%s -o "StrictHostKeyChecking no" -i %s "%s | gzip -9" | gunzip > %s',
            $backup->getBackupConfiguration()->getHost()->getLogin(),
            $backup->getBackupConfiguration()->getHost()->getIp(),
            $privateKeypath,
            $backup->getBackupConfiguration()->getDumpCommand(),
            $backupDestination
        );

        $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(self::OS_DOWNLOAD_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing download - exec dump command - %s', $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        }

        $this->log($backup, Log::LOG_NOTICE, 'Dump done');
    }

    public function uploadBackup(Backup $backup)
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $env = $backup->getBackupConfiguration()->getStorage()->getOSEnv() + $backup->getBackupConfiguration()->getResticEnv();

        switch ($backup->getBackupConfiguration()->getType()) {
            case BackupConfiguration::TYPE_OS_INSTANCE:
                $command = sprintf(
                    'cat %s | restic backup --tag project=%s --tag instance=%s --tag configuration=%s --host cloudbackup --stdin --stdin-filename /%s.qcow2',
                    $this->getTemporaryBackupDestination($backup),
                    $backup->getBackupConfiguration()->getOsInstance()->getOSProject()->getSlug(),
                    $backup->getBackupConfiguration()->getOsInstance()->getSlug(),
                    $backup->getBackupConfiguration()->getSlug(),
                    $backup->getName(false)
                );
                break;
            case BackupConfiguration::TYPE_MYSQL:
                $command = sprintf(
                    'cat %s | restic backup --tag host=%s --tag configuration=%s --host cloudbackup --stdin --stdin-filename /%s.sql',
                    $this->getTemporaryBackupDestination($backup),
                    $backup->getBackupConfiguration()->getHost()->getSlug(),
                    $backup->getBackupConfiguration()->getSlug(),
                    $backup->getName(false)
                );
                break;
            default:
                throw new Exception(sprintf('Backup configuration type not found : %s', $backup->getBackupConfiguration()->getType()));
                break;
        }

        $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
        $process = Process::fromShellCommandline($command, null, $env);
        $process->setTimeout(self::RESTIC_UPLOAD_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing backup - restic upload - %s', $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        }
    }

    public function cleanBackup(Backup $backup)
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        // Restic forget
        if ($backup->getBackupConfiguration()->getStorage()->isRestic()) {
            $env = $backup->getBackupConfiguration()->getStorage()->getOSEnv() + $backup->getBackupConfiguration()->getResticEnv();

            $command = sprintf(
                'restic forget --prune %s',
                $backup->getBackupConfiguration()->getResticForgetArgs()
            );

            $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
            $process = Process::fromShellCommandline($command, null, $env);
            $process->setTimeout(self::RESTIC_UPLOAD_TIMEOUT);
            $process->run();

            if (!$process->isSuccessful()) {
                $this->log($backup, Log::LOG_ERROR, sprintf('Error executing cleanup - restic forget - %s', $process->getErrorOutput()));
                throw new ProcessFailedException($process);
            }
        }

        // Remove local temporary file
        if (file_exists($this->getTemporaryBackupDestination($backup))) {
            $this->log($backup, Log::LOG_NOTICE, sprintf('Error executing cleanup - remove local file - %s', $this->getTemporaryBackupDestination($backup)));
            unlink($this->getTemporaryBackupDestination($backup));
        }

        // Remove OS image
        if (BackupConfiguration::TYPE_OS_INSTANCE === $backup->getBackupConfiguration()->getType()) {
            if (null !== $this->getSnapshotOsInstanceStatus($backup)) {
                $command = sprintf('openstack image delete %s', $backup->getOsImageId());

                $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
                $process = Process::fromShellCommandline($command, null, $backup->getBackupConfiguration()->getOsInstance()->getOSEnv());
                $process->setTimeout(self::OS_IMAGE_LIST_TIMEOUT);
                $process->run();

                if (!$process->isSuccessful()) {
                    $this->log($backup, Log::LOG_ERROR, sprintf('Error executing cleanup - openstack image image - %s', $process->getErrorOutput()));
                    throw new ProcessFailedException($process);
                }
            }
        }
    }

    public function isBackupCleaned(Backup $backup): bool
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        switch ($backup->getBackupConfiguration()->getType()) {
            case BackupConfiguration::TYPE_OS_INSTANCE:
                if (null !== $this->getSnapshotOsInstanceStatus($backup)) {
                    return false;
                }

                if (file_exists($this->getTemporaryBackupDestination($backup))) {
                    return false;
                }
                break;
            case BackupConfiguration::TYPE_MYSQL:
                if (file_exists($this->getTemporaryBackupDestination($backup))) {
                    return false;
                }
                break;
            default:
                throw new Exception(sprintf('Backup configuration type not found : %s', $backup->getBackupConfiguration()->getType()));
                break;
        }

        return true;
    }

    public function checkDownloadedOSSnapshot(Backup $backup)
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $imageDestination = $this->getTemporaryBackupDestination($backup);

        if (file_exists($imageDestination) && filesize($imageDestination) === $backup->getSize()) {
            $this->log($backup, Log::LOG_NOTICE, 'Openstack image downloaded');

            return true;
        } else {
            $this->log($backup, Log::LOG_NOTICE, 'Openstack image not downloaded : %s != %s', filesize($imageDestination), $backup->getSize());

            return false;
        }
    }

    public function resticInitRepo(Backup $backup)
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $env = $backup->getBackupConfiguration()->getStorage()->getOSEnv() + $backup->getBackupConfiguration()->getResticEnv();

        $command = 'restic init';

        $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
        $process = Process::fromShellCommandline($command, null, $env);
        $process->setTimeout(self::RESTIC_INIT_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful() && !preg_match(self::RESTIC_INIT_REGEX, $process->getErrorOutput())) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing backup - restic init repo - %s', $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        }

        $command = 'restic init';

        $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
        $process = Process::fromShellCommandline($command, null, $env);
        $process->setTimeout(self::RESTIC_INIT_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful() && !preg_match(self::RESTIC_INIT_REGEX, $process->getErrorOutput())) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing backup - restic init repo - %s', $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        }
    }

    public function getTemporaryBackupDestination(Backup $backup): string
    {
        return sprintf('%s/%s.bkp', $this->temporaryDownloadDirectory, $backup->getName());
    }

    public function initBackup(BackupConfiguration $backupConfiguration)
    {
        $now = new DateTime();

        /** @var Backup */
        $backup = $this->backupRepository->findOneBy([
            'backupConfiguration' => $backupConfiguration,
        ], ['id' => 'DESC']);

        if (null === $backup) {
            $backup = new Backup();
            $backup->setBackupConfiguration($backupConfiguration);
        }

        $backupWorkflow = $this->workflowRegistry->get($backup);
        if ('backuped' === $backup->getCurrentPlace()) {
            if ($backup->getCreatedAt()->format('Y-m-d') === $now->format('Y-m-d') && BackupConfiguration::PERIODICITY_DAILY === $backupConfiguration->getPeriodicity()) {
                return;
            } else {
                $backup = new Backup();
                $backup->setBackupConfiguration($backupConfiguration);
            }
        } elseif ('initialized' !== $backup->getCurrentPlace()) {
            if ($backup->getCreatedAt()->format('Y-m-d') !== $now->format('Y-m-d') && BackupConfiguration::PERIODICITY_DAILY === $backupConfiguration->getPeriodicity()) {
                if ('failed' === $backup->getCurrentPlace()) {
                    $backupWorkflow->apply($backup, 'failed');
                }

                $backup = new Backup();
                $backup->setBackupConfiguration($backupConfiguration);
            } else {
                $this->log($backup, Log::LOG_INFO, sprintf('Resume backup with current state %s', $backup->getCurrentPlace()));
            }
        }

        try {
            $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

            if ($backupWorkflow->can($backup, 'start')) {
                $backupWorkflow->apply($backup, 'start');
            }

            // Some backup types can go through start to upload without dump and download
            if ($backupWorkflow->can($backup, 'upload')) {
                $backupWorkflow->apply($backup, 'upload');
            } elseif ($backupWorkflow->can($backup, 'dump')) {
                $backupWorkflow->apply($backup, 'dump');
            }
        } catch (\Exception $e) {
            $this->log($backup, Log::LOG_NOTICE, sprintf('General error : %s', $e->getMessage()));
        }

        $this->entityManager->persist($backup);
        $this->entityManager->flush();
    }

    public function completeBackup(BackupConfiguration $backupConfiguration)
    {
        $backup = $this->backupRepository->findOneBy([
            'backupConfiguration' => $backupConfiguration,
        ], ['id' => 'DESC']);

        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s. CurrentState : %s', __CLASS__, __FUNCTION__, $backup->getCurrentPlace()));

        try {
            $backupWorkflow = $this->workflowRegistry->get($backup);

            if ($backupWorkflow->can($backup, 'download')) {
                $backupWorkflow->apply($backup, 'download');
            }

            if ($backupWorkflow->can($backup, 'upload')) {
                $backupWorkflow->apply($backup, 'upload');
            }

            if ($backupWorkflow->can($backup, 'cleanup')) {
                $backupWorkflow->apply($backup, 'cleanup');
            }

            if ($backupWorkflow->can($backup, 'backuped')) {
                $backupWorkflow->apply($backup, 'backuped');
            }
        } catch (\Exception $e) {
            $this->log($backup, Log::LOG_NOTICE, sprintf('General error : %s', $e->getMessage()));
        }

        $this->entityManager->persist($backup);
        $this->entityManager->flush();
    }
}
