<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Ticket;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class NotificationService
{

    // dans src/Entity/Notification.php
    public const TYPE_WORKFLOW_ASSIGNED = 'workflow_assigned';
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private ?MailerInterface $mailer = null
    ) {}

    public function notify(User $user, string $type, string $message, ?Ticket $ticket = null): Notification
    {
        $notification = new Notification();
        $notification->setUser($user);
        $notification->setType($type);
        $notification->setMessage($message);
        $notification->setTicket($ticket);
        $this->em->persist($notification);

        // Simulate email
        $this->logger->info('[EMAIL] To: ' . $user->getEmail() . ' - ' . $type . ' - ' . $message);

        // Real email (optional)
        if ($this->mailer) {
            $email = (new Email())
                ->from('no-reply@yourdomain.com')
                ->to($user->getEmail())
                ->subject('Notification: ' . $type)
                ->text($message);
            $this->mailer->send($email);
        }

        return $notification;
    }

    public function notifyMultiple(array $users, string $type, string $message, ?Ticket $ticket = null): array
    {
        $notifications = [];
        foreach ($users as $user) {
            $notifications[] = $this->notify($user, $type, $message, $ticket);
        }
        return $notifications;
    }

    public function notifyWorkflowAssignment(Ticket $ticket, array $users, int $taskCount): void
    {
        $siteNames = $ticket->getTicketSites()->map(fn($ts) => $ts->getSiteName())->toArray();
        $message = sprintf(
            "Nouveau workflow assigné : %s\nSites: %s\nTâches: %d",
            $ticket->getTitle(),
            implode(', ', $siteNames),
            $taskCount
        );
        $this->notifyMultiple($users, Notification::TYPE_WORKFLOW_ASSIGNED, $message, $ticket);
    }
}
