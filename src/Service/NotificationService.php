<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Ticket;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use App\Entity\SiteAlert;


class NotificationService
{
    public const TYPE_WORKFLOW_ASSIGNED = 'workflow_assigned';
    public const TYPE_SITE_CONGESTION = 'site_congestion';
    public const TYPE_SITE_BRIDAGE = 'site_bridage';
    public const TYPE_SITE_RISQUE_CONGESTION = 'site_risque_congestion';

    private const SUPERVISION_EMAIL = 'inhae2362@gmail.com';

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private UserRepository $userRepository,
        private ?MailerInterface $mailer = null
    ) {}

       /**
     * Crée une notification en base et envoie un email (si configuré).
     * Ne fait pas de flush automatique pour permettre le batch.
     */
    public function notify(User $user, string $type, string $message, ?Ticket $ticket = null): Notification
    {
        $notification = new Notification();
        $notification->setUser($user);
        $notification->setType($type);
        $notification->setMessage($message);
        $notification->setTicket($ticket);
        $this->em->persist($notification);

        $this->sendEmail($user, $type, $message);

        return $notification;
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
        $this->notifyMultiple($users, self::TYPE_WORKFLOW_ASSIGNED, $message, $ticket);
    }

    public function notifyWorkflowReadyForSuperuser(Ticket $ticket): void
    {
        $superusers = $this->userRepository->findUsersByRole('ROLE_SUPERUSER');
        if ($superusers === []) {
            return;
        }

        $message = sprintf(
            'Le workflow #%d est terminé à 100%%. Merci de le valider puis de le clôturer.',
            $ticket->getId() ?? 0
        );

        $this->notifyMultiple($superusers, 'workflow_ready_for_validation', $message, $ticket);
    }


    
    /**
     * Envoie un email à l'utilisateur.
     */
    private function sendEmail(User $user, string $type, string $message): void
    {
        $emailAddress = $user->getEmail();

        if (!$this->mailer) {
            $this->logger->warning('NotificationService: mailer non configuré (MailerInterface null), email non envoyé.', [
                'type' => $type,
                'user' => $emailAddress,
            ]);
            return;
        }

        if (!$emailAddress) {
            $this->logger->warning('NotificationService: utilisateur sans email, envoi ignoré.', [
                'type' => $type,
                'user_id' => $user->getId(),
            ]);
            return;
        }

        try {
            $email = (new Email())
                ->from('no-reply@yourdomain.com')
                ->to($emailAddress)
                ->subject('Notification: ' . $type)
                ->text($message);
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Failed to send notification email to %s: %s',
                $emailAddress,
                $e->getMessage()
            ));
        }
    }

    


    public function notifyMultiple(array $users, string $type, string $message, ?Ticket $ticket = null): array
    {
        $notifications = [];
        foreach ($users as $user) {
            $notifications[] = $this->notify($user, $type, $message, $ticket);
        }
        $this->em->flush();
        return $notifications;
    }

    /**
     * Notifie tous les destinataires d'une alerte réseau (site_alert)
     * et lie chaque notification à l'alerte.
     */
    public function notifySiteAlert(SiteAlert $alert): void
    {
        $type = match (true) {
            str_starts_with($alert->getEtat(), 'CONGESTION') => self::TYPE_SITE_CONGESTION,
            $alert->getEtat() === 'BRIDAGE' => self::TYPE_SITE_BRIDAGE,
            $alert->getEtat() === 'RISQUE_DE_CONGESTION' => self::TYPE_SITE_RISQUE_CONGESTION,
            default => self::TYPE_SITE_CONGESTION,
        };

        // Récupération des superusers et des utilisateurs du service concerné
        $superusers = $this->userRepository->findUsersByRole('ROLE_SUPERUSER');
        $service = $alert->getClassification(); // ou un champ service dédié
        $concernedUsers = $service ? $this->userRepository->findUsersByRoleAndService('ROLE_USER', $service) : [];

        $recipients = [];
        foreach (array_merge($superusers, $concernedUsers) as $u) {
            $recipients[$u->getId()] = $u;
        }

        $fullMessage = sprintf("[%s] %s\n\n%s", $alert->getSite(), $alert->getEtat(), $alert->getMessage());

        $now = new \DateTime();
        foreach ($recipients as $user) {
            $notification = $this->notify($user, $type, $fullMessage, null);
            $notification->setAlert($alert);
            $notification->setEmailSentAt($now);
            $this->em->persist($notification);
        }
        $this->em->flush();

        // Email de supervision (copie systématique)
        $this->sendSupervisionEmail($alert->getSite(), $alert->getEtat(), $fullMessage);
    }

    private function sendSupervisionEmail(string $siteName, string $etat, string $message): void
    {
        if (!$this->mailer) {
            $this->logger->warning('NotificationService: mailer non configuré, email de supervision non envoyé.');
            return;
        }
        try {
            $email = (new Email())
                ->from('no-reply@yourdomain.com')
                ->to(self::SUPERVISION_EMAIL)
                ->subject(sprintf('[NetWON] %s détecté sur %s', $etat, $siteName))
                ->text($message);
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Failed to send supervision email for site %s: %s',
                $siteName,
                $e->getMessage()
            ));
        }
    }
}