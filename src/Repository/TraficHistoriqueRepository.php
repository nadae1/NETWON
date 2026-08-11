<?php

namespace App\Repository;

use App\Entity\TraficHistorique;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TraficHistoriqueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TraficHistorique::class);
    }

    /**
     * Dernière mesure connue pour un site exact (utile pour un widget
     * "dernier point" sans repasser par le repository ProcessedSite).
     */
    public function findLatestForSite(string $site): ?TraficHistorique
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.site = :site')
            ->setParameter('site', $site)
            ->orderBy('t.dateHeure', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Historique agrégé (max par heure) pour un ou plusieurs noms de site bruts
     * (ex: site_fdd + site_tdd d'un même préfixe), sur les N derniers jours.
     * Utilisé pour tracer la courbe réelle avant le point de prédiction.
     */
    public function findHistoryForSites(array $siteNames, int $days = 30): array
    {
        if (empty($siteNames)) {
            return [];
        }

        $since = new \DateTime("-{$days} days");

        $rows = $this->createQueryBuilder('t')
            ->select('t.dateHeure AS dateHeure, t.maxSpeed AS maxSpeed, t.site AS site')
            ->andWhere('t.site IN (:sites)')
            ->andWhere('t.dateHeure >= :since')
            ->setParameter('sites', $siteNames)
            ->setParameter('since', $since)
            ->orderBy('t.dateHeure', 'ASC')
            ->getQuery()
            ->getArrayResult();

        // Agrégation par timestamp : on prend le max entre les sous-sites (FDD/TDD)
        $aggregated = [];
        foreach ($rows as $row) {
            $key = $row['dateHeure']->format('Y-m-d H:i:s');
            $value = (float) $row['maxSpeed'];
            if (!isset($aggregated[$key]) || $value > $aggregated[$key]) {
                $aggregated[$key] = $value;
            }
        }
        ksort($aggregated);

        return array_map(
            fn($ts, $val) => ['date' => $ts, 'value' => $val],
            array_keys($aggregated),
            array_values($aggregated)
        );
    }
}