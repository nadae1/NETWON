<?php

namespace App\Repository;

use App\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Site>
 */
class SiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Site::class);
    }

    public function findCriticalSitesForWorkflow(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('statuses', ['critical', 'congested', 'bridage', 'degraded'])
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
    }
}