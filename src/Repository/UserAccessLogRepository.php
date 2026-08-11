<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserAccessLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserAccessLog>
 */
class UserAccessLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserAccessLog::class);
    }

    public function findOneByUser(User $user): ?UserAccessLog
    {
        $log = $this->findOneBy(['user' => $user]);

        return $log instanceof UserAccessLog ? $log : null;
    }

    /**
     * @return UserAccessLog[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('l')
            ->addSelect('u')
            ->join('l.user', 'u')
            ->orderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();
    }
}