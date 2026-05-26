<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RestoreBundleAudit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RestoreBundleAudit>
 */
class RestoreBundleAuditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, RestoreBundleAudit::class);
    }
}
