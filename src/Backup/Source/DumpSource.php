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
use App\Utils\StringUtils;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.backup.source')]
final class DumpSource extends AbstractBackupSource
{
    public const int DUMP_TIMEOUT = 3600 * 4;
    public const array SUPPORTED_TYPES = [
        BackupConfiguration::TYPE_MYSQL,
        BackupConfiguration::TYPE_POSTGRESQL,
        BackupConfiguration::TYPE_SQL_SERVER,
        BackupConfiguration::TYPE_SSH_CMD,
    ];

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
        return \in_array($backupConfiguration->getType(), self::SUPPORTED_TYPES, true);
    }

    public function download(Backup $backup): void
    {
        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('call %s::%s', self::class, __FUNCTION__));

        $backupDestination = $this->pathResolver->resolve($backup);
        $configuration = $backup->getBackupConfiguration();
        $host = $configuration->getHost();
        $privateKeyPath = null;

        try {
            if (null !== $host) {
                $privateKeyPath = null !== $host->getPrivateKey() ? $this->sshKeyMaterializer->writeTempKey((string) $host->getPrivateKey()) : null;
                $privateKeyString = null !== $privateKeyPath ? '-i ${PRIVATE_KEY_PATH}' : null;
                $sshpass = null !== $host->getPassword() ? 'sshpass -p ${SSHPASS}' : null;
                $sshOptions = $this->sshOptionsBuilder->build($host);

                $command = \sprintf(
                    '%s ssh "${LOGIN}@${IP}" -p "${PORT}" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null%s %s "${DUMP_COMMAND} | gzip -9" | gunzip > "${DESTINATION}"',
                    $sshpass,
                    $sshOptions,
                    $privateKeyString
                );
                $parameters = [
                    'SSHPASS' => $host->getPassword(),
                    'LOGIN' => $host->getLogin(),
                    'IP' => $host->getIp(),
                    'PORT' => $host->getPort() ?? 22,
                    'PRIVATE_KEY_PATH' => $privateKeyPath,
                    'DUMP_COMMAND' => $configuration->getDumpCommand(),
                    'DESTINATION' => $backupDestination,
                ];
            } else {
                $command = 'sh -c "${DUMP_COMMAND}" > "${DESTINATION}"';
                $parameters = [
                    'DUMP_COMMAND' => $configuration->getDumpCommand(),
                    'DESTINATION' => $backupDestination,
                ];
            }

            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, $this->backupLogger->formatParameters($parameters)));
            $outcome = $this->processRunner->runShell($command, $parameters, self::DUMP_TIMEOUT);

            if (!$outcome->successful) {
                $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing download - exec dump command - %s', $outcome->errorOutput));
                throw new ProcessExecutionException($outcome);
            }
            $this->backupLogger->log($backup, Log::LOG_INFO, $outcome->output);

            $backup->setSize((int) filesize($backupDestination));
            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Backup size : %s', StringUtils::humanizeFileSize($backup->getSize())));
            $this->backupLogger->log($backup, Log::LOG_NOTICE, 'Dump done');
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

        $host = $backup->getBackupConfiguration()->getHost();
        $tags = [
            'host' => null !== $host ? $host->getSlug() : 'direct',
            'configuration' => $backup->getBackupConfiguration()->getName(),
        ];

        $backend->uploadLocal($backup, $this->pathResolver->resolve($backup), $tags);
    }

    public function cleanupLocal(Backup $backup): void
    {
        $path = $this->pathResolver->resolve($backup);
        if (file_exists($path)) {
            $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('Remove local file - %s', $path));
            unlink($path);
        }

        $remoteCleanCommand = $backup->getBackupConfiguration()->getRemoteCleanCommand();
        if (BackupConfiguration::TYPE_SSH_CMD === $backup->getBackupConfiguration()->getType()
            && null !== $remoteCleanCommand && '' !== $remoteCleanCommand
        ) {
            $this->executeRemoteCleanCommand($backup);
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

    private function executeRemoteCleanCommand(Backup $backup): void
    {
        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('call %s::%s', self::class, __FUNCTION__));

        $host = $backup->getBackupConfiguration()->getHost();
        if (null === $host) {
            $message = 'Remote cleanup requires a host on the backup configuration';
            $this->backupLogger->log($backup, Log::LOG_ERROR, $message);
            throw new InvalidArgumentException($message);
        }

        $privateKeyPath = null;

        try {
            $privateKeyPath = null !== $host->getPrivateKey() ? $this->sshKeyMaterializer->writeTempKey((string) $host->getPrivateKey()) : null;
            $privateKeyString = null !== $privateKeyPath ? '-i ${PRIVATE_KEY_PATH}' : null;
            $sshpass = null !== $host->getPassword() ? 'sshpass -p ${SSHPASS}' : null;
            $sshOptions = $this->sshOptionsBuilder->build($host);

            $command = \sprintf(
                '%s ssh "${LOGIN}@${IP}" -p "${PORT}" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null%s %s "${REMOTE_CLEAN_COMMAND}"',
                $sshpass,
                $sshOptions,
                $privateKeyString
            );
            $parameters = [
                'SSHPASS' => $host->getPassword(),
                'LOGIN' => $host->getLogin(),
                'IP' => $host->getIp(),
                'PORT' => $host->getPort() ?? 22,
                'PRIVATE_KEY_PATH' => $privateKeyPath,
                'REMOTE_CLEAN_COMMAND' => $backup->getBackupConfiguration()->getRemoteCleanCommand(),
            ];

            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, $this->backupLogger->formatParameters($parameters)));
            $outcome = $this->processRunner->runShell($command, $parameters, self::DUMP_TIMEOUT);
            $this->failOrLog($backup, $outcome, 'remote cleanup command');

            $this->backupLogger->log($backup, Log::LOG_NOTICE, 'Remote cleanup done');
        } finally {
            $this->sshKeyMaterializer->cleanup($privateKeyPath);
        }
    }
}
