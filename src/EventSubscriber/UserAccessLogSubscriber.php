<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Entity\UserAccessLog;
use App\Repository\UserAccessLogRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

class UserAccessLogSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserAccessLogRepository $userAccessLogRepository,
        private UserRepository $userRepository
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User || !$this->userRepository->isControllerManagedUser($user)) {
            return;
        }

        $this->recordLogin($user);
    }

    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();

        if ($token === null) {
            return;
        }

        $user = $token->getUser();

        if (!$user instanceof User || !$this->userRepository->isControllerManagedUser($user)) {
            return;
        }

        $this->recordLogout($user);
    }

    private function recordLogin(User $user): void
    {
        $log = $this->userAccessLogRepository->findOneByUser($user) ?? new UserAccessLog();
        $log->setUser($user);
        $log->setLastLoginAt(new \DateTimeImmutable());

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    private function recordLogout(User $user): void
    {
        $log = $this->userAccessLogRepository->findOneByUser($user) ?? new UserAccessLog();
        $log->setUser($user);
        $log->setLastLogoutAt(new \DateTimeImmutable());

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }
}