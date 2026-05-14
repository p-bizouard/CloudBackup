<?php

namespace App\Service\Inventory;

use App\ApiModel\BackupEntry;
use App\ApiModel\InventoryEntry;
use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Repository\BackupRepository;
use DateTimeInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class InventoryBuilder
{
    /**
     * @param iterable<BackupConfigurationInventoryBuilderInterface> $builders
     */
    public function __construct(
        #[AutowireIterator('app.backup_configuration_inventory_builder')]
        private readonly iterable $builders,
        private readonly BackupRepository $backupRepository,
    ) {
    }

    /**
     * @param BackupConfiguration[] $backupConfigurations
     *
     * @return InventoryEntry[]
     */
    public function build(array $backupConfigurations): array
    {
        $inventory = [];
        foreach ($backupConfigurations as $backupConfiguration) {
            $inventory[] = $this->buildEntry($backupConfiguration);
        }

        return $inventory;
    }

    private function buildEntry(BackupConfiguration $backupConfiguration): InventoryEntry
    {
        $minimumBackupSize = $backupConfiguration->getMinimumBackupSize();
        $inventoryEntry = new InventoryEntry(
            id: (int) $backupConfiguration->getId(),
            name: (string) $backupConfiguration->getName(),
            type: (string) $backupConfiguration->getType(),
            expectedSize: null !== $minimumBackupSize ? (int) $minimumBackupSize : null,
            latestBackup: $this->buildBackupEntry($backupConfiguration->getLatestBackup()),
            latestSuccessfulBackup: $this->buildBackupEntry(
                $this->backupRepository->findLatestSuccessful($backupConfiguration)
            ),
        );

        $hasDumpFragment = false;
        foreach ($this->builders as $builder) {
            if (!$builder->supports($backupConfiguration)) {
                continue;
            }
            $builder->apply($backupConfiguration, $inventoryEntry);
            if ($builder instanceof DumpFragmentInventoryBuilderInterface) {
                $hasDumpFragment = true;
            }
        }

        if (!$hasDumpFragment) {
            $inventoryEntry->dumpCommand = $backupConfiguration->getDumpCommand();
        }

        return $inventoryEntry;
    }

    private function buildBackupEntry(?Backup $backup): ?BackupEntry
    {
        if (null === $backup) {
            return null;
        }

        $backupEntry = new BackupEntry();
        $backupEntry->id = (int) $backup->getId();
        $backupEntry->status = (string) $backup->getCurrentPlace();
        $backupEntry->date = $backup->getCreatedAt()?->format(DateTimeInterface::ATOM);
        $backupEntry->size = $backup->getSize();

        return $backupEntry;
    }
}
