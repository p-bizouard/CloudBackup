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
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Workflow\Registry;

class BackupService
{
    final public const RESTIC_INIT_TIMEOUT = 60;
    final public const RESTIC_INIT_REGEX = '/Fatal\: create key in repository.*repository master key and config already initialized|failed\: config file already exists/';
    final public const RESTIC_UPLOAD_TIMEOUT = 3600 * 4;
    final public const RESTIC_CHECK_TIMEOUT = 3600;
    final public const RESTIC_REPAIR_TIMEOUT = 3600;

    final public const OS_INSTANCE_SNAPSHOT_TIMEOUT = 60;
    final public const OS_IMAGE_LIST_TIMEOUT = 60;
    final public const OS_DOWNLOAD_TIMEOUT = 3600 * 4;

    final public const SSHFS_MOUNT_TIMEOUT = 60;
    final public const SSHFS_UMOUNT_TIMEOUT = 60;

    final public const DOWNLOAD_SIZE_TIMEOUT = 60 * 10;

    final public const RCLONE_UPLOAD_TIMEOUT = 3600 * 4;
    final public const RCLONE_CHECK_TIMEOUT = 3600;

    public function __construct(
        private readonly string $temporaryDownloadDirectory,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
        private readonly Registry $workflowRegistry,
        private readonly BackupRepository $backupRepository,
    ) {
    }

    public function log(Backup $backup, string $level, string $message): void
    {
        match ($level) {
            Log::LOG_ERROR => $this->logger->error($message),
            Log::LOG_WARNING => $this->logger->warning($message),
            Log::LOG_INFO => $this->logger->info($message),
            Log::LOG_NOTICE => $this->logger->notice($message),
            default => throw new Exception('Log level not found'),
        };

        $log = new Log();
        $log->setLevel($level);
        $log->setMessage($message);

        $backup->addLog($log);

        $this->entityManager->persist($log);
        $this->entityManager->persist($backup);
        $this->entityManager->flush();
    }

    public function logParameters(mixed $parameters): string
    {
        return strip_tags(json_encode($parameters, \JSON_PRETTY_PRINT));
    }

    public function applyWorkflow(Backup $backup, string $transition): void
    {
        $workflow = $this->workflowRegistry->get($backup);
        $workflow->apply($backup, $transition);

        $this->entityManager->persist($backup);
        $this->entityManager->flush();
    }

    public function snapshotOSInstance(Backup $backup): void
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', self::class, __FUNCTION__));

        $status = $this->getSnapshotOsInstanceStatus($backup);
        if (null !== $status) {
            $this->log($backup, Log::LOG_NOTICE, sprintf('Snapshot already found with %s', $status));

            return;
        }

        $env = $backup->getBackupConfiguration()->getOsInstance()->getOSEnv();

        $command = 'openstack server image create --name ${BACKUP_NAME} ${OS_INSTANCE_ID}';
        $parameters = [
            'BACKUP_NAME' => $backup->getName(),
            'OS_INSTANCE_ID' => $backup->getBackupConfiguration()->getOsInstance()->getId(),
        ];

