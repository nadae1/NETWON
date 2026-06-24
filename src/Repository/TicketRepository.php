<?php

namespace App\Repository;

use App\Entity\Ticket;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    public function findCreatedByUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.createdBy = :user')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.createdBy', 'u')->addSelect('u')
            ->leftJoin('t.tasks', 'tt')->addSelect('tt')
            ->leftJoin('t.ticketSites', 'ts')->addSelect('ts')
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByStatusOrdered(?string $status): array
{
    $qb = $this->createQueryBuilder('t')
        ->leftJoin('t.createdBy', 'u')->addSelect('u')
        ->leftJoin('t.tasks', 'tt')->addSelect('tt')
        ->leftJoin('t.ticketSites', 'ts')->addSelect('ts')
        ->orderBy('t.createdAt', 'DESC');

    if ($status) {
        $qb->andWhere('t.status = :status')
            ->setParameter('status', $status);
    }

    return $qb->getQuery()->getResult();
}

    public function findOverdueTickets(): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.deadlineAt IS NOT NULL')
            ->andWhere('t.deadlineAt < :now')
            ->andWhere('t.status NOT IN (:statuses)')
            ->setParameter('now', new \DateTime())
            ->setParameter('statuses', ['completed', 'closed'])
            ->orderBy('t.deadlineAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAllTickets(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countOverdueNotClosed(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.deadlineAt IS NOT NULL')
            ->andWhere('t.deadlineAt < :now')
            ->andWhere('t.status NOT IN (:statuses)')
            ->setParameter('now', new \DateTime())
            ->setParameter('statuses', ['completed', 'closed'])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findAllByService(string $service): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.tasks', 'tt')->addSelect('tt')
            ->leftJoin('t.createdBy', 'u')->addSelect('u')
            ->andWhere('tt.serviceName = :service')
            ->setParameter('service', $service)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }


    
    }