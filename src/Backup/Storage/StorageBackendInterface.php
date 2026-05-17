<?php

declare(strict_types=1);

namespace App\Backup\Storage;

use App\Entity\Backup;
use App\Entity\Storage;

interface StorageBackendInterface
{
    public function supports(Storage $storage): bool;

    public function initRepository(Backup $backup): void;

    public function healthCheck(Backup $backup, bool $tryRepair = true): void;

    public function prune(Backup $backup): void;
}
