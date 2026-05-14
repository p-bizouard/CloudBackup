<?php

namespace App\Tests\Service\Inventory;

use App\ApiModel\HostEntry;
use App\ApiModel\InventoryEntry;
use App\Entity\BackupConfiguration;
use App\Repository\BackupRepository;
use App\Service\Inventory\BackupConfigurationInventoryBuilderInterface;
use App\Service\Inventory\DumpFragmentInventoryBuilderInterface;
use App\Service\Inventory\InventoryBuilder;
use PHPUnit\Framework\TestCase;

final class InventoryBuilderTest extends TestCase
{
    public function testEmitsNameAndType(): void
    {
        $bc = (new BackupConfiguration())
            ->setName('foo')
            ->setType(BackupConfiguration::TYPE_SSHFS)
            ->setDumpCommand('do-stuff');

        $builder = new InventoryBuilder([], $this->makeBackupRepository());

        $entries = $builder->build([$bc]);

        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame('foo', $entry->name);
        self::assertSame('sshfs', $entry->type);
        self::assertSame('do-stuff', $entry->dumpCommand);
        self::assertNull($entry->expectedSize);
        self::assertNull($entry->latestBackup);
        self::assertNull($entry->latestSuccessfulBackup);
        self::assertNull($entry->host);
    }

    public function testFallsBackToDumpCommandWhenNoDumpFragmentMatches(): void
    {
        $bc = (new BackupConfiguration())
            ->setName('foo')
            ->setType(BackupConfiguration::TYPE_SSH_RESTIC)
            ->setDumpCommand('restic backup');

        $hostBuilder = $this->makeBuilder(
            supports: true,
            apply: static function (BackupConfiguration $cfg, InventoryEntry $entry): void {
                $entry->host = new HostEntry(name: 'srv', ip: '1.2.3.4');
            },
        );

        $builder = new InventoryBuilder([$hostBuilder], $this->makeBackupRepository());
        $entry = $builder->build([$bc])[0];

        self::assertSame('ssh-restic', $entry->type);
        self::assertSame('restic backup', $entry->dumpCommand);
        self::assertNotNull($entry->host);
        self::assertSame('srv', $entry->host->name);
        self::assertSame('1.2.3.4', $entry->host->ip);
    }

    public function testDumpFragmentBuilderSuppressesDumpCommandFallback(): void
    {
        $bc = (new BackupConfiguration())
            ->setName('foo')
            ->setType(BackupConfiguration::TYPE_POSTGRESQL)
            ->setDumpCommand('pg_dump ...');

        $pgBuilder = $this->makeDumpFragmentBuilder(
            supports: true,
            apply: static function (BackupConfiguration $cfg, InventoryEntry $entry): void {
                $entry->postgresql = new \App\ApiModel\DbConnectionEntry(host: 'localhost', port: null, user: null, database: null);
            },
        );

        $builder = new InventoryBuilder([$pgBuilder], $this->makeBackupRepository());
        $entry = $builder->build([$bc])[0];

        self::assertNull($entry->dumpCommand);
        self::assertNotNull($entry->postgresql);
        self::assertSame('localhost', $entry->postgresql->host);
    }

    public function testIteratesAllBuildersAndMergesFragments(): void
    {
        $bc = (new BackupConfiguration())
            ->setName('foo')
            ->setType(BackupConfiguration::TYPE_MYSQL);

        $dbBuilder = $this->makeDumpFragmentBuilder(true, static function (BackupConfiguration $cfg, InventoryEntry $entry): void {
            $entry->mysql = new \App\ApiModel\DbConnectionEntry(host: 'localhost', port: null, user: null, database: null);
        });
        $hostBuilder = $this->makeBuilder(true, static function (BackupConfiguration $cfg, InventoryEntry $entry): void {
            $entry->host = new HostEntry(name: 'srv', ip: '1.2.3.4');
        });
        $unrelatedBuilder = $this->makeBuilder(false, static function (BackupConfiguration $cfg, InventoryEntry $entry): void {
            $entry->kubeNamespace = 'should-not-set';
        });

        $builder = new InventoryBuilder([$dbBuilder, $hostBuilder, $unrelatedBuilder], $this->makeBackupRepository());
        $entry = $builder->build([$bc])[0];

        self::assertSame('foo', $entry->name);
        self::assertSame('mysql', $entry->type);
        self::assertNotNull($entry->mysql);
        self::assertSame('localhost', $entry->mysql->host);
        self::assertNotNull($entry->host);
        self::assertSame('srv', $entry->host->name);
        self::assertNull($entry->kubeNamespace);
        self::assertNull($entry->dumpCommand);
    }

    private function makeBackupRepository(): BackupRepository
    {
        $repository = $this->createMock(BackupRepository::class);
        $repository->method('findLatestSuccessful')->willReturn(null);

        return $repository;
    }

    /**
     * @param \Closure(BackupConfiguration, InventoryEntry): void $apply
     */
    private function makeBuilder(bool $supports, \Closure $apply): BackupConfigurationInventoryBuilderInterface
    {
        return new class($supports, $apply) implements BackupConfigurationInventoryBuilderInterface {
            public function __construct(private readonly bool $supports, private readonly \Closure $apply)
            {
            }

            public function supports(BackupConfiguration $backupConfiguration): bool
            {
                return $this->supports;
            }

            public function apply(BackupConfiguration $backupConfiguration, InventoryEntry $entry): void
            {
                ($this->apply)($backupConfiguration, $entry);
            }
        };
    }

    /**
     * @param \Closure(BackupConfiguration, InventoryEntry): void $apply
     */
    private function makeDumpFragmentBuilder(bool $supports, \Closure $apply): DumpFragmentInventoryBuilderInterface
    {
        return new class($supports, $apply) implements DumpFragmentInventoryBuilderInterface {
            public function __construct(private readonly bool $supports, private readonly \Closure $apply)
            {
            }

            public function supports(BackupConfiguration $backupConfiguration): bool
            {
                return $this->supports;
            }

            public function apply(BackupConfiguration $backupConfiguration, InventoryEntry $entry): void
            {
                ($this->apply)($backupConfiguration, $entry);
            }
        };
    }
}
