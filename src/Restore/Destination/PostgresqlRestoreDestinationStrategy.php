<?php

declare(strict_types=1);

namespace App\Restore\Destination;

use App\Backup\Path\TemporaryPathResolver;
use App\Entity\Backup;
use App\Entity\RestoreDestination;
use App\Entity\Storage;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.restore.destination_strategy')]
final class PostgresqlRestoreDestinationStrategy extends AbstractRestoreDestinationStrategy
{
    public function __construct(
        private readonly BundleBuilder $bundleBuilder,
        private readonly TemporaryPathResolver $temporaryPathResolver,
    ) {
    }

    public function supports(RestoreDestination $destination): bool
    {
        return RestoreDestination::TYPE_POSTGRESQL === $destination->getType();
    }

    public function renderBundle(RestoreDestination $destination, Backup $snapshot): RestoreBundle
    {
        $sourceStorage = $this->requireSourceStorage($snapshot);
        if (!\in_array($sourceStorage->getType(), [Storage::TYPE_RCLONE, Storage::TYPE_RESTIC], true)) {
            throw new RuntimeException(\sprintf('postgresql restore destination supports source Storage of type "rclone" or "restic" (got "%s").', (string) $sourceStorage->getType()));
        }

        $resolved = $this->resolveDbCreds($destination, $snapshot);
        $database = $resolved['database'];
        if ('' === $database) {
            throw new RuntimeException('postgresql restore destination requires target_database (on destination or BackupConfiguration override).');
        }

        $byo = $destination->isBringOwnCredentials();
        $dbUser = $resolved['user'];
        $dbPassword = $resolved['password'];
        if (!$byo && ('' === $dbUser || '' === $dbPassword)) {
            throw new RuntimeException('postgresql restore destination requires target_db_user and target_db_password (or bring_own_credentials=true).');
        }

        $payloadFetch = $this->buildPayloadFetch($sourceStorage, $snapshot);
        $allowOverwrite = $destination->isAllowOverwrite() ? 'true' : 'false';

        $files = [
            'MANIFEST.txt' => new BundleFile($this->manifestText($destination, $snapshot), 0o644),
        ];
        $files += $payloadFetch['files'];

        if (!$byo) {
            $files['db_password.txt'] = new BundleFile($dbPassword."\n", 0o600);
        }

        if (RestoreDestination::CONNECTION_SSH === $destination->getConnectionMode()) {
            $host = $this->requireHost($destination);
            $ssh = $this->bundleBuilder->sshConnectionParts($host);
            $files['id_rsa'] = new BundleFile($this->bundleBuilder->sshPrivateKey($host), 0o600);
            $files['restore.sh'] = new BundleFile($this->renderSshScript($ssh, $database, $dbUser, $byo, $allowOverwrite, $payloadFetch['command'], $payloadFetch['envSource'])."\n", 0o700);
        } else {
            $endpoint = $this->requireDirectEndpoint($destination);
            $files['restore.sh'] = new BundleFile($this->renderDirectScript($endpoint, $database, $dbUser, $byo, $allowOverwrite, $payloadFetch['command'], $payloadFetch['envSource'])."\n", 0o700);
        }

        return new RestoreBundle($this->bundleName($destination, $snapshot), $files);
    }

    /**
     * @return array{command: string, envSource: string, files: array<string, BundleFile>}
     */
    private function buildPayloadFetch(Storage $sourceStorage, Backup $snapshot): array
    {
        if (Storage::TYPE_RCLONE === $sourceStorage->getType()) {
            $sourcePath = (string) $snapshot->getBackupConfiguration()?->getStorageSubPath();
            if ('' === $sourcePath) {
                throw new RuntimeException('Source BackupConfiguration has no storageSubPath.');
            }

            return [
                'command' => \sprintf('rclone --config rclone.conf cat "%s"', $this->escape($sourcePath)),
                'envSource' => '',
                'files' => [
                    'rclone.conf' => new BundleFile($this->bundleBuilder->mergeRcloneConfigs($sourceStorage, null)."\n", 0o600),
                ],
            ];
        }

        $payloadPath = $this->temporaryPathResolver->resolve($snapshot);
        $config = $snapshot->getBackupConfiguration();
        $resticEnv = $sourceStorage->getEnv() + ($config?->getResticEnv() ?? []);
        if (!isset($resticEnv['RESTIC_REPOSITORY'], $resticEnv['RESTIC_PASSWORD'])) {
            throw new RuntimeException('Restic source storage is missing RESTIC_REPOSITORY or RESTIC_PASSWORD env entries (check Storage.resticRepo and Storage.resticPassword).');
        }

        return [
            'command' => \sprintf('restic dump latest "%s"', $this->escape($payloadPath)),
            'envSource' => "set -a\n. ./restic.env\nset +a",
            'files' => [
                'restic.env' => new BundleFile($this->bundleBuilder->buildEnvFile($resticEnv), 0o600),
            ],
        ];
    }

