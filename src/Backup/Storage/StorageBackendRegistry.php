<?php

declare(strict_types=1);

namespace App\Backup\Storage;

use App\Entity\Storage;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class StorageBackendRegistry
{
    /** @var iterable<StorageBackendInterface> */
    private readonly iterable $backends;

    /** @param iterable<StorageBackendInterface> $backends */
    public function __construct(
        #[AutowireIterator('app.backup.storage_backend')]
        iterable $backends,
    ) {
        $this->backends = $backends;
    }

    public function forStorage(Storage $storage): StorageBackendInterface
    {
        foreach ($this->backends as $backend) {
            if ($backend->supports($storage)) {
                return $backend;
            }
        }

        throw new RuntimeException(\sprintf('No storage backend supports storage type "%s"', (string) $storage->getType()));
    }
}
