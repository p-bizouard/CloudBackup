<?php

namespace App\Handler;

use App\Entity\Log;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\AbstractProcessingHandler;

class MonologDoctrineHandler extends AbstractProcessingHandler
{
    private $initialized;
    private $entityManager;
    private $channel = 'database';

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct();

        $this->entityManager = $entityManager;
    }

    protected function write(array $record): void
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

    private function initialize()
    {
        $this->initialized = true;
    }
}
