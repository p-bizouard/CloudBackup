<?php

namespace App\Tests\Service\Inventory\Builder;

use App\ApiModel\InventoryEntry;
use App\Entity\BackupConfiguration;
use App\Service\Inventory\Builder\KubeconfigInventoryBuilder;
use PHPUnit\Framework\TestCase;

final class KubeconfigInventoryBuilderTest extends TestCase
{
    private KubeconfigInventoryBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new KubeconfigInventoryBuilder();
    }

    public function testSupportsOnlyKubeconfigType(): void
    {
        $bc = (new BackupConfiguration())->setType(BackupConfiguration::TYPE_KUBECONFIG);
        self::assertTrue($this->builder->supports($bc));

        $bc = (new BackupConfiguration())->setType(BackupConfiguration::TYPE_MYSQL);
        self::assertFalse($this->builder->supports($bc));
    }

    public function testApplyEmitsKubeNamespace(): void
    {
        $bc = (new BackupConfiguration())
            ->setType(BackupConfiguration::TYPE_KUBECONFIG)
            ->setKubeNamespace('production');
        $entry = $this->makeEntry();

        $this->builder->apply($bc, $entry);

        self::assertSame('production', $entry->kubeNamespace);
    }

    public function testApplyLeavesNullWhenNotSet(): void
    {
        $bc = (new BackupConfiguration())->setType(BackupConfiguration::TYPE_KUBECONFIG);
        $entry = $this->makeEntry();

        $this->builder->apply($bc, $entry);

        self::assertNull($entry->kubeNamespace);
    }

    private function makeEntry(): InventoryEntry
    {
        return new InventoryEntry(id: 1, name: 'foo', type: BackupConfiguration::TYPE_KUBECONFIG);
    }
}
