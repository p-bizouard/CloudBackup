<?php

declare(strict_types=1);

namespace App\Tests\Restore\Destination;

use App\Entity\RestoreDestination;
use App\Backup\Path\TemporaryPathResolver;
use App\Restore\Destination\BundleBuilder;
use App\Restore\Destination\MysqlRestoreDestinationStrategy;
use App\Restore\Destination\PostgresqlRestoreDestinationStrategy;
use App\Restore\Destination\RcloneRestoreDestinationStrategy;
use App\Restore\Destination\RestoreDestinationStrategyRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RestoreDestinationStrategyRegistryTest extends TestCase
{
    public function testResolvesMatchingStrategy(): void
    {
        $registry = $this->buildRegistry();

        $rclone = new RestoreDestination()->setType(RestoreDestination::TYPE_RCLONE);
        $mysql = new RestoreDestination()->setType(RestoreDestination::TYPE_MYSQL);
        $postgresql = new RestoreDestination()->setType(RestoreDestination::TYPE_POSTGRESQL);

        self::assertInstanceOf(RcloneRestoreDestinationStrategy::class, $registry->forDestination($rclone));
        self::assertInstanceOf(MysqlRestoreDestinationStrategy::class, $registry->forDestination($mysql));
        self::assertInstanceOf(PostgresqlRestoreDestinationStrategy::class, $registry->forDestination($postgresql));
    }

    public function testThrowsOnUnknownType(): void
    {
        $registry = $this->buildRegistry();

        $unknown = new RestoreDestination()->setType('does-not-exist');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No restore destination strategy supports type "does-not-exist"');

        $registry->forDestination($unknown);
    }

    private function buildRegistry(): RestoreDestinationStrategyRegistry
    {
        $builder = new BundleBuilder();
        $paths = new TemporaryPathResolver('/var/cloud-bkp');

        return new RestoreDestinationStrategyRegistry([
            new RcloneRestoreDestinationStrategy($builder),
            new MysqlRestoreDestinationStrategy($builder, $paths),
            new PostgresqlRestoreDestinationStrategy($builder, $paths),
        ]);
    }
}
