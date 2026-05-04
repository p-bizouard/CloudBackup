<?php

namespace App\Service\Inventory;

use App\Entity\BackupConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class InventoryBuilder
{
    /**
     * @param iterable<BackupConfigurationInventoryBuilderInterface> $builders
     */
    public function __construct(
        #[AutowireIterator('app.backup_configuration_inventory_builder')]
        private readonly iterable $builders,
    ) {
    }

    /**
     * @param BackupConfiguration[] $backupConfigurations
     *
     * @return array<int, array<string, mixed>>
     */
    public function build(array $backupConfigurations): array
    {
        $inventory = [];
        foreach ($backupConfigurations as $backupConfiguration) {
            $inventory[] = $this->buildEntry($backupConfiguration);
        }

        return $inventory;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEntry(BackupConfiguration $backupConfiguration): array
    {
        $entry = [
            'name' => $backupConfiguration->getName(),
            'type' => $backupConfiguration->getType(),
        ];
        $hasDumpFragment = false;

        foreach ($this->builders as $builder) {
            if (!$builder->supports($backupConfiguration)) {
                continue;
            }
            $entry += $builder->build($backupConfiguration);
            if ($builder instanceof DumpFragmentInventoryBuilderInterface) {
                $hasDumpFragment = true;
            }
        }

        if (!$hasDumpFragment) {
            $entry['dumpCommand'] = $backupConfiguration->getDumpCommand();
        }

        return $entry;
    }
}
