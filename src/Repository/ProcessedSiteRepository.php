<?php

namespace App\Repository;

use App\Entity\ProcessedSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProcessedSiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcessedSite::class);
    }

    /**
     * Vérifie si un hash de données existe déjà.
     */
    public function existsByDataHash(string $hash): bool
    {
        return (int) $this->createQueryBuilder('ps')
            ->select('COUNT(ps.id)')
            ->andWhere('ps.dataHash = :hash')
            ->setParameter('hash', $hash)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Trouve un site par son nom exact (insensible à la casse).
     */
    public function findOneBySiteName(string $siteName): ?ProcessedSite
    {
        return $this->createQueryBuilder('ps')
            ->andWhere('LOWER(ps.siteName) = :siteName')
            ->setParameter('siteName', mb_strtolower(trim($siteName)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Compte le nombre total de sites, éventuellement filtrés par service.
     */
    public function countAllSites(?string $service = null): int
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)');
        if ($service) {
            $qb->andWhere('s.service = :service')
               ->setParameter('service', $this->normalizeService($service));
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Compte le nombre de sites critiques, éventuellement filtrés par service.
     * Pour PostgreSQL, on compare avec true (booléen).
     */
    public function countCriticalSites(?string $service = null): int
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.isCritical = true');  // ← correction : true
        if ($service) {
            $qb->andWhere('s.service = :service')
               ->setParameter('service', $this->normalizeService($service));
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Récupère les derniers sites (triés par trafic max descendant).
     */
    public function findLatestSites(?string $service = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.maxTrafic', 'DESC')
            ->setMaxResults($limit);
        if ($service) {
            $qb->andWhere('p.service = :service')
               ->setParameter('service', $this->normalizeService($service));
        }
        return $qb->getQuery()->getResult();
    }

    /**
     * Récupère les sites critiques.
     */
    public function findCriticalSites(?string $service = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('ps')
            ->leftJoin('ps.processedImport', 'pi')
            ->addSelect('pi')
            ->andWhere('ps.isCritical = true')
            ->orderBy('ps.nombreOccurrences', 'DESC')
            ->addOrderBy('ps.dateMax', 'DESC')
            ->setMaxResults($limit);

        if ($service) {
            $qb->andWhere('ps.service = :service')
               ->setParameter('service', $this->normalizeService($service));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Liste des noms de sites distincts.
     */
    public function findDistinctSiteNames(?string $service = null): array
    {
        $qb = $this->createQueryBuilder('ps')
            ->select('DISTINCT ps.siteName AS siteName')
            ->orderBy('ps.siteName', 'ASC');

        if ($service) {
            $qb->andWhere('ps.service = :service')
               ->setParameter('service', $this->normalizeService($service));
        }

        $rows = $qb->getQuery()->getArrayResult();
        return array_map(fn ($row) => $row['siteName'], $rows);
    }

    /**
     * Statistiques par service (total et critiques).
     */
    public function getStatsByService(): array
    {
        return $this->createQueryBuilder('ps')
            ->select('ps.service AS service')
            ->addSelect('COUNT(ps.id) AS totalSites')
            ->addSelect('SUM(CASE WHEN ps.isCritical = true THEN 1 ELSE 0 END) AS criticalSites')
            ->andWhere('ps.service IS NOT NULL')
            ->groupBy('ps.service')
            ->orderBy('ps.service', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Distribution des sites par service (FO, FH, SHARED, etc.).
     */
    public function getServiceDistribution(): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('s.service, COUNT(s.id) as count')
            ->groupBy('s.service');
        $results = $qb->getQuery()->getResult();
        $dist = [];
        foreach ($results as $row) {
            $dist[$row['service']] = (int) $row['count'];
        }
        return $dist;
    }

    /**
     * Statistiques de classification (total et critiques) par classification.
     */
    public function getClassificationStats(?string $service = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('s.classification, COUNT(s.id) as total, SUM(CASE WHEN s.isCritical = true THEN 1 ELSE 0 END) as critical')
            ->groupBy('s.classification');
        if ($service) {
            $qb->andWhere('s.service = :service')
               ->setParameter('service', $this->normalizeService($service));
        }
        $results = $qb->getQuery()->getResult();
        $stats = [];
        foreach ($results as $row) {
            $stats[$row['classification']] = [
                'total' => (int) $row['total'],
                'critical' => (int) $row['critical'],
            ];
        }
        return $stats;
    }

    /**
     * Trafic moyen (maxTrafic) des sites.
     */
    public function getAverageTraffic(?string $service = null): float
    {
        $qb = $this->createQueryBuilder('s')
            ->select('AVG(s.maxTrafic) as avgTraffic');
        if ($service) {
            $qb->andWhere('s.service = :service')
               ->setParameter('service', $this->normalizeService($service));
        }
        $result = $qb->getQuery()->getSingleScalarResult();
        return $result ? (float) $result : 0.0;
    }

    /**
     * Récupère les sites paginés avec filtres.
     */
    public function findSitesPaginated(
        ?string $service,
        ?string $classification,
        ?string $search,
        int $page = 1,
        int $limit = 50
    ): array {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $qb = $this->createQueryBuilder('ps')
            ->leftJoin('ps.processedImport', 'pi')
            ->addSelect('pi');

        if ($service) {
            $qb->andWhere('ps.service = :service')
               ->setParameter('service', $this->normalizeService($service));
        }

        if ($classification) {
            $qb->andWhere('ps.classification = :classification')
               ->setParameter('classification', $classification);
        }

        if ($search) {
            $qb->andWhere('LOWER(ps.siteName) LIKE :search OR LOWER(ps.pairedSiteName) LIKE :search')
               ->setParameter('search', '%' . mb_strtolower(trim($search)) . '%');
        }

        $countQb = clone $qb;
        $total = (int) $countQb
            ->resetDQLPart('orderBy')
            ->select('COUNT(ps.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $qb
            ->orderBy('ps.dateMax', 'DESC')
            ->addOrderBy('ps.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => max(1, (int) ceil($total / $limit)),
        ];
    }

    /**
     * Export avancé avec filtres.
     */
    public function findForAdvancedExport(
        ?string $service,
        string $mode = 'all',
        array $siteNames = [],
        string $siteSearch = '',
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $qb = $this->createQueryBuilder('ps')
            ->orderBy('ps.dateMax', 'DESC')
            ->addOrderBy('ps.id', 'DESC');

        if ($service) {
            $qb->andWhere('ps.service = :service')
               ->setParameter('service', $this->normalizeService($service));
        }

        if ($mode === 'selected' && !empty($siteNames)) {
            $qb->andWhere('ps.siteName IN (:siteNames)')
               ->setParameter('siteNames', $siteNames);
        }

        if ($siteSearch !== '') {
            $qb->andWhere('LOWER(ps.siteName) LIKE :siteSearch OR LOWER(ps.pairedSiteName) LIKE :siteSearch')
               ->setParameter('siteSearch', '%' . mb_strtolower(trim($siteSearch)) . '%');
        }

        if ($dateFrom) {
            $qb->andWhere('ps.dateMax >= :dateFrom')
               ->setParameter('dateFrom', new \DateTime($dateFrom . ' 00:00:00'));
        }

        if ($dateTo) {
            $qb->andWhere('ps.dateMax <= :dateTo')
               ->setParameter('dateTo', new \DateTime($dateTo . ' 23:59:59'));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Normalise le nom du service pour les requêtes.
     */
    private function normalizeService(?string $service): string
    {
        $service = strtoupper(trim((string) $service));
        if ($service === 'FO') return 'FO';
        if ($service === 'FH') return 'FH';
        if ($service === 'BACKBONE') return 'BACKBONE';
        return 'SHARED';
    }

    /**
     * Compte les sites sécurisés (non critiques et trafic < 80% de la capacité).
     */
    public function countSecureSites(?string $service = null): int
    {
        $qb = $this->createQueryBuilder('ps')
            ->select('COUNT(ps.id)')
            ->where('ps.isCritical = false')
            ->andWhere('ps.maxTrafic < ps.capaciteMbps * 0.8');
        if ($service) {
            $qb->andWhere('ps.service = :service')
               ->setParameter('service', $this->normalizeService($service));
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Compte les sites sous observation (non critiques et trafic >= 80% de la capacité).
     */
    public function countWarningSites(?string $service = null): int
    {
        $qb = $this->createQueryBuilder('ps')
            ->select('COUNT(ps.id)')
            ->where('ps.isCritical = false')
            ->andWhere('ps.maxTrafic >= ps.capaciteMbps * 0.8');
        if ($service) {
            $qb->andWhere('ps.service = :service')
               ->setParameter('service', $this->normalizeService($service));
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Récupère les sites avec coordonnées, avec filtres optionnels.
     * Retourne un tableau associatif pour un accès facile dans Twig.
     */
    public function findSitesWithCoordinates(
        ?string $service = null,
        ?string $classification = null,
        ?string $critical = null
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->select('s.siteName, s.latitude, s.longitude, s.isCritical, s.maxTrafic, s.service, s.classification, s.typeTrans')
            ->where('s.latitude IS NOT NULL')
            ->andWhere('s.longitude IS NOT NULL');

        if ($service) {
            $qb->andWhere('s.service = :service')
               ->setParameter('service', $this->normalizeService($service));
        }
        if ($classification) {
            $qb->andWhere('s.classification = :classification')
               ->setParameter('classification', $classification);
        }
        if ($critical === '1') {
            $qb->andWhere('s.isCritical = true');
        } elseif ($critical === '0') {
            $qb->andWhere('s.isCritical = false');
        }

        // Utilisation de getArrayResult() pour obtenir des tableaux associatifs
        return $qb->getQuery()->getArrayResult();
    }
}