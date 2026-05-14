<?php

namespace App\Tests\Service\Inventory\Builder;

use App\ApiModel\InventoryEntry;
use App\Entity\BackupConfiguration;
use App\Entity\OSInstance;
use App\Entity\OSProject;
use App\Service\Inventory\Builder\OsInstanceInventoryBuilder;
use PHPUnit\Framework\TestCase;

final class OsInstanceInventoryBuilderTest extends TestCase
{
    private OsInstanceInventoryBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new OsInstanceInventoryBuilder();
    }

    public function testDoesNotSupportNonOsInstanceType(): void
    {
        $bc = (new BackupConfiguration())
            ->setType(BackupConfiguration::TYPE_MYSQL)
            ->setOsInstance($this->makeOsInstance());

        self::assertFalse($this->builder->supports($bc));
    }

    public function testSupportsWhenTypeAndInstanceMatch(): void
    {
        $bc = (new BackupConfiguration())
            ->setType(BackupConfiguration::TYPE_OS_INSTANCE)
            ->setOsInstance($this->makeOsInstance());

        self::assertTrue($this->builder->supports($bc));
    }

    public function testApplyWithFullProject(): void
    {
        $osProject = (new OSProject())
            ->setName('infra')
            ->setTenantId('tenant-xyz')
            ->setAuthUrl('http://x')
            ->setIdentityApiVersion(3)
            ->setUserDomainName('Default')
            ->setProjectDomainName('Default')
            ->setTenantName('infra')
            ->setUsername('u')
            ->setPassword('p')
            ->setSlug('infra');

        $osInstance = $this->makeOsInstance()->setOSProject($osProject);

        $bc = (new BackupConfiguration())
            ->setType(BackupConfiguration::TYPE_OS_INSTANCE)
            ->setOsInstance($osInstance);
        $entry = $this->makeEntry();

        $this->builder->apply($bc, $entry);

        self::assertNotNull($entry->osInstance);
        self::assertSame('web-01', $entry->osInstance->name);
        self::assertSame('inst-123', $entry->osInstance->id);
        self::assertSame('GRA11', $entry->osInstance->osRegionName);
        self::assertNotNull($entry->osInstance->osProject);
        self::assertSame('infra', $entry->osInstance->osProject->name);
        self::assertSame('tenant-xyz', $entry->osInstance->osProject->tenantId);
    }

    public function testApplyWithoutProject(): void
    {
        $bc = (new BackupConfiguration())
            ->setType(BackupConfiguration::TYPE_OS_INSTANCE)
            ->setOsInstance($this->makeOsInstance());
        $entry = $this->makeEntry();

        $this->builder->apply($bc, $entry);

        self::assertNotNull($entry->osInstance);
        self::assertSame('web-01', $entry->osInstance->name);
        self::assertNull($entry->osInstance->osProject);
    }

    private function makeOsInstance(): OSInstance
    {
        return (new OSInstance())
            ->setId('inst-123')
            ->setName('web-01')
            ->setOSRegionName('GRA11')
            ->setSlug('web-01');
    }

    private function makeEntry(): InventoryEntry
    {
        return new InventoryEntry(id: 1, name: 'foo', type: BackupConfiguration::TYPE_OS_INSTANCE);
    }
}