    /**
     * @param array{login: string, ip: string, port: int} $ssh
     */
    private function renderSshScript(array $ssh, string $database, string $dbUser, bool $byo, string $allowOverwrite, string $payloadCommand, string $envSource): string
    {
        if ($byo) {
            $authSetup = '';
            $envPrefix = '';
        } else {
            $authSetup = <<<BASH
                chmod 600 db_password.txt
                DB_USER="{$this->escape($dbUser)}"
                DB_PASSWORD="\$(cat db_password.txt)"
                BASH;
            $envPrefix = "PGPASSWORD='\$DB_PASSWORD' PGUSER='\$DB_USER' ";
        }

        return <<<BASH
            #!/usr/bin/env bash
            set -euo pipefail
            cd "\$(dirname "\$0")"

            echo "=== cloud-bkp postgresql restore (ssh) ==="
            cat MANIFEST.txt
            echo

            {$envSource}

            chmod 600 id_rsa
            SSH_OPTS="-i id_rsa -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/dev/null -p {$ssh['port']}"
            TARGET="{$this->escape($ssh['login'])}@{$this->escape($ssh['ip'])}"
            DB="{$this->escape($database)}"
            ALLOW_OVERWRITE="\${ALLOW_OVERWRITE:-{$allowOverwrite}}"

            {$authSetup}

            PG_RESTORE_FLAGS="--dbname=\$DB"
            if [ "\$ALLOW_OVERWRITE" = "true" ]; then
                PG_RESTORE_FLAGS="\$PG_RESTORE_FLAGS --clean --if-exists"
            fi

            {$payloadCommand} | ssh \$SSH_OPTS "\$TARGET" "{$envPrefix}pg_restore \$PG_RESTORE_FLAGS"
            echo "Restore complete."
            BASH;
    }

    /**
     * @param array{host: string, port: int} $endpoint
     */
    private function renderDirectScript(array $endpoint, string $database, string $dbUser, bool $byo, string $allowOverwrite, string $payloadCommand, string $envSource): string
    {
        if ($byo) {
            $authSetup = '';
            $userArgs = '';
        } else {
            $authSetup = <<<BASH
                chmod 600 db_password.txt
                export PGPASSWORD="\$(cat db_password.txt)"
                export PGUSER="{$this->escape($dbUser)}"
                BASH;
            $userArgs = '';
        }

        return <<<BASH
            #!/usr/bin/env bash
            set -euo pipefail
            cd "\$(dirname "\$0")"

            echo "=== cloud-bkp postgresql restore (direct) ==="
            cat MANIFEST.txt
            echo

            {$envSource}

            DB_HOST="{$this->escape($endpoint['host'])}"
            DB_PORT="{$endpoint['port']}"
            DB="{$this->escape($database)}"
            ALLOW_OVERWRITE="\${ALLOW_OVERWRITE:-{$allowOverwrite}}"

            {$authSetup}

            PG_RESTORE_FLAGS="-h \$DB_HOST -p \$DB_PORT --dbname=\$DB"
            if [ "\$ALLOW_OVERWRITE" = "true" ]; then
                PG_RESTORE_FLAGS="\$PG_RESTORE_FLAGS --clean --if-exists"
            fi

            {$payloadCommand} | pg_restore {$userArgs} \$PG_RESTORE_FLAGS
            echo "Restore complete."
            BASH;
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '"', '$', '`'], ['\\\\', '\\"', '\\$', '\\`'], $value);
    }
}
