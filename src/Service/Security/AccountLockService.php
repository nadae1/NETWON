<?php
// src/Service/Security/AccountLockService.php

namespace App\Service\Security;

use App\Entity\Security\SecurityEvent;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

class AccountLockService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly SecurityAuditService $auditService,
    ) {
    }

    public function lockUser(User $user, string $reason): void
    {
        $user->setLocked(true)
            ->setLockedAt(new \DateTimeImmutable())
            ->setLockReason($reason);

        $this->entityManager->flush();

        $this->auditService->log(
            SecurityEvent::TYPE_ACCOUNT_LOCKED,
            SecurityEvent::SEVERITY_HIGH,
            $user,
            $user->getUserIdentifier(),
            ['reason' => $reason]
        );
    }

public function unlockUser(User $user): void
{
    $user->setLocked(false)
        ->setLockedAt(null)
        ->setLockReason(null);

    $this->entityManager->flush();

    $this->auditService->log(
        SecurityEvent::TYPE_ACCOUNT_LOCKED, // même type, on distingue via le contexte
        SecurityEvent::SEVERITY_LOW,
        $user,
        $user->getUserIdentifier(),
        ['action' => 'unlocked']
    );
}

/**
 * @return User[]
 */
public function findLockedUsers(): array
{
    return $this->userRepository->findBy(['isLocked' => true], ['lockedAt' => 'DESC']);
}

    /**
     * Tente de verrouiller un compte à partir d'un identifiant tenté (username/email),
     * utile quand on part d'un SecurityEvent de type LOGIN_FAILED / BRUTE_FORCE qui
     * n'a pas de User directement associé (l'auth a échoué avant identification complète).
     */
    public function lockByIdentifier(string $identifier, string $reason): ?User
    {
        $user = $this->userRepository->findOneBy(['username' => $identifier])
            ?? $this->userRepository->findOneBy(['email' => $identifier]);

        if (!$user instanceof User) {
            return null;
        }

        $this->lockUser($user, $reason);

        return $user;
    }
}