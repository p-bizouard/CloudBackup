<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RestoreDestination;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RestoreDestination>
 */
class RestoreDestinationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, RestoreDestination::class);
    }

    public function findOneByIdentifier(string $identifier): ?RestoreDestination
    {
        if (ctype_digit($identifier)) {
            $byId = $this->find((int) $identifier);
            if ($byId instanceof RestoreDestination) {
                return $byId;
            }
        }

        return $this->findOneBy(['slug' => $identifier]);
    }
}
