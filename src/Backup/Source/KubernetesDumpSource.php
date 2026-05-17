<?php

declare(strict_types=1);

namespace App\Backup\Source;

use App\Backup\Process\ProcessExecutionException;
use App\Backup\Storage\ResticStorageBackend;
use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\Log;
use App\Utils\StringUtils;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Filesystem\Filesystem;

#[AutoconfigureTag('app.backup.source')]
final class KubernetesDumpSource extends AbstractBackupSource
{
    public const int DUMP_TIMEOUT = 3600 * 4;

    public function supports(BackupConfiguration $backupConfiguration): bool
    {
        return BackupConfiguration::TYPE_KUBECONFIG === $backupConfiguration->getType();
    }

    public function download(Backup $backup): void
    {
        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('call %s::%s', self::class, __FUNCTION__));

        $filesystem = new Filesystem();
        $backupDestination = $this->pathResolver->resolve($backup);
        $kubeconfigPath = null;

        try {
            $kubeconfigPath = $filesystem->tempnam('/tmp', 'kubeconfig_');
            $filesystem->appendToFile($kubeconfigPath, str_replace("\r", '', $backup->getBackupConfiguration()->getKubeconfig()->getKubeconfig()."\n"));

            $command = 'kubectl --kubeconfig ${KUBECONFIG_PATH} --namespace ${KUBE_NAMESPACE} exec --tty=false ${KUBE_RESOURCE} -- ${DUMP_COMMAND} > "${DESTINATION}"';
            $parameters = [
                'KUBECONFIG_PATH' => $kubeconfigPath,
                'KUBE_NAMESPACE' => $backup->getBackupConfiguration()->getKubeNamespace(),
                'KUBE_RESOURCE' => $backup->getBackupConfiguration()->getKubeResource(),
                'DUMP_COMMAND' => $backup->getBackupConfiguration()->getDumpCommand(),
                'DESTINATION' => $backupDestination,
            ];

            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Run `%s` with %s', $command, $this->backupLogger->formatParameters($parameters)));
            $outcome = $this->processRunner->runShell($command, $parameters, self::DUMP_TIMEOUT);

            if (!$outcome->successful) {
                $this->backupLogger->log($backup, Log::LOG_ERROR, \sprintf('Error executing download - exec dump command - %s', $outcome->errorOutput));
                throw new ProcessExecutionException($outcome);
            }
            $this->backupLogger->log($backup, Log::LOG_INFO, $outcome->output);

            $backup->setSize((int) filesize($backupDestination));
            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('Backup size : %s', StringUtils::humanizeFileSize($backup->getSize())));
            $this->backupLogger->log($backup, Log::LOG_NOTICE, 'Dump done');
        } finally {
            if (null !== $kubeconfigPath) {
                @unlink($kubeconfigPath);
            }
        }
    }

    public function isDownloadComplete(Backup $backup): bool
    {
        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('call %s::%s', self::class, __FUNCTION__));

        $destination = $this->pathResolver->resolve($backup);
        $minimum = (int) $backup->getBackupConfiguration()->getMinimumBackupSize();

        if ($backup->getSize() >= $minimum) {
            $this->backupLogger->log($backup, Log::LOG_NOTICE, 'Backup downloaded');

            return true;
        }

        $this->backupLogger->log($backup, Log::LOG_NOTICE, \sprintf('Backup not downloaded : %s < %s', StringUtils::humanizeFileSize(@filesize($destination) ?: 0), StringUtils::humanizeFileSize($minimum)));

        return false;
    }

    public function upload(Backup $backup): void
    {
        $backend = $this->storageBackends->forStorage($backup->getBackupConfiguration()->getStorage());
        if (!$backend instanceof ResticStorageBackend) {
            $this->backupLogger->log($backup, Log::LOG_INFO, \sprintf('%s : Nothing to do', $backup->getCurrentPlace()));

            return;
        }

        $kubeconfig = $backup->getBackupConfiguration()->getKubeconfig();
        $tags = [
            'host' => null !== $kubeconfig ? $kubeconfig->getName() : 'direct',
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
    }
}
