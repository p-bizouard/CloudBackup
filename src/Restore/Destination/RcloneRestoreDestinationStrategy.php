<?php

declare(strict_types=1);

namespace App\Restore\Destination;

use App\Entity\Backup;
use App\Entity\RestoreDestination;
use App\Entity\Storage;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.restore.destination_strategy')]
final class RcloneRestoreDestinationStrategy extends AbstractRestoreDestinationStrategy
{
    public function __construct(private readonly BundleBuilder $bundleBuilder)
    {
    }

    public function supports(RestoreDestination $destination): bool
    {
        return RestoreDestination::TYPE_RCLONE === $destination->getType();
    }

    public function renderBundle(RestoreDestination $destination, Backup $snapshot): RestoreBundle
    {
        $sourceStorage = $this->requireSourceStorage($snapshot);
        $targetStorage = $this->requireStorage($destination);

        if (Storage::TYPE_RCLONE !== $sourceStorage->getType()) {
            throw new RuntimeException(\sprintf('rclone restore destination requires the source Storage to be of type "rclone" (got "%s"). Restic/Kopia sources are not yet supported.', (string) $sourceStorage->getType()));
        }
        if (Storage::TYPE_RCLONE !== $targetStorage->getType()) {
            throw new RuntimeException(\sprintf('rclone restore destination requires the target Storage to be of type "rclone" (got "%s").', (string) $targetStorage->getType()));
        }

        $sourcePath = (string) $snapshot->getBackupConfiguration()?->getStorageSubPath();
        if ('' === $sourcePath) {
            throw new RuntimeException('Source BackupConfiguration has no storageSubPath.');
        }

        $targetPath = (string) $destination->getTargetPath();
        if ('' === $targetPath) {
            throw new RuntimeException('rclone restore destination requires target_path.');
        }

        $rcloneConfig = $this->bundleBuilder->mergeRcloneConfigs($sourceStorage, $targetStorage);

        $script = <<<BASH
            #!/usr/bin/env bash
            set -euo pipefail
            cd "\$(dirname "\$0")"

            echo "=== cloud-bkp rclone restore ==="
            cat MANIFEST.txt
            echo

            rclone --config rclone.conf copy "{$this->escape($sourcePath)}" "{$this->escape($targetPath)}" --progress
            echo "Restore complete."
            BASH;

        return new RestoreBundle(
            $this->bundleName($destination, $snapshot),
            [
                'restore.sh' => new BundleFile($script."\n", 0o700),
                'rclone.conf' => new BundleFile($rcloneConfig."\n", 0o600),
                'MANIFEST.txt' => new BundleFile($this->manifestText($destination, $snapshot), 0o644),
            ],
        );
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '"', '$', '`'], ['\\\\', '\\"', '\\$', '\\`'], $value);
    }
}
