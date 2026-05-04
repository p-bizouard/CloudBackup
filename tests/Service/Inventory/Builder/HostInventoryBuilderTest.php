<?php

namespace App\Tests\Service\Inventory\Builder;

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

    public function testBuildEmitsHostNameAndIp(): void
    {
        $bc = (new BackupConfiguration())->setHost($this->makeHost());

        self::assertSame(
            ['host' => ['name' => 'srv01', 'ip' => '10.0.0.1']],
            $this->builder->build($bc),
        );
    }

    private function makeHost(): Host
    {
        return (new Host())
            ->setName('srv01')
            ->setIp('10.0.0.1')
            ->setLogin('root');
    }
}
