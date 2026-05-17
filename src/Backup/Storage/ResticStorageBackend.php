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
use Symfony\Component\PropertyAccess\PropertyAccess;

#[AutoconfigureTag('app.backup.storage_backend')]
class ResticStorageBackend implements StorageBackendInterface
{
    public const int INIT_TIMEOUT = 60;
    public const string INIT_REGEX = '/Fatal\: create key in repository.*repository master key and config already initialized|failed\: config file already exists/';
    public const int UPLOAD_TIMEOUT = 3600 * 4;
    public const int CHECK_TIMEOUT = 3600;
    public const int REPAIR_TIMEOUT = 3600;
    public const int LOCK_MAX_AGE_SECONDS = 3600 * 20;
    public const int SIZE_MAX_RATIO = 2;

    public function __construct(
        private readonly ProcessRunnerInterface $processRunner,
        private readonly BackupLogger $backupLogger,
    ) {
    }

    public function supports(Storage $storage): bool
    {
        return $storage->isRestic();
    }

    public function initRepository(Backup $backup): void
    {
        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('call %s::%s', self::class, __FUNCTION__));

        $env = $this->buildEnv($backup);
        $command = '(restic snapshots > /dev/null 2>&1) || restic init';

        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s`', $command));
        $outcome = $this->processRunner->runShell($command, $env, self::INIT_TIMEOUT);

        if (!$outcome->successful && !preg_match(self::INIT_REGEX, $outcome->errorOutput)) {
            $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing %s::%s - %s - %s', self::class, __FUNCTION__, $command, $outcome->errorOutput));
            throw new ProcessExecutionException($outcome);
        }
        $this->backupLogger->log($backup, Log::LOG_INFO, $outcome->output);
    }

    /**
     * Upload a local payload to the restic repository.
     *
     * @param array<string, string> $tags map of tag name => value
     */
    public function uploadLocal(Backup $backup, string $localPath, array $tags, string $host = 'cloudbackup'): void
    {
        $env = $this->buildEnv($backup);

        $tagFragments = [];
        $tagParameters = [];
        $idx = 0;
        foreach ($tags as $tagKey => $tagValue) {
            $placeholder = \sprintf('RESTIC_TAG_VAL_%d', $idx);
            $tagFragments[] = \sprintf('--tag %s="${%s}"', $tagKey, $placeholder);
            $tagParameters[$placeholder] = $tagValue;
            ++$idx;
        }

        $command = \sprintf(
            'restic backup %s --host %s "${DIRECTORY}"',
            implode(' ', $tagFragments),
            $host,
        );
        $parameters = $tagParameters + ['DIRECTORY' => $localPath];

        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, $this->backupLogger->formatParameters($parameters)));

        $outcome = $this->processRunner->runShell($command, $env + $parameters, self::UPLOAD_TIMEOUT);
        $this->failOrLog($backup, $outcome, 'restic upload');
    }

    public function healthCheck(Backup $backup, bool $tryRepair = true): void
    {
        $env = $this->buildEnv($backup);

        $checkCommand = 'restic check';
        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s`', $checkCommand));
        $outcome = $this->processRunner->runShell($checkCommand, $env, self::CHECK_TIMEOUT);

        if (!$outcome->successful) {
            $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing %s::%s - %s - %s', self::class, __FUNCTION__, $checkCommand, $outcome->errorOutput));

            if ($tryRepair) {
                $this->repair($backup, $outcome->errorOutput);
                $this->healthCheck($backup, false);
            } else {
                throw new ProcessExecutionException($outcome);
            }
        } else {
            $this->backupLogger->log($backup, Log::LOG_INFO, $outcome->output);
        }