        $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, $this->logParameters($parameters)));
        $process = Process::fromShellCommandline($command, null, $env + $parameters);
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
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', self::class, __FUNCTION__));

        $env = $backup->getBackupConfiguration()->getOsInstance()->getOSEnv();

        $command = 'openstack image list --private --name ${BACKUP_NAME} --long -f json';
        $parameters = [
            'BACKUP_NAME' => $backup->getName(),
        ];

        $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, $this->logParameters($parameters)));
        $process = Process::fromShellCommandline($command, null, $env + $parameters);
        $process->setTimeout(self::OS_IMAGE_LIST_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing snapshot - openstack image list - %s', $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        } else {
            $this->log($backup, Log::LOG_INFO, $process->getOutput());
        }

        $output = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        if (null === $output) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing snapshot - openstack image list - %s - %s', $process->getOutput(), $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        }

        if ((is_countable($output) ? \count($output) : 0) === 0) {
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
        $command = 'du -sb "${BACKUP_DESTINATION}" | cut -f1';
        $parameters = [
            'BACKUP_DESTINATION' => $this->getTemporaryBackupDestination($backup),
        ];

        $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, $this->logParameters($parameters)));
        $process = Process::fromShellCommandline($command, null, $parameters);
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
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', self::class, __FUNCTION__));

        $env = $backup->getBackupConfiguration()->getOsInstance()->getOSEnv();

        if (!$backup->getOsImageId()) {
            $command = 'openstack image list --private --name ${BACKUP_NAME} --long -f json';
            $parameters = [
                'BACKUP_NAME' => $backup->getName(),
            ];

            $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, $this->logParameters($parameters)));
            $process = Process::fromShellCommandline($command, null, $env + $parameters);
            $process->setTimeout(self::OS_IMAGE_LIST_TIMEOUT);
            $process->run();

            if (!$process->isSuccessful()) {
                $this->log($backup, Log::LOG_ERROR, sprintf('Error executing download - openstack image list - %s', $process->getErrorOutput()));
                throw new ProcessFailedException($process);
            } else {
                $this->log($backup, Log::LOG_INFO, $process->getOutput());
            }

            $output = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            if (null === $output) {
                $this->log($backup, Log::LOG_ERROR, sprintf('Error executing download - openstack image list - %s - %s', $process->getOutput(), $process->getErrorOutput()));
                throw new ProcessFailedException($process);
            }

            if ((is_countable($output) ? \count($output) : 0) === 0) {
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

        $command = 'openstack image save --file ${IMAGE_DESTINATION} ${IMAGE_ID}';
        $parameters = [
            'IMAGE_DESTINATION' => $imageDestination,
            'IMAGE_ID' => $backup->getOsImageId(),
        ];

        $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, $this->logParameters($parameters)));
        $process = Process::fromShellCommandline($command, null, $env + $parameters);
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
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', self::class, __FUNCTION__));

        $filesystem = new Filesystem();
        $backupDestination = $this->getTemporaryBackupDestination($backup);

        if (null !== $backup->getBackupConfiguration()->getHost()) {
            if (null !== $backup->getBackupConfiguration()->getHost()->getPrivateKey()) {
                $privateKeypath = $filesystem->tempnam('/tmp', 'key_');
                $filesystem->appendToFile($privateKeypath, str_replace("\r", '', $backup->getBackupConfiguration()->getHost()->getPrivateKey()."\n"));
                $privateKeyString = '-i ${PRIVATE_KEY_PATH}';
            }

            if (null !== $backup->getBackupConfiguration()->getHost()->getPassword()) {
                $sshpass = 'sshpass -p ${SSHPASS}';
            }

            $command = sprintf(
                '%s ssh "${LOGIN}@${IP}" -p "${PORT}" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s "${DUMP_COMMAND} | gzip -9" | gunzip > "${DESTINATION}"',
                $sshpass ?? null,
                $privateKeyString ?? null
            );
            $parameters = [
                'SSHPASS' => $backup->getBackupConfiguration()->getHost()->getPassword(),
                'LOGIN' => $backup->getBackupConfiguration()->getHost()->getLogin(),
                'IP' => $backup->getBackupConfiguration()->getHost()->getIp(),
                'PORT' => $backup->getBackupConfiguration()->getHost()->getPort() ?? 22,
                'PRIVATE_KEY_PATH' => $privateKeypath ?? null,
                'DUMP_COMMAND' => $backup->getBackupConfiguration()->getDumpCommand(),
                'DESTINATION' => $backupDestination,
            ];
        } else {
            $command = 'sh -c "${DUMP_COMMAND}" > "${DESTINATION}"';
            $parameters = [
                'DUMP_COMMAND' => $backup->getBackupConfiguration()->getDumpCommand(),
                'DESTINATION' => $backupDestination,
            ];
        }

        $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, $this->logParameters($parameters)));
        $process = Process::fromShellCommandline($command, null, $parameters);
        $process->setTimeout(self::OS_DOWNLOAD_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing download - exec dump command - %s', $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        } else {
            $this->log($backup, Log::LOG_INFO, $process->getOutput());
        }

        $backup->setSize(filesize($backupDestination));
        $this->log($backup, Log::LOG_INFO, sprintf('Backup size : %s', StringUtils::humanizeFileSize($backup->getSize())));

        $this->log($backup, Log::LOG_NOTICE, 'Dump done');
    }

    private function downloadSSHFS(Backup $backup): void
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', self::class, __FUNCTION__));

        if (!$this->checkDownloadedFUSE($backup)) {
            $filesystem = new Filesystem();

            $backupDestination = $this->getTemporaryBackupDestination($backup);
            $filesystem->mkdir($backupDestination);

            if (null !== $backup->getBackupConfiguration()->getHost()->getPrivateKey()) {
                $privateKeypath = $filesystem->tempnam('/tmp', 'key_');
                $filesystem->appendToFile($privateKeypath, str_replace("\r", '', $backup->getBackupConfiguration()->getHost()->getPrivateKey()."\n"));
                $privateKeyString = '-o IdentityFile="${PRIVATE_KEY_PATH}"';
            }

            if (null !== $backup->getBackupConfiguration()->getHost()->getPassword()) {
                $sshpass = 'echo "${SSHPASS}" | ';
            }

            $command = sprintf(
                '%s sshfs "${LOGIN}@${IP}:${REMOTE_PATH}" "${DESTINATION}" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s -o "uid=%d,gid=%d" -o ro %s',
                $sshpass ?? null,
                $privateKeyString ?? null,
                posix_getuid(),
                posix_getgid(),
                $backup->getBackupConfiguration()->getDumpCommand() // Unsafe use of shell command - allow to add options to sshfs
            );
            $parameters = [
                'SSHPASS' => $backup->getBackupConfiguration()->getHost()->getPassword(),
                'LOGIN' => $backup->getBackupConfiguration()->getHost()->getLogin(),
                'IP' => $backup->getBackupConfiguration()->getHost()->getIp(),
                'REMOTE_PATH' => $backup->getBackupConfiguration()->getRemotePath(),
                'DESTINATION' => $backupDestination,
                'PRIVATE_KEY_PATH' => $privateKeypath ?? null,
            ];

            $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, $this->logParameters($parameters)));
            $process = Process::fromShellCommandline($command, null, $parameters);
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
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', self::class, __FUNCTION__));

        $filesystem = new Filesystem();

        if (null !== $backup->getBackupConfiguration()->getHost()->getPrivateKey()) {
            $privateKeypath = $filesystem->tempnam('/tmp', 'key_');
            $filesystem->appendToFile($privateKeypath, str_replace("\r", '', $backup->getBackupConfiguration()->getHost()->getPrivateKey()."\n"));
            $privateKeyString = '-i ${PRIVATE_KEY_PATH}';
        }

        $command = sprintf(
            'sftp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s "${LOGIN}@${IP}:${REMOTE_DIRECTORY}" "${BACKUP_DESTINATION}"',
            $privateKeyString ?? null
        );
        $parameters = [
            'PORT' => $backup->getBackupConfiguration()->getHost()->getPort() ?? 22,
            'PRIVATE_KEY_PATH' => $privateKeypath ?? null,
            'LOGIN' => $backup->getBackupConfiguration()->getHost()->getLogin(),
            'IP' => $backup->getBackupConfiguration()->getHost()->getIp(),
            'REMOTE_DIRECTORY' => $backup->getBackupConfiguration()->getRemotePath(),
            'BACKUP_DESTINATION' => $this->getTemporaryBackupDestination($backup),
        ];

        $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, $this->logParameters($parameters)));
        $process = Process::fromShellCommandline($command, null, $parameters);
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
        match ($backup->getBackupConfiguration()->getType()) {
            BackupConfiguration::TYPE_OS_INSTANCE => $this->downloadOSSnapshot($backup),
            BackupConfiguration::TYPE_MYSQL, BackupConfiguration::TYPE_POSTGRESQL, BackupConfiguration::TYPE_SQL_SERVER, BackupConfiguration::TYPE_SSH_CMD => $this->downloadCommandResult($backup),
            BackupConfiguration::TYPE_SFTP => $this->downloadSftp($backup),
            BackupConfiguration::TYPE_SSHFS => $this->downloadSSHFS($backup),
            default => $this->log($backup, Log::LOG_INFO, sprintf('%s : Nothing to do', $backup->getCurrentPlace())),
        };
    }

    public function checkDownloadedFUSE(Backup $backup): bool
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', self::class, __FUNCTION__));

        $command = 'grep -qs "${DIRECTORY}" /proc/mounts';
        $parameters = [
            'DIRECTORY' => $this->getTemporaryBackupDestination($backup),
        ];

        $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, $this->logParameters($parameters)));
        $process = Process::fromShellCommandline($command, null, $parameters);
        $process->setTimeout(self::OS_DOWNLOAD_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->log($backup, Log::LOG_ERROR, 'checkDownloadedFUSE : not mounted');

            return false;
        } else {
            $this->log($backup, Log::LOG_NOTICE, 'checkDownloadedFUSE : mounted');

            return true;
        }
    }

    public function checkDownloadedDump(Backup $backup): bool
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', self::class, __FUNCTION__));

        $dumpDestination = $this->getTemporaryBackupDestination($backup);

        if (file_exists($dumpDestination) && filesize($dumpDestination) >= $backup->getBackupConfiguration()->getMinimumBackupSize()) {
            $this->log($backup, Log::LOG_NOTICE, 'Backup downloaded');

            return true;
        } else {
            $this->log($backup, Log::LOG_NOTICE, sprintf('Backup not downloaded : %s < %s', StringUtils::humanizeFileSize(filesize($dumpDestination)), StringUtils::humanizeFileSize($backup->getBackupConfiguration()->getMinimumBackupSize())));

            return false;
        }
    }

    public function checkDownloadedOSSnapshot(Backup $backup): bool
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', self::class, __FUNCTION__));

        $imageDestination = $this->getTemporaryBackupDestination($backup);

        if (!$backup->getSize()) {
            $this->log($backup, Log::LOG_NOTICE, 'Openstack image not backuped');

            return false;
        }

        if (!file_exists($imageDestination) || filesize($imageDestination) !== $backup->getSize()) {
            $this->log($backup, Log::LOG_NOTICE, sprintf('Openstack image not downloaded : %s != %s', StringUtils::humanizeFileSize(filesize($imageDestination)), StringUtils::humanizeFileSize($backup->getSize())));

            return false;
        }

        return true;
    }

    private function uploadBackupSSHResticRmScript(Backup $backup, string $privateKeypath, string $scriptFilePath): void
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', self::class, __FUNCTION__));

        $command = sprintf(
            'ssh %s@%s -p %d -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o IdentityFile=%s "sudo rm -f %s"',
            $backup->getBackupConfiguration()->getHost()->getLogin(),
            $backup->getBackupConfiguration()->getHost()->getIp(),
            $backup->getBackupConfiguration()->getHost()->getPort() ?? 22,
            $privateKeypath,
            $scriptFilePath,
        );

        $this->log($backup, Log::LOG_INFO, sprintf('Run `%s`', $command));
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
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', self::class, __FUNCTION__));

        $env = $backup->getBackupConfiguration()->getStorage()->getEnv() + $backup->getBackupConfiguration()->getResticEnv();

        $filesystem = new Filesystem();
        $privateKeypath = $filesystem->tempnam('/tmp', 'key_');
        $filesystem->appendToFile($privateKeypath, str_replace("\r", '', $backup->getBackupConfiguration()->getHost()->getPrivateKey()."\n"));

        $scriptFilePath = $filesystem->tempnam('/tmp', 'env_');
        $filesystem->appendToFile($scriptFilePath, sprintf('#!/bin/bash%s', \PHP_EOL));
        foreach ($env as $k => $v) {
            $filesystem->appendToFile($scriptFilePath, sprintf('export %s="%s"%s', $k, str_replace('"', '\\"', (string) $v), \PHP_EOL));
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

        $this->log($backup, Log::LOG_INFO, sprintf('Run `%s`', $command));
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

        $this->log($backup, Log::LOG_INFO, sprintf('Run `%s`', $command));

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
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', self::class, __FUNCTION__));

        $env = $backup->getBackupConfiguration()->getStorage()->getEnv() + $backup->getBackupConfiguration()->getResticEnv();

        switch ($backup->getBackupConfiguration()->getType()) {
            case BackupConfiguration::TYPE_OS_INSTANCE:
                $command = 'restic backup --tag project="${PROJECT}" --tag instance="${INSTANCE}" --tag configuration="${CONFIGURATION}" --host cloudbackup "${DIRECTORY}"';
                $parameters = [
                    'PROJECT' => $backup->getBackupConfiguration()->getOsInstance()->getOSProject()->getSlug(),
                    'INSTANCE' => $backup->getBackupConfiguration()->getOsInstance()->getSlug(),
                    'CONFIGURATION' => $backup->getBackupConfiguration()->getName(),
                    'DIRECTORY' => $this->getTemporaryBackupDestination($backup),
                ];

                $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, $this->logParameters($parameters)));
                $process = Process::fromShellCommandline($command, null, $env + $parameters);
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
                $command = 'restic backup --tag host="${HOST}" --tag configuration="${CONFIGURATION}" --host cloudbackup "${DIRECTORY}"';
                $parameters = [
                    'HOST' => null !== $backup->getBackupConfiguration()->getHost() ? $backup->getBackupConfiguration()->getHost()->getSlug() : 'direct',
                    'CONFIGURATION' => $backup->getBackupConfiguration()->getName(),
                    'DIRECTORY' => $this->getTemporaryBackupDestination($backup),
                ];

                $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, $this->logParameters($parameters)));
                $process = Process::fromShellCommandline($command, null, $env + $parameters);
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
                $command = 'restic backup --tag host="${HOST}" --tag configuration="${CONFIGURATION}" --host cloudbackup "${DIRECTORY}"';
                $parameters = [
                    'HOST' => $backup->getBackupConfiguration()->getHost()->getSlug(),
                    'CONFIGURATION' => $backup->getBackupConfiguration()->getName(),
                    'DIRECTORY' => $this->getTemporaryBackupDestination($backup),
                ];

                $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, $this->logParameters($parameters)));
                $process = Process::fromShellCommandline($command, null, $env + $parameters);
                $process->setTimeout(self::RESTIC_UPLOAD_TIMEOUT);
                $process->run();

                if (!$process->isSuccessful()) {
                    $this->log($backup, Log::LOG_ERROR, sprintf('Error executing backup - restic upload - %s', $process->getErrorOutput()));
                    throw new ProcessFailedException($process);
                } else {
                    $this->log($backup, Log::LOG_INFO, $process->getOutput());
                }
                break;
            case BackupConfiguration::TYPE_RCLONE:
                $filesystem = new Filesystem();
                $configFile = $filesystem->tempnam('/tmp', 'key_');
                $filesystem->appendToFile($configFile, $backup->getBackupConfiguration()->getCompleteRcloneConfiguration());

                $daily_backup_dir = sprintf('%s/%s', $backup->getBackupConfiguration()->getRcloneBackupDir(), date('Y-m-d'));
                $command = 'rclone sync "${SOURCE_LOCATION}" "${REMOTE_STORAGE_LOCATION}" --backup-dir "${REMOTE_STORAGE_BACKUP}" --config "${RCLONE_CONFIG}" ${RCLONE_FLAGS}';
                $parameters = [
                    'SOURCE_LOCATION' => $backup->getBackupConfiguration()->getRemotePath(),
                    'REMOTE_STORAGE_LOCATION' => $backup->getBackupConfiguration()->getStorageSubPath(),
                    'REMOTE_STORAGE_BACKUP' => $daily_backup_dir,
                    'RCLONE_CONFIG' => $configFile,
                    'RCLONE_FLAGS' => $backup->getBackupConfiguration()->getRcloneFlags(),
                ];

                $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, nl2br($this->logParameters($parameters))));
                $process = Process::fromShellCommandline($command, null, $parameters);
                $process->setTimeout(self::RCLONE_UPLOAD_TIMEOUT);
                $process->run();

                if (!$process->isSuccessful()) {
                    if ($backup->getBackupConfiguration()->getStdErrIgnore()) {
                        if ('' === preg_replace('/^\s+/m', '', preg_replace($backup->getBackupConfiguration()->getStdErrIgnore(), '', $process->getErrorOutput()))) {
                            $this->log($backup, Log::LOG_WARNING, sprintf('Warning executing backup - rclone sync - %s', $process->getErrorOutput()));

                            break;
                        }
                    }

                    $this->log($backup, Log::LOG_ERROR, sprintf('Error executing backup - rclone sync - %s', $process->getErrorOutput()));
                    throw new ProcessFailedException($process);
                } else {
                    $this->log($backup, Log::LOG_INFO, $process->getOutput());
                }
                break;
            case BackupConfiguration::TYPE_SSH_RESTIC:
                $this->uploadBackupSSHRestic($backup);
                break;
            default:
                $this->log($backup, Log::LOG_INFO, sprintf('%s : Nothing to do', $backup->getCurrentPlace()));
                break;
        }
    }

    public function cleanBackupRestic(Backup $backup): void
    {
        $env = $backup->getBackupConfiguration()->getStorage()->getEnv() + $backup->getBackupConfiguration()->getResticEnv();

        $command = sprintf(
            'restic forget --prune %s',
            $backup->getBackupConfiguration()->getResticForgetArgs()
        );

        $this->log($backup, Log::LOG_INFO, sprintf('Run `%s`', $command));
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

    public function cleanBackupRclone(Backup $backup): void
    {
        if (null === $backup->getBackupConfiguration()->getRcloneBackupDir() || '' === $backup->getBackupConfiguration()->getRcloneBackupDir()) {
            return;
        }

        $filesystem = new Filesystem();
        $configFile = $filesystem->tempnam('/tmp', 'key_');
        $filesystem->appendToFile($configFile, $backup->getBackupConfiguration()->getStorage()->getRcloneConfiguration());

        $backup_parent_directory = dirname($backup->getBackupConfiguration()->getRcloneBackupDir());
        $backup_direcory = str_replace($backup_parent_directory.'/', '', $backup->getBackupConfiguration()->getRcloneBackupDir());

        $command = 'rclone lsd "${REMOTE_STORAGE_DIRECTORY}" --config "${RCLONE_CONFIG}"';
        $parameters = [
            'REMOTE_STORAGE_DIRECTORY' => $backup_parent_directory,
            'RCLONE_CONFIG' => $configFile,
        ];

        $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, $this->logParameters($parameters)));

        $process = Process::fromShellCommandline($command, null, $parameters);
        $process->setTimeout(self::RESTIC_UPLOAD_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing backup - rclone lsd  - %s', $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        } else {
            $this->log($backup, Log::LOG_INFO, $process->getOutput());
            if (!preg_match('/\s'.$backup_direcory.'\n/', $process->getOutput())) {
                $this->log($backup, Log::LOG_INFO, sprintf('INFO executing backup %s does not seems to exists', $backup->getBackupConfiguration()->getRcloneBackupDir()));

                return;
            }
        }

        $command = 'rclone delete --min-age "${KEEP_DAILY}d" "${REMOTE_STORAGE_BACKUP}" --config "${RCLONE_CONFIG}"';
        $parameters = [
            'KEEP_DAILY' => $backup->getBackupConfiguration()->getKeepDaily(),
            'REMOTE_STORAGE_BACKUP' => $backup->getBackupConfiguration()->getRcloneBackupDir(),
            'RCLONE_CONFIG' => $configFile,
        ];

        $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, $this->logParameters($parameters)));

        $process = Process::fromShellCommandline($command, null, $parameters);
        $process->setTimeout(self::RESTIC_UPLOAD_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing backup - rclone delete  - %s', $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        } else {
            $this->log($backup, Log::LOG_INFO, $process->getOutput());
        }

        $command = 'rclone rmdirs "${REMOTE_STORAGE_BACKUP}" --config "${RCLONE_CONFIG}"';
        $parameters = [
            'REMOTE_STORAGE_BACKUP' => $backup->getBackupConfiguration()->getRcloneBackupDir(),
            'RCLONE_CONFIG' => $configFile,
        ];

        $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, $this->logParameters($parameters)));

        $process = Process::fromShellCommandline($command, null, $parameters);
        $process->setTimeout(self::RESTIC_UPLOAD_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing backup - rclone rmdirs  - %s', $process->getErrorOutput()));
            throw new ProcessFailedException($process);
        } else {
            $this->log($backup, Log::LOG_INFO, $process->getOutput());
        }
    }

    private function cleanBackupFUSE(Backup $backup): void
    {
        if ($this->checkDownloadedFUSE($backup)) {
            $command = 'fusermount -u "${DIRECTORY}"';
            $parameters = [
                'DIRECTORY' => $this->getTemporaryBackupDestination($backup),
            ];

            $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, $this->logParameters($parameters)));
            $process = Process::fromShellCommandline($command, null, $parameters);
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
        if (2 !== (is_countable(scandir($this->getTemporaryBackupDestination($backup))) ? \count(scandir($this->getTemporaryBackupDestination($backup))) : 0)) {
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
            $command = 'openstack image delete ${OS_IMAGE_ID}';
            $parameters = [
                'OS_IMAGE_ID' => $backup->getOsImageId(),
            ];

            $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, $this->logParameters($parameters)));
            $process = Process::fromShellCommandline($command, null, $parameters + $backup->getBackupConfiguration()->getOsInstance()->getOSEnv());
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
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', self::class, __FUNCTION__));

        $filesystem = new Filesystem();

        if (null !== $backup->getBackupConfiguration()->getHost()->getPrivateKey()) {
            $privateKeypath = $filesystem->tempnam('/tmp', 'key_');
            $filesystem->appendToFile($privateKeypath, str_replace("\r", '', $backup->getBackupConfiguration()->getHost()->getPrivateKey()."\n"));
            $privateKeyString = '-i ${PRIVATE_KEY_PATH}';
        }

        if (null !== $backup->getBackupConfiguration()->getHost()->getPassword()) {
            $sshpass = 'sshpass -p ${SSHPASS}';
        }

        $command = sprintf(
            '%s ssh "${LOGIN}@${IP}" -p "${PORT}" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s "${REMOTE_CLEAN_COMMAND}"',
            $sshpass ?? null,
            $privateKeyString ?? null
        );
        $parameters = [
            'SSHPASS' => $backup->getBackupConfiguration()->getHost()->getPassword(),
            'LOGIN' => $backup->getBackupConfiguration()->getHost()->getLogin(),
            'IP' => $backup->getBackupConfiguration()->getHost()->getIp(),
            'PORT' => $backup->getBackupConfiguration()->getHost()->getPort() ?? 22,
            'PRIVATE_KEY_PATH' => $privateKeypath ?? null,
            'REMOTE_CLEAN_COMMAND' => $backup->getBackupConfiguration()->getRemoteCleanCommand(),
        ];

        $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, $this->logParameters($parameters)));
        $process = Process::fromShellCommandline($command, null, $parameters);
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
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', self::class, __FUNCTION__));

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
                    $this->cleanBackupFUSE($backup);
                    break;
            }
        }

        // Remove OS image
        if (BackupConfiguration::TYPE_OS_INSTANCE === $backup->getBackupConfiguration()->getType()) {
            $this->cleanBackupOsInstance($backup);
        }

        // Execute remote clean command
        if (BackupConfiguration::TYPE_SSH_CMD === $backup->getBackupConfiguration()->getType() && (null !== $backup->getBackupConfiguration()->getRemoteCleanCommand() && '' !== $backup->getBackupConfiguration()->getRemoteCleanCommand())) {
            $this->cleanRemoteByCommand($backup);
        }
    }

    public function isBackupCleaned(Backup $backup): bool
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', self::class, __FUNCTION__));

        switch ($backup->getBackupConfiguration()->getType()) {
            case BackupConfiguration::TYPE_OS_INSTANCE:
                if (null !== $this->getSnapshotOsInstanceStatus($backup)) {
                    return false;
                }

                if (file_exists($this->getTemporaryBackupDestination($backup))) {
                    $this->log($backup, Log::LOG_NOTICE, sprintf('Backup location does exists - %s', $this->getTemporaryBackupDestination($backup)));

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
                    $this->log($backup, Log::LOG_NOTICE, sprintf('Backup location does exists - %s', $this->getTemporaryBackupDestination($backup)));

                    return false;
                }
                break;
        }

        return true;
    }

    public function healhCheckBackup(Backup $backup, bool $tryRepair = true): void
    {
        switch ($backup->getBackupConfiguration()->getStorage()->getType()) {
            case Storage::TYPE_RESTIC:
                $env = $backup->getBackupConfiguration()->getStorage()->getEnv() + $backup->getBackupConfiguration()->getResticEnv();

                $command = 'restic check';

                $this->log($backup, Log::LOG_INFO, sprintf('Run `%s`', $command));
                $process = Process::fromShellCommandline($command, null, $env);
                $process->setTimeout(self::RESTIC_CHECK_TIMEOUT);
                $process->run();

                if (!$process->isSuccessful()) {
                    $this->log($backup, Log::LOG_ERROR, sprintf('Error executing %s::%s - %s - %s', self::class, __FUNCTION__, $command, $process->getErrorOutput()));

                    if ($tryRepair) {
                        $this->repairBackup($backup);
                        $this->healhCheckBackup($backup, false);
                    } else {
                        throw new ProcessFailedException($process);
                    }
                } else {
                    $this->log($backup, Log::LOG_INFO, $process->getOutput());
                }

                $command = 'restic snapshots --json --latest 1 -q';

                $this->log($backup, Log::LOG_INFO, sprintf('Run `%s`', $command));
                $process = Process::fromShellCommandline($command, null, $env);
                $process->setTimeout(self::RESTIC_CHECK_TIMEOUT);
                $process->run();

                if (!$process->isSuccessful()) {
                    $this->log($backup, Log::LOG_ERROR, sprintf('Error executing %s::%s - %s - %s', self::class, __FUNCTION__, $command, $process->getErrorOutput()));
                    throw new ProcessFailedException($process);
                } else {
                    if (($json = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR)) === null || !(is_countable($json) ? \count($json) : 0)) {
                        $message = sprintf('Cannot decode json : %s', $process->getOutput());
                        $this->log($backup, Log::LOG_ERROR, $message);
                        throw new Exception($message);
                    }

                    $prettyJson = json_encode($json, \JSON_PRETTY_PRINT);
                    $this->log($backup, Log::LOG_INFO, $prettyJson);

                    $lastBackup = new DateTime(preg_replace('/(\d+\-\d+\-\d+T\d+:\d+:\d+)\..*/', '$1', (string) end($json)['time']));
                    $this->log($backup, Log::LOG_NOTICE, sprintf('Last backup : %s', $lastBackup->format('d/m/Y H:i')));

                    $yesterday = new DateTime('yesterday');
                    if ($lastBackup < $yesterday) {
                        $message = 'Last backup older than 24h';
                        $this->log($backup, Log::LOG_ERROR, $message);
                        throw new Exception($message);
                    }
                }

                $sizes = [
                    '--mode restore-size latest' => 'resticSize',
                    '--mode raw-data latest' => 'resticDedupSize',
                    '--mode restore-size' => 'resticTotalSize',
                    '--mode raw-data' => 'resticTotalDedupSize',
                ];

                foreach ($sizes as $resticCommandSuffix => $backupAttribute) {
                    $command = sprintf('restic stats --json %s', $resticCommandSuffix);

                    $this->log($backup, Log::LOG_INFO, sprintf('Run `%s`', $command));
                    $process = Process::fromShellCommandline($command, null, $env);
                    $process->setTimeout(self::RESTIC_CHECK_TIMEOUT);
                    $process->run();

                    if (!$process->isSuccessful()) {
                        $this->log($backup, Log::LOG_ERROR, sprintf('Error executing %s::%s - %s - %s', self::class, __FUNCTION__, $command, $process->getErrorOutput()));
                        throw new ProcessFailedException($process);
                    } else {
                        if (($json = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR)) === null) {
                            $message = sprintf('Cannot decode json : %s', $process->getOutput());
                            $this->log($backup, Log::LOG_ERROR, $message);
                            throw new Exception($message);
                        }

                        $prettyJson = json_encode($json, \JSON_PRETTY_PRINT);
                        $this->log($backup, Log::LOG_INFO, $prettyJson);

                        $accessor = PropertyAccess::createPropertyAccessor();
                        $accessor->setValue($backup, $backupAttribute, $json['total_size']);

                        $this->log($backup, Log::LOG_NOTICE, sprintf('Stat %s : %s', $backupAttribute, StringUtils::humanizeFileSize($json['total_size'])));
                    }
                }

                if (null === $backup->getSize()) {
                    $backup->setSize($backup->getResticSize());
                }

                break;
            case Storage::TYPE_RCLONE:
                $filesystem = new Filesystem();
                $configFile = $filesystem->tempnam('/tmp', 'key_');
                $filesystem->appendToFile($configFile, $backup->getBackupConfiguration()->getCompleteRcloneConfiguration());
                $env = $backup->getBackupConfiguration()->getStorage()->getEnv() + $backup->getBackupConfiguration()->getResticEnv();

                $isCrypt = preg_match('/^\s*type\s*=\s*crypt\s*$/m', $backup->getBackupConfiguration()->getCompleteRcloneConfiguration());

                $command = 'rclone "${CHECK}" "${SOURCE_LOCATION}" "${REMOTE_STORAGE_LOCATION}" --config "${RCLONE_CONFIG}"';
                $parameters = [
                    'CHECK' => $isCrypt ? 'cryptcheck' : 'check',
                    'SOURCE_LOCATION' => $backup->getBackupConfiguration()->getRemotePath(),
                    'REMOTE_STORAGE_LOCATION' => $backup->getBackupConfiguration()->getStorageSubPath(),
                    'RCLONE_CONFIG' => $configFile,
                ];

                $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, nl2br($this->logParameters($parameters))));
                $process = Process::fromShellCommandline($command, null, $parameters);

                $process->setTimeout(self::RCLONE_CHECK_TIMEOUT);
                $process->run();

                if (!$process->isSuccessful()) {
                    $this->log($backup, Log::LOG_ERROR, sprintf('Error executing %s::%s - %s - %s', self::class, __FUNCTION__, $command, $process->getErrorOutput()));
                    throw new ProcessFailedException($process);
                } else {
                    $this->log($backup, Log::LOG_INFO, $process->getOutput());
                }

                $command = 'rclone size "${REMOTE_STORAGE_LOCATION}" --config "${RCLONE_CONFIG}" --json';
                $parameters = [
                    'REMOTE_STORAGE_LOCATION' => $backup->getBackupConfiguration()->getStorageSubPath(),
                    'RCLONE_CONFIG' => $configFile,
                ];

                $this->log($backup, Log::LOG_INFO, sprintf('Run `%s` with %s', $command, $this->logParameters($parameters)));
                $process = Process::fromShellCommandline($command, null, $parameters);
                $process->setTimeout(self::RCLONE_CHECK_TIMEOUT);
                $process->run();

                if (!$process->isSuccessful()) {
                    $this->log($backup, Log::LOG_ERROR, sprintf('Error executing backup - rclone size - %s', $process->getErrorOutput()));
                    throw new ProcessFailedException($process);
                } else {
                    $this->log($backup, Log::LOG_INFO, $process->getOutput());
                }

                $output = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                if (null === $output) {
                    $this->log($backup, Log::LOG_ERROR, sprintf('Error executing rclone size - %s - %s', $process->getOutput(), $process->getErrorOutput()));
                    throw new ProcessFailedException($process);
                }

                $backup->setSize($output['bytes']);

                if ($output['bytes'] <= $backup->getBackupConfiguration()->getMinimumBackupSize()) {
                    $message = sprintf('Rclone failed. Minimum backup size not met : %s < %s', StringUtils::humanizeFileSize($output['bytes']), StringUtils::humanizeFileSize($backup->getBackupConfiguration()->getMinimumBackupSize()));
                    $this->log($backup, Log::LOG_NOTICE, $message);
                    throw new Exception($message);
                }
                break;
        }
    }

    public function repairBackup(Backup $backup): void
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', self::class, __FUNCTION__));

        $env = $backup->getBackupConfiguration()->getStorage()->getEnv() + $backup->getBackupConfiguration()->getResticEnv();

        $commands = [
            'restic repair index',
            'restic prune',
            'restic check',
        ];

        foreach ($commands as $command) {
            $this->log($backup, Log::LOG_INFO, sprintf('Run `%s`', $command));
            $process = Process::fromShellCommandline($command, null, $env);
            $process->setTimeout(self::RESTIC_REPAIR_TIMEOUT);
            $process->run();

            if (!$process->isSuccessful()) {
                $this->log($backup, Log::LOG_ERROR, sprintf('Error executing %s::%s - %s - %s', self::class, __FUNCTION__, $command, $process->getErrorOutput()));
                throw new ProcessFailedException($process);
            } else {
                $this->log($backup, Log::LOG_INFO, $process->getOutput());
            }
        }
    }

    public function resticInitRepo(Backup $backup): void
    {
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', self::class, __FUNCTION__));

        $env = $backup->getBackupConfiguration()->getStorage()->getEnv() + $backup->getBackupConfiguration()->getResticEnv();

        $command = 'restic init';

        $this->log($backup, Log::LOG_INFO, sprintf('Run `%s`', $command));
        $process = Process::fromShellCommandline($command, null, $env);
        $process->setTimeout(self::RESTIC_INIT_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful() && !preg_match(self::RESTIC_INIT_REGEX, $process->getErrorOutput())) {
            $this->log($backup, Log::LOG_ERROR, sprintf('Error executing %s::%s - %s - %s', self::class, __FUNCTION__, $command, $process->getErrorOutput()));
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
        $dateTime = new DateTime();

        $backup = $this->backupRepository->findOneBy([
            'backupConfiguration' => $backupConfiguration,
        ], ['id' => 'DESC']);

        if (null === $backup) {
            $backup = new Backup();
            $backup->setBackupConfiguration($backupConfiguration);
        }

        $backupWorkflow = $this->workflowRegistry->get($backup);
        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s. CurrentState : %s', self::class, __FUNCTION__, $backup->getCurrentPlace()));
        if ('backuped' === $backup->getCurrentPlace()) {
            if ($backup->getCreatedAt()->format('Y-m-d') === $dateTime->format('Y-m-d') && BackupConfiguration::PERIODICITY_DAILY === $backupConfiguration->getPeriodicity()) {
                return;
            } else {
                $backup = new Backup();
                $backup->setBackupConfiguration($backupConfiguration);
            }
        } elseif ('initialized' !== $backup->getCurrentPlace()) {
            $this->log($backup, Log::LOG_NOTICE, sprintf('Resume backup with current state %s', $backup->getCurrentPlace()));
            if ($backup->getCreatedAt()->format('Y-m-d') !== $dateTime->format('Y-m-d') && BackupConfiguration::PERIODICITY_DAILY === $backupConfiguration->getPeriodicity()) {
                $this->log($backup, Log::LOG_NOTICE, 'Backup is not from today, force fail it');
                if ('failed' !== $backup->getCurrentPlace()) {
                    $backupWorkflow->apply($backup, 'failed');

                    $this->entityManager->persist($backup);
                    $this->entityManager->flush();
                }

                $backup = new Backup();
                $backup->setBackupConfiguration($backupConfiguration);
            } else {
                $this->log($backup, Log::LOG_INFO, sprintf('Resume backup with current state %s', $backup->getCurrentPlace()));
            }
        }

        try {
            $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s', self::class, __FUNCTION__));

            if ($backupWorkflow->can($backup, 'start')) {
                $backupWorkflow->apply($backup, 'start');
            }

            // Some backup types can go through start to upload without dump and download
            if ($backupWorkflow->can($backup, 'upload')) {
                $backupWorkflow->apply($backup, 'upload');
            } elseif ($backupWorkflow->can($backup, 'dump')) {
                $backupWorkflow->apply($backup, 'dump');
            }
        } catch (Exception $e) {
            $this->log($backup, Log::LOG_NOTICE, sprintf('An error occured : %s', $e->getMessage()));

            if ($backupWorkflow->can($backup, 'failed')) {
                $backupWorkflow->apply($backup, 'failed');
            }
        }

        $this->entityManager->persist($backup);
        $this->entityManager->flush();
    }

    public function performBackup(BackupConfiguration $backupConfiguration): void
    {
        $backup = $this->backupRepository->findOneBy([
            'backupConfiguration' => $backupConfiguration,
        ], ['id' => 'DESC']);

        if (null === $backup) {
            throw new Exception(sprintf('No backup found: %s', $backupConfiguration->getName()));
        }

        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s. CurrentState : %s', self::class, __FUNCTION__, $backup->getCurrentPlace()));

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
        } catch (Exception $e) {
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

        $this->log($backup, Log::LOG_NOTICE, sprintf('call %s::%s. CurrentState : %s', self::class, __FUNCTION__, $backup->getCurrentPlace()));

        $backupWorkflow = $this->workflowRegistry->get($backup);

        try {
            if ($backupWorkflow->can($backup, 'health_check')) {
                $backupWorkflow->apply($backup, 'health_check');
            }

            if ($backupWorkflow->can($backup, 'forget')) {
                $backupWorkflow->apply($backup, 'forget');
            }

            if ($backupWorkflow->can($backup, 'backuped')) {
                $backupWorkflow->apply($backup, 'backuped');
            }
        } catch (Exception $e) {
            $this->log($backup, Log::LOG_NOTICE, sprintf('An error occured : %s', $e->getMessage()));

            if ($backupWorkflow->can($backup, 'failed')) {
                $backupWorkflow->apply($backup, 'failed');
            }
        }

        $this->entityManager->persist($backup);
        $this->entityManager->flush();
    }
}
