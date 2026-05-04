<?php

namespace App\Tests\Service\Inventory\Builder;

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

    public function testBuildWithFullProject(): void
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

        self::assertSame(
            [
                'osInstance' => [
                    'name' => 'web-01',
                    'id' => 'inst-123',
                    'osRegionName' => 'GRA11',
                    'osProject' => [
                        'name' => 'infra',
                        'tenantId' => 'tenant-xyz',
                    ],
                ],
            ],
            $this->builder->build($bc),
        );
    }

    public function testBuildWithoutProject(): void
    {
        $bc = (new BackupConfiguration())
            ->setType(BackupConfiguration::TYPE_OS_INSTANCE)
            ->setOsInstance($this->makeOsInstance());

        self::assertSame(
            [
                'osInstance' => [
                    'name' => 'web-01',
                    'id' => 'inst-123',
                    'osRegionName' => 'GRA11',
                ],
            ],
            $this->builder->build($bc),
        );
    }

    private function makeOsInstance(): OSInstance
    {
        return (new OSInstance())
            ->setId('inst-123')
            ->setName('web-01')
            ->setOSRegionName('GRA11')
            ->setSlug('web-01');
    }
}