        $tagsClause = $backup->getBackupConfiguration()->getResticCheckTags() ? '--tag "${RESTIC_CHECK_TAG}"' : '';
        $listCommand = \sprintf('restic snapshots --json --latest 1 -q %s', $tagsClause);
        $listParameters = ['RESTIC_CHECK_TAG' => $backup->getBackupConfiguration()->getResticCheckTags()];

        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $listCommand, nl2br($this->backupLogger->formatParameters($listParameters))));
        $outcome = $this->processRunner->runShell($listCommand, $env + $listParameters, self::CHECK_TIMEOUT);

        if (!$outcome->successful) {
            $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing %s::%s - %s - %s', self::class, __FUNCTION__, $listCommand, $outcome->errorOutput));
            throw new ProcessExecutionException($outcome);
        }

        $json = json_decode($outcome->output, true, 512, \JSON_THROW_ON_ERROR);
        if (null === $json || 0 === (is_countable($json) ? \count($json) : 0)) {
            $message = \sprintf('Cannot decode json : %s', $outcome->output);
            $this->backupLogger->log($backup, Log::LOG_ERROR, $message);
            throw new Exception($message);
        }

        usort($json, static fn ($a, $b) => new DateTime($b['time']) <=> new DateTime($a['time']));

        $this->backupLogger->log($backup, Log::LOG_INFO, json_encode($json, \JSON_PRETTY_PRINT));

        $lastBackup = new DateTime(preg_replace('/(\d+\-\d+\-\d+T\d+:\d+:\d+)\..*/', '$1', (string) $json[0]['time']));
        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('Last backup : %s', $lastBackup->format('d/m/Y H:i')));

        if ($lastBackup < new DateTime('yesterday')) {
            $message = 'Last backup older than 24h';
            $this->backupLogger->log($backup, Log::LOG_ERROR, $message);
            throw new Exception($message);
        }

        $sizes = [
            '--mode restore-size latest' => 'resticSize',
            '--mode raw-data latest' => 'resticDedupSize',
            '--mode restore-size' => 'resticTotalSize',
            '--mode raw-data' => 'resticTotalDedupSize',
        ];

        foreach ($sizes as $suffix => $attribute) {
            $command = \sprintf('restic stats --json %s %s', $suffix, $tagsClause);
            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, nl2br($this->backupLogger->formatParameters($listParameters))));

            $outcome = $this->processRunner->runShell($command, $env + $listParameters, self::CHECK_TIMEOUT);
            if (!$outcome->successful) {
                $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing %s::%s - %s - %s', self::class, __FUNCTION__, $command, $outcome->errorOutput));
                throw new ProcessExecutionException($outcome);
            }

            $statsJson = json_decode($outcome->output, true, 512, \JSON_THROW_ON_ERROR);
            if (null === $statsJson) {
                $message = \sprintf('Cannot decode json : %s', $outcome->output);
                $this->backupLogger->log($backup, Log::LOG_ERROR, $message);
                throw new Exception($message);
            }

            $this->backupLogger->log($backup, Log::LOG_INFO, json_encode($statsJson, \JSON_PRETTY_PRINT));

            PropertyAccess::createPropertyAccessor()->setValue($backup, $attribute, $statsJson['total_size']);
            $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('Stat %s : %s', $attribute, StringUtils::humanizeFileSize($statsJson['total_size'])));
        }

        if (null === $backup->getSize()) {
            $backup->setSize($backup->getResticSize());
        }

        $minimumBackupSize = (int) $backup->getBackupConfiguration()->getMinimumBackupSize();
        $resticSize = (int) $backup->getResticSize();

        if ($resticSize <= $minimumBackupSize) {
            $message = \sprintf('Restic failed. Minimum backup size not met : %s < %s', StringUtils::humanizeFileSize($resticSize), StringUtils::humanizeFileSize($minimumBackupSize));
            $this->backupLogger->log($backup, Log::LOG_NOTICE, $message);
            throw new Exception($message);
        }

        if ($minimumBackupSize > 0 && $resticSize > self::SIZE_MAX_RATIO * $minimumBackupSize) {
            $newMinimumBackupSize = (int) ($minimumBackupSize * (1 + (self::SIZE_MAX_RATIO - 1) / 2));
            $message = \sprintf('Restic backup size %s exceeds %dx the expected size %s. Updating expected size to %s.', StringUtils::humanizeFileSize($resticSize), self::SIZE_MAX_RATIO, StringUtils::humanizeFileSize($minimumBackupSize), StringUtils::humanizeFileSize($newMinimumBackupSize));
            $this->backupLogger->log($backup, Log::LOG_NOTICE, $message);
            $backup->getBackupConfiguration()->setMinimumBackupSize((string) $newMinimumBackupSize);
        }
    }

    public function prune(Backup $backup): void
    {
        $env = $this->buildEnv($backup);
        $command = \sprintf('restic forget --prune %s', $backup->getBackupConfiguration()->getResticForgetArgs());

        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s`', $command));
        $outcome = $this->processRunner->runShell($command, $env, self::UPLOAD_TIMEOUT);
        $this->failOrLog($backup, $outcome, 'restic forget');
    }

    public function repair(Backup $backup, ?string $errorOutput = null): void
    {
        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('call %s::%s', self::class, __FUNCTION__));

        $env = $this->buildEnv($backup);
        $commands = [
            'restic repair index',
            'restic prune',
            'restic check',
        ];

        if (null !== $errorOutput
            && preg_match('/repository is already locked/m', $errorOutput)
            && preg_match('/lock was created at .* \((\d+)h(\d+)m(\d+)\.(\d+)s ago/m', $errorOutput, $matches)
        ) {
            $hours = (int) $matches[1];
            $minutes = (int) $matches[2];
            $seconds = (int) $matches[3];
            $totalSeconds = ($hours * 3600) + ($minutes * 60) + $seconds;

            $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Repository is locked since %dh%dm%ds', $hours, $minutes, $seconds));

            if ($totalSeconds > self::LOCK_MAX_AGE_SECONDS) {
                $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Repository is locked since %dh%dm%ds which is more than the max allowed lock age of %ds', $hours, $minutes, $seconds, self::LOCK_MAX_AGE_SECONDS));
                array_unshift($commands, 'restic unlock');
            }
        }

        foreach ($commands as $command) {
            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s`', $command));
            $outcome = $this->processRunner->runShell($command, $env, self::REPAIR_TIMEOUT);
            $this->failOrLog($backup, $outcome, \sprintf('%s::%s - %s', self::class, __FUNCTION__, $command));
        }
    }

    /** @return array<string, scalar|null> */
    private function buildEnv(Backup $backup): array
    {
        return $backup->getBackupConfiguration()->getStorage()->getEnv()
            + $backup->getBackupConfiguration()->getResticEnv();
    }

    private function failOrLog(Backup $backup, ProcessOutcome $processOutcome, string $context): void
    {
        if (!$processOutcome->successful) {
            $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing %s - %s', $context, $processOutcome->errorOutput));
            throw new ProcessExecutionException($processOutcome);
        }
        $this->backupLogger->log($backup, Log::LOG_INFO, $processOutcome->output);
    }
}
