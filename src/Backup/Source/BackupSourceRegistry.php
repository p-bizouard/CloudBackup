<?php

declare(strict_types=1);

namespace App\Backup\Source;

use App\Entity\BackupConfiguration;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class BackupSourceRegistry
{
    /** @var iterable<BackupSourceInterface> */
    private readonly iterable $sources;

    /** @param iterable<BackupSourceInterface> $sources */
    public function __construct(
        #[AutowireIterator('app.backup.source')]
        iterable $sources,
    ) {
        $this->sources = $sources;
    }

    public function forConfiguration(BackupConfiguration $backupConfiguration): BackupSourceInterface
    {
        foreach ($this->sources as $source) {
            if ($source->supports($backupConfiguration)) {
                return $source;
            }
        }

        throw new RuntimeException(\sprintf('No backup source supports type "%s"', (string) $backupConfiguration->getType()));
    }
}
