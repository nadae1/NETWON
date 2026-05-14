<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class NotificationExtension extends AbstractExtension
{
    public function __construct(
        private Security $security,
        private NotificationRepository $notificationRepository
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notifications_count', [$this, 'unreadNotificationsCount']),
        ];
    }

    public function unreadNotificationsCount(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return 0;
        }

        return $this->notificationRepository->countUnreadForUser($user);
    }
}

