<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Ticket;
use App\Entity\TicketTask;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\TicketRepository;
use App\Repository\TicketTaskRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;

class DeadlineAlertService
{
    public function __construct(
        private EntityManagerInterface $em,
        private TicketDeadlineMailService $mailService,
        private TicketRepository $ticketRepository,
        private TicketTaskRepository $ticketTaskRepository,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Vérifie tous les tickets ouverts et génère les alertes.
     * Retourne le nombre total de notifications créées.
     */
    public function checkAndNotify(): int
    {
        $tickets = $this->ticketRepository->findOverdueTickets();
        $tasks = $this->ticketTaskRepository->findOverdueTasks();

        $this->logger->info(sprintf(
            'Vérification des échéances : %d ticket(s) en retard, %d tâche(s) en retard trouvée(s).',
            count($tickets), count($tasks)
        ));

        $total = 0;
        foreach ($tickets as $ticket) {
            $total += $this->checkTicket($ticket);
        }

        foreach ($tasks as $task) {
            $total += $this->checkTask($task);
        }

        return $total;
    }

    /**
     * Vérifie un ticket spécifique et crée les notifications nécessaires.
     * Retourne le nombre de notifications créées pour ce ticket.
     */
    public function checkTicket(Ticket $ticket): int
    {
        $deadline = $this->getEffectiveDeadline($ticket);
        if (!$deadline) {
            return 0;
        }

        if ($this->isClosedTicket($ticket)) {
            return 0;
        }

        $now = new \DateTimeImmutable();
        if ($now <= $deadline) {
            return 0;
        }

        $recipients = $this->collectRecipients($ticket);

        if ($recipients === []) {
            $this->logger->info(sprintf('Overdue ticket #%d found but no recipients were available.', (int) $ticket->getId()));
            return 0;
        }

        $daysOverdue = $this->calculateDaysOverdue($deadline, $now);
        $responsibles = $this->buildResponsibleLabels($ticket);
        $sentCount = 0;

        foreach ($recipients as $recipient) {
            if ($this->alreadyNotifiedToday($ticket, $recipient, Notification::TYPE_DEADLINE_REMINDER)) {
                continue;
            }

            // ✅ CORRIGÉ (bug principal) : la notification en base est
            // désormais TOUJOURS créée, indépendamment du succès de
            // l'envoi d'email. Avant, si sendOverdueTicketEmail()
            // retournait false (mailer non configuré, SMTP injoignable,
            // destinataire sans email...), la notification n'était
            // JAMAIS créée -- c'était la cause racine de l'absence totale
            // d'alertes visibles pour les workflows en retard.
            $this->persistNotification($ticket, $recipient, $daysOverdue, $responsibles);
            $sentCount++;

            try {
                $emailSent = $this->mailService->sendOverdueTicketEmail($recipient, $ticket, $daysOverdue, $responsibles);
                if (!$emailSent) {
                    $this->logger->warning(sprintf(
                        'Notification créée pour le ticket #%d mais email non envoyé à %s (mailer indisponible ou email manquant).',
                        (int) $ticket->getId(),
                        $recipient->getEmail() ?? 'sans email'
                    ));
                }
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Erreur envoi email de retard pour le ticket #%d à %s : %s',
                    (int) $ticket->getId(),
                    $recipient->getEmail() ?? 'sans email',
                    $e->getMessage()
                ));
            }
        }

