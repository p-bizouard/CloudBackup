<?php

declare(strict_types=1);

namespace App\Backup\Source;

use App\Backup\Logging\BackupLogger;
use App\Backup\Path\TemporaryPathResolver;
use App\Backup\Process\ProcessExecutionException;
use App\Backup\Process\ProcessRunnerInterface;
use App\Backup\Storage\ResticStorageBackend;
use App\Backup\Storage\StorageBackendRegistry;
use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\Log;
use App\Utils\StringUtils;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.backup.source')]
final class OsInstanceSource extends AbstractBackupSource
{
    public const int SNAPSHOT_TIMEOUT = 60;
    public const int IMAGE_LIST_TIMEOUT = 60;
    public const int DOWNLOAD_TIMEOUT = 3600 * 4;

    public function __construct(
        ProcessRunnerInterface $processRunner,
        BackupLogger $backupLogger,
        TemporaryPathResolver $temporaryPathResolver,
        StorageBackendRegistry $storageBackendRegistry,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct($processRunner, $backupLogger, $temporaryPathResolver, $storageBackendRegistry);
    }

    public function supports(BackupConfiguration $backupConfiguration): bool
    {
        return BackupConfiguration::TYPE_OS_INSTANCE === $backupConfiguration->getType();
    }

    public function onDump(Backup $backup): void
    {
        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('call %s::%s', self::class, __FUNCTION__));

        $existingStatus = $this->getSnapshotStatus($backup);
        if (null !== $existingStatus) {
            $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('Snapshot already found with %s', $existingStatus));

