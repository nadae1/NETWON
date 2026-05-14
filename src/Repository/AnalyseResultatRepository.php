<?php
// src/Repository/AnalyseResultatRepository.php

namespace App\Repository;

use App\Entity\AnalyseResultat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AnalyseResultatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyseResultat::class);
    }

    public function existsByDataHash(string $hash): bool
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.dataHash = :hash')
            ->setParameter('hash', $hash)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function findSitesCritiques(?string $service = null, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.isCritical = true')
            ->orderBy('a.nombreOccurrences', 'DESC')
            ->setMaxResults($limit);

        if ($service) {
            $qb->andWhere('a.serviceName = :service')
                ->setParameter('service', $service);
        }

        return $qb->getQuery()->getResult();
    }

    public function countAllSites(?string $service = null): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)');

        if ($service) {
            $qb->andWhere('a.serviceName = :service')
                ->setParameter('service', $service);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countCriticalSites(?string $service = null): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.isCritical = true');

        if ($service) {
            $qb->andWhere('a.serviceName = :service')
                ->setParameter('service', $service);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function getStatsByService(): array
    {
        return $this->createQueryBuilder('a')
            ->select('a.serviceName AS service')
            ->addSelect('COUNT(a.id) AS totalSites')
            ->addSelect('SUM(CASE WHEN a.isCritical = true THEN 1 ELSE 0 END) AS criticalSites')
            ->andWhere('a.serviceName IS NOT NULL')
            ->groupBy('a.serviceName')
            ->orderBy('a.serviceName', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    public function getStatsByType(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $fo = $conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE type_trans = ?', ['FO']);
        $fh = $conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE type_trans = ?', ['FH']);
        $backbone = $conn->fetchOne('SELECT COUNT(*) FROM analyse_resultat WHERE type_trans = ?', ['BACKBONE']);

        return [
            'fo' => (int) $fo,
            'fh' => (int) $fh,
            'backbone' => (int) $backbone,
        ];
    }
    public function findLatestSites(?string $service, int $limit = 100)
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.dateMax', 'DESC')
            ->setMaxResults($limit);

        if ($service && $service !== 'SHARED') {
            $qb->andWhere('a.serviceName = :service')
                ->setParameter('service', $service);
        }

        return $qb->getQuery()->getResult();
    }





    public function findDistinctSiteNames(?string $service = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('DISTINCT a.site AS site')
            ->orderBy('a.site', 'ASC');

        if ($service) {
            $qb->andWhere('a.serviceName = :service')
                ->setParameter('service', $service);
        }

        $rows = $qb->getQuery()->getArrayResult();

        return array_map(fn($row) => $row['site'], $rows);
    }

    public function findForExport(
        ?string $service,
        string $mode,
        array $siteNames = [],
        ?string $siteSearch = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.site', 'ASC')
            ->addOrderBy('a.dateMax', 'DESC');

        if ($service) {
            $qb->andWhere('a.serviceName = :service')
                ->setParameter('service', $service);
        }

        if ($mode === 'selected' && !empty($siteNames)) {
            $qb->andWhere('a.site IN (:sites)')
                ->setParameter('sites', $siteNames);
        }

        if (!empty($siteSearch)) {
            $qb->andWhere('LOWER(a.site) LIKE :siteSearch')
                ->setParameter('siteSearch', '%' . mb_strtolower($siteSearch) . '%');
        }

        if (!empty($dateFrom)) {
            $qb->andWhere('a.dateMax >= :dateFrom')
                ->setParameter('dateFrom', new \DateTime($dateFrom . ' 00:00:00'));
        }

        if (!empty($dateTo)) {
            $qb->andWhere('a.dateMax <= :dateTo')
                ->setParameter('dateTo', new \DateTime($dateTo . ' 23:59:59'));
        }

        return $qb->getQuery()->getResult();
    }
}
