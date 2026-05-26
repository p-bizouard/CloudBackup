<?php

declare(strict_types=1);

namespace App\Tests\Restore\Destination;

use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\Host;
use App\Entity\RestoreDestination;
use App\Entity\Storage;
use App\Backup\Path\TemporaryPathResolver;
use App\Restore\Destination\BundleBuilder;
use App\Restore\Destination\PostgresqlRestoreDestinationStrategy;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class PostgresqlRestoreDestinationStrategyTest extends TestCase
{
    public function testSshAllowOverwriteAddsCleanFlags(): void
    {
        $strategy = new PostgresqlRestoreDestinationStrategy(new BundleBuilder(), new TemporaryPathResolver('/var/cloud-bkp'));
        $backup = $this->buildBackup();

        $base = $this->buildDestination(RestoreDestination::CONNECTION_SSH, byo: false);
        $defaultBundle = $strategy->renderBundle($base, $backup);
        self::assertStringContainsString('ALLOW_OVERWRITE="${ALLOW_OVERWRITE:-false}"', $defaultBundle->files['restore.sh']->contents);
        self::assertArrayHasKey('db_password.txt', $defaultBundle->files);

        $overwrite = clone $base;
        $overwrite->setAllowOverwrite(true)->setDescription('approved');
        $overwriteBundle = $strategy->renderBundle($overwrite, $backup);
        self::assertStringContainsString('--clean --if-exists', $overwriteBundle->files['restore.sh']->contents);
        self::assertStringContainsString("PGPASSWORD='\$DB_PASSWORD' PGUSER='\$DB_USER' pg_restore", $overwriteBundle->files['restore.sh']->contents);
    }

    public function testSshByoOmitsPasswordFileAndEnv(): void
    {
        $bundle = (new PostgresqlRestoreDestinationStrategy(new BundleBuilder(), new TemporaryPathResolver('/var/cloud-bkp')))
            ->renderBundle($this->buildDestination(RestoreDestination::CONNECTION_SSH, byo: true), $this->buildBackup());

        $script = $bundle->files['restore.sh']->contents;
        self::assertArrayNotHasKey('db_password.txt', $bundle->files);
        self::assertStringNotContainsString('PGPASSWORD', $script);
        self::assertStringNotContainsString('PGUSER', $script);
        self::assertStringContainsString('ssh $SSH_OPTS "$TARGET" "pg_restore $PG_RESTORE_FLAGS"', $script);
    }

    public function testDirectModeWithStoredCreds(): void
    {
        $bundle = (new PostgresqlRestoreDestinationStrategy(new BundleBuilder(), new TemporaryPathResolver('/var/cloud-bkp')))
            ->renderBundle($this->buildDestination(RestoreDestination::CONNECTION_DIRECT, byo: false), $this->buildBackup());

        $script = $bundle->files['restore.sh']->contents;
        self::assertArrayNotHasKey('id_rsa', $bundle->files);
        self::assertArrayHasKey('db_password.txt', $bundle->files);
        self::assertStringContainsString('DB_HOST="pg.example.com"', $script);
        self::assertStringContainsString('DB_PORT="5432"', $script);
        self::assertStringContainsString('export PGPASSWORD=', $script);
        self::assertStringContainsString('export PGUSER=', $script);
        self::assertStringContainsString('pg_restore  $PG_RESTORE_FLAGS', $script);
        self::assertStringNotContainsString('ssh', $script);
    }

    public function testDirectModeByoOmitsPasswordFile(): void
    {
        $bundle = (new PostgresqlRestoreDestinationStrategy(new BundleBuilder(), new TemporaryPathResolver('/var/cloud-bkp')))
            ->renderBundle($this->buildDestination(RestoreDestination::CONNECTION_DIRECT, byo: true), $this->buildBackup());

        $script = $bundle->files['restore.sh']->contents;
        self::assertArrayNotHasKey('id_rsa', $bundle->files);
        self::assertArrayNotHasKey('db_password.txt', $bundle->files);
        self::assertStringNotContainsString('PGPASSWORD', $script);
        self::assertStringNotContainsString('PGUSER', $script);
    }

    private function buildDestination(string $connectionMode, bool $byo): RestoreDestination
    {
        $destination = new RestoreDestination()
            ->setName('DR pg')
            ->setType(RestoreDestination::TYPE_POSTGRESQL)
            ->setConnectionMode($connectionMode)
            ->setBringOwnCredentials($byo)
            ->setTargetDatabase('appdb');

        if (RestoreDestination::CONNECTION_SSH === $connectionMode) {
            $host = new Host()
                ->setName('target-pg')
                ->setIp('10.0.0.7')
                ->setLogin('postgres')
                ->setPrivateKey("KEY\n");
            $destination->setHost($host);
        } else {
            $destination->setDirectHost('pg.example.com')->setDirectPort(5432);
        }

        if (!$byo) {
            $destination->setTargetDbUser('appuser')->setTargetDbPassword('s3cret');
        }

        $slug = new ReflectionProperty(RestoreDestination::class, 'slug');
        $slug->setValue($destination, 'dr-pg');

        return $destination;
    }

    private function buildBackup(): Backup
    {
        $sourceStorage = new Storage()
            ->setType(Storage::TYPE_RCLONE)
            ->setName('source-store')
            ->setRcloneConfiguration("[remote]\ntype = s3\n");

        $config = new BackupConfiguration();
        $config->setType(BackupConfiguration::TYPE_POSTGRESQL);
        $config->setStorage($sourceStorage);
        $reflSub = new ReflectionProperty(BackupConfiguration::class, 'storageSubPath');
        $reflSub->setValue($config, 'remote:/dumps/db.dump');

        $backup = new Backup();
        $idProp = new ReflectionProperty(Backup::class, 'id');
        $idProp->setValue($backup, 9);
        $backup->setBackupConfiguration($config);

        return $backup;
    }
}
