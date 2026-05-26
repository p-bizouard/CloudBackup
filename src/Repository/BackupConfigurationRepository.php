<?php

namespace App\Repository;

use App\Entity\Backup;
use App\Entity\BackupConfiguration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BackupConfiguration>
 */
class BackupConfigurationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, BackupConfiguration::class);
    }

    /**
     * @return BackupConfiguration[] enabled configurations that have a RestoreDestination set
     */
    public function findEnabledWithRestoreDestination(): array
    {
        return $this->createQueryBuilder('bc')
            ->andWhere('bc.enabled = true')
            ->andWhere('bc.restoreDestination IS NOT NULL')
            ->orderBy('bc.type', 'ASC')
            ->addOrderBy('bc.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByIdentifier(string $identifier): ?BackupConfiguration
    {
        if (ctype_digit($identifier)) {
            $byId = $this->find((int) $identifier);
            if ($byId instanceof BackupConfiguration) {
                return $byId;
            }
        }

        return $this->findOneBy(['slug' => $identifier]);
    }

    /**
     * @return BackupConfiguration[] Returns an array of BackupConfiguration objects with only their latest backup
     */
    public function findEnabledWithLatestBackupOnly(): array
    {
        $sub = $this->getEntityManager()->createQueryBuilder()
            ->select('MAX(b2.id)')
            ->from(Backup::class, 'b2')
            ->where('b2.backupConfiguration = bc.id');

        $qb = $this->createQueryBuilder('bc');
        $qb->leftJoin('bc.backups', 'b')
            ->addSelect('b')
            ->andWhere('bc.enabled = true')
            ->andWhere($qb->expr()->orX(
                'b.id IS NULL',
                'b.id = ('.$sub->getDQL().')'
            ))
            ->orderBy('bc.type', 'ASC')
            ->addOrderBy('bc.name', 'ASC');

        return $qb->getQuery()->getResult();
    }

    // /**
    //  * @return BackupConfiguration[] Returns an array of BackupConfiguration objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('b.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?BackupConfiguration
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
