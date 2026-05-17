<?php

declare(strict_types=1);

namespace App\Backup\Source;

use App\Backup\Logging\BackupLogger;
use App\Backup\Path\TemporaryPathResolver;
use App\Backup\Process\ProcessExecutionException;
use App\Backup\Process\ProcessOutcome;
use App\Backup\Process\ProcessRunnerInterface;
use App\Backup\Storage\StorageBackendRegistry;
use App\Entity\Backup;
use App\Entity\Log;

abstract class AbstractBackupSource implements BackupSourceInterface
{
    public function __construct(
        protected readonly ProcessRunnerInterface $processRunner,
        protected readonly BackupLogger $backupLogger,
        protected readonly TemporaryPathResolver $pathResolver,
        protected readonly StorageBackendRegistry $storageBackends,
    ) {
    }

    public function onDump(Backup $backup): void
    {
        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('%s : Nothing to do', $backup->getCurrentPlace()));
    }

    public function isDownloadComplete(Backup $backup): bool
    {
        return true;
    }

    public function isLocallyCleaned(Backup $backup): bool
    {
        return true;
    }

    public function getSnapshotStatus(Backup $backup): ?string
    {
        return null;
    }

    protected function failOrLog(Backup $backup, ProcessOutcome $processOutcome, string $context): void
    {
        if (!$processOutcome->successful) {
            $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing %s - %s', $context, $processOutcome->errorOutput));
            throw new ProcessExecutionException($processOutcome);
        }
        $this->backupLogger->log($backup, Log::LOG_INFO, $processOutcome->output);
    }
}
