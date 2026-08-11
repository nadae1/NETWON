<?php

namespace App\Command;

use App\Entity\TicketTask;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ✅ NOUVEAU : commande de diagnostic (lecture seule, ne modifie rien)
 * pour retrouver les tâches dont le departmentName ne correspond pas au
 * vrai département de l'utilisateur assigné -- symptôme direct du bug
 * corrigé dans WorkflowAutoAssigner (departmentName déduit du service
 * du site plutôt que du département réel de l'utilisateur).
 *
 * Utile pour évaluer l'ampleur du problème avant toute correction
 * manuelle en base, et pour vérifier concrètement le cas du ticket #73
 * mentionné.
 */
#[AsCommand(
    name: 'app:diagnose:task-department-mismatch',
    description: 'Liste les tâches dont le departmentName ne correspond pas au département réel de l\'utilisateur assigné.'
)]
class DiagnoseTaskAssignmentsCommand extends Command
{
    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tasks = $this->em->getRepository(TicketTask::class)
            ->createQueryBuilder('t')
            ->leftJoin('t.assignedTo', 'u')->addSelect('u')
            ->leftJoin('t.ticket', 'tk')->addSelect('tk')
            ->where('t.assignedTo IS NOT NULL')
            ->getQuery()
            ->getResult();

        $mismatches = [];
        foreach ($tasks as $task) {
            /** @var TicketTask $task */
            $user = $task->getAssignedTo();
            if (!$user instanceof User) {
                continue;
            }

            $userDept = $user->getDepartment();
            $taskDept = $task->getDepartmentName();

            // On ne signale que les cas où l'utilisateur A un
            // département renseigné en base ET qu'il diffère de celui
            // de la tâche -- sinon trop de faux positifs (tâches créées
            // par le pipeline FO/Déploiement, volontairement différentes
            // du "département principal" de l'utilisateur, ex: un
            // utilisateur IP dont département est 'ingenierie_ip' mais
            // dont une tâche legacy a 'engineering_ip' -- pas notre cible).
            if ($userDept && $taskDept && $userDept !== $taskDept
                && in_array($userDept, ['support_backhaul', 'support_radio', 'deploiement_telecom', 'ingenierie_ip', 'ingenierie_capillaire'], true)) {
                $mismatches[] = [
                    'ticket_id' => $task->getTicket()?->getId(),
                    'ticket_title' => $task->getTicket()?->getTitle(),
                    'task_id' => $task->getId(),
                    'task_title' => $task->getTitle(),
                    'task_status' => $task->getStatus(),
                    'assigned_user' => $user->getUsername(),
                    'user_department' => $userDept,
                    'task_department' => $taskDept,
                ];
            }
        }

        if (empty($mismatches)) {
            $io->success('Aucune incohérence trouvée entre le département utilisateur et le departmentName des tâches.');
            return Command::SUCCESS;
        }

        $io->warning(sprintf('%d tâche(s) avec incohérence de département trouvée(s) :', count($mismatches)));
        $io->table(
            ['Ticket', 'Tâche', 'Statut', 'Utilisateur', 'Département réel', 'Département tâche'],
            array_map(fn($m) => [
                '#' . $m['ticket_id'] . ' — ' . mb_strimwidth((string) $m['ticket_title'], 0, 30, '…'),
                '#' . $m['task_id'] . ' — ' . mb_strimwidth((string) $m['task_title'], 0, 25, '…'),
                $m['task_status'],
                $m['assigned_user'],
                $m['user_department'],
                $m['task_department'],
            ], $mismatches)
        );

        $io->note('Cette commande ne modifie rien. Pour corriger une tâche, mettez à jour manuellement task.departmentName en base après vérification, ou via une future commande de correction si vous le souhaitez.');

        return Command::SUCCESS;
    }
}