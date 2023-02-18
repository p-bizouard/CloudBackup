<?php

namespace App\Handler;

use App\Entity\Log;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

class MonologDoctrineHandler extends AbstractProcessingHandler
{
    private bool $initialized = false;
    private EntityManagerInterface $entityManager;
    private string $channel = 'database';

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct();

        $this->entityManager = $entityManager;
    }

    protected function write(LogRecord $record): void
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        if ($this->channel != $record['channel']) {
            return;
        }

        $log = new Log();
        $log->setMessage($record['message']);
        $log->setLevel($record['level_name']);

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    private function initialize(): void
    {
        $this->initialized = true;
    }
}
