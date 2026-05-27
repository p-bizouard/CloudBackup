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
use App\Restore\Destination\MysqlRestoreDestinationStrategy;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

final class MysqlRestoreDestinationStrategyTest extends TestCase
{
    public function testSshModeWithStoredCreds(): void
    {
        $bundle = (new MysqlRestoreDestinationStrategy(new BundleBuilder(), new TemporaryPathResolver('/var/cloud-bkp')))
            ->renderBundle($this->buildDestination(RestoreDestination::CONNECTION_SSH, byo: false), $this->buildBackup());

        $script = $bundle->files['restore.sh']->contents;
        self::assertArrayHasKey('id_rsa', $bundle->files);
        self::assertArrayHasKey('db_password.txt', $bundle->files);
        self::assertStringContainsString('dba@10.0.0.5', $script);
        self::assertStringContainsString('--defaults-extra-file=$REMOTE_CNF', $script);
        self::assertStringContainsString('umask 077 && cat > $REMOTE_CNF', $script);
        self::assertStringContainsString('DB="appdb"', $script);
    }

    public function testSshModeWithBYOCredentials(): void
    {
        $bundle = (new MysqlRestoreDestinationStrategy(new BundleBuilder(), new TemporaryPathResolver('/var/cloud-bkp')))
            ->renderBundle($this->buildDestination(RestoreDestination::CONNECTION_SSH, byo: true), $this->buildBackup());

        $script = $bundle->files['restore.sh']->contents;
        self::assertArrayHasKey('id_rsa', $bundle->files);
        self::assertArrayNotHasKey('db_password.txt', $bundle->files);
        self::assertStringNotContainsString('--defaults-extra-file', $script);
        self::assertStringNotContainsString('db_password.txt', $script);
        self::assertStringContainsString('mysql  $DB', $script);
    }

    public function testDirectModeWithStoredCreds(): void
    {
        $bundle = (new MysqlRestoreDestinationStrategy(new BundleBuilder(), new TemporaryPathResolver('/var/cloud-bkp')))
            ->renderBundle($this->buildDestination(RestoreDestination::CONNECTION_DIRECT, byo: false), $this->buildBackup());

        $script = $bundle->files['restore.sh']->contents;
        self::assertArrayNotHasKey('id_rsa', $bundle->files);
        self::assertArrayHasKey('db_password.txt', $bundle->files);
        self::assertStringContainsString('DB_HOST="db.example.com"', $script);
        self::assertStringContainsString('DB_PORT="3306"', $script);
        self::assertStringContainsString('mysql -h "$DB_HOST" -P "$DB_PORT"', $script);
        self::assertStringContainsString('export MYSQL_PWD=', $script);
        self::assertStringNotContainsString('ssh', $script);
    }

    public function testDirectModeWithBYOCredentials(): void
    {
        $bundle = (new MysqlRestoreDestinationStrategy(new BundleBuilder(), new TemporaryPathResolver('/var/cloud-bkp')))
            ->renderBundle($this->buildDestination(RestoreDestination::CONNECTION_DIRECT, byo: true), $this->buildBackup());

        $script = $bundle->files['restore.sh']->contents;
        self::assertArrayNotHasKey('id_rsa', $bundle->files);
        self::assertArrayNotHasKey('db_password.txt', $bundle->files);
        self::assertStringNotContainsString('MYSQL_PWD', $script);
        self::assertStringNotContainsString('-u ', $script);
    }

    public function testBackupConfigurationOverridesWinOverDestination(): void
    {
        $destination = $this->buildDestination(RestoreDestination::CONNECTION_SSH, byo: false);
        $backup = $this->buildBackup();
        $config = $backup->getBackupConfiguration();
        self::assertNotNull($config);
        $config->setRestoreTargetDbUser('override_user');
        $config->setRestoreTargetDbPassword('override_pw');
        $config->setRestoreTargetDatabase('override_db');

        $bundle = (new MysqlRestoreDestinationStrategy(new BundleBuilder(), new TemporaryPathResolver('/var/cloud-bkp')))
            ->renderBundle($destination, $backup);

        $script = $bundle->files['restore.sh']->contents;
        self::assertStringContainsString('DB="override_db"', $script);
        self::assertStringContainsString('DB_USER="override_user"', $script);
        self::assertSame("override_pw\n", $bundle->files['db_password.txt']->contents);
    }

