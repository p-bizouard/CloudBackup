<?php

declare(strict_types=1);

namespace App\Backup\Storage;

use App\Backup\Logging\BackupLogger;
use App\Backup\Process\ProcessExecutionException;
use App\Backup\Process\ProcessOutcome;
use App\Backup\Process\ProcessRunnerInterface;
use App\Entity\Backup;
use App\Entity\Log;
use App\Entity\Storage;
use App\Utils\StringUtils;
use DateTime;
use Exception;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Filesystem\Filesystem;

#[AutoconfigureTag('app.backup.storage_backend')]
final class RcloneStorageBackend implements StorageBackendInterface
{
    public const int UPLOAD_TIMEOUT = 3600 * 4;
    public const int CHECK_TIMEOUT = 3600;
    public const int SIZE_MAX_RATIO = 2;

    public function __construct(
        private readonly ProcessRunnerInterface $processRunner,
        private readonly BackupLogger $backupLogger,
    ) {
    }

    public function supports(Storage $storage): bool
    {
        return $storage->isRclone();
    }

    public function initRepository(Backup $backup): void
    {
        // Rclone does not require explicit repository initialisation.
    }

    public function healthCheck(Backup $backup, bool $tryRepair = true): void
    {
        $filesystem = new Filesystem();
        $configFile = $filesystem->tempnam('/tmp', 'key_');

        try {
            $filesystem->appendToFile($configFile, $backup->getBackupConfiguration()->getCompleteRcloneConfiguration());

            if ($backup->getBackupConfiguration()->isSkipRcloneCheck()) {
                $this->backupLogger->log($backup, Log::LOG_NOTICE, 'Skipping rclone check (skipRcloneCheck enabled on configuration)');
            } else {
                $this->runRcloneCheck($backup, $configFile);
            }

            $command = 'rclone size "${REMOTE_STORAGE_LOCATION}" --config "${RCLONE_CONFIG}" --json';
            $parameters = [
                'REMOTE_STORAGE_LOCATION' => $backup->getBackupConfiguration()->getStorageSubPath(),
                'RCLONE_CONFIG' => $configFile,
            ];

            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, $this->backupLogger->formatParameters($parameters)));
            $outcome = $this->processRunner->runShell($command, $parameters, self::CHECK_TIMEOUT);
            $this->failOrLog($backup, $outcome, 'rclone size');

            $output = json_decode($outcome->output, true, 512, \JSON_THROW_ON_ERROR);
            if (null === $output) {
                $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing rclone size - %s - %s', $outcome->output, $outcome->errorOutput));
                throw new ProcessExecutionException($outcome);
            }

            $backup->setSize($output['bytes']);

            $minimumBackupSize = (int) $backup->getBackupConfiguration()->getMinimumBackupSize();

            if ($output['bytes'] <= $minimumBackupSize) {
                $message = \sprintf('Rclone failed. Minimum backup size not met : %s < %s', StringUtils::humanizeFileSize($output['bytes']), StringUtils::humanizeFileSize($minimumBackupSize));
                $this->backupLogger->log($backup, Log::LOG_NOTICE, $message);
                throw new Exception($message);
            }