            return;
        }

        $env = $backup->getBackupConfiguration()->getOsInstance()->getOSEnv();

        $command = 'openstack server image create --name ${BACKUP_NAME} ${OS_INSTANCE_ID}';
        $parameters = [
            'BACKUP_NAME' => $backup->getName(),
            'OS_INSTANCE_ID' => $backup->getBackupConfiguration()->getOsInstance()->getId(),
        ];

        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, $this->backupLogger->formatParameters($parameters)));
        $outcome = $this->processRunner->runShell($command, $env + $parameters, self::SNAPSHOT_TIMEOUT);

        if (!$outcome->successful) {
            $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing backup - openstack server image create - %s', $outcome->errorOutput));
            throw new ProcessExecutionException($outcome);
        }
        $this->backupLogger->log($backup, Log::LOG_INFO, $outcome->output);
    }

    public function getSnapshotStatus(Backup $backup): ?string
    {
        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('call %s::%s', self::class, __FUNCTION__));

        $image = $this->lookupImage($backup);
        if (null === $image) {
            return null;
        }

        if (null === $backup->getOsImageId() || null === $backup->getSize()) {
            $backup->setOsImageId($image['ID']);
            $backup->setChecksum($image['Checksum']);
            $backup->setSize($image['Size']);

            $this->entityManager->persist($backup);
            $this->entityManager->flush();
        }

        return $image['Status'];
    }

    /**
     * Look up the OpenStack image for this backup without mutating or persisting state.
     *
     * @return array<string, mixed>|null
     */
    private function lookupImage(Backup $backup): ?array
    {
        $env = $backup->getBackupConfiguration()->getOsInstance()->getOSEnv();

        $command = 'openstack image list --private --name ${BACKUP_NAME} --long -f json';
        $parameters = ['BACKUP_NAME' => $backup->getName()];

        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, $this->backupLogger->formatParameters($parameters)));
        $outcome = $this->processRunner->runShell($command, $env + $parameters, self::IMAGE_LIST_TIMEOUT);

        if (!$outcome->successful) {
            $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing snapshot - openstack image list - %s', $outcome->errorOutput));
            throw new ProcessExecutionException($outcome);
        }
        $this->backupLogger->log($backup, Log::LOG_INFO, $outcome->output);

        $output = json_decode($outcome->output, true, 512, \JSON_THROW_ON_ERROR);
        if (null === $output) {
            $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing snapshot - openstack image list - %s - %s', $outcome->output, $outcome->errorOutput));
            throw new ProcessExecutionException($outcome);
        }

        if ((is_countable($output) ? \count($output) : 0) === 0) {
            return null;
        }

        return $output[0];
    }

    public function download(Backup $backup): void
    {
        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('call %s::%s', self::class, __FUNCTION__));

        $env = $backup->getBackupConfiguration()->getOsInstance()->getOSEnv();

        if (!$backup->getOsImageId()) {
            $command = 'openstack image list --private --name ${BACKUP_NAME} --long -f json';
            $parameters = ['BACKUP_NAME' => $backup->getName()];

            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, $this->backupLogger->formatParameters($parameters)));
            $outcome = $this->processRunner->runShell($command, $env + $parameters, self::IMAGE_LIST_TIMEOUT);

            if (!$outcome->successful) {
                $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing download - openstack image list - %s', $outcome->errorOutput));
                throw new ProcessExecutionException($outcome);
            }
            $this->backupLogger->log($backup, Log::LOG_INFO, $outcome->output);

            $output = json_decode($outcome->output, true, 512, \JSON_THROW_ON_ERROR);
            if (null === $output) {
                $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing download - openstack image list - %s - %s', $outcome->output, $outcome->errorOutput));
                throw new ProcessExecutionException($outcome);
            }

            if ((is_countable($output) ? \count($output) : 0) === 0) {
                return;
            }

            $backup->setOsImageId($output[0]['ID']);
            $backup->setChecksum($output[0]['Checksum']);
            $backup->setSize($output[0]['Size']);

            $this->entityManager->persist($backup);
            $this->entityManager->flush();
        }

        $imageDestination = $this->pathResolver->resolve($backup);

        if (file_exists($imageDestination) && filesize($imageDestination) === $backup->getSize()) {
            $this->backupLogger->log($backup, Log::LOG_NOTICE, 'Openstack image already downloaded');

            return;
        }

        $command = 'openstack image save --file ${IMAGE_DESTINATION} ${IMAGE_ID}';
        $parameters = [
            'IMAGE_DESTINATION' => $imageDestination,
            'IMAGE_ID' => $backup->getOsImageId(),
        ];

        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, $this->backupLogger->formatParameters($parameters)));
        $outcome = $this->processRunner->runShell($command, $env + $parameters, self::DOWNLOAD_TIMEOUT);

        if (!$outcome->successful) {
            $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing download - openstack image save - %s', $outcome->errorOutput));
            $this->deleteOsImage($backup);
            @unlink($imageDestination);
            throw new ProcessExecutionException($outcome);
        }
        $this->backupLogger->log($backup, Log::LOG_INFO, $outcome->output);

        $this->backupLogger->log($backup, Log::LOG_NOTICE, 'Openstack image downloaded');
    }

    public function isDownloadComplete(Backup $backup): bool
    {
        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('call %s::%s', self::class, __FUNCTION__));

        $imageDestination = $this->pathResolver->resolve($backup);

        if (!$backup->getSize()) {
            $this->backupLogger->log($backup, Log::LOG_NOTICE, 'Openstack image not backuped');

            return false;
        }

        if (!file_exists($imageDestination) || filesize($imageDestination) !== $backup->getSize()) {
            $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('Openstack image not downloaded : %s != %s', StringUtils::humanizeFileSize(@filesize($imageDestination) ?: 0), StringUtils::humanizeFileSize($backup->getSize())));

            return false;
        }

        return true;
    }

    public function upload(Backup $backup): void
    {
        $backend = $this->storageBackends->forStorage($backup->getBackupConfiguration()->getStorage());
        if (!$backend instanceof ResticStorageBackend) {
            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('%s : Nothing to do', $backup->getCurrentPlace()));

            return;
        }

        $tags = [
            'project' => $backup->getBackupConfiguration()->getOsInstance()->getOSProject()->getSlug(),
            'instance' => $backup->getBackupConfiguration()->getOsInstance()->getSlug(),
            'configuration' => $backup->getBackupConfiguration()->getName(),
        ];

        $backend->uploadLocal($backup, $this->pathResolver->resolve($backup), $tags);
    }

    public function cleanupLocal(Backup $backup): void
    {
        $path = $this->pathResolver->resolve($backup);
        if (file_exists($path)) {
            $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('Remove local file - %s', $path));
            unlink($path);
        }
        $this->deleteOsImage($backup);
    }

    public function isLocallyCleaned(Backup $backup): bool
    {
        // Read-only existence check: no entity mutation or persist here.
        if (null !== $this->lookupImage($backup)) {
            return false;
        }

        if (file_exists($this->pathResolver->resolve($backup))) {
            $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('Backup location does exists - %s', $this->pathResolver->resolve($backup)));

            return false;
        }

        return true;
    }

    private function deleteOsImage(Backup $backup): void
    {
        if (null === $this->lookupImage($backup)) {
            return;
        }

        $command = 'openstack image delete ${OS_IMAGE_ID}';
        $parameters = ['OS_IMAGE_ID' => $backup->getOsImageId()];

        $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, $this->backupLogger->formatParameters($parameters)));
        $outcome = $this->processRunner->runShell($command, $parameters + $backup->getBackupConfiguration()->getOsInstance()->getOSEnv(), self::IMAGE_LIST_TIMEOUT);

        $this->failOrLog($backup, $outcome, 'openstack image delete');
    }
}
