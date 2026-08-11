<?php
// src/Repository/SitePredictionRepository.php

namespace App\Repository;

use App\Entity\SitePrediction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SitePredictionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SitePrediction::class);
    }

    public function findLatestByHorizon(string $horizon): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.horizon = :horizon')
            ->setParameter('horizon', $horizon)
            ->orderBy('p.actionPriorite', 'ASC')
            ->addOrderBy('p.tauxUtilisationProjetePct', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneBySiteAndHorizon(string $site, string $horizon): ?SitePrediction
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.site = :site')
            ->andWhere('p.horizon = :horizon')
            ->setParameter('site', $site)
            ->setParameter('horizon', $horizon)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findAllHorizonsForSite(string $site): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.site = :site')
            ->setParameter('site', $site)
            ->getQuery()
            ->getResult();
    }

    public function countByEtatPredit(string $horizon): array
    {
        return $this->createQueryBuilder('p')
            ->select('p.etatPredit AS etat, COUNT(p.id) AS total')
            ->andWhere('p.horizon = :horizon')
            ->setParameter('horizon', $horizon)
            ->groupBy('p.etatPredit')
            ->getQuery()
            ->getArrayResult();
    }

    public function countByActionCode(string $horizon): array
    {
        return $this->createQueryBuilder('p')
            ->select('p.actionCode AS action, COUNT(p.id) AS total')
            ->andWhere('p.horizon = :horizon')
            ->setParameter('horizon', $horizon)
            ->groupBy('p.actionCode')
            ->getQuery()
            ->getArrayResult();
    }

    public function findTopCritiques(string $horizon, int $limit = 10): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.horizon = :horizon')
            ->andWhere("p.etatPredit IN ('CONGESTION', 'CONGESTION(FDD)', 'CONGESTION(TDD)', 'RISQUE_DE_CONGESTION', 'BRIDAGE')")
            ->setParameter('horizon', $horizon)
            ->orderBy('p.tauxUtilisationProjetePct', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countTotal(string $horizon): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.horizon = :horizon')
            ->setParameter('horizon', $horizon)
            ->getQuery()
            ->getSingleScalarResult();
    }
}