            if ($minimumBackupSize > 0 && $output['bytes'] > self::SIZE_MAX_RATIO * $minimumBackupSize) {
                $newMinimumBackupSize = (int) ($minimumBackupSize * (1 + (self::SIZE_MAX_RATIO - 1) / 2));
                $message = \sprintf('Rclone backup size %s exceeds %dx the expected size %s. Updating expected size to %s.', StringUtils::humanizeFileSize($output['bytes']), self::SIZE_MAX_RATIO, StringUtils::humanizeFileSize($minimumBackupSize), StringUtils::humanizeFileSize($newMinimumBackupSize));
                $this->backupLogger->log($backup, Log::LOG_NOTICE, $message);
                $backup->getBackupConfiguration()->setMinimumBackupSize((string) $newMinimumBackupSize);
            }
        } finally {
            @unlink($configFile);
        }
    }

    public function prune(Backup $backup): void
    {
        if (null === $backup->getBackupConfiguration()->getRcloneBackupDir() || '' === $backup->getBackupConfiguration()->getRcloneBackupDir()) {
            return;
        }

        $filesystem = new Filesystem();
        $configFile = $filesystem->tempnam('/tmp', 'key_');

        try {
            $filesystem->appendToFile($configFile, $backup->getBackupConfiguration()->getStorage()->getRcloneConfiguration());
            $backupParentDirectory = \dirname($backup->getBackupConfiguration()->getRcloneBackupDir());
            $backupDirectory = str_replace($backupParentDirectory.'/', '', $backup->getBackupConfiguration()->getRcloneBackupDir());

            $command = 'rclone lsd "${REMOTE_STORAGE_DIRECTORY}" --config "${RCLONE_CONFIG}"';
            $parameters = [
                'REMOTE_STORAGE_DIRECTORY' => $backupParentDirectory,
                'RCLONE_CONFIG' => $configFile,
            ];

            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, $this->backupLogger->formatParameters($parameters)));
            $outcome = $this->processRunner->runShell($command, $parameters, self::UPLOAD_TIMEOUT);
            $this->failOrLog($backup, $outcome, 'rclone lsd');

            if (!preg_match('/\s'.preg_quote($backupDirectory, '/').'\n/', $outcome->output)) {
                $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('INFO executing backup %s does not seems to exists', $backup->getBackupConfiguration()->getRcloneBackupDir()));

                return;
            }

            $keepDays = $this->resolveKeepDays($backup);

            $command = 'rclone delete --min-age "${KEEP_DAYS}d" "${REMOTE_STORAGE_BACKUP}" --config "${RCLONE_CONFIG}"';
            $parameters = [
                'KEEP_DAYS' => (string) $keepDays,
                'REMOTE_STORAGE_BACKUP' => $backup->getBackupConfiguration()->getRcloneBackupDir(),
                'RCLONE_CONFIG' => $configFile,
            ];
            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, $this->backupLogger->formatParameters($parameters)));
            $outcome = $this->processRunner->runShell($command, $parameters, self::UPLOAD_TIMEOUT);
            $this->failOrLog($backup, $outcome, 'rclone delete');

            $this->pruneArchiveDirectories($backup, $configFile, $keepDays);

            $command = 'rclone rmdirs "${REMOTE_STORAGE_BACKUP}" --config "${RCLONE_CONFIG}"';
            $parameters = [
                'REMOTE_STORAGE_BACKUP' => $backup->getBackupConfiguration()->getRcloneBackupDir(),
                'RCLONE_CONFIG' => $configFile,
            ];
            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, $this->backupLogger->formatParameters($parameters)));
            $outcome = $this->processRunner->runShell($command, $parameters, self::UPLOAD_TIMEOUT);
            $this->failOrLog($backup, $outcome, 'rclone rmdirs');
        } finally {
            @unlink($configFile);
        }
    }

    private function runRcloneCheck(Backup $backup, string $configFile): void
    {
        $isCrypt = preg_match('/^\s*type\s*=\s*crypt\s*$/m', (string) $backup->getBackupConfiguration()->getCompleteRcloneConfiguration());

        $command = 'rclone "${CHECK}" "${SOURCE_LOCATION}" "${REMOTE_STORAGE_LOCATION}" --config "${RCLONE_CONFIG}"';
        $parameters = [
            'CHECK' => $isCrypt ? 'cryptcheck' : 'check',
            'SOURCE_LOCATION' => $backup->getBackupConfiguration()->getRemotePath(),
            'REMOTE_STORAGE_LOCATION' => $backup->getBackupConfiguration()->getStorageSubPath(),
            'RCLONE_CONFIG' => $configFile,
        ];

        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, nl2br($this->backupLogger->formatParameters($parameters))));
        $outcome = $this->processRunner->runShell($command, $parameters, self::CHECK_TIMEOUT);
        $this->failOrLog($backup, $outcome, \sprintf('%s::healthCheck - %s', self::class, $command));
    }

    private function resolveKeepDays(Backup $backup): int
    {
        $keepDaily = max(0, (int) $backup->getBackupConfiguration()->getKeepDaily());
        $keepWeekly = max(0, (int) $backup->getBackupConfiguration()->getKeepWeekly());

        $keepDays = max($keepDaily, $keepWeekly * 7);

        if (0 === $keepDays) {
            $keepDays = 7;
            $this->backupLogger->log($backup, Log::LOG_WARNING, 'Both keepDaily and keepWeekly are 0. Using default retention of 7 days.');
        }

        return $keepDays;
    }

    private function pruneArchiveDirectories(Backup $backup, string $configFile, int $keepDays): void
    {
        $command = 'rclone lsf "${REMOTE_STORAGE_BACKUP}" --dirs-only --config "${RCLONE_CONFIG}"';
        $parameters = [
            'REMOTE_STORAGE_BACKUP' => $backup->getBackupConfiguration()->getRcloneBackupDir(),
            'RCLONE_CONFIG' => $configFile,
        ];
        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, $this->backupLogger->formatParameters($parameters)));

        $outcome = $this->processRunner->runShell($command, $parameters, self::CHECK_TIMEOUT);
        if (!$outcome->successful) {
            $this->backupLogger->log($backup, Log::LOG_WARNING, \sprintf('Failed to list archive directories: %s', $outcome->errorOutput));

            return;
        }

        $this->backupLogger->log($backup, Log::LOG_INFO, $outcome->output);
        $directories = array_filter(explode("\n", trim($outcome->output)), static fn ($dir) => '' !== $dir);

        $cutoffDate = new DateTime()->modify(\sprintf('-%d days', $keepDays));
        $cutoffDate->setTime(0, 0, 0);

        foreach ($directories as $directory) {
            $directory = rtrim($directory, '/');
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})$/', $directory, $matches)) {
                continue;
            }

            try {
                $dirDate = DateTime::createFromFormat('!Y-m-d', $matches[1]);
                $errors = DateTime::getLastErrors();

                $hasErrors = $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
                $isValidFormat = false !== $dirDate && $dirDate->format('Y-m-d') === $matches[1];

                if (!$isValidFormat || $hasErrors) {
                    $this->backupLogger->log($backup, Log::LOG_WARNING, \sprintf('Invalid date in directory name: %s', $directory));
                    continue;
                }

                if ($dirDate < $cutoffDate) {
                    $archiveDir = rtrim($backup->getBackupConfiguration()->getRcloneBackupDir(), '/').'/'.$directory;
                    $deleteCommand = 'rclone purge "${ARCHIVE_DIR}" --config "${RCLONE_CONFIG}"';
                    $deleteParameters = [
                        'ARCHIVE_DIR' => $archiveDir,
                        'RCLONE_CONFIG' => $configFile,
                    ];

                    $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Attempting to delete old archive directory: %s (older than %d days retention period)', $directory, $keepDays));
                    $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $deleteCommand, $this->backupLogger->formatParameters($deleteParameters)));

                    $deleteOutcome = $this->processRunner->runShell($deleteCommand, $deleteParameters, self::CHECK_TIMEOUT);
                    if (!$deleteOutcome->successful) {
                        $this->backupLogger->log($backup, Log::LOG_WARNING, \sprintf('Failed to delete archive directory %s: %s', $directory, $deleteOutcome->errorOutput));
                    } else {
                        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Successfully deleted archive directory: %s', $directory));
                    }
                }
            } catch (Exception $e) {
                $this->backupLogger->log($backup, Log::LOG_WARNING, \sprintf('Error parsing date for directory %s: %s', $directory, $e->getMessage()));
            }
        }
    }

    private function failOrLog(Backup $backup, ProcessOutcome $processOutcome, string $context): void
    {
        if (!$processOutcome->successful) {
            $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing %s - %s', $context, $processOutcome->errorOutput));
            throw new ProcessExecutionException($processOutcome);
        }
        $this->backupLogger->log($backup, Log::LOG_INFO, $processOutcome->output);
    }
}
