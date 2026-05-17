<?php

declare(strict_types=1);

namespace App\Backup\Storage;

use App\Backup\Logging\BackupLogger;
use App\Backup\Process\ProcessExecutionException;
use App\Backup\Process\ProcessOutcome;
use App\Backup\Process\ProcessRunnerInterface;
use App\Entity\Backup;
use App\Entity\Log;
use App\Entity\Storage;
use App\Utils\StringUtils;
use DateTime;
use Exception;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Filesystem\Filesystem;

#[AutoconfigureTag('app.backup.storage_backend')]
final class KopiaStorageBackend implements StorageBackendInterface
{
    public const int CHECK_TIMEOUT = 3600;
    public const int SIZE_MAX_RATIO = 2;

    public function __construct(
        private readonly ProcessRunnerInterface $processRunner,
        private readonly BackupLogger $backupLogger,
    ) {
    }

    public function supports(Storage $storage): bool
    {
        return $storage->isKopia();
    }

    public function initRepository(Backup $backup): void
    {
        // Kopia repositories are connected per healthCheck call, no global init step.
    }

    public function healthCheck(Backup $backup, bool $tryRepair = true): void
    {
        $storage = $backup->getBackupConfiguration()->getStorage();
        $env = $storage->getEnv() + $backup->getBackupConfiguration()->getKopiaEnv();

        $filesystem = new Filesystem();
        $configFile = $filesystem->tempnam('/tmp', 'kopia_');

        try {
            $parameters = ['KOPIA_CONFIG' => $configFile];
            $prefixClause = '';
            $storageSubPath = trim((string) $backup->getBackupConfiguration()->getStorageSubPath());
            if ('' !== $storageSubPath) {
                $prefixClause = ' --prefix="${KOPIA_PREFIX}"';
                $parameters['KOPIA_PREFIX'] = $storageSubPath;
            }

            $command = \sprintf(
                'kopia repository connect %s %s%s --config-file="${KOPIA_CONFIG}"',
                escapeshellarg((string) $storage->getKopiaBackend()),
                (string) $storage->getKopiaConnectArgs(),
                $prefixClause,
            );
            $this->run($backup, $command, $env + $parameters, $parameters);

            $command = 'kopia --config-file="${KOPIA_CONFIG}" snapshot verify --verify-files-percent=0';
            $this->run($backup, $command, $env + $parameters, $parameters);

            $tagsClause = '';
            $listParameters = $parameters;
            $rawTags = trim((string) $backup->getBackupConfiguration()->getKopiaCheckTags());
            if ('' !== $rawTags) {
                foreach (preg_split('/\s+/', $rawTags) as $idx => $token) {
                    $key = \sprintf('KOPIA_CHECK_TAG_%d', $idx);
                    $tagsClause .= \sprintf(' --tags "${%s}"', $key);
                    $listParameters[$key] = $token;
                }
            }
            $command = 'kopia --config-file="${KOPIA_CONFIG}" snapshot list --json'.$tagsClause;
            $outcome = $this->run($backup, $command, $env + $listParameters, $listParameters);

            $snapshots = json_decode($outcome->output, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($snapshots) || [] === $snapshots) {
                $message = \sprintf('Cannot decode json or empty snapshot list : %s', $outcome->output);
                $this->backupLogger->log($backup, Log::LOG_ERROR, $message);
                throw new Exception($message);
            }

            usort($snapshots, static fn ($a, $b) => new DateTime($b['endTime']) <=> new DateTime($a['endTime']));

            $this->backupLogger->log($backup, Log::LOG_INFO, json_encode($snapshots, \JSON_PRETTY_PRINT));

            $lastBackup = new DateTime(preg_replace('/(\d+\-\d+\-\d+T\d+:\d+:\d+)\..*/', '$1', (string) $snapshots[0]['endTime']));
            $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('Last backup : %s', $lastBackup->format('d/m/Y H:i')));

            if ($lastBackup < new DateTime('yesterday')) {
                $message = 'Last backup older than 24h';
                $this->backupLogger->log($backup, Log::LOG_ERROR, $message);
                throw new Exception($message);
            }

            $backup->setKopiaSize((int) ($snapshots[0]['stats']['totalSize'] ?? 0));
            $backup->setKopiaTotalSize(array_sum(array_map(
                static fn (array $snapshot): int => (int) ($snapshot['stats']['totalSize'] ?? 0),
                $snapshots,
            )));
            $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('Stat kopiaSize : %s', StringUtils::humanizeFileSize($backup->getKopiaSize())));
            $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('Stat kopiaTotalSize : %s', StringUtils::humanizeFileSize($backup->getKopiaTotalSize())));

            $command = 'kopia --config-file="${KOPIA_CONFIG}" blob stats --raw';
            $outcome = $this->run($backup, $command, $env + $parameters, $parameters);

            if (1 !== preg_match('/^Total:\s+(\d+)\s*$/m', $outcome->output, $matches)) {
                $message = \sprintf('Cannot read total dedup size from blob stats output : %s', $outcome->output);
                $this->backupLogger->log($backup, Log::LOG_ERROR, $message);
                throw new Exception($message);
            }

            $backup->setKopiaTotalDedupSize((int) $matches[1]);
            $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('Stat kopiaTotalDedupSize : %s', StringUtils::humanizeFileSize($backup->getKopiaTotalDedupSize())));

            if (null === $backup->getSize()) {
                $backup->setSize($backup->getKopiaSize());
            }

            $minimumBackupSize = (int) $backup->getBackupConfiguration()->getMinimumBackupSize();
            $kopiaSize = (int) $backup->getKopiaSize();

            if ($kopiaSize <= $minimumBackupSize) {
                $message = \sprintf('Kopia failed. Minimum backup size not met : %s < %s', StringUtils::humanizeFileSize($kopiaSize), StringUtils::humanizeFileSize($minimumBackupSize));
                $this->backupLogger->log($backup, Log::LOG_NOTICE, $message);
                throw new Exception($message);
            }

            if ($minimumBackupSize > 0 && $kopiaSize > self::SIZE_MAX_RATIO * $minimumBackupSize) {
                $newMinimumBackupSize = (int) ($minimumBackupSize * (1 + (self::SIZE_MAX_RATIO - 1) / 2));
                $message = \sprintf('Kopia backup size %s exceeds %dx the expected size %s. Updating expected size to %s.', StringUtils::humanizeFileSize($kopiaSize), self::SIZE_MAX_RATIO, StringUtils::humanizeFileSize($minimumBackupSize), StringUtils::humanizeFileSize($newMinimumBackupSize));
                $this->backupLogger->log($backup, Log::LOG_NOTICE, $message);
                $backup->getBackupConfiguration()->setMinimumBackupSize((string) $newMinimumBackupSize);
            }
        } finally {
            @unlink($configFile);
        }
    }

    public function prune(Backup $backup): void
    {
        // Kopia retention is configured server-side; no explicit prune action runs here.
    }

    /**
     * @param array<string, scalar|null> $env
     * @param array<string, scalar|null> $parameters
     */
    private function run(Backup $backup, string $command, array $env, array $parameters): ProcessOutcome
    {
        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, $this->backupLogger->formatParameters($parameters)));
        $outcome = $this->processRunner->runShell($command, $env, self::CHECK_TIMEOUT);

        if (!$outcome->successful) {
            $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing %s::healthCheck - %s - %s', self::class, $command, $outcome->errorOutput));
            throw new ProcessExecutionException($outcome);
        }

        $this->backupLogger->log($backup, Log::LOG_INFO, $outcome->output);

        return $outcome;
    }
}
