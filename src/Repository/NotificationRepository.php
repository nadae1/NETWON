<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function findLatestForUser(User $user, int $limit = 50): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findLatestForUserByType(User $user, string $type, int $limit = 50): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->andWhere('n.type = :type')
            ->setParameter('user', $user)
            ->setParameter('type', $type)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUnreadForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->andWhere('n.isRead = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByTypeForUser(User $user): array
    {
        $results = $this->createQueryBuilder('n')
            ->select('n.type, COUNT(n.id) as count')
            ->where('n.user = :user')
            ->setParameter('user', $user)
            ->groupBy('n.type')
            ->getQuery()
            ->getResult();

        $types = [];
        foreach ($results as $row) {
            $types[$row['type']] = (int) $row['count'];
        }
        return $types;
    }
}