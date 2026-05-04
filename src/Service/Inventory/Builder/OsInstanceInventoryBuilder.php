<?php

namespace App\Service\Inventory\Builder;

use App\Entity\BackupConfiguration;
use App\Service\Inventory\BackupConfigurationInventoryBuilderInterface;

final class OsInstanceInventoryBuilder implements BackupConfigurationInventoryBuilderInterface
{
    public function supports(BackupConfiguration $backupConfiguration): bool
    {
        return BackupConfiguration::TYPE_OS_INSTANCE === $backupConfiguration->getType() && null !== $backupConfiguration->getOsInstance();
    }

    public function build(BackupConfiguration $backupConfiguration): array
    {
        $osInstance = $backupConfiguration->getOsInstance();
        $data = [
            'name' => $osInstance->getName(),
            'id' => $osInstance->getId(),
            'osRegionName' => $osInstance->getOSRegionName(),
        ];

        $osProject = $osInstance->getOSProject();
        if (null !== $osProject) {
            $data['osProject'] = [
                'name' => $osProject->getName(),
                'tenantId' => $osProject->getTenantId(),
            ];
        }

        return ['osInstance' => $data];
    }
}
