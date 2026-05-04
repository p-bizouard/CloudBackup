<?php

namespace App\Service\Inventory\Builder;

use App\Entity\BackupConfiguration;
use App\Service\Inventory\BackupConfigurationInventoryBuilderInterface;

final class HostInventoryBuilder implements BackupConfigurationInventoryBuilderInterface
{
    public function supports(BackupConfiguration $backupConfiguration): bool
    {
        return null !== $backupConfiguration->getHost();
    }

    public function build(BackupConfiguration $backupConfiguration): array
    {
        $host = $backupConfiguration->getHost();

        return [
            'host' => [
                'name' => $host->getName(),
                'ip' => $host->getIp(),
            ],
        ];
    }
}
