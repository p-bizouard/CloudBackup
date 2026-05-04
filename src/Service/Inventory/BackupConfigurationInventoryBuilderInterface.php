<?php

namespace App\Service\Inventory;

use App\Entity\BackupConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.backup_configuration_inventory_builder')]
interface BackupConfigurationInventoryBuilderInterface
{
    public function supports(BackupConfiguration $backupConfiguration): bool;

    /**
     * @return array<string, mixed>
     */
    public function build(BackupConfiguration $backupConfiguration): array;
}
