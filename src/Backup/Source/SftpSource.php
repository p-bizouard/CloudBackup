<?php

declare(strict_types=1);

namespace App\Backup\Source;

use App\Backup\Logging\BackupLogger;
use App\Backup\Path\TemporaryPathResolver;
use App\Backup\Process\ProcessExecutionException;
use App\Backup\Process\ProcessRunnerInterface;
use App\Backup\Ssh\SshKeyMaterializer;
use App\Backup\Storage\ResticStorageBackend;
use App\Backup\Storage\StorageBackendRegistry;
use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\Log;
use App\Utils\StringUtils;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Filesystem\Filesystem;

#[AutoconfigureTag('app.backup.source')]
final class SftpSource extends AbstractBackupSource
{
    public const int MOUNT_TIMEOUT = 60;
    public const int DOWNLOAD_SIZE_TIMEOUT = 60 * 10;

    public function __construct(
        ProcessRunnerInterface $processRunner,
        BackupLogger $backupLogger,
        TemporaryPathResolver $temporaryPathResolver,
        StorageBackendRegistry $storageBackendRegistry,
        private readonly SshKeyMaterializer $sshKeyMaterializer,
    ) {
        parent::__construct($processRunner, $backupLogger, $temporaryPathResolver, $storageBackendRegistry);
    }

    public function supports(BackupConfiguration $backupConfiguration): bool
    {
        return BackupConfiguration::TYPE_SFTP === $backupConfiguration->getType();
    }

    public function download(Backup $backup): void
    {
        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('call %s::%s', self::class, __FUNCTION__));

        $host = $backup->getBackupConfiguration()->getHost();
        $privateKeyPath = null;

        try {
            $privateKeyPath = null !== $host->getPrivateKey() ? $this->sshKeyMaterializer->writeTempKey((string) $host->getPrivateKey()) : null;
            $privateKeyString = null !== $privateKeyPath ? '-i ${PRIVATE_KEY_PATH}' : null;

            $command = \sprintf(
                'sftp -P "${PORT}" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s "${LOGIN}@${IP}:${REMOTE_DIRECTORY}" "${BACKUP_DESTINATION}"',
                $privateKeyString
            );
            $parameters = [
                'PORT' => $host->getPort() ?? 22,
                'PRIVATE_KEY_PATH' => $privateKeyPath,
                'LOGIN' => $host->getLogin(),
                'IP' => $host->getIp(),
                'REMOTE_DIRECTORY' => $backup->getBackupConfiguration()->getRemotePath(),
                'BACKUP_DESTINATION' => $this->pathResolver->resolve($backup),
            ];

            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, $this->backupLogger->formatParameters($parameters)));
            $outcome = $this->processRunner->runShell($command, $parameters, self::MOUNT_TIMEOUT);

            if (!$outcome->successful) {
                $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing download - exec dump command - %s', $outcome->errorOutput));
                throw new ProcessExecutionException($outcome);
            }
            $this->backupLogger->log($backup, Log::LOG_INFO, $outcome->output);

            $this->backupLogger->log($backup, Log::LOG_NOTICE, 'Download done');

            $backup->setSize($this->measureSize($backup));
        } finally {
            $this->sshKeyMaterializer->cleanup($privateKeyPath);
        }
    }

    public function isDownloadComplete(Backup $backup): bool
    {
        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('call %s::%s', self::class, __FUNCTION__));

        $destination = $this->pathResolver->resolve($backup);
        $minimum = (int) $backup->getBackupConfiguration()->getMinimumBackupSize();

        if ($backup->getSize() >= $minimum) {
            $this->backupLogger->log($backup, Log::LOG_NOTICE, 'Backup downloaded');

            return true;
        }

        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('Backup not downloaded : %s < %s', StringUtils::humanizeFileSize(@filesize($destination) ?: 0), StringUtils::humanizeFileSize($minimum)));

        return false;
    }

    public function upload(Backup $backup): void
    {
        $backend = $this->storageBackends->forStorage($backup->getBackupConfiguration()->getStorage());
        if (!$backend instanceof ResticStorageBackend) {
            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('%s : Nothing to do', $backup->getCurrentPlace()));

            return;
        }

        $tags = [
            'host' => $backup->getBackupConfiguration()->getHost()->getSlug(),
            'configuration' => $backup->getBackupConfiguration()->getName(),
        ];

        $backend->uploadLocal($backup, $this->pathResolver->resolve($backup), $tags);
    }

    public function cleanupLocal(Backup $backup): void
    {
        if ($this->isDownloadComplete($backup)) {
            $path = $this->pathResolver->resolve($backup);
            $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('Remove local directory - %s', $path));
            new Filesystem()->remove($path);
        }
    }

    public function isLocallyCleaned(Backup $backup): bool
    {
        if (file_exists($this->pathResolver->resolve($backup))) {
            $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('Backup location does exists - %s', $this->pathResolver->resolve($backup)));

            return false;
        }

        return true;
    }

    private function measureSize(Backup $backup): int
    {
        $command = 'du -sb "${BACKUP_DESTINATION}" | cut -f1';
        $parameters = ['BACKUP_DESTINATION' => $this->pathResolver->resolve($backup)];

        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, $this->backupLogger->formatParameters($parameters)));
        $outcome = $this->processRunner->runShell($command, $parameters, self::DOWNLOAD_SIZE_TIMEOUT);

        if (!$outcome->successful) {
            $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error getting download size - %s', $outcome->errorOutput));
            throw new ProcessExecutionException($outcome);
        }
        $this->backupLogger->log($backup, Log::LOG_INFO, $outcome->output);

        return (int) $outcome->output;
    }
}
