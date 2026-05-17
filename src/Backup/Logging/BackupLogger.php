<?php

declare(strict_types=1);

namespace App\Backup\Logging;

use App\Entity\Backup;
use App\Entity\Log;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

class BackupLogger
{
    private const array PSR_LEVEL_MAP = [
        Log::LOG_ERROR => 'error',
        Log::LOG_WARNING => 'warning',
        Log::LOG_INFO => 'info',
        Log::LOG_NOTICE => 'notice',
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function log(Backup $backup, string $level, string $message): void
    {
        if (!isset(self::PSR_LEVEL_MAP[$level])) {
            throw new InvalidArgumentException(\sprintf('Unsupported log level: %s', $level));
        }

        $psrMethod = self::PSR_LEVEL_MAP[$level];
        $this->logger->{$psrMethod}($message);

        $log = new Log();
        $log->setLevel($level);
        $log->setMessage($message);

        $backup->addLog($log);

        $this->entityManager->persist($log);
        $this->entityManager->persist($backup);
        $this->entityManager->flush();
    }

    public function formatParameters(mixed $parameters): string
    {
        return strip_tags(json_encode($parameters, \JSON_PRETTY_PRINT));
    }
}
