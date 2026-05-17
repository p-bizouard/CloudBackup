<?php

declare(strict_types=1);

namespace App\Backup\Path;

use App\Entity\Backup;
use App\Entity\BackupConfiguration;

final class TemporaryPathResolver
{
    public function __construct(private readonly string $temporaryDownloadDirectory)
    {
    }

    public function resolve(Backup $backup): string
    {
        $configuration = $backup->getBackupConfiguration();

        if (BackupConfiguration::TYPE_SSHFS === $configuration->getType()) {
            return \sprintf('%s/%s', $this->temporaryDownloadDirectory, $backup->getName(false));
        }

        $extension = $configuration->getExtension();
        if (null !== $extension) {
            return \sprintf('%s/%s.%s', $this->temporaryDownloadDirectory, $backup->getName(false), $extension);
        }

        return \sprintf('%s/%s', $this->temporaryDownloadDirectory, $backup->getName(false));
    }
}
