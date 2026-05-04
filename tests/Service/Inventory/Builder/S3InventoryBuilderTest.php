<?php

namespace App\Tests\Service\Inventory\Builder;

use App\Entity\BackupConfiguration;
use App\Service\Inventory\Builder\RcloneIniParser;
use App\Service\Inventory\Builder\S3InventoryBuilder;
use PHPUnit\Framework\TestCase;

final class S3InventoryBuilderTest extends TestCase
{
    private S3InventoryBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new S3InventoryBuilder(new RcloneIniParser());
    }

    private const string S3_CONFIG = <<<'INI'
        [source]
        type = s3
        provider = Other
        env_auth = false
        access_key_id = fa0c54fef3c14eeaa479d5189df9a69c
        secret_access_key = aaaaaaaa
        region = gra
        endpoint = https://example.com
        acl = private
        INI;

    private const string SWIFT_CONFIG = <<<'INI'
        [src]
        type = swift
        user = foo
        key = bar
        INI;

    public function testDoesNotSupportNonRcloneType(): void
    {
        $bc = (new BackupConfiguration())
            ->setType(BackupConfiguration::TYPE_MYSQL)
            ->setRcloneConfiguration(self::S3_CONFIG);

        self::assertFalse($this->builder->supports($bc));
    }

    public function testDoesNotSupportRcloneWithoutS3Type(): void
    {
        $bc = (new BackupConfiguration())
            ->setType(BackupConfiguration::TYPE_RCLONE)
            ->setRcloneConfiguration(self::SWIFT_CONFIG);

        self::assertFalse($this->builder->supports($bc));
    }

    public function testDoesNotSupportEmptyConfig(): void
    {
        $bc = (new BackupConfiguration())
            ->setType(BackupConfiguration::TYPE_RCLONE)
            ->setRcloneConfiguration(null);

        self::assertFalse($this->builder->supports($bc));
    }

    public function testSupportsRcloneWithS3Type(): void
    {
        $bc = (new BackupConfiguration())
            ->setType(BackupConfiguration::TYPE_RCLONE)
            ->setRcloneConfiguration(self::S3_CONFIG);

        self::assertTrue($this->builder->supports($bc));
    }

    public function testBuildExtractsBucketAndCredentials(): void
    {
        $bc = (new BackupConfiguration())
            ->setType(BackupConfiguration::TYPE_RCLONE)
            ->setRcloneConfiguration(self::S3_CONFIG)
            ->setRemotePath('source:/bucket');

        self::assertSame(
            [
                's3' => [
                    'bucket' => 'bucket',
                    'access_key_id' => 'abcd54fef3c14eeaa479d5189df9a69c',
                    'region' => 'gra',
                    'endpoint' => 'https://example.com',
                ],
            ],
            $this->builder->build($bc),
        );
    }

    public function testBucketWithSubpathStripsToFirstSegment(): void
    {
        $bc = (new BackupConfiguration())
            ->setType(BackupConfiguration::TYPE_RCLONE)
            ->setRcloneConfiguration(self::S3_CONFIG)
            ->setRemotePath('source:/my-bucket/subpath/deeper');

        self::assertSame('my-bucket', $this->builder->build($bc)['s3']['bucket']);
    }

    public function testBucketNullWhenRemotePathMissing(): void
    {
        $bc = (new BackupConfiguration())
            ->setType(BackupConfiguration::TYPE_RCLONE)
            ->setRcloneConfiguration(self::S3_CONFIG)
            ->setRemotePath(null);

        self::assertNull($this->builder->build($bc)['s3']['bucket']);
    }

    public function testMissingS3FieldsReturnNull(): void
    {
        $minimalConfig = <<<'INI'
            [source]
            type = s3
            INI;

        $bc = (new BackupConfiguration())
            ->setType(BackupConfiguration::TYPE_RCLONE)
            ->setRcloneConfiguration($minimalConfig)
            ->setRemotePath('source:/bkt');

        self::assertSame(
            [
                's3' => [
                    'bucket' => 'bkt',
                    'access_key_id' => null,
                    'region' => null,
                    'endpoint' => null,
                ],
            ],
            $this->builder->build($bc),
        );
    }
}
