<?php
// src/Repository/Security/SecurityEventRepository.php

namespace App\Repository\Security;

use App\Entity\Security\SecurityEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SecurityEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecurityEvent::class);
    }

    public function save(SecurityEvent $event, bool $flush = true): void
    {
        $this->getEntityManager()->persist($event);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Compte les échecs de connexion pour une IP donnée sur une fenêtre de temps.
     * Utilisé par le détecteur de brute-force.
     */
    public function countRecentFailuresByIp(string $ipAddress, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.ipAddress = :ip')
            ->andWhere('e.type = :type')
            ->andWhere('e.createdAt >= :since')
            ->setParameter('ip', $ipAddress)
            ->setParameter('type', SecurityEvent::TYPE_LOGIN_FAILED)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Idem, mais par identifiant tenté (login) plutôt que par IP.
     */
    public function countRecentFailuresByIdentifier(string $identifier, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.attemptedIdentifier = :identifier')
            ->andWhere('e.type = :type')
            ->andWhere('e.createdAt >= :since')
            ->setParameter('identifier', $identifier)
            ->setParameter('type', SecurityEvent::TYPE_LOGIN_FAILED)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Événements récents pour le dashboard, avec filtres optionnels.
     *
     * @return SecurityEvent[]
     */
    public function findRecent(
        ?string $type = null,
        ?string $severity = null,
        ?int $userId = null,
        int $limit = 50
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($type !== null) {
            $qb->andWhere('e.type = :type')->setParameter('type', $type);
        }
        if ($severity !== null) {
            $qb->andWhere('e.severity = :severity')->setParameter('severity', $severity);
        }
        if ($userId !== null) {
            $qb->andWhere('e.user = :userId')->setParameter('userId', $userId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Compteurs par type sur une période, pour les cartes du dashboard.
     *
     * @return array<string, int>
     */
    public function countByTypeSince(\DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('e.type AS type, COUNT(e.id) AS nb')
            ->andWhere('e.createdAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('e.type')
            ->getQuery()
            ->getResult();

        $result = array_fill_keys(SecurityEvent::TYPES, 0);
        foreach ($rows as $row) {
            $result[$row['type']] = (int) $row['nb'];
        }

        return $result;
    }

    /**
     * Historique d'activité pour un utilisateur donné (page "activité détaillée").
     *
     * @return SecurityEvent[]
     */
    public function findByUser(int $userId, int $limit = 100): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
 * Compte les événements non résolus par sévérité — utilisé pour le badge de notification.
 *
 * @return array<string, int> ex: ['CRITICAL' => 3, 'HIGH' => 1]
 */
public function countUnresolvedBySeverity(): array
{
    $rows = $this->createQueryBuilder('e')
        ->select('e.severity AS severity, COUNT(e.id) AS nb')
        ->andWhere('e.resolved = false')
        ->groupBy('e.severity')
        ->getQuery()
        ->getResult();

    $result = array_fill_keys(SecurityEvent::SEVERITIES, 0);
    foreach ($rows as $row) {
        $result[$row['severity']] = (int) $row['nb'];
    }

    return $result;
}

/**
 * Les N événements non résolus les plus récents et les plus graves,
 * pour la liste déroulante de notifications.
 *
 * @return SecurityEvent[]
 */
public function findRecentUnresolvedAlerts(int $limit = 10): array
{
    return $this->createQueryBuilder('e')
        ->andWhere('e.resolved = false')
        ->andWhere('e.severity IN (:severities)')
        ->setParameter('severities', [SecurityEvent::SEVERITY_HIGH, SecurityEvent::SEVERITY_CRITICAL])
        ->orderBy('e.createdAt', 'DESC')
        ->setMaxResults($limit)
        ->getQuery()
        ->getResult();
}

/**
 * Liste filtrée pour la page "Tous les événements", avec pagination simple.
 *
 * @return SecurityEvent[]
 */
public function findFiltered(
    ?string $type,
    ?string $severity,
    ?bool $resolved,
    int $page = 1,
    int $perPage = 25
): array {
    $qb = $this->createQueryBuilder('e')
        ->orderBy('e.createdAt', 'DESC')
        ->setFirstResult(($page - 1) * $perPage)
        ->setMaxResults($perPage);

    if ($type !== null) {
        $qb->andWhere('e.type = :type')->setParameter('type', $type);
    }
    if ($severity !== null) {
        $qb->andWhere('e.severity = :severity')->setParameter('severity', $severity);
    }
    if ($resolved !== null) {
        $qb->andWhere('e.resolved = :resolved')->setParameter('resolved', $resolved);
    }

    return $qb->getQuery()->getResult();
}

/**
 * Compte total pour la pagination (mêmes filtres que findFiltered).
 */
public function countFiltered(?string $type, ?string $severity, ?bool $resolved): int
{
    $qb = $this->createQueryBuilder('e')
        ->select('COUNT(e.id)');

    if ($type !== null) {
        $qb->andWhere('e.type = :type')->setParameter('type', $type);
    }
    if ($severity !== null) {
        $qb->andWhere('e.severity = :severity')->setParameter('severity', $severity);
    }
    if ($resolved !== null) {
        $qb->andWhere('e.resolved = :resolved')->setParameter('resolved', $resolved);
    }

    return (int) $qb->getQuery()->getSingleScalarResult();
}
}