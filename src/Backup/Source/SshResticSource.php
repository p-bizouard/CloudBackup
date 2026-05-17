<?php

declare(strict_types=1);

namespace App\Backup\Source;

use App\Backup\Logging\BackupLogger;
use App\Backup\Path\TemporaryPathResolver;
use App\Backup\Process\ProcessExecutionException;
use App\Backup\Process\ProcessRunnerInterface;
use App\Backup\Ssh\SshKeyMaterializer;
use App\Backup\Ssh\SshOptionsBuilder;
use App\Backup\Storage\ResticStorageBackend;
use App\Backup\Storage\StorageBackendRegistry;
use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\Log;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

#[AutoconfigureTag('app.backup.source')]
final class SshResticSource extends AbstractBackupSource
{
    public function __construct(
        ProcessRunnerInterface $processRunner,
        BackupLogger $backupLogger,
        TemporaryPathResolver $temporaryPathResolver,
        StorageBackendRegistry $storageBackendRegistry,
        private readonly SshOptionsBuilder $sshOptionsBuilder,
        private readonly SshKeyMaterializer $sshKeyMaterializer,
    ) {
        parent::__construct($processRunner, $backupLogger, $temporaryPathResolver, $storageBackendRegistry);
    }

    public function supports(BackupConfiguration $backupConfiguration): bool
    {
        return BackupConfiguration::TYPE_SSH_RESTIC === $backupConfiguration->getType();
    }

    public function download(Backup $backup): void
    {
        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('%s : Nothing to do', $backup->getCurrentPlace()));
    }

    public function upload(Backup $backup): void
    {
        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('call %s::%s', self::class, __FUNCTION__));

        $configuration = $backup->getBackupConfiguration();
        $host = $configuration->getHost();

        if (null === $host || null === $host->getPrivateKey()) {
            throw new InvalidArgumentException('SSH restic upload requires a host with a private key.');
        }

        $env = $configuration->getStorage()->getEnv() + $configuration->getResticEnv();

        $filesystem = new Filesystem();
        $privateKeyPath = null;
        $scriptFilePath = null;

        try {
            $privateKeyPath = $this->sshKeyMaterializer->writeTempKey($host->getPrivateKey());

            $scriptFilePath = $filesystem->tempnam('/tmp', 'env_');
            $filesystem->appendToFile($scriptFilePath, \sprintf('#!/bin/bash%s', \PHP_EOL));
            foreach ($env as $k => $v) {
                $filesystem->appendToFile($scriptFilePath, \sprintf('export %s=%s%s', $k, escapeshellarg((string) $v), \PHP_EOL));
            }
            $filesystem->appendToFile($scriptFilePath, \sprintf(
                'restic backup --tag host=%s --tag configuration=%s --host cloudbackup %s || exit 1%s',
                $configuration->getHost()->getSlug(),
                $configuration->getSlug(),
                $configuration->getRemotePath(),
                \PHP_EOL
            ));

            $scpCommand = \sprintf(
                'scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o IdentityFile=%s %s %s@%s:%s',
                $privateKeyPath,
                $scriptFilePath,
                $configuration->getHost()->getLogin(),
                $configuration->getHost()->getIp(),
                $scriptFilePath,
            );

            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s`', $scpCommand));
            $scpOutcome = $this->processRunner->runShell($scpCommand, [], ResticStorageBackend::UPLOAD_TIMEOUT);
            if (!$scpOutcome->successful) {
                $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing backup - ssh restic scp - %s', $scpOutcome->errorOutput));
                throw new ProcessExecutionException($scpOutcome);
            }

            $sshOptions = $this->sshOptionsBuilder->build($configuration->getHost());

            $execCommand = \sprintf(
                'ssh %s@%s -p %d -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null%s -o IdentityFile=%s "%s"',
                $configuration->getHost()->getLogin(),
                $configuration->getHost()->getIp(),
                $configuration->getHost()->getPort() ?? 22,
                $sshOptions,
                $privateKeyPath,
                \sprintf('sudo chmod 700 %s && sudo chown root:root %s && sudo %s', $scriptFilePath, $scriptFilePath, $scriptFilePath)
            );

            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s`', $execCommand));
            $execOutcome = $this->processRunner->runShell($execCommand, [], ResticStorageBackend::UPLOAD_TIMEOUT);

            try {
                $this->removeRemoteScript($backup, $privateKeyPath, $scriptFilePath);
            } catch (Throwable $cleanupError) {
                $this->backupLogger->log($backup, Log::LOG_WARNING, \sprintf('Remote script cleanup failed (continuing): %s', $cleanupError->getMessage()));
            }

            if (!$execOutcome->successful) {
                $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing backup - ssh restic upload - %s', $execOutcome->errorOutput));
                throw new ProcessExecutionException($execOutcome);
            }
            $this->backupLogger->log($backup, Log::LOG_INFO, $execOutcome->output);
        } finally {
            $this->sshKeyMaterializer->cleanup($privateKeyPath);
            if (null !== $scriptFilePath) {
                @unlink($scriptFilePath);
            }
        }
    }

    public function cleanupLocal(Backup $backup): void
    {
        // ssh-restic uploads happen entirely on the remote host; no local artifact to clean.
    }

    private function removeRemoteScript(Backup $backup, string $privateKeyPath, string $scriptFilePath): void
    {
        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('call %s::%s', self::class, __FUNCTION__));

        $configuration = $backup->getBackupConfiguration();
        $sshOptions = $this->sshOptionsBuilder->build($configuration->getHost());

        $command = \sprintf(
            'ssh %s@%s -p %d -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null%s -o IdentityFile=%s "sudo rm -f %s"',
            $configuration->getHost()->getLogin(),
            $configuration->getHost()->getIp(),
            $configuration->getHost()->getPort() ?? 22,
            $sshOptions,
            $privateKeyPath,
            $scriptFilePath,
        );

        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s`', $command));
        $outcome = $this->processRunner->runShell($command, [], ResticStorageBackend::UPLOAD_TIMEOUT);

        if (!$outcome->successful) {
            $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing backup - ssh restic remove script - %s', $outcome->errorOutput));
            throw new ProcessExecutionException($outcome);
        }
        $this->backupLogger->log($backup, Log::LOG_INFO, $outcome->output);
    }
}
