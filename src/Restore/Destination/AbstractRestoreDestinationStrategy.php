<?php

declare(strict_types=1);

namespace App\Restore\Destination;

use App\Entity\Backup;
use App\Entity\Host;
use App\Entity\RestoreDestination;
use App\Entity\Storage;
use DateTimeImmutable;
use RuntimeException;

abstract class AbstractRestoreDestinationStrategy implements RestoreDestinationStrategyInterface
{
    abstract public function supports(RestoreDestination $destination): bool;

    public function validateConnectivity(RestoreDestination $destination): ConnectivityReport
    {
        return ConnectivityReport::notImplemented((string) $destination->getType());
    }

    abstract public function renderBundle(RestoreDestination $destination, Backup $snapshot): RestoreBundle;

    protected function bundleName(RestoreDestination $destination, Backup $snapshot): string
    {
        $slug = (string) $destination->getSlug();
        $backupId = (string) $snapshot->getId();

        return \sprintf('restore-%s-%s', $slug, $backupId);
    }

    protected function manifestText(RestoreDestination $destination, Backup $snapshot): string
    {
        $lines = [
            '# cloud-bkp restore bundle manifest',
            '',
            \sprintf('Destination:        %s', (string) $destination->getName()),
            \sprintf('Destination slug:   %s', (string) $destination->getSlug()),
            \sprintf('Destination type:   %s', (string) $destination->getType()),
            \sprintf('Backup id:          %s', (string) $snapshot->getId()),
            \sprintf('Backup created:     %s', $snapshot->getCreatedAt()?->format('c') ?? 'unknown'),
            \sprintf('Allow overwrite:    %s', $destination->isAllowOverwrite() ? 'true' : 'false'),
            \sprintf('Generated at:       %s', new DateTimeImmutable()->format('c')),
            '',
        ];

        $config = $snapshot->getBackupConfiguration();
        if (null !== $config) {
            $lines[] = \sprintf('Source backup name: %s', (string) $config);
            $lines[] = \sprintf('Source type:        %s', (string) $config->getType());
            $sourceStorage = $config->getStorage();
            if ($sourceStorage instanceof Storage) {
                $lines[] = \sprintf('Source storage:     %s (%s)', (string) $sourceStorage->getName(), (string) $sourceStorage->getType());
            }
        }

        $lines[] = '';
        $lines[] = 'Run: bash restore.sh';
        if ($destination->isAllowOverwrite()) {
            $lines[] = 'NOTE: allow_overwrite=true — destructive operations on target are enabled.';
        }

        return implode("\n", $lines)."\n";
    }

    protected function requireHost(RestoreDestination $destination): Host
    {
        $host = $destination->getHost();
        if (!$host instanceof Host) {
            throw new RuntimeException(\sprintf('Destination "%s" requires a Host but none is set.', (string) $destination->getName()));
        }

        return $host;
    }

    protected function requireStorage(RestoreDestination $destination): Storage
    {
        $storage = $destination->getStorage();
        if (!$storage instanceof Storage) {
            throw new RuntimeException(\sprintf('Destination "%s" requires a Storage but none is set.', (string) $destination->getName()));
        }

        return $storage;
    }

    protected function requireSourceStorage(Backup $snapshot): Storage
    {
        $config = $snapshot->getBackupConfiguration();
        $storage = $config?->getStorage();
        if (!$storage instanceof Storage) {
            throw new RuntimeException(\sprintf('Backup #%s has no source Storage.', (string) $snapshot->getId()));
        }

        return $storage;
    }

    /**
     * Network endpoint (host + port) for direct connection mode.
     *
     * @return array{host: string, port: int}
     */
    protected function requireDirectEndpoint(RestoreDestination $destination): array
    {
        $host = $destination->getDirectHost();
        $port = $destination->getDirectPort();
        if (null === $host || '' === trim($host) || null === $port) {
            throw new RuntimeException(\sprintf('Destination "%s" requires direct_host and direct_port for direct connection mode.', (string) $destination->getName()));
        }

        return ['host' => $host, 'port' => $port];
    }

    /**
     * Effective DB user/password/database resolves per-BC override or destination default.
     *
     * @return array{user: string, password: string, database: string}
     */
    protected function resolveDbCreds(RestoreDestination $destination, Backup $snapshot): array
    {
        $config = $snapshot->getBackupConfiguration();

        $user = $config?->getRestoreTargetDbUser() ?: (string) $destination->getTargetDbUser();
        $password = $config?->getRestoreTargetDbPassword() ?? $destination->getTargetDbPassword() ?? '';
        $database = $config?->getRestoreTargetDatabase() ?: (string) $destination->getTargetDatabase();

        return ['user' => $user, 'password' => $password, 'database' => $database];
    }
}
