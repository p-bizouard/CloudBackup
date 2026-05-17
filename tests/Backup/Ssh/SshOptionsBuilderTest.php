<?php

declare(strict_types=1);

namespace App\Tests\Backup\Ssh;

use App\Backup\Ssh\SshOptionsBuilder;
use App\Entity\Host;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SshOptionsBuilderTest extends TestCase
{
    public function testReturnsEmptyStringWhenHostIsNull(): void
    {
        self::assertSame('', new SshOptionsBuilder()->build(null));
    }

    public function testReturnsEmptyStringWhenOptionsAreEmpty(): void
    {
        $host = new Host()->setSshOptions('');

        self::assertSame('', new SshOptionsBuilder()->build($host));
    }

    public function testReturnsLeadingSpacePrefixedOptionsWhenValid(): void
    {
        $host = new Host()->setSshOptions('-o ConnectTimeout=10');

        self::assertSame(' -o ConnectTimeout=10', new SshOptionsBuilder()->build($host));
    }

    public function testRejectsInvalidCharactersToPreventInjection(): void
    {
        $host = new Host()->setSshOptions('-o ConnectTimeout=10; rm -rf /');

        $this->expectException(InvalidArgumentException::class);
        new SshOptionsBuilder()->build($host);
    }
}
