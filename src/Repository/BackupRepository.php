<?php

namespace App\Repository;

use App\Entity\Backup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Backup>
 */
class BackupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, Backup::class);
    }

    /**
     * @return int Return the number of failed backups since the last successful backup
     */
    public function countFailedBackupsSinceLastSuccess(Backup $backup): int
    {
        /** @var ?Backup */
        $lastSuccessBackup = $this->createQueryBuilder('b')
            ->where("b.currentPlace = 'backuped'")
            ->andWhere('b.backupConfiguration = :backupConfiguration')
            ->setParameter('backupConfiguration', $backup->getBackupConfiguration())
            ->orderBy('b.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $qb = $this->createQueryBuilder('b');
        $qb->select($qb->expr()->count('b'))
            ->where("b.currentPlace != 'backuped'")
            ->andWhere('b.backupConfiguration = :backupConfiguration')
            ->andWhere('b.id != :currentBackup')
            ->setParameter('backupConfiguration', $backup->getBackupConfiguration())
            ->setParameter('currentBackup', $backup->getId());

        if (null !== $lastSuccessBackup) {
            $qb->andWhere('b.createdAt > :lastSuccessDate')
                ->setParameter('lastSuccessDate', $lastSuccessBackup->getCreatedAt());
        }

        return $qb->getQuery()->getSingleScalarResult();
    }

    // /**
    //  * @return Backup[] Returns an array of Backup objects
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
    public function findOneBySomeField($value): ?Backup
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
