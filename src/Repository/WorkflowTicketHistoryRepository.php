<?php

namespace App\Repository;

use App\Entity\WorkflowTicket;
use App\Entity\WorkflowTicketHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WorkflowTicketHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowTicketHistory::class);
    }

    public function findByTicketOrdered(WorkflowTicket $ticket): array
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.ticket = :ticket')
            ->setParameter('ticket', $ticket)
            ->orderBy('h.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}