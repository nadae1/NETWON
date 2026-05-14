<?php

namespace App\Repository;

use App\Entity\SubWorkflow;
use App\Entity\Ticket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SubWorkflowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubWorkflow::class);
    }

    public function findChildren(Ticket $parentTicket): array
    {
        return $this->createQueryBuilder('sw')
            ->andWhere('sw.parentTicket = :parent')
            ->setParameter('parent', $parentTicket)
            ->orderBy('sw.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

