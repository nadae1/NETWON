<?php

namespace App\Repository;

use App\Entity\Ticket;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\TicketSite;


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


    

    public function findByStatusOrdered(?string $status = null, ?string $search = null): array
{
    $qb = $this->createQueryBuilder('t')
        ->orderBy('t.createdAt', 'DESC');

    if ($status) {
        $qb->andWhere('t.status = :status')
           ->setParameter('status', $status);
    }

    if ($search) {
        $qb->andWhere('LOWER(t.title) LIKE :search')
           ->setParameter('search', '%' . mb_strtolower($search) . '%');
    }

    return $qb->getQuery()->getResult();
}



    public function findOverdueTickets(): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.assignedUsers', 'au')->addSelect('au')
            ->leftJoin('t.createdBy', 'cb')->addSelect('cb')
            ->andWhere('(t.deadlineAt < :now OR t.deadline < :now)')
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
            ->andWhere('(t.deadlineAt < :now OR t.deadline < :now)')
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



    public function getMonthlyStats(\DateTime $start, \DateTime $end): array
{
    $qb = $this->createQueryBuilder('t')
        ->select('COUNT(t.id) as total')
        ->addSelect('SUM(CASE WHEN t.status = :closed THEN 1 ELSE 0 END) as closed')
        ->addSelect('SUM(CASE WHEN t.deadline < :now AND t.status NOT IN (:closedStatuses) THEN 1 ELSE 0 END) as overdue')
        ->setParameter('closed', 'closed')
        ->setParameter('now', new \DateTime())
        ->setParameter('closedStatuses', ['closed', 'completed']);
    // Filtrer par date de création
    $qb->andWhere('t.createdAt BETWEEN :start AND :end')
       ->setParameter('start', $start)
       ->setParameter('end', $end);
    return $qb->getQuery()->getSingleResult();
}

public function findWorkflowsForMonth(\DateTime $start, \DateTime $end): array
{
    return $this->createQueryBuilder('t')
        ->leftJoin('t.ticketSites', 'sites')
        ->leftJoin('t.tasks', 'tasks')
        ->leftJoin('t.assignedUsers', 'users')
        ->where('t.createdAt BETWEEN :start AND :end')
        ->setParameter('start', $start)
        ->setParameter('end', $end)
        ->orderBy('t.createdAt', 'DESC')
        ->getQuery()
        ->getResult();
}

public function findTicketsBySiteName(string $siteName): array
{
    return $this->createQueryBuilder('t')
        ->join('t.ticketSites', 'ts')
        ->andWhere('LOWER(ts.siteName) LIKE :name')
        ->setParameter('name', '%' . mb_strtolower(trim($siteName)) . '%')
        ->orderBy('t.createdAt', 'DESC')
        ->getQuery()
        ->getResult();
}
public function getWorkflowSitesProgress(): array
{
    $tickets = $this->createQueryBuilder('t')->getQuery()->getResult();

    $totalSites = 0;
    $completedSites = 0;
    foreach ($tickets as $ticket) {
        $totalSites += $ticket->getTicketSites()->count();
        $completedSites += $ticket->getCompletedSitesCount();
    }

    return [
        'total_sites' => $totalSites,
        'completed_sites' => $completedSites,
        'progress_percent' => $totalSites > 0 ? round(($completedSites / $totalSites) * 100, 1) : 0,
    ];
}

public function findRecentlyProcessedTicketSites(int $limit = 5): array
{
    return $this->getEntityManager()->createQueryBuilder()
        ->select(
            'ts.siteName as siteName',
            'ts.serviceName as serviceName',
            'ts.typeTrans as typeTrans',
            'ts.status as siteStatus',
            't.id as ticketId',
            't.title as ticketTitle',
            't.updatedAt as ticketUpdatedAt'
        )
        ->from(TicketSite::class, 'ts')
        ->join('ts.ticket', 't')
        ->where('ts.status = :completed')
        ->setParameter('completed', 'completed')
        ->orderBy('t.updatedAt', 'DESC')
        ->setMaxResults($limit)
        ->getQuery()
        ->getArrayResult();
}
    
    }