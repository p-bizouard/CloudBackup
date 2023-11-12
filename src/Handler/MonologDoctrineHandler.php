<?php

namespace App\Handler;

use App\Entity\Log;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

class MonologDoctrineHandler extends AbstractProcessingHandler
{
    private bool $initialized = false;
    private string $channel = 'database';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function write(LogRecord $logRecord): void
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        if ($this->channel != $logRecord['channel']) {
            return;
        }

        $log = new Log();
        $log->setMessage($logRecord['message']);
        $log->setLevel($logRecord['level_name']);

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    private function initialize(): void
    {
        $this->initialized = true;
    }
}
