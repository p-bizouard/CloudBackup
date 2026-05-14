<?php

namespace App\Tests\Service\Inventory\Builder;

use App\ApiModel\InventoryEntry;
use App\Entity\BackupConfiguration;
use App\Entity\Host;
use App\Service\Inventory\Builder\HostInventoryBuilder;
use PHPUnit\Framework\TestCase;

final class HostInventoryBuilderTest extends TestCase
{
    private HostInventoryBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new HostInventoryBuilder();
    }

    public function testDoesNotSupportWhenNoHost(): void
    {
        $bc = (new BackupConfiguration())->setHost(null);

        self::assertFalse($this->builder->supports($bc));
    }

    public function testSupportsWhenHostSet(): void
    {
        $bc = (new BackupConfiguration())->setHost($this->makeHost());

        self::assertTrue($this->builder->supports($bc));
    }

    public function testApplyEmitsHostNameAndIp(): void
    {
        $bc = (new BackupConfiguration())->setHost($this->makeHost());
        $entry = $this->makeEntry();

        $this->builder->apply($bc, $entry);

        self::assertNotNull($entry->host);
        self::assertSame('srv01', $entry->host->name);
        self::assertSame('10.0.0.1', $entry->host->ip);
    }

    private function makeHost(): Host
    {
        return (new Host())
            ->setName('srv01')
            ->setIp('10.0.0.1')
            ->setLogin('root');
    }

    private function makeEntry(): InventoryEntry
    {
        return new InventoryEntry(id: 1, name: 'foo', type: BackupConfiguration::TYPE_SSH_CMD);
    }
}
