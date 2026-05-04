<?php

namespace App\Tests\Service\Inventory\Builder;

use App\Entity\BackupConfiguration;
use App\Service\Inventory\Builder\PostgresqlInventoryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostgresqlInventoryBuilderTest extends TestCase
{
    private PostgresqlInventoryBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PostgresqlInventoryBuilder();
    }

    public function testSupportsOnlyPostgresqlType(): void
    {
        $bc = (new BackupConfiguration())->setType(BackupConfiguration::TYPE_POSTGRESQL);
        self::assertTrue($this->builder->supports($bc));

        $bc = (new BackupConfiguration())->setType(BackupConfiguration::TYPE_MYSQL);
        self::assertFalse($this->builder->supports($bc));
    }

    public function testNullCommandFallsBackToLocalhost(): void
    {
        $bc = (new BackupConfiguration())
            ->setType(BackupConfiguration::TYPE_POSTGRESQL)
            ->setDumpCommand(null);

        self::assertSame(
            ['postgresql' => ['host' => 'localhost', 'port' => null, 'user' => null, 'database' => null]],
            $this->builder->build($bc),
        );
    }

    /**
     * @return iterable<string, array{string, array{host: string, port: int|null, user: string|null, database: string|null}}>
     */
    public static function commandProvider(): iterable
    {
        yield 'short flags space form' => [
            "PGPASSWORD='redacted' pg_dump -U user -h example.com -p 5432 database",
            ['host' => 'example.com', 'port' => 5432, 'user' => 'user', 'database' => 'database'],
        ];

        yield 'localhost no port' => [
            "PGPASSWORD='redacted' pg_dump -U user -h localhost user",
            ['host' => 'localhost', 'port' => null, 'user' => 'user', 'database' => 'user'],
        ];

        yield 'port appears after database' => [
            "PGPASSWORD='redacted' pg_dump -U user -h example.com user -p 5432",
            ['host' => 'example.com', 'port' => 5432, 'user' => 'user', 'database' => 'user'],
        ];

        yield 'long flags equals form' => [
            "pg_dump --host=db.example.com --username=alice --port=5432 --dbname=mydb",
            ['host' => 'db.example.com', 'port' => 5432, 'user' => 'alice', 'database' => 'mydb'],
        ];

        yield 'long flags space form' => [
            "pg_dump --host db.example.com --username alice --port 5432 --dbname mydb",
            ['host' => 'db.example.com', 'port' => 5432, 'user' => 'alice', 'database' => 'mydb'],
        ];

        yield 'positional db with long port flag' => [
            "pg_dump --host db.example.com --port 5432 mydb",
            ['host' => 'db.example.com', 'port' => 5432, 'user' => null, 'database' => 'mydb'],
        ];

        yield 'no host falls back to localhost' => [
            "pg_dump -U alice mydb",
            ['host' => 'localhost', 'port' => null, 'user' => 'alice', 'database' => 'mydb'],
        ];

        yield '127.0.0.1 host' => [
            "PGPASSWORD='redacted' pg_dump -U project -h 127.0.0.1 project",
            ['host' => '127.0.0.1', 'port' => null, 'user' => 'project', 'database' => 'project'],
        ];
    }

    /**
     * @param array{host: string, port: int|null, user: string|null, database: string|null} $expected
     */
    #[DataProvider('commandProvider')]
    public function testBuildExtractsConnectionInfo(string $command, array $expected): void
    {
        $bc = (new BackupConfiguration())
            ->setType(BackupConfiguration::TYPE_POSTGRESQL)
            ->setDumpCommand($command);

        self::assertSame(['postgresql' => $expected], $this->builder->build($bc));
    }
}
