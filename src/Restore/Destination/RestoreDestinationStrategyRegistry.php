<?php

declare(strict_types=1);

namespace App\Restore\Destination;

use App\Entity\RestoreDestination;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class RestoreDestinationStrategyRegistry
{
    /** @var iterable<RestoreDestinationStrategyInterface> */
    private readonly iterable $strategies;

    /** @param iterable<RestoreDestinationStrategyInterface> $strategies */
    public function __construct(
        #[AutowireIterator('app.restore.destination_strategy')]
        iterable $strategies,
    ) {
        $this->strategies = $strategies;
    }

    public function forDestination(RestoreDestination $destination): RestoreDestinationStrategyInterface
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($destination)) {
                return $strategy;
            }
        }

        throw new RuntimeException(\sprintf('No restore destination strategy supports type "%s"', (string) $destination->getType()));
    }
}
