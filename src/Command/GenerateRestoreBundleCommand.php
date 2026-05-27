<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use App\Entity\RestoreBundleAudit;
use App\Entity\RestoreDestination;
use App\Repository\BackupConfigurationRepository;
use App\Restore\Destination\RestoreDestinationStrategyRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

#[AsCommand(
    name: 'app:restore:generate-bundle',
    description: 'Render restore bundles for every BackupConfiguration (or a specific one) into var/restore/.',
)]
class GenerateRestoreBundleCommand extends Command
{
    public function __construct(
        private readonly BackupConfigurationRepository $backupConfigurationRepository,
        private readonly RestoreDestinationStrategyRegistry $strategyRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('backup-configuration', InputArgument::OPTIONAL, 'BackupConfiguration id or slug (omit to process every enabled configuration with a RestoreDestination)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing output directories')
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Output directory (default: <project>/var/restore)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        $identifier = $input->getArgument('backup-configuration');
        $force = (bool) $input->getOption('force');
        $outputDir = $input->getOption('output-dir');
        if (null === $outputDir || '' === $outputDir) {
            $outputDir = $this->projectDir.'/var/restore';
        }

        if (!is_dir($outputDir) && !mkdir($outputDir, 0o700, true) && !is_dir($outputDir)) {
            $symfonyStyle->error(\sprintf('Failed to create output dir: %s', $outputDir));

            return Command::FAILURE;
        }

        $configurations = $this->resolveConfigurations($symfonyStyle, $identifier);
        if (null === $configurations) {
            return Command::FAILURE;
        }
        if ([] === $configurations) {
            $symfonyStyle->warning('No backup configurations to process.');

            return Command::SUCCESS;
        }

        $okCount = 0;
        $skipCount = 0;
        $failCount = 0;

        foreach ($configurations as $configuration) {
            $name = (string) $configuration;
            $destination = $configuration->getRestoreDestination();
            if (!$destination instanceof RestoreDestination) {
                $symfonyStyle->writeln(\sprintf(' <fg=yellow>SKIP</> %s — no RestoreDestination set', $name));
                ++$skipCount;
                continue;
            }
            if (!$destination->isEnabled()) {
                $symfonyStyle->writeln(\sprintf(' <fg=yellow>SKIP</> %s — destination "%s" disabled', $name, (string) $destination->getName()));
                ++$skipCount;
                continue;
            }

            $violations = $this->validator->validate($destination);
            if (\count($violations) > 0) {
                $symfonyStyle->writeln(\sprintf(' <fg=red>FAIL</> %s — destination has validation errors:', $name));
                foreach ($violations as $violation) {
                    $symfonyStyle->writeln(\sprintf('   - %s: %s', $violation->getPropertyPath(), $violation->getMessage()));
                }
                ++$failCount;
                continue;
            }

            $backup = $configuration->getLatestBackup();
            if (!$backup instanceof Backup) {
                $symfonyStyle->writeln(\sprintf(' <fg=yellow>SKIP</> %s — no backup snapshots available yet', $name));
                ++$skipCount;
                continue;
            }

            try {
                $strategy = $this->strategyRegistry->forDestination($destination);
                $bundle = $strategy->renderBundle($destination, $backup);
                $written = $bundle->writeToDirectory($outputDir, $force);
                $sha256 = $bundle->sha256();

                $audit = new RestoreBundleAudit()
                    ->setDestination($destination)
                    ->setBackup($backup)
                    ->setSha256($sha256)
                    ->setFilename($bundle->name)
                    ->setSource(RestoreBundleAudit::SOURCE_CLI);
                $this->entityManager->persist($audit);

                $symfonyStyle->writeln(\sprintf(' <fg=green>OK</>   %s → %s', $name, $written));
                ++$okCount;
            } catch (Throwable $e) {
                $symfonyStyle->writeln(\sprintf(' <fg=red>FAIL</> %s — %s', $name, $e->getMessage()));
                ++$failCount;
            }
        }

        $this->entityManager->flush();

        $symfonyStyle->newLine();
        $symfonyStyle->writeln(\sprintf('Done. ok=%d skip=%d fail=%d', $okCount, $skipCount, $failCount));

        return $failCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return BackupConfiguration[]|null null on resolution error
     */
    private function resolveConfigurations(SymfonyStyle $symfonyStyle, ?string $identifier): ?array
    {
        if (null === $identifier || '' === $identifier) {
            return $this->backupConfigurationRepository->findEnabledWithRestoreDestination();
        }

        $configuration = $this->backupConfigurationRepository->findOneByIdentifier($identifier);
        if (null === $configuration) {
            $symfonyStyle->error(\sprintf('BackupConfiguration "%s" not found.', $identifier));

            return null;
        }
        if (!$configuration->isEnabled()) {
            $symfonyStyle->error(\sprintf('BackupConfiguration "%s" is disabled.', (string) $configuration));

            return null;
        }

        return [$configuration];
    }
}
