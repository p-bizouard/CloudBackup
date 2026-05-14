<?php

namespace App\Service\Inventory\Builder;

use App\ApiModel\HostEntry;
use App\ApiModel\InventoryEntry;
use App\Entity\BackupConfiguration;
use App\Service\Inventory\BackupConfigurationInventoryBuilderInterface;

final class HostInventoryBuilder implements BackupConfigurationInventoryBuilderInterface
{
    public function supports(BackupConfiguration $backupConfiguration): bool
    {
        return null !== $backupConfiguration->getHost();
    }

    public function apply(BackupConfiguration $backupConfiguration, InventoryEntry $inventoryEntry): void
    {
        $host = $backupConfiguration->getHost();
        if (null === $host) {
            return;
        }

        $inventoryEntry->host = new HostEntry(
            name: $host->getName(),
            ip: $host->getIp(),
        );
    }
}
