<?php

declare(strict_types=1);

namespace App\Backup\Source;

use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\Log;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.backup.source')]
final class ReadOnlySource extends AbstractBackupSource
{
    private const array TYPES = [
        BackupConfiguration::TYPE_READ_RESTIC,
        BackupConfiguration::TYPE_READ_KOPIA,
    ];

    public function supports(BackupConfiguration $backupConfiguration): bool
    {
        return \in_array($backupConfiguration->getType(), self::TYPES, true);
    }

    public function download(Backup $backup): void
    {
        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('%s : Nothing to do', $backup->getCurrentPlace()));
    }

    public function upload(Backup $backup): void
    {
        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('%s : Nothing to do', $backup->getCurrentPlace()));
    }

    public function cleanupLocal(Backup $backup): void
    {
        // read-only sources never produce local artifacts.
    }
}
