<?php

namespace App\Tests\Service\Inventory;

use App\Entity\BackupConfiguration;
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

        $builder = new InventoryBuilder([]);

        self::assertSame(
            [['name' => 'foo', 'type' => 'sshfs', 'dumpCommand' => 'do-stuff']],
            $builder->build([$bc]),
        );
    }

    public function testFallsBackToDumpCommandWhenNoDumpFragmentMatches(): void
    {
        $bc = (new BackupConfiguration())
            ->setName('foo')
            ->setType(BackupConfiguration::TYPE_SSH_RESTIC)
            ->setDumpCommand('restic backup');

        $hostBuilder = $this->makeBuilder(
            supports: true,
            output: ['host' => ['name' => 'srv', 'ip' => '1.2.3.4']],
        );

        $builder = new InventoryBuilder([$hostBuilder]);

        self::assertSame(
            [[
                'name' => 'foo',
                'type' => 'ssh-restic',
                'host' => ['name' => 'srv', 'ip' => '1.2.3.4'],
                'dumpCommand' => 'restic backup',
            ]],
            $builder->build([$bc]),
        );
    }

    public function testDumpFragmentBuilderSuppressesDumpCommandFallback(): void
    {
        $bc = (new BackupConfiguration())
            ->setName('foo')
            ->setType(BackupConfiguration::TYPE_POSTGRESQL)
            ->setDumpCommand('pg_dump ...');

        $pgBuilder = $this->makeDumpFragmentBuilder(
            supports: true,
            output: ['postgresql' => ['host' => 'localhost']],
        );

        $builder = new InventoryBuilder([$pgBuilder]);

        $entry = $builder->build([$bc])[0];

        self::assertArrayNotHasKey('dumpCommand', $entry);
        self::assertSame(['host' => 'localhost'], $entry['postgresql']);
    }

    public function testIteratesAllBuildersAndMergesFragments(): void
    {
        $bc = (new BackupConfiguration())
            ->setName('foo')
            ->setType(BackupConfiguration::TYPE_MYSQL);

        $dbBuilder = $this->makeDumpFragmentBuilder(true, ['mysql' => ['host' => 'localhost']]);
        $hostBuilder = $this->makeBuilder(true, ['host' => ['name' => 'srv', 'ip' => '1.2.3.4']]);
        $unrelatedBuilder = $this->makeBuilder(false, ['ignored' => true]);

        $builder = new InventoryBuilder([$dbBuilder, $hostBuilder, $unrelatedBuilder]);
        $entry = $builder->build([$bc])[0];

        self::assertSame(['name' => 'foo', 'type' => 'mysql'], array_intersect_key($entry, ['name' => 0, 'type' => 0]));
        self::assertSame(['host' => 'localhost'], $entry['mysql']);
        self::assertSame(['name' => 'srv', 'ip' => '1.2.3.4'], $entry['host']);
        self::assertArrayNotHasKey('ignored', $entry);
        self::assertArrayNotHasKey('dumpCommand', $entry);
    }

    /**
     * @param array<string, mixed> $output
     */
    private function makeBuilder(bool $supports, array $output): BackupConfigurationInventoryBuilderInterface
    {
        return new class($supports, $output) implements BackupConfigurationInventoryBuilderInterface {
            /**
             * @param array<string, mixed> $output
             */
            public function __construct(private readonly bool $supports, private readonly array $output)
            {
            }

            public function supports(BackupConfiguration $backupConfiguration): bool
            {
                return $this->supports;
            }

            public function build(BackupConfiguration $backupConfiguration): array
            {
                return $this->output;
            }
        };
    }

    /**
     * @param array<string, mixed> $output
     */
    private function makeDumpFragmentBuilder(bool $supports, array $output): DumpFragmentInventoryBuilderInterface
    {
        return new class($supports, $output) implements DumpFragmentInventoryBuilderInterface {
            /**
             * @param array<string, mixed> $output
             */
            public function __construct(private readonly bool $supports, private readonly array $output)
            {
            }

            public function supports(BackupConfiguration $backupConfiguration): bool
            {
                return $this->supports;
            }

            public function build(BackupConfiguration $backupConfiguration): array
            {
                return $this->output;
            }
        };
    }
}
