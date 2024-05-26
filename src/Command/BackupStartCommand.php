<?php

namespace App\Command;

use App\Entity\Log;
use App\Repository\BackupConfigurationRepository;
use App\Service\BackupService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'app:backup:start',
    description: 'Start backups',
)]
class BackupStartCommand extends Command
{
    final public const MAX_RETRY = 4;
    final public const LOCK_TIMEOUT = 3600 * 6;

    public function __construct(
        private readonly BackupConfigurationRepository $backupConfigurationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly BackupService $backupService,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);

        $lock = $this->lockFactory->createLock($this->getName(), self::LOCK_TIMEOUT);

        if (!$lock->acquire()) {
            $symfonyStyle->error('Cannot acquire lock');

            return Command::FAILURE;
        }

        try {
            $backupConfigurations = $this->backupConfigurationRepository->findBy([
                'enabled' => true,
            ]);
            foreach ($backupConfigurations as $backupConfiguration) {
                $this->backupService->initBackup($backupConfiguration);
                $lock->refresh();
            }

            $this->entityManager->flush();

            foreach ($backupConfigurations as $backupConfiguration) {
                $this->backupService->performBackup($backupConfiguration);
                $lock->refresh();
            }

            $this->entityManager->flush();

            foreach ($backupConfigurations as $backupConfiguration) {
                $this->backupService->completeBackup($backupConfiguration);
                $lock->refresh();
            }
        } catch (Exception $e) {
            $errorMessage = sprintf('General error : %s', $e->getMessage());

            $log = new Log();
            $log->setLevel(Log::LOG_ERROR);
            $log->setMessage($errorMessage);

            $symfonyStyle->error($errorMessage);

            $this->entityManager->persist($log);
            $this->entityManager->flush();
        }
        $lock->release();

        return Command::SUCCESS;
    }
}
