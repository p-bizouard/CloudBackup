<?php

namespace App\Service\Inventory\Builder;

use App\Entity\BackupConfiguration;
use App\Service\Inventory\BackupConfigurationInventoryBuilderInterface;

final class KubeconfigInventoryBuilder implements BackupConfigurationInventoryBuilderInterface
{
    public function supports(BackupConfiguration $backupConfiguration): bool
    {
        return BackupConfiguration::TYPE_KUBECONFIG === $backupConfiguration->getType();
    }

    public function build(BackupConfiguration $backupConfiguration): array
    {
        return ['kubeNamespace' => $backupConfiguration->getKubeNamespace()];
    }
}
