<?php

namespace App\Repository;

use App\Entity\AiRecommendation;
use App\Entity\ProcessedSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AiRecommendationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiRecommendation::class);
    }

    public function findOneByProcessedSite(ProcessedSite $site): ?AiRecommendation
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.processedSite = :site')
            ->setParameter('site', $site)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

