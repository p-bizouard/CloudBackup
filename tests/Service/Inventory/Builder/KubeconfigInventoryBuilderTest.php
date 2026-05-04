<?php

namespace App\Tests\Service\Inventory\Builder;

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

    public function testBuildEmitsKubeNamespace(): void
    {
        $bc = (new BackupConfiguration())
            ->setType(BackupConfiguration::TYPE_KUBECONFIG)
            ->setKubeNamespace('production');

        self::assertSame(['kubeNamespace' => 'production'], $this->builder->build($bc));
    }

    public function testBuildEmitsNullWhenNotSet(): void
    {
        $bc = (new BackupConfiguration())->setType(BackupConfiguration::TYPE_KUBECONFIG);

        self::assertSame(['kubeNamespace' => null], $this->builder->build($bc));
    }
}
