<?php

namespace App\Command;

use App\Entity\Ticket;
use App\Entity\User;
use App\Service\NotificationService;
use App\Service\WorkflowEngineService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:assign-users-to-ia-workflow',
    description: 'Assigne des utilisateurs aux workflows IA qui n\'en ont pas et envoie les notifications',
)]
class AssignUsersToIaWorkflowCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private WorkflowEngineService $workflowEngine,
        private NotificationService $notificationService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'ticket-id',
                InputArgument::OPTIONAL,
                'L\'ID du workflow IA à traiter (optionnel)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $ticketId = $input->getArgument('ticket-id');

        // Récupérer les workflows IA sans utilisateurs assignés
        $qb = $this->em->getRepository(Ticket::class)
            ->createQueryBuilder('t')
            ->leftJoin('t.tasks', 'tasks')
            ->where('t.actionType = :actionType')
            ->andWhere('tasks.id IS NULL')
            ->setParameter('actionType', 'PLAN_DATA_IA');

        if ($ticketId) {
            $qb->andWhere('t.id = :ticketId')
                ->setParameter('ticketId', $ticketId);
        }

        $workflows = $qb->getQuery()->getResult();

        if (empty($workflows)) {
            $io->warning('Aucun workflow IA sans utilisateurs assignés trouvé.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Traitement de %d workflow(s) IA...', count($workflows)));

        $totalAssigned = 0;

        foreach ($workflows as $ticket) {
            $io->writeln('');
            $io->section(sprintf('Workflow #%d - %s', $ticket->getId(), $ticket->getTitle()));

            $assignedUsers = $this->assignUsersToWorkflow($ticket);

            if (!empty($assignedUsers)) {
                // Envoyer les notifications
                $this->notificationService->notifyWorkflowAssignment(
                    $ticket,
                    $assignedUsers,
                    count($assignedUsers)
                );
                $this->em->flush();

                $io->success(sprintf(
                    '%d utilisateur(s) assigné(s): %s',
                    count($assignedUsers),
                    implode(', ', array_map(fn($u) => $u->getUsername(), $assignedUsers))
                ));

                $totalAssigned += count($assignedUsers);
            } else {
                $io->warning('Aucun utilisateur disponible pour ce workflow.');
            }
        }

        $io->newLine();
        $io->success(sprintf('Total: %d utilisateur(s) assigné(s) à %d workflow(s)', $totalAssigned, count($workflows)));

        return Command::SUCCESS;
    }

    private function assignUsersToWorkflow(Ticket $ticket): array
    {
        $assignedUsers = [];
        $sites = $ticket->getTicketSites();

        if ($sites->isEmpty()) {
            return $assignedUsers;
        }

        // Récupérer tous les utilisateurs non-admin et non-superuser
        $availableUsers = $this->em->getRepository(User::class)
            ->createQueryBuilder('u')
            ->orderBy('u.service', 'ASC')
            ->addOrderBy('u.department', 'ASC')
            ->addOrderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();

        $filtered = array_filter($availableUsers, function (User $user): bool {
            return !in_array('ROLE_ADMIN', $user->getRoles(), true)
                && !in_array('ROLE_SUPERUSER', $user->getRoles(), true);
        });

        if (empty($filtered)) {
            return $assignedUsers;
        }

        // Grouper les utilisateurs par service
        $usersByService = [];
        foreach ($filtered as $user) {
            $service = $user->getService() ?? 'GENERAL';
            if (!isset($usersByService[$service])) {
                $usersByService[$service] = [];
            }
            $usersByService[$service][] = $user;
        }

        // Extraire les services des sites
        $services = [];
        foreach ($sites as $site) {
            $service = $site->getServiceName() ?? 'GENERAL';
            if (!in_array($service, $services, true)) {
                $services[] = $service;
            }
        }

        // Assigner les utilisateurs des services concernés
        foreach ($services as $service) {
            if (isset($usersByService[$service]) && !empty($usersByService[$service])) {
                $assignedUser = $usersByService[$service][0];
                $this->workflowEngine->createInitialIpTask($ticket, $assignedUser);
                $assignedUsers[] = $assignedUser;
            }
        }

        // Si aucun utilisateur spécifique, assigner les 2 premiers utilisateurs disponibles
        if (empty($assignedUsers) && !empty($filtered)) {
            $selectedUsers = array_slice($filtered, 0, 2);
            foreach ($selectedUsers as $user) {
                $this->workflowEngine->createInitialIpTask($ticket, $user);
                $assignedUsers[] = $user;
            }
        }

        return $assignedUsers;
    }
}
