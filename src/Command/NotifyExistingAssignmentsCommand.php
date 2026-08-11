<?php

namespace App\Command;

use App\Entity\Notification;
use App\Entity\TicketTask;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:notify-existing-assignments',
    description: 'Envoie les notifications/emails manqués pour les tâches déjà assignées avant le correctif.'
)]
class NotifyExistingAssignmentsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationService $notificationService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Toutes les tâches non terminées avec un utilisateur assigné
        $tasks = $this->em->getRepository(TicketTask::class)
            ->createQueryBuilder('t')
            ->where('t.status != :done')
            ->andWhere('t.assignedTo IS NOT NULL')
            ->setParameter('done', TicketTask::STATUS_DONE)
            ->getQuery()
            ->getResult();

        $io->writeln(sprintf('Tâches actives trouvées : %d', count($tasks)));

        $sent = 0;
        foreach ($tasks as $task) {
            $user = $task->getAssignedTo();
            $ticket = $task->getTicket();

            if (!$user || !$ticket) {
                continue;
            }

            // Évite les doublons si la commande est relancée
            // ✅ Correction : setMaxResults(1) + getOneOrNullResult, car plusieurs
            // tâches d'un même ticket peuvent générer plusieurs notifications
            // pour le même user/type -> getOneOrNullResult() plantait sinon.
            $alreadyNotified = $this->em->getRepository(Notification::class)
                ->createQueryBuilder('n')
                ->where('n.user = :user')
                ->andWhere('n.ticket = :ticket')
                ->andWhere('n.type = :type')
                ->setParameter('user', $user)
                ->setParameter('ticket', $ticket)
                ->setParameter('type', NotificationService::TYPE_WORKFLOW_ASSIGNED)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($alreadyNotified) {
                continue;
            }

            $this->notificationService->notify(
                $user,
                NotificationService::TYPE_WORKFLOW_ASSIGNED,
                sprintf(
                    'Tâche assignée : %s pour le ticket #%d - %s',
                    $task->getTitle(),
                    $ticket->getId() ?? 0,
                    $ticket->getTitle()
                ),
                $ticket
            );

            $sent++;
            $io->writeln(sprintf(
                ' - Notifié : %s <%s> (ticket #%d)',
                $user->getUsername(),
                $user->getEmail() ?? 'sans email',
                $ticket->getId() ?? 0
            ));
        }

        $this->em->flush();

        $io->success(sprintf('%d notification(s)/email(s) envoyé(s) en rattrapage.', $sent));

        return Command::SUCCESS;
    }
}