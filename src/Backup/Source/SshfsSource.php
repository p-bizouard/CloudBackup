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
use Exception;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Filesystem\Filesystem;

#[AutoconfigureTag('app.backup.source')]
final class SshfsSource extends AbstractBackupSource
{
    public const int MOUNT_TIMEOUT = 60;
    public const int UMOUNT_TIMEOUT = 60;
    public const int CHECK_TIMEOUT = 3600 * 4;

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
        return BackupConfiguration::TYPE_SSHFS === $backupConfiguration->getType();
    }

    public function download(Backup $backup): void
    {
        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('call %s::%s', self::class, __FUNCTION__));

        if ($this->isMounted($backup)) {
            $this->backupLogger->log($backup, Log::LOG_NOTICE, 'Already mounted');

            return;
        }

        $filesystem = new Filesystem();
        $backupDestination = $this->pathResolver->resolve($backup);
        $filesystem->mkdir($backupDestination);

        $host = $backup->getBackupConfiguration()->getHost();
        $privateKeyPath = null;

        try {
            $privateKeyPath = null !== $host->getPrivateKey() ? $this->sshKeyMaterializer->writeTempKey((string) $host->getPrivateKey()) : null;
            $privateKeyString = null !== $privateKeyPath ? '-o IdentityFile="${PRIVATE_KEY_PATH}"' : null;
            $sshpass = null !== $host->getPassword() ? 'echo "${SSHPASS}" | ' : null;
            $sshOptions = $this->sshOptionsBuilder->build($host);

            $command = \sprintf(
                '%s sshfs "${LOGIN}@${IP}:${REMOTE_PATH}" "${DESTINATION}" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null%s %s -o "uid=%d,gid=%d" -o ro %s',
                $sshpass,
                $sshOptions,
                $privateKeyString,
                posix_getuid(),
                posix_getgid(),
                $backup->getBackupConfiguration()->getDumpCommand()
            );
            $parameters = [
                'SSHPASS' => $host->getPassword(),
                'LOGIN' => $host->getLogin(),
                'IP' => $host->getIp(),
                'REMOTE_PATH' => $backup->getBackupConfiguration()->getRemotePath(),
                'DESTINATION' => $backupDestination,
                'PRIVATE_KEY_PATH' => $privateKeyPath,
            ];

            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, $this->backupLogger->formatParameters($parameters)));
            $outcome = $this->processRunner->runShell($command, $parameters, self::MOUNT_TIMEOUT);

            if (!$outcome->successful) {
                $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing download - exec dump command - %s', $outcome->errorOutput));
                throw new ProcessExecutionException($outcome);
            }
            $this->backupLogger->log($backup, Log::LOG_INFO, $outcome->output);
            $this->backupLogger->log($backup, Log::LOG_NOTICE, 'Mount done');
        } finally {
            $this->sshKeyMaterializer->cleanup($privateKeyPath);
        }
    }

    public function isDownloadComplete(Backup $backup): bool
    {
        return $this->isMounted($backup);
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
        $path = $this->pathResolver->resolve($backup);
        if (!file_exists($path)) {
            return;
        }

        if ($this->isMounted($backup)) {
            $command = 'fusermount -u "${DIRECTORY}"';
            $parameters = ['DIRECTORY' => $path];

            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, $this->backupLogger->formatParameters($parameters)));
            $outcome = $this->processRunner->runShell($command, $parameters, self::UMOUNT_TIMEOUT);

            if (!$outcome->successful) {
                $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing cleanup - exec umount command - %s', $outcome->errorOutput));
                throw new ProcessExecutionException($outcome);
            }
            $this->backupLogger->log($backup, Log::LOG_INFO, $outcome->output);
        }

        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('Remove local file - %s', $path));

        $entries = is_countable(scandir($path)) ? \count(scandir($path)) : 0;
        if (2 !== $entries) {
            $message = \sprintf('Error executing cleanup - %s directory is not empty', $path);
            $this->backupLogger->log($backup, Log::LOG_ERROR, $message);
            throw new Exception($message);
        }

        rmdir($path);
    }

    public function isLocallyCleaned(Backup $backup): bool
    {
        if (file_exists($this->pathResolver->resolve($backup))) {
            $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('Backup location does exists - %s', $this->pathResolver->resolve($backup)));

            return false;
        }

        return true;
    }

    private function isMounted(Backup $backup): bool
    {
        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('call %s::%s', self::class, __FUNCTION__));

        $command = 'grep -qsF "${DIRECTORY}" /proc/mounts';
        $parameters = ['DIRECTORY' => $this->pathResolver->resolve($backup)];

        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, $this->backupLogger->formatParameters($parameters)));
        $outcome = $this->processRunner->runShell($command, $parameters, self::CHECK_TIMEOUT);

        if (!$outcome->successful) {
            $this->backupLogger->log($backup, Log::LOG_ERROR, 'checkDownloadedFUSE : not mounted');

            return false;
        }
        $this->backupLogger->log($backup, Log::LOG_NOTICE, 'checkDownloadedFUSE : mounted');

        return true;
    }
}
