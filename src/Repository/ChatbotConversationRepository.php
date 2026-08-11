<?php

namespace App\Repository;

use App\Entity\ChatbotConversation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ChatbotConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatbotConversation::class);
    }

    public function findOneByUser(User $user): ?ChatbotConversation
    {
        return $this->findOneBy(['user' => $user]);
    }
}