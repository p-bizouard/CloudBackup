<?php

namespace App\Service\Inventory\Builder;

use App\ApiModel\InventoryEntry;
use App\Entity\BackupConfiguration;
use App\Service\Inventory\BackupConfigurationInventoryBuilderInterface;

final class KubeconfigInventoryBuilder implements BackupConfigurationInventoryBuilderInterface
{
    public function supports(BackupConfiguration $backupConfiguration): bool
    {
        return BackupConfiguration::TYPE_KUBECONFIG === $backupConfiguration->getType();
    }

    public function apply(BackupConfiguration $backupConfiguration, InventoryEntry $inventoryEntry): void
    {
        $inventoryEntry->kubeNamespace = $backupConfiguration->getKubeNamespace();
    }
}
