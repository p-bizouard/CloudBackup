<?php

namespace App\Command;

use App\Entity\Log;
use App\Repository\BackupConfigurationRepository;
use App\Service\BackupService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

class BackupStartCommand extends Command
{
    protected static $defaultName = 'app:backup:start';
    protected static $defaultDescription = 'Start all backups';

    const MAX_RETRY = 4;
    const LOCK_TIMEOUT = 3600 * 6;

    public function __construct(
        private BackupConfigurationRepository $backupConfigurationRepository,
        private EntityManagerInterface $entityManager,
        private BackupService $backupService,
        private LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->addArgument('os-instance', InputArgument::OPTIONAL, 'Openstack Instance')
            ->addOption('option1', null, InputOption::VALUE_NONE, 'Option description')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $osInstance = $input->getArgument('os-instance');

        if ($osInstance) {
            $io->note(sprintf('You passed an argument: %s', $osInstance));
        }

        $lock = $this->lockFactory->createLock($this->getName(), self::LOCK_TIMEOUT);

        if (!$lock->acquire()) {
            $io->error('Cannot acquire lock');

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
                $this->backupService->completeBackup($backupConfiguration);
                $lock->refresh();
            }
        } catch (Exception $e) {
            $log = new Log();
            $log->setLevel(Log::LOG_ERROR);
            $log->setMessage(sprintf('General error : %s', $e->getMessage()));

            $this->entityManager->persist($log);
            $this->entityManager->flush();
        }
        $lock->release();

        return Command::SUCCESS;
    }
}
