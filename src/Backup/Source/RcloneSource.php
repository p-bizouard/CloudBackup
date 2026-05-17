<?php

declare(strict_types=1);

namespace App\Backup\Source;

use App\Backup\Process\ProcessExecutionException;
use App\Backup\Storage\RcloneStorageBackend;
use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\Log;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Filesystem\Filesystem;

#[AutoconfigureTag('app.backup.source')]
final class RcloneSource extends AbstractBackupSource
{
    public function supports(BackupConfiguration $backupConfiguration): bool
    {
        return BackupConfiguration::TYPE_RCLONE === $backupConfiguration->getType();
    }

    public function download(Backup $backup): void
    {
        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('%s : Nothing to do', $backup->getCurrentPlace()));
    }

    public function upload(Backup $backup): void
    {
        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('call %s::%s', self::class, __FUNCTION__));

        $filesystem = new Filesystem();
        $configFile = $filesystem->tempnam('/tmp', 'key_');

        try {
            $filesystem->appendToFile($configFile, $backup->getBackupConfiguration()->getCompleteRcloneConfiguration());

            $dailyBackupDir = \sprintf('%s/%s', $backup->getBackupConfiguration()->getRcloneBackupDir(), date('Y-m-d'));
            $command = 'rclone sync "${SOURCE_LOCATION}" "${REMOTE_STORAGE_LOCATION}" --backup-dir "${REMOTE_STORAGE_BACKUP}" --config "${RCLONE_CONFIG}" ${RCLONE_FLAGS}';
            $parameters = [
                'SOURCE_LOCATION' => $backup->getBackupConfiguration()->getRemotePath(),
                'REMOTE_STORAGE_LOCATION' => $backup->getBackupConfiguration()->getStorageSubPath(),
                'REMOTE_STORAGE_BACKUP' => $dailyBackupDir,
                'RCLONE_CONFIG' => $configFile,
                'RCLONE_FLAGS' => $backup->getBackupConfiguration()->getRcloneFlags(),
            ];

            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, nl2br($this->backupLogger->formatParameters($parameters))));
            $outcome = $this->processRunner->runShell($command, $parameters, RcloneStorageBackend::UPLOAD_TIMEOUT);

            if (!$outcome->successful) {
                $stdErrIgnore = $backup->getBackupConfiguration()->getStdErrIgnore();
                if (null !== $stdErrIgnore) {
                    $filtered = preg_replace($stdErrIgnore, '', $outcome->errorOutput);
                    if (null === $filtered) {
                        $message = \sprintf('Invalid stdErrIgnore regex `%s`: %s', $stdErrIgnore, preg_last_error_msg());
                        $this->backupLogger->log($backup, Log::LOG_ERROR, $message);
                        throw new InvalidArgumentException($message);
                    }
                    if ('' === preg_replace('/^\s+/m', '', $filtered)) {
                        $this->backupLogger->log($backup, Log::LOG_WARNING, \sprintf('Warning executing backup - rclone sync - %s', $outcome->errorOutput));

                        return;
                    }
                }

                $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing backup - rclone sync - %s', $outcome->errorOutput));
                throw new ProcessExecutionException($outcome);
            }
            $this->backupLogger->log($backup, Log::LOG_INFO, $outcome->output);
        } finally {
            @unlink($configFile);
        }
    }

    public function cleanupLocal(Backup $backup): void
    {
        // Rclone uploads stream directly from the remote source; nothing local to clean.
    }
}