    public function testResticSourceShipsResticEnv(): void
    {
        $backup = $this->buildBackup(useRestic: true);
        $bundle = (new MysqlRestoreDestinationStrategy(new BundleBuilder(), new TemporaryPathResolver('/var/cloud-bkp')))
            ->renderBundle($this->buildDestination(RestoreDestination::CONNECTION_SSH, byo: false), $backup);

        self::assertArrayHasKey('restic.env', $bundle->files);
        self::assertArrayNotHasKey('rclone.conf', $bundle->files);

        $env = $bundle->files['restic.env']->contents;
        self::assertStringContainsString('RESTIC_REPOSITORY', $env);
        self::assertStringContainsString('RESTIC_PASSWORD', $env);

        $script = $bundle->files['restore.sh']->contents;
        self::assertStringContainsString('. ./restic.env', $script);
        self::assertStringContainsString('restic dump latest', $script);
        self::assertStringNotContainsString('rclone --config', $script);
    }

    public function testThrowsWhenCredsMissingAndByoFalse(): void
    {
        $destination = $this->buildDestination(RestoreDestination::CONNECTION_SSH, byo: false)
            ->setTargetDbPassword(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires target_db_user and target_db_password');
        (new MysqlRestoreDestinationStrategy(new BundleBuilder(), new TemporaryPathResolver('/var/cloud-bkp')))
            ->renderBundle($destination, $this->buildBackup());
    }

    private function buildDestination(string $connectionMode, bool $byo): RestoreDestination
    {
        $destination = new RestoreDestination()
            ->setName('DR mysql')
            ->setType(RestoreDestination::TYPE_MYSQL)
            ->setConnectionMode($connectionMode)
            ->setBringOwnCredentials($byo)
            ->setTargetDatabase('appdb');

        if (RestoreDestination::CONNECTION_SSH === $connectionMode) {
            $host = new Host()
                ->setName('target-db')
                ->setIp('10.0.0.5')
                ->setPort(22)
                ->setLogin('dba')
                ->setPrivateKey("-----BEGIN OPENSSH PRIVATE KEY-----\nFAKE\n-----END OPENSSH PRIVATE KEY-----");
            $destination->setHost($host);
        } else {
            $destination->setDirectHost('db.example.com')->setDirectPort(3306);
        }

        if (!$byo) {
            $destination->setTargetDbUser('appuser')->setTargetDbPassword('s3cret');
        }

        $slug = new ReflectionProperty(RestoreDestination::class, 'slug');
        $slug->setValue($destination, 'dr-mysql');

        return $destination;
    }

    private function buildBackup(bool $useRestic = false): Backup
    {
        $sourceStorage = new Storage();
        if ($useRestic) {
            $sourceStorage
                ->setType(Storage::TYPE_RESTIC)
                ->setName('source-restic')
                ->setResticRepo('s3:s3.amazonaws.com/backups')
                ->setResticPassword('repo-pass')
                ->setAwsAccessKeyId('AKIA')
                ->setAwsSecretAccessKey('SECRET');
        } else {
            $sourceStorage
                ->setType(Storage::TYPE_RCLONE)
                ->setName('source-store')
                ->setRcloneConfiguration("[remote]\ntype = s3\n");
        }

        $config = new BackupConfiguration();
        $config->setType(BackupConfiguration::TYPE_MYSQL);
        $config->setStorage($sourceStorage);
        $reflSub = new ReflectionProperty(BackupConfiguration::class, 'storageSubPath');
        $reflSub->setValue($config, $useRestic ? 'mysql-prod' : 'remote:/dumps/db.sql.gz');
        $reflSlug = new ReflectionProperty(BackupConfiguration::class, 'slug');
        $reflSlug->setValue($config, 'mysql-prod');

        $backup = new Backup();
        $idProp = new ReflectionProperty(Backup::class, 'id');
        $idProp->setValue($backup, 7);
        $backup->setBackupConfiguration($config);

        return $backup;
    }
}
