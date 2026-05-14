<?php

namespace App\Service\Inventory\Builder;

use App\ApiModel\InventoryEntry;
use App\ApiModel\OsInstanceEntry;
use App\ApiModel\OsProjectEntry;
use App\Entity\BackupConfiguration;
use App\Service\Inventory\BackupConfigurationInventoryBuilderInterface;

final class OsInstanceInventoryBuilder implements BackupConfigurationInventoryBuilderInterface
{
    public function supports(BackupConfiguration $backupConfiguration): bool
    {
        return BackupConfiguration::TYPE_OS_INSTANCE === $backupConfiguration->getType()
            && null !== $backupConfiguration->getOsInstance();
    }

    public function apply(BackupConfiguration $backupConfiguration, InventoryEntry $inventoryEntry): void
    {
        $osInstance = $backupConfiguration->getOsInstance();
        if (null === $osInstance) {
            return;
        }

        $osProject = $osInstance->getOSProject();
        $inventoryEntry->osInstance = new OsInstanceEntry(
            name: $osInstance->getName(),
            id: $osInstance->getId(),
            osRegionName: $osInstance->getOSRegionName(),
            osProject: null !== $osProject ? new OsProjectEntry(
                name: $osProject->getName(),
                tenantId: $osProject->getTenantId(),
            ) : null,
        );
    }
}
