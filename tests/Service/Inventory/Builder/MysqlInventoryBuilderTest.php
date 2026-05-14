<?php

namespace App\Tests\Service\Inventory\Builder;

use App\ApiModel\InventoryEntry;
use App\Entity\BackupConfiguration;
use App\Service\Inventory\Builder\MysqlInventoryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MysqlInventoryBuilderTest extends TestCase
{
    private MysqlInventoryBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new MysqlInventoryBuilder();
    }

    public function testSupportsOnlyMysqlType(): void
    {
        $bc = (new BackupConfiguration())->setType(BackupConfiguration::TYPE_MYSQL);
        self::assertTrue($this->builder->supports($bc));

        $bc = (new BackupConfiguration())->setType(BackupConfiguration::TYPE_POSTGRESQL);
        self::assertFalse($this->builder->supports($bc));
    }

    public function testNullCommandFallsBackToLocalhost(): void
    {
        $bc = (new BackupConfiguration())
            ->setType(BackupConfiguration::TYPE_MYSQL)
            ->setDumpCommand(null);
        $entry = $this->makeEntry();

        $this->builder->apply($bc, $entry);

        self::assertNotNull($entry->mysql);
        self::assertSame('localhost', $entry->mysql->host);
        self::assertNull($entry->mysql->port);
        self::assertNull($entry->mysql->user);
        self::assertNull($entry->mysql->database);
    }

    /**
     * @return iterable<string, array{string, array{host: string, port: int|null, user: string|null, database: string|null}}>
     */
    public static function commandProvider(): iterable
    {
        yield 'long flags equals form' => [
            'mysqldump --host=example.com --user=user --port=5432 --password=redacted database --ssl-verify-server-cert=false',
            ['host' => 'example.com', 'port' => 5432, 'user' => 'user', 'database' => 'database'],
        ];

        yield 'short flags space form (capital P for port)' => [
            'mysqldump -h db.example.com -u alice -P 3306 -p secret mydb',
            ['host' => 'db.example.com', 'port' => 3306, 'user' => 'alice', 'database' => 'mydb'],
        ];

        yield 'short flags glued' => [
            'mysqldump -hdb.example.com -ualice -P3306 -psecret mydb',
            ['host' => 'db.example.com', 'port' => 3306, 'user' => 'alice', 'database' => 'mydb'],
        ];

        yield 'lowercase -p is password not port' => [
            'mysqldump -h localhost -u root -psecret mydb',
            ['host' => 'localhost', 'port' => null, 'user' => 'root', 'database' => 'mydb'],
        ];

        yield '--databases takes precedence over positional' => [
            'mysqldump --host=localhost --user=root --databases db1 db2',
            ['host' => 'localhost', 'port' => null, 'user' => 'root', 'database' => 'db1'],
        ];

        yield 'long flags space form' => [
            'mysqldump --host db.example.com --user alice --port 3306 mydb',
            ['host' => 'db.example.com', 'port' => 3306, 'user' => 'alice', 'database' => 'mydb'],
        ];

        yield 'no host falls back to localhost' => [
            'mysqldump -u root mydb',
            ['host' => 'localhost', 'port' => null, 'user' => 'root', 'database' => 'mydb'],
        ];

        yield 'glued -h with IP' => [
            'mysqldump -h127.0.0.1 -uadmin mydb',
            ['host' => '127.0.0.1', 'port' => null, 'user' => 'admin', 'database' => 'mydb'],
        ];
    }

    /**
     * @param array{host: string, port: int|null, user: string|null, database: string|null} $expected
     */
    #[DataProvider('commandProvider')]
    public function testApplyExtractsConnectionInfo(string $command, array $expected): void
    {
        $bc = (new BackupConfiguration())
            ->setType(BackupConfiguration::TYPE_MYSQL)
            ->setDumpCommand($command);
        $entry = $this->makeEntry();

        $this->builder->apply($bc, $entry);

        self::assertNotNull($entry->mysql);
        self::assertSame($expected['host'], $entry->mysql->host);
        self::assertSame($expected['port'], $entry->mysql->port);
        self::assertSame($expected['user'], $entry->mysql->user);
        self::assertSame($expected['database'], $entry->mysql->database);
    }

    private function makeEntry(): InventoryEntry
    {
        return new InventoryEntry(id: 1, name: 'foo', type: BackupConfiguration::TYPE_MYSQL);
    }
}
