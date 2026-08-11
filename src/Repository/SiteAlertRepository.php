<?php

namespace App\Repository;

use App\Entity\SiteAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SiteAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteAlert::class);
    }

    /**
     * Récupère les alertes des 7 derniers jours (ou plus)
     */
    public function findRecentAlerts(int $days = 7): array
    {
        $date = new \DateTime("-{$days} days");
        return $this->createQueryBuilder('a')
            ->where('a.date_alerte >= :date')
            ->setParameter('date', $date)
            ->orderBy('a.date_alerte', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte les alertes par état sur les 7 derniers jours (tous services)
     */
    public function countByEtat(int $days = 7): array
    {
        $date = new \DateTime("-{$days} days");
        $results = $this->createQueryBuilder('a')
            ->select('a.etat, COUNT(a.id) as count')
            ->where('a.date_alerte >= :date')
            ->setParameter('date', $date)
            ->groupBy('a.etat')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['etat']] = (int) $row['count'];
        }
        return $counts;
    }

    /**
     * ✅ NOUVEAU : Compte les alertes par état pour un service donné, sur N jours
     */
    public function countByEtatForService(?string $service, int $days = 7): array
    {
        $date = new \DateTime("-{$days} days");
        $qb = $this->createQueryBuilder('a')
            ->select('a.etat, COUNT(a.id) as count')
            ->where('a.date_alerte >= :date')
            ->setParameter('date', $date);

        if ($service) {
            // Jointure avec ProcessedSite pour filtrer par service
            $qb->innerJoin(
                'App\Entity\ProcessedSite',
                'ps',
                'WITH',
                'ps.siteName = a.site AND ps.service = :service'
            )
            ->setParameter('service', $service);
        }

        $qb->groupBy('a.etat');

        $results = $qb->getQuery()->getResult();
        $counts = [];
        foreach ($results as $row) {
            $counts[$row['etat']] = (int) $row['count'];
        }
        return $counts;
    }

    /**
     * ✅ NOUVEAU : Compte le nombre de sites distincts ayant eu au moins une alerte récente
     */
    public function countDistinctSitesWithRecentAlerts(int $days = 7): int
    {
        $date = new \DateTime("-{$days} days");
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(DISTINCT a.site)')
            ->where('a.date_alerte >= :date')
            ->setParameter('date', $date);
        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}