<?php

declare(strict_types=1);

namespace App\Backup\Source;

use App\Entity\Backup;
use App\Entity\BackupConfiguration;

interface BackupSourceInterface
{
    public function supports(BackupConfiguration $backupConfiguration): bool;

    public function onDump(Backup $backup): void;

    public function download(Backup $backup): void;

    public function isDownloadComplete(Backup $backup): bool;

    public function cleanupLocal(Backup $backup): void;

    public function isLocallyCleaned(Backup $backup): bool;

    public function upload(Backup $backup): void;

    /**
     * Snapshot readiness state for guards (null = none, "active" = ready, anything else = still pending).
     */
    public function getSnapshotStatus(Backup $backup): ?string;
}