        return $sentCount;
    }

    public function checkTask(TicketTask $task): int
    {
        $ticket = $task->getTicket();
        $assignedTo = $task->getAssignedTo();

        if (!$ticket || !$assignedTo instanceof User || !$assignedTo->getId()) {
            return 0;
        }

        $deadline = $this->getEffectiveDeadline($ticket);
        if (!$deadline || $this->isClosedTicket($ticket)) {
            return 0;
        }

        $now = new \DateTimeImmutable();
        if ($now <= $deadline) {
            return 0;
        }

        if ($this->alreadyNotifiedToday($ticket, $assignedTo, Notification::TYPE_DEADLINE_REMINDER)) {
            return 0;
        }

        $daysOverdue = $this->calculateDaysOverdue($deadline, $now);
        $responsibles = $this->buildResponsibleLabels($ticket);

        // ✅ Même correctif : la notification n'est plus conditionnée à
        // l'envoi réussi de l'email.
        $this->persistNotification($ticket, $assignedTo, $daysOverdue, $responsibles);

        try {
            $this->mailService->sendOverdueTicketEmail($assignedTo, $ticket, $daysOverdue, $responsibles);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Erreur envoi email de retard (tâche #%d, ticket #%d) : %s',
                (int) $task->getId(), (int) $ticket->getId(), $e->getMessage()
            ));
        }

        return 1;
    }

    /**
     * @return array<int, User>
     */
    private function collectRecipients(Ticket $ticket): array
    {
        $recipients = [];

        foreach ($this->userRepository->findUsersByRole('ROLE_SUPERUSER') as $superuser) {
            if ($superuser instanceof User && $superuser->getId()) {
                $recipients[$superuser->getId()] = $superuser;
            }
        }

        // ✅ Défensif : getAssignedUsers() peut ne pas exister selon les
        // versions de l'entité Ticket -- on vérifie avant d'appeler pour
        // éviter qu'une erreur fatale ici ne bloque TOUTE la vérification
        // des échéances (et donc l'ensemble des notifications, pas
        // seulement celles de ce ticket).
        if (method_exists($ticket, 'getAssignedUsers')) {
            try {
                foreach ($ticket->getAssignedUsers() as $assignedUser) {
                    if ($assignedUser instanceof User && $assignedUser->getId()) {
                        $recipients[$assignedUser->getId()] = $assignedUser;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('getAssignedUsers() a échoué : ' . $e->getMessage());
            }
        }

        foreach ($ticket->getTasks() as $task) {
            if (!$task instanceof TicketTask) {
                continue;
            }

            $assignedTo = $task->getAssignedTo();
            if ($assignedTo instanceof User && $assignedTo->getId()) {
                $recipients[$assignedTo->getId()] = $assignedTo;
            }
        }

        $createdBy = $ticket->getCreatedBy();
        if ($createdBy instanceof User && $createdBy->getId()) {
            $recipients[$createdBy->getId()] = $createdBy;
        }

        return array_values($recipients);
    }

    private function persistNotification(Ticket $ticket, User $recipient, int $daysOverdue, array $responsibles): void
    {
        $notification = new Notification();
        $notification->setUser($recipient);
        $notification->setTicket($ticket);
        $notification->setType(Notification::TYPE_DEADLINE_REMINDER);
        $notification->setMessage(sprintf(
            'Ticket #%d en retard de %d jour(s). Responsables: %s',
            (int) $ticket->getId(),
            $daysOverdue,
            implode(', ', $responsibles)
        ));

        $this->em->persist($notification);
        $this->em->flush();

        $this->logger->info(sprintf(
            'Notification de retard créée : ticket=%d destinataire=%s',
            (int) $ticket->getId(),
            $recipient->getUsername() ?? 'unknown'
        ));
    }

    private function getEffectiveDeadline(Ticket $ticket): ?\DateTimeInterface
    {
        return $ticket->getDeadlineAt() ?? $ticket->getDeadline();
    }

    private function alreadyNotifiedToday(Ticket $ticket, User $user, string $type): bool
    {
        $today = new \DateTimeImmutable('today');
        $count = $this->em->getRepository(Notification::class)
            ->createQueryBuilder('n')
            ->select('COUNT(n)')
            ->where('n.ticket = :ticket')
            ->andWhere('n.user = :user')
            ->andWhere('n.type = :type')
            ->andWhere('n.createdAt >= :today')
            ->setParameter('ticket', $ticket)
            ->setParameter('user', $user)
            ->setParameter('type', $type)
            ->setParameter('today', $today)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    private function isClosedTicket(Ticket $ticket): bool
    {
        return in_array($ticket->getStatus(), ['closed', 'completed'], true);
    }

    private function calculateDaysOverdue(\DateTimeInterface $deadline, \DateTimeImmutable $now): int
    {
        $difference = $deadline->diff($now);

        return max(1, (int) $difference->days);
    }

    /**
     * @param User[] $assignedUsers
     * @return array<int, string>
     */
    private function buildResponsibleLabels(Ticket $ticket): array
    {
        $labels = [];

        foreach ($ticket->getTasks() as $task) {
            if (!$task instanceof TicketTask) {
                continue;
            }

            $assignedTo = $task->getAssignedTo();
            if ($assignedTo instanceof User) {
                $labels[] = $assignedTo->getUsername() ?? ($assignedTo->getEmail() ?? 'Utilisateur');
            }
        }

        $labels = array_values(array_unique(array_filter($labels)));
        if ($labels !== []) {
            return $labels;
        }

        $createdBy = $ticket->getCreatedBy();
        if ($createdBy instanceof User) {
            return [$createdBy->getUsername() ?? ($createdBy->getEmail() ?? 'Créateur')];
        }

        return ['-'];
    }
}