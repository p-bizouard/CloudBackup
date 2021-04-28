<?php

namespace App\Repository;

use App\Entity\BackupConfiguration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method BackupConfiguration|null find($id, $lockMode = null, $lockVersion = null)
 * @method BackupConfiguration|null findOneBy(array $criteria, array $orderBy = null)
 * @method BackupConfiguration[]    findAll()
 * @method BackupConfiguration[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BackupConfigurationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BackupConfiguration::class);
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
