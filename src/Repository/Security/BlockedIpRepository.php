<?php
// src/Repository/Security/BlockedIpRepository.php

namespace App\Repository\Security;

use App\Entity\Security\BlockedIp;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BlockedIpRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlockedIp::class);
    }

    public function isBlocked(string $ipAddress): bool
    {
        return $this->findOneBy(['ipAddress' => $ipAddress]) instanceof BlockedIp;
    }

    public function save(BlockedIp $blockedIp, bool $flush = true): void
    {
        $this->getEntityManager()->persist($blockedIp);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function removeByIp(string $ipAddress): bool
    {
        $blockedIp = $this->findOneBy(['ipAddress' => $ipAddress]);

        if (!$blockedIp instanceof BlockedIp) {
            return false;
        }

        $this->getEntityManager()->remove($blockedIp);
        $this->getEntityManager()->flush();

        return true;
    }

    /**
     * @return BlockedIp[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('b')
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}