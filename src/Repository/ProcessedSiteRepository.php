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

    public function existsByDataHash(string $hash): bool
    {
        return (int) $this->createQueryBuilder('ps')
            ->select('COUNT(ps.id)')
            ->andWhere('ps.dataHash = :hash')
            ->setParameter('hash', $hash)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function countAllSites(?string $service = null): int
    {
        $qb = $this->createQueryBuilder('ps')
            ->select('COUNT(ps.id)');

        if ($service) {
            $qb->andWhere('ps.service = :service')
               ->setParameter('service', $this->normalizeService($service));
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countCriticalSites(?string $service = null): int
    {
        $qb = $this->createQueryBuilder('ps')
            ->select('COUNT(ps.id)')
            ->andWhere('ps.isCritical = true');

        if ($service) {
            $qb->andWhere('ps.service = :service')
               ->setParameter('service', $this->normalizeService($service));
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    // ✅ CORRIGÉ : utiliser dateMax au lieu de createdAt
    public function findLatestSites(?string $service = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.dateMax', 'DESC')
            ->setMaxResults($limit);

        if ($service) {
            $qb->andWhere('p.service = :service')
               ->setParameter('service', $this->normalizeService($service));
        }

        return $qb->getQuery()->getResult();
    }

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

    public function getServiceDistribution(): array
    {
        $rows = $this->createQueryBuilder('ps')
            ->select('ps.service AS service')
            ->addSelect('COUNT(ps.id) AS total')
            ->andWhere('ps.service IS NOT NULL')
            ->groupBy('ps.service')
            ->orderBy('ps.service', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $result = [
            'FO' => 0,
            'FH' => 0,
            'SHARED' => 0,
            'BACKBONE' => 0,
        ];

        foreach ($rows as $row) {
            $service = $this->normalizeService($row['service'] ?? null);
            if (isset($result[$service])) {
                $result[$service] += (int) $row['total'];
            } else {
                $result['SHARED'] += (int) $row['total'];
            }
        }

        return $result;
    }

    public function getClassificationStats(?string $service = null): array
    {
        $qb = $this->createQueryBuilder('ps')
            ->select('ps.classification AS classification')
            ->addSelect('COUNT(ps.id) AS total')
            ->addSelect('SUM(CASE WHEN ps.isCritical = true THEN 1 ELSE 0 END) AS critical')
            ->groupBy('ps.classification')
            ->orderBy('ps.classification', 'ASC');

        if ($service) {
            $qb->andWhere('ps.service = :service')
               ->setParameter('service', $this->normalizeService($service));
        }

        $rows = $qb->getQuery()->getArrayResult();
        $result = [];

        foreach ($rows as $row) {
            $classification = $row['classification'] ?: 'UNKNOWN';
            $result[$classification] = [
                'total' => (int) $row['total'],
                'critical' => (int) $row['critical'],
            ];
        }

        return $result;
    }

    public function getAverageTraffic(?string $service = null): float
    {
        $qb = $this->createQueryBuilder('ps')
            ->select('AVG(ps.maxTrafic)');

        if ($service) {
            $qb->andWhere('ps.service = :service')
               ->setParameter('service', $this->normalizeService($service));
        }

        return (float) ($qb->getQuery()->getSingleScalarResult() ?? 0);
    }

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

    private function normalizeService(?string $service): string
    {
        $service = strtoupper(trim((string) $service));

        if ($service === 'FO') {
            return 'FO';
        }
        if ($service === 'FH') {
            return 'FH';
        }
        if ($service === 'BACKBONE') {
            return 'BACKBONE';
        }
        return 'SHARED';
    }
}