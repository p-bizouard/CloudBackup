<?php

namespace App\Service;

use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\Log;
use App\Entity\Storage;
use App\Repository\BackupRepository;
use App\Utils\StringUtils;
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
    public const RESTIC_INIT_TIMEOUT = 60;
    public const RESTIC_INIT_REGEX = '/Fatal\: create key in repository.*repository master key and config already initialized|failed\: config file already exists/';
    public const RESTIC_UPLOAD_TIMEOUT = 3600 * 4;
    public const RESTIC_CHECK_TIMEOUT = 3600;

    public const OS_INSTANCE_SNAPSHOT_TIMEOUT = 60;
    public const OS_IMAGE_LIST_TIMEOUT = 60;
    public const OS_DOWNLOAD_TIMEOUT = 3600 * 4;

    public const SSHFS_MOUNT_TIMEOUT = 60;
    public const SSHFS_UMOUNT_TIMEOUT = 60;

    public const DOWNLOAD_SIZE_TIMEOUT = 60 * 10;

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

    public function snapshotOSInstance(Backup $backup): void
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
        } else {
            $this->log($backup, Log::LOG_INFO, $process->getOutput());
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
        } else {
            $this->log($backup, Log::LOG_INFO, $process->getOutput());
        }

        $output = json_decode($process->getOutput(), true);
        if (null === $output) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing snapshot - openstack image list - %s - %s', $process->getOutput(), $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        }

        if (!\count($output)) {
            return null;
        }

        if (null === $backup->getOsImageId() || null === $backup->getSize()) {
            $backup->setOsImageId($output[0]['ID']);
            $backup->setChecksum($output[0]['Checksum']);
            $backup->setSize($output[0]['Size']);
        }

        $this->entityManager->persist($backup);
        $this->entityManager->flush();

        return $output[0]['Status'];
    }

    private function getSftpDownloadSize(Backup $backup): int
    {
        $command = sprintf('du -sb %s | cut -f1', $this->getTemporaryBackupDestination($backup));

        $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(self::DOWNLOAD_SIZE_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error getting download size - %s', $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        } else {
            $this->log($backup, Log::LOG_INFO, $process->getOutput());
        }

        return (int) $process->getOutput();
    }

    private function downloadOSSnapshot(Backup $backup): void
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
            } else {
                $this->log($backup, Log::LOG_INFO, $process->getOutput());
            }

            $output = json_decode($process->getOutput(), true);
            if (null === $output) {
                $this->log($backup, Log::LOG_ERROR, sprintf('Error executing download - openstack image list - %s - %s', $process->getOutput(), $process->getErrorOutput()));
                throw new ProcessFailedException($process);
            }

            if (!\count($output)) {
                return;
            }

            $backup->setOsImageId($output[0]['ID']);
            $backup->setChecksum($output[0]['Checksum']);
            $backup->setSize($output[0]['Size']);

            $this->entityManager->persist($backup);
            $this->entityManager->flush();
        }

        $imageDestination = $this->getTemporaryBackupDestination($backup);

        if (file_exists($imageDestination) && filesize($imageDestination) === $backup->getSize()) {
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
            $this->cleanBackupOsInstance($backup);
            @unlink($imageDestination);
            throw new ProcessFailedException($process);
        } else {
            $this->log($backup, Log::LOG_INFO, $process->getOutput());
        }

        $this->log($backup, Log::LOG_NOTICE, 'Openstack image downloaded');
    }

    private function downloadCommandResult(Backup $backup): void
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $filesystem = new Filesystem();
        $backupDestination = $this->getTemporaryBackupDestination($backup);

        if (null !== $backup->getBackupConfiguration()->getHost()) {
            if (null !== $backup->getBackupConfiguration()->getHost()->getPrivateKey()) {
                $privateKeypath = $filesystem->tempnam('/tmp', 'key_');
                $filesystem->appendToFile($privateKeypath, str_replace("\r", '', $backup->getBackupConfiguration()->getHost()->getPrivateKey()."\n"));
                $privateKeyString = sprintf('-i %s', $privateKeypath);
            } else {
                $privateKeyString = '';
            }

            if (null !== $backup->getBackupConfiguration()->getHost()->getPassword()) {
                $sshpass = sprintf('sshpass -p %s', $backup->getBackupConfiguration()->getHost()->getPassword());
            } else {
                $sshpass = '';
            }

            $command = sprintf(
                '%s ssh %s@%s -p %d -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s "%s | gzip -9" | gunzip > %s',
                $sshpass,
                $backup->getBackupConfiguration()->getHost()->getLogin(),
                $backup->getBackupConfiguration()->getHost()->getIp(),
                $backup->getBackupConfiguration()->getHost()->getPort() ?? 22,
                $privateKeyString,
                $backup->getBackupConfiguration()->getDumpCommand(),
                $backupDestination
            );
        } else {
            $command = sprintf(
                '%s > %s',
                $backup->getBackupConfiguration()->getDumpCommand(),
                $backupDestination
            );
        }

        $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(self::OS_DOWNLOAD_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing download - exec dump command - %s', $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        } else {
            $this->log($backup, Log::LOG_INFO, $process->getOutput());
        }

        $backup->setSize(filesize($backupDestination));
        $this->log($backup, Log::LOG_INFO, sprintf('Backup size : %s', StringUtils::humanizeFilesize($backup->getSize())));

        $this->log($backup, Log::LOG_NOTICE, 'Dump done');
    }

    private function downloadSSHFS(Backup $backup): void
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        if (!$this->checkDownloadedSSHFS($backup)) {
            $filesystem = new Filesystem();
            $privateKeypath = $filesystem->tempnam('/tmp', 'key_');
            $backupDestination = $this->getTemporaryBackupDestination($backup);

            $filesystem->mkdir($backupDestination);

            $filesystem->appendToFile($privateKeypath, str_replace("\r", '', $backup->getBackupConfiguration()->getHost()?->getPrivateKey()."\n"));

            if (null !== $backup->getBackupConfiguration()->getHost()->getPassword()) {
                $sshpass = sprintf('echo "%s" |', $backup->getBackupConfiguration()->getHost()->getPassword());
            } else {
                $sshpass = '';
            }

            $command = sprintf(
                '%s sshfs %s@%s:%s %s -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o uid=%d,gid=%d -o ro -o IdentityFile=%s %s',
                $sshpass,
                $backup->getBackupConfiguration()->getHost()->getLogin(),
                $backup->getBackupConfiguration()->getHost()->getIp(),
                $backup->getBackupConfiguration()->getRemotePath(),
                $backupDestination,
                posix_getuid(),
                posix_getgid(),
                $privateKeypath,
                $backup->getBackupConfiguration()->getDumpCommand(),
            );

            $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(self::SSHFS_MOUNT_TIMEOUT);
            $process->run();

            if (!$process->isSuccessful()) {
                $this->log($backup, Log::LOG_ERROR, sprintf('Error executing download - exec dump command - %s', $process->getErrorOutput()));
                throw new ProcessFailedException($process);
            } else {
                $this->log($backup, Log::LOG_INFO, $process->getOutput());
            }

            $this->log($backup, Log::LOG_NOTICE, 'Mount done');
        } else {
            $this->log($backup, Log::LOG_NOTICE, 'Already mounted');
        }
    }

    private function downloadSftp(Backup $backup): void
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $filesystem = new Filesystem();
        $privateKeypath = $filesystem->tempnam('/tmp', 'key_');
        $backupDestination = $this->getTemporaryBackupDestination($backup);

        $filesystem->mkdir($backupDestination);

        $filesystem->appendToFile($privateKeypath, str_replace("\r", '', $backup->getBackupConfiguration()->getHost()->getPrivateKey()."\n"));

        $command = sprintf(
            'sftp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o IdentityFile=%s %s %s@%s:%s %s',
            $privateKeypath,
            $backup->getBackupConfiguration()->getDumpCommand(),
            $backup->getBackupConfiguration()->getHost()->getLogin(),
            $backup->getBackupConfiguration()->getHost()->getIp(),
            $backup->getBackupConfiguration()->getRemotePath(),
            $backupDestination,
        );

        $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(self::SSHFS_MOUNT_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing download - exec dump command - %s', $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        } else {
            $this->log($backup, Log::LOG_INFO, $process->getOutput());
        }

        $this->log($backup, Log::LOG_NOTICE, 'Download done');

        $backup->setSize($this->getSftpDownloadSize($backup));
    }

    public function downloadBackup(Backup $backup): void
    {
        switch ($backup->getBackupConfiguration()->getType()) {
            case BackupConfiguration::TYPE_OS_INSTANCE:
                $this->downloadOSSnapshot($backup);
                break;
            case BackupConfiguration::TYPE_MYSQL:
            case BackupConfiguration::TYPE_POSTGRESQL:
            case BackupConfiguration::TYPE_SQL_SERVER:
            case BackupConfiguration::TYPE_SSH_CMD:
                $this->downloadCommandResult($backup);
                break;
            case BackupConfiguration::TYPE_SFTP:
                $this->downloadSftp($backup);
                break;
            case BackupConfiguration::TYPE_SSHFS:
                $this->downloadSSHFS($backup);
                break;
        }
    }

    public function checkDownloadedSSHFS(Backup $backup): bool
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $backupDestination = $this->getTemporaryBackupDestination($backup);

        $command = sprintf(
            'grep -qs "%s" /proc/mounts',
            $backupDestination
        );

        $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(self::OS_DOWNLOAD_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->log($backup, Log::LOG_ERROR, 'checkDownloadedSSHFS : not mounted');

            return false;
        } else {
            $this->log($backup, Log::LOG_NOTICE, 'checkDownloadedSSHFS : mounted');

            return true;
        }
    }

    public function checkDownloadedDump(Backup $backup): bool
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $dumpDestination = $this->getTemporaryBackupDestination($backup);

        if (file_exists($dumpDestination) && filesize($dumpDestination) >= $backup->getBackupConfiguration()->getMinimumBackupSize()) {
            $this->log($backup, Log::LOG_NOTICE, 'Backup downloaded');

            return true;
        } else {
            $this->log($backup, Log::LOG_NOTICE, sprintf('Backup not downloaded : %s < %s', StringUtils::humanizeFilesize(filesize($dumpDestination)), StringUtils::humanizeFilesize($backup->getBackupConfiguration()->getMinimumBackupSize())));

            return false;
        }
    }

    public function checkDownloadedOSSnapshot(Backup $backup): bool
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $imageDestination = $this->getTemporaryBackupDestination($backup);

        if (!$backup->getSize()) {
            $this->log($backup, Log::LOG_NOTICE, 'Openstack image not backuped');

            return false;
        }

        if (!file_exists($imageDestination) || filesize($imageDestination) !== $backup->getSize()) {
            $this->log($backup, Log::LOG_NOTICE, sprintf('Openstack image not downloaded : %s != %s', StringUtils::humanizeFilesize(filesize($imageDestination)), StringUtils::humanizeFilesize($backup->getSize())));

            return false;
        }

        return true;
    }

    private function uploadBackupSSHResticRmScript(Backup $backup, string $privateKeypath, string $scriptFilePath): void
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $command = sprintf(
            'ssh %s@%s -p %d -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o IdentityFile=%s "sudo rm -f %s"',
            $backup->getBackupConfiguration()->getHost()->getLogin(),
            $backup->getBackupConfiguration()->getHost()->getIp(),
            $backup->getBackupConfiguration()->getHost()->getPort() ?? 22,
            $privateKeypath,
            $scriptFilePath,
        );

        $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
        $process = Process::fromShellCommandline($command, null);
        $process->setTimeout(self::RESTIC_UPLOAD_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing backup - ssh restic remove script - %s', $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        } else {
            $this->log($backup, Log::LOG_INFO, $process->getOutput());
        }
    }

    private function uploadBackupSSHRestic(Backup $backup): void
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $env = $backup->getBackupConfiguration()->getStorage()->getEnv() + $backup->getBackupConfiguration()->getResticEnv();

        $filesystem = new Filesystem();
        $privateKeypath = $filesystem->tempnam('/tmp', 'key_');
        $filesystem->appendToFile($privateKeypath, str_replace("\r", '', $backup->getBackupConfiguration()->getHost()->getPrivateKey()."\n"));

        $scriptFilePath = $filesystem->tempnam('/tmp', 'env_');
        $filesystem->appendToFile($scriptFilePath, sprintf('#!/bin/bash%s', \PHP_EOL));
        foreach ($env as $k => $v) {
            $filesystem->appendToFile($scriptFilePath, sprintf('export %s="%s"%s', $k, str_replace('"', '\\"', $v), \PHP_EOL));
        }
        $filesystem->appendToFile($scriptFilePath, sprintf(
            'restic backup --tag host=%s --tag configuration=%s --host cloudbackup %s || exit 1%s',
            $backup->getBackupConfiguration()->getHost()->getSlug(),
            $backup->getBackupConfiguration()->getSlug(),
            $backup->getBackupConfiguration()->getRemotePath(),
            \PHP_EOL
        ));

        $command = sprintf(
            'scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o IdentityFile=%s %s %s@%s:%s',
            $privateKeypath,
            $scriptFilePath,
            $backup->getBackupConfiguration()->getHost()->getLogin(),
            $backup->getBackupConfiguration()->getHost()->getIp(),
            $scriptFilePath,
        );

        $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
        $process = Process::fromShellCommandline($command, null);
        $process->setTimeout(self::RESTIC_UPLOAD_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing backup - ssh restic scp - %s', $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        }

        $command = sprintf(
            'ssh %s@%s -p %d -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o IdentityFile=%s "%s"',
            $backup->getBackupConfiguration()->getHost()->getLogin(),
            $backup->getBackupConfiguration()->getHost()->getIp(),
            $backup->getBackupConfiguration()->getHost()->getPort() ?? 22,
            $privateKeypath,
            sprintf(
                'sudo chmod 700 %s && sudo chown root:root %s && sudo %s',
                $scriptFilePath,
                $scriptFilePath,
                $scriptFilePath,
            )
        );

        $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));

        $process = Process::fromShellCommandline($command, null);
        $process->setTimeout(self::RESTIC_UPLOAD_TIMEOUT);
        $process->run();

        $this->uploadBackupSSHResticRmScript($backup, $privateKeypath, $scriptFilePath);

        if (!$process->isSuccessful()) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing backup - ssh restic upload - %s', $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        } else {
            $this->log($backup, Log::LOG_INFO, $process->getOutput());
        }
    }

    public function uploadBackup(Backup $backup): void
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $env = $backup->getBackupConfiguration()->getStorage()->getEnv() + $backup->getBackupConfiguration()->getResticEnv();

        switch ($backup->getBackupConfiguration()->getType()) {
            case BackupConfiguration::TYPE_OS_INSTANCE:
                $command = sprintf(
                    'restic backup --tag project=%s --tag instance=%s --tag configuration=%s --host cloudbackup %s',
                    $backup->getBackupConfiguration()->getOsInstance()->getOSProject()->getSlug(),
                    $backup->getBackupConfiguration()->getOsInstance()->getSlug(),
                    $backup->getBackupConfiguration()->getSlug(),
                    $this->getTemporaryBackupDestination($backup)
                );

                $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
                $process = Process::fromShellCommandline($command, null, $env);
                $process->setTimeout(self::RESTIC_UPLOAD_TIMEOUT);
                $process->run();

                if (!$process->isSuccessful()) {
                    $this->log($backup, Log::LOG_ERROR, sprintf('Error executing backup - restic upload - %s', $process->getErrorOutput()));
                    throw new ProcessFailedException($process);
                } else {
                    $this->log($backup, Log::LOG_INFO, $process->getOutput());
                }
                break;
            case BackupConfiguration::TYPE_MYSQL:
            case BackupConfiguration::TYPE_POSTGRESQL:
            case BackupConfiguration::TYPE_SQL_SERVER:
            case BackupConfiguration::TYPE_SSH_CMD:
                $command = sprintf(
                    'restic backup --tag host=%s --tag configuration=%s --host cloudbackup %s',
                    $backup->getBackupConfiguration()->getHost() ? $backup->getBackupConfiguration()->getHost()->getSlug() : 'direct',
                    $backup->getBackupConfiguration()->getSlug(),
                    $this->getTemporaryBackupDestination($backup)
                );

                $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
                $process = Process::fromShellCommandline($command, null, $env);
                $process->setTimeout(self::RESTIC_UPLOAD_TIMEOUT);
                $process->run();

                if (!$process->isSuccessful()) {
                    $this->log($backup, Log::LOG_ERROR, sprintf('Error executing backup - restic upload - %s', $process->getErrorOutput()));
                    throw new ProcessFailedException($process);
                } else {
                    $this->log($backup, Log::LOG_INFO, $process->getOutput());
                }
                break;
            case BackupConfiguration::TYPE_SSHFS:
            case BackupConfiguration::TYPE_SFTP:
                $command = sprintf(
                    'restic backup --tag host=%s --tag configuration=%s --host cloudbackup %s',
                    $backup->getBackupConfiguration()->getHost()->getSlug(),
                    $backup->getBackupConfiguration()->getSlug(),
                    $this->getTemporaryBackupDestination($backup),
                );

                $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
                $process = Process::fromShellCommandline($command, null, $env);
                $process->setTimeout(self::RESTIC_UPLOAD_TIMEOUT);
                $process->run();

                if (!$process->isSuccessful()) {
                    $this->log($backup, Log::LOG_ERROR, sprintf('Error executing backup - restic upload - %s', $process->getErrorOutput()));
                    throw new ProcessFailedException($process);
                } else {
                    $this->log($backup, Log::LOG_INFO, $process->getOutput());
                }
                break;
            case BackupConfiguration::TYPE_SSH_RESTIC:
                $this->uploadBackupSSHRestic($backup);
                break;
        }
    }

    private function cleanBackupRestic(Backup $backup): void
    {
        $env = $backup->getBackupConfiguration()->getStorage()->getEnv() + $backup->getBackupConfiguration()->getResticEnv();

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
        } else {
            $this->log($backup, Log::LOG_INFO, $process->getOutput());
        }
    }

    private function cleanBackupSSHFS(Backup $backup): void
    {
        if ($this->checkDownloadedSSHFS($backup)) {
            $command = sprintf('fusermount -u %s', $this->getTemporaryBackupDestination($backup));
            $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(self::SSHFS_UMOUNT_TIMEOUT);
            $process->run();

            if (!$process->isSuccessful()) {
                $this->log($backup, Log::LOG_ERROR, sprintf('Error executing cleanup - exec umount command - %s', $process->getErrorOutput()));
                throw new ProcessFailedException($process);
            } else {
                $this->log($backup, Log::LOG_INFO, $process->getOutput());
            }
        }

        $this->log($backup, Log::LOG_NOTICE, sprintf('Remove local file - %s', $this->getTemporaryBackupDestination($backup)));
        if ((2 !== \count(scandir($this->getTemporaryBackupDestination($backup))))) {
            $message = sprintf('Error executing cleanup - %s directory is not empty', $this->getTemporaryBackupDestination($backup));
            $this->log($backup, Log::LOG_ERROR, $message);
            throw new Exception($message);
        } else {
            // php's rmdir function only remove empty directories
            rmdir($this->getTemporaryBackupDestination($backup));
        }
    }

    private function cleanBackupSftp(Backup $backup): void
    {
        if ($this->checkDownloadedDump($backup)) {
            $this->log($backup, Log::LOG_NOTICE, sprintf('Remove local directory - %s', $this->getTemporaryBackupDestination($backup)));

            $filesystem = new Filesystem();
            $filesystem->remove($this->getTemporaryBackupDestination($backup));
        }
    }

    private function cleanBackupOsInstance(Backup $backup): void
    {
        if (null !== $this->getSnapshotOsInstanceStatus($backup)) {
            $command = sprintf('openstack image delete %s', $backup->getOsImageId());

            $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
            $process = Process::fromShellCommandline($command, null, $backup->getBackupConfiguration()->getOsInstance()->getOSEnv());
            $process->setTimeout(self::OS_IMAGE_LIST_TIMEOUT);
            $process->run();

            if (!$process->isSuccessful()) {
                $this->log($backup, Log::LOG_ERROR, sprintf('Error executing cleanup - openstack image image - %s', $process->getErrorOutput()));
                throw new ProcessFailedException($process);
            } else {
                $this->log($backup, Log::LOG_INFO, $process->getOutput());
            }
        }
    }

    private function cleanRemoteByCommand(Backup $backup): void
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $filesystem = new Filesystem();

        if (null !== $backup->getBackupConfiguration()->getHost()->getPrivateKey()) {
            $privateKeypath = $filesystem->tempnam('/tmp', 'key_');
            $filesystem->appendToFile($privateKeypath, str_replace("\r", '', $backup->getBackupConfiguration()->getHost()->getPrivateKey()."\n"));
            $privateKeyString = sprintf('-i %s', $privateKeypath);
        } else {
            $privateKeyString = '';
        }

        if (null !== $backup->getBackupConfiguration()->getHost()->getPassword()) {
            $sshpass = sprintf('sshpass -p %s', $backup->getBackupConfiguration()->getHost()->getPassword());
        } else {
            $sshpass = '';
        }

        $command = sprintf(
            '%s ssh %s@%s -p %d -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s "%s"',
            $sshpass,
            $backup->getBackupConfiguration()->getHost()->getLogin(),
            $backup->getBackupConfiguration()->getHost()->getIp(),
            $backup->getBackupConfiguration()->getHost()->getPort() ?? 22,
            $privateKeyString,
            $backup->getBackupConfiguration()->getRemoteCleanCommand(),
        );

        $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(self::OS_DOWNLOAD_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing download - exec remote cleanup command - %s', $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        } else {
            $this->log($backup, Log::LOG_INFO, $process->getOutput());
        }

        $this->log($backup, Log::LOG_NOTICE, 'Remote cleanup done');
    }

    public function cleanBackup(Backup $backup): void
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        // Restic forget
        if ($backup->getBackupConfiguration()->getStorage()->isRestic() && BackupConfiguration::TYPE_READ_RESTIC !== $backup->getBackupConfiguration()->getType()) {
            $this->cleanBackupRestic($backup);
        }

        // Remove local temporary file / directory
        if (file_exists($this->getTemporaryBackupDestination($backup))) {
            switch ($backup->getBackupConfiguration()->getType()) {
                case BackupConfiguration::TYPE_MYSQL:
                case BackupConfiguration::TYPE_POSTGRESQL:
                case BackupConfiguration::TYPE_SQL_SERVER:
                case BackupConfiguration::TYPE_OS_INSTANCE:
                case BackupConfiguration::TYPE_SSH_CMD:
                    $this->log($backup, Log::LOG_NOTICE, sprintf('Remove local file - %s', $this->getTemporaryBackupDestination($backup)));
                    unlink($this->getTemporaryBackupDestination($backup));
                    break;
                case BackupConfiguration::TYPE_SFTP:
                    $this->cleanBackupSftp($backup);
                    break;
                case BackupConfiguration::TYPE_SSHFS:
                    $this->cleanBackupSSHFS($backup);
                    break;
            }
        }

        // Remove OS image
        if (BackupConfiguration::TYPE_OS_INSTANCE === $backup->getBackupConfiguration()->getType()) {
            $this->cleanBackupOsInstance($backup);
        }

        // Execute remote clean command
        if (BackupConfiguration::TYPE_SSH_CMD === $backup->getBackupConfiguration()->getType() && !empty($backup->getBackupConfiguration()->getRemoteCleanCommand())) {
            $this->cleanRemoteByCommand($backup);
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
            case BackupConfiguration::TYPE_POSTGRESQL:
            case BackupConfiguration::TYPE_SQL_SERVER:
            case BackupConfiguration::TYPE_SSH_CMD:
            case BackupConfiguration::TYPE_SSHFS:
            case BackupConfiguration::TYPE_SFTP:
                if (file_exists($this->getTemporaryBackupDestination($backup))) {
                    return false;
                }
                break;
        }

        return true;
    }

    public function healhCheckBackup(Backup $backup): void
    {
        switch ($backup->getBackupConfiguration()->getStorage()->getType()) {
            case Storage::TYPE_RESTIC:
                $env = $backup->getBackupConfiguration()->getStorage()->getEnv() + $backup->getBackupConfiguration()->getResticEnv();

                $command = 'restic check';

                $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
                $process = Process::fromShellCommandline($command, null, $env);
                $process->setTimeout(self::RESTIC_CHECK_TIMEOUT);
                $process->run();

                if (!$process->isSuccessful()) {
                    $this->log($backup, Log::LOG_ERROR, sprintf('Error executing cleanup - restic check - %s', $process->getErrorOutput()));
                    throw new ProcessFailedException($process);
                } else {
                    $this->log($backup, Log::LOG_INFO, $process->getOutput());
                }

                $command = 'restic snapshots --json --last -q';

                $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
                $process = Process::fromShellCommandline($command, null, $env);
                $process->setTimeout(self::RESTIC_CHECK_TIMEOUT);
                $process->run();

                if (!$process->isSuccessful()) {
                    $this->log($backup, Log::LOG_ERROR, sprintf('Error executing cleanup - restic check - %s', $process->getErrorOutput()));
                    throw new ProcessFailedException($process);
                } else {
                    if (($json = json_decode($process->getOutput(), true)) === null || !\count($json)) {
                        $message = sprintf('Cannot decode json : %s', $process->getOutput());
                        $this->log($backup, Log::LOG_ERROR, $message);
                        throw new Exception($message);
                    }

                    $prettyJson = json_encode($json, \JSON_PRETTY_PRINT);
                    $this->log($backup, Log::LOG_INFO, $prettyJson);

                    $lastBackup = new DateTime(preg_replace('/(\d+\-\d+\-\d+T\d+:\d+:\d+)\..*/', '$1', end($json)['time']));
                    $this->log($backup, Log::LOG_NOTICE, sprintf('Last backup : %s', $lastBackup->format('d/m/Y H:i')));

                    $yesterday = new DateTime('yesterday');
                    if ($lastBackup < $yesterday) {
                        $message = 'Last backup older than 24h';
                        $this->log($backup, Log::LOG_ERROR, $message);
                        throw new Exception($message);
                    }
                }
            break;
        }
    }

    public function resticInitRepo(Backup $backup): void
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', __CLASS__, __FUNCTION__));

        $env = $backup->getBackupConfiguration()->getStorage()->getEnv() + $backup->getBackupConfiguration()->getResticEnv();

        $command = 'restic init';

        $this->log($backup, Log::LOG_NOTICE, sprintf('Run `%s`', $command));
        $process = Process::fromShellCommandline($command, null, $env);
        $process->setTimeout(self::RESTIC_INIT_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful() && !preg_match(self::RESTIC_INIT_REGEX, $process->getErrorOutput())) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing backup - restic init repo - %s', $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        } else {
            $this->log($backup, Log::LOG_INFO, $process->getOutput());
        }
    }

    public function getTemporaryBackupDestination(Backup $backup): string
    {
        switch ($backup->getBackupConfiguration()->getType()) {
            case BackupConfiguration::TYPE_SSHFS:
                return sprintf('%s/%s', $this->temporaryDownloadDirectory, $backup->getName(false));
            default:
                if (null !== $backup->getBackupConfiguration()->getExtension()) {
                    return sprintf('%s/%s.%s', $this->temporaryDownloadDirectory, $backup->getName(false), $backup->getBackupConfiguration()->getExtension());
                }

                return sprintf('%s/%s', $this->temporaryDownloadDirectory, $backup->getName(false));
            }
    }

    public function initBackup(BackupConfiguration $backupConfiguration): void
    {
        $now = new DateTime();

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
                if ('failed' !== $backup->getCurrentPlace()) {
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
            $this->log($backup, Log::LOG_NOTICE, sprintf('An error occured : %s', $e->getMessage()));

            if ($backupWorkflow->can($backup, 'failed')) {
                $backupWorkflow->apply($backup, 'failed');
            }
        }

        $this->entityManager->persist($backup);
        $this->entityManager->flush();
    }

    public function completeBackup(BackupConfiguration $backupConfiguration): void
    {
        $backup = $this->backupRepository->findOneBy([
            'backupConfiguration' => $backupConfiguration,
        ], ['id' => 'DESC']);

        if (null === $backup) {
            throw new Exception(sprintf('No backup found: %s', $backupConfiguration->getName()));
        }

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

            if ($backupWorkflow->can($backup, 'health_check')) {
                $backupWorkflow->apply($backup, 'health_check');
            }

            if ($backupWorkflow->can($backup, 'backuped')) {
                $backupWorkflow->apply($backup, 'backuped');
            }
        } catch (\Exception $e) {
            $this->log($backup, Log::LOG_NOTICE, sprintf('An error occured : %s', $e->getMessage()));

            if ($backupWorkflow->can($backup, 'failed')) {
                $backupWorkflow->apply($backup, 'failed');
            }
        }

        $this->entityManager->persist($backup);
        $this->entityManager->flush();
    }
}
