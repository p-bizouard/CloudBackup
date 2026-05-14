<?php

namespace App\Service\Inventory;

use App\ApiModel\InventoryEntry;
use App\Entity\BackupConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.backup_configuration_inventory_builder')]
interface BackupConfigurationInventoryBuilderInterface
{
    public function supports(BackupConfiguration $backupConfiguration): bool;

    public function apply(BackupConfiguration $backupConfiguration, InventoryEntry $inventoryEntry): void;
}
