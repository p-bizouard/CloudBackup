<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\RestoreDestination;
use PHPUnit\Framework\TestCase;

final class RestoreDestinationTest extends TestCase
{
    public function testAvailableTypesContainsThree(): void
    {
        $types = RestoreDestination::getAvailableTypes();

        self::assertSame(
            [
                RestoreDestination::TYPE_RCLONE,
                RestoreDestination::TYPE_MYSQL,
                RestoreDestination::TYPE_POSTGRESQL,
            ],
            $types,
        );
    }

    public function testFluentSettersRoundTrip(): void
    {
        $destination = new RestoreDestination();

        $destination
            ->setName('Cold restore')
            ->setType(RestoreDestination::TYPE_MYSQL)
            ->setDescription('DR target')
            ->setEnabled(true)
            ->setTargetDatabase('app_prod')
            ->setTargetPath(null)
            ->setAllowOverwrite(true)
            ->setExtraOptions(['k' => 'v']);

        self::assertSame('Cold restore', $destination->getName());
        self::assertSame(RestoreDestination::TYPE_MYSQL, $destination->getType());
        self::assertSame('DR target', $destination->getDescription());
        self::assertTrue($destination->isEnabled());
        self::assertSame('app_prod', $destination->getTargetDatabase());
        self::assertTrue($destination->isAllowOverwrite());
        self::assertSame(['k' => 'v'], $destination->getExtraOptions());
        self::assertSame('Cold restore', (string) $destination);
    }

    public function testEnabledDefaultsToTrue(): void
    {
        $destination = new RestoreDestination();

        self::assertTrue($destination->isEnabled());
        self::assertFalse($destination->isAllowOverwrite());
    }
}
