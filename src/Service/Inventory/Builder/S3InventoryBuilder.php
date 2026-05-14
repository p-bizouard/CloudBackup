<?php

namespace App\Service\Inventory\Builder;

use App\ApiModel\InventoryEntry;
use App\ApiModel\S3Entry;
use App\Entity\BackupConfiguration;
use App\Service\Inventory\DumpFragmentInventoryBuilderInterface;

final class S3InventoryBuilder implements DumpFragmentInventoryBuilderInterface
{
    public function __construct(
        private readonly RcloneIniParser $rcloneIniParser,
    ) {
    }

    public function supports(BackupConfiguration $backupConfiguration): bool
    {
        if (BackupConfiguration::TYPE_RCLONE !== $backupConfiguration->getType()) {
            return false;
        }

        $config = $backupConfiguration->getRcloneConfiguration();
        if (null === $config || '' === trim($config)) {
            return false;
        }

        return 1 === preg_match('/^\s*type\s*=\s*s3\s*$/m', $config);
    }

    public function apply(BackupConfiguration $backupConfiguration, InventoryEntry $inventoryEntry): void
    {
        $config = $backupConfiguration->getRcloneConfiguration();
        if (null === $config || '' === trim($config)) {
            return;
        }

        $section = $this->findS3Section($config);
        if (null === $section) {
            return;
        }

        $inventoryEntry->s3 = new S3Entry(
            bucket: $this->extractBucket($backupConfiguration->getRemotePath()),
            accessKeyId: $section['access_key_id'] ?? null,
            region: $section['region'] ?? null,
            endpoint: $section['endpoint'] ?? null,
        );
    }

    /**
     * @return array<string, string>|null
     */
    private function findS3Section(string $rcloneConfiguration): ?array
    {
        foreach ($this->rcloneIniParser->parse($rcloneConfiguration) as $kv) {
            if (($kv['type'] ?? null) === 's3') {
                return $kv;
            }
        }

        return null;
    }

    private function extractBucket(?string $remotePath): ?string
    {
        if (null === $remotePath || !str_contains($remotePath, ':')) {
            return null;
        }
        [, $rest] = explode(':', $remotePath, 2);
        $rest = ltrim($rest, '/');
        if ('' === $rest) {
            return null;
        }
        $bucket = explode('/', $rest, 2)[0];

        return '' !== $bucket ? $bucket : null;
    }
}
