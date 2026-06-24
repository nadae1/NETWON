<?php

namespace App\Service;

use App\Entity\ProcessedSite;
use App\Entity\Ticket;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class WorkflowAutoAssigner
{
    private EntityManagerInterface $em;
    private WorkflowEngineService $workflowEngine;
    private LoggerInterface $logger;

    public function __construct(
        EntityManagerInterface $em,
        WorkflowEngineService $workflowEngine,
        LoggerInterface $logger
    ) {
        $this->em = $em;
        $this->workflowEngine = $workflowEngine;
        $this->logger = $logger;
    }

    /**
     * @param ProcessedSite[] $sites
     * @return User[]
     * @throws \Exception
     */
    public function assignUsersForSites(array $sites, Ticket $ticket, User $createdBy): array
    {
        $requiredDepartments = [];

        foreach ($sites as $site) {
            $depts = $this->getRequiredDepartmentsForSite($site);
            foreach ($depts as $dept) {
                if (!in_array($dept, $requiredDepartments, true)) {
                    $requiredDepartments[] = $dept;
                }
            }
        }

        // Récupérer tous les utilisateurs normaux (ROLE_USER, non admin/superuser)
        $allUsers = $this->em->getRepository(User::class)->findAll();
        $normalUsers = array_filter($allUsers, function (User $user) {
            $roles = $user->getRoles();
            return in_array('ROLE_USER', $roles) 
                && !in_array('ROLE_ADMIN', $roles) 
                && !in_array('ROLE_SUPERUSER', $roles);
        });

        // Grouper par department
        $usersByDepartment = [];
        foreach ($normalUsers as $user) {
            $dept = $user->getDepartment();
            if ($dept) {
                if (!isset($usersByDepartment[$dept])) {
                    $usersByDepartment[$dept] = [];
                }
                $usersByDepartment[$dept][] = $user;
            }
        }

        $assignedUsers = [];
        foreach ($requiredDepartments as $dept) {
            if (!empty($usersByDepartment[$dept])) {
                $user = $usersByDepartment[$dept][0];
                $this->workflowEngine->createInitialIpTask($ticket, $user);
                $assignedUsers[] = $user;
                $this->logger->info("Assigné département '$dept' à l'utilisateur {$user->getUsername()}");
            } else {
                $this->logger->warning("Département requis '$dept' non trouvé parmi les utilisateurs.");
            }
        }

        if (empty($assignedUsers)) {
            throw new \Exception(
                "Aucun utilisateur trouvé pour les départements requis : " . implode(', ', $requiredDepartments) .
                ". Veuillez créer des utilisateurs avec ces departments et le rôle ROLE_USER."
            );
        }

        return $assignedUsers;
    }

    /**
     * Retourne la liste des départements requis pour un site donné.
     */
    private function getRequiredDepartmentsForSite(ProcessedSite $site): array
    {
        $typeTrans = strtoupper($site->getTypeTrans() ?? '');
        $action = $site->getRecommendedAction() ?? 'UPGRADE';

        // Workflow FO
        if (str_contains($typeTrans, 'FO')) {
            $base = ['ingenierie_ip'];
            if ($site->isCritical() || str_contains($action, 'FO_UPGRADE')) {
                $base[] = 'ingenierie_cap';
            }
            $base[] = 'deploiement';
            $base[] = 'support_radio';
            $base[] = 'support_backhaul';
            return array_unique($base);
        }

        // Workflow FH
        if (str_contains($typeTrans, 'FH')) {
            return ['ingenierie_fh', 'ingenierie_ip', 'support_radio', 'deploiement_telecom'];
        }

        // Shared / Backbone
        if (str_contains($typeTrans, 'SHARED') || str_contains($typeTrans, 'BACKBONE')) {
            return ['deploiement_shared', 'operateur'];
        }

        return ['ingenierie_ip'];
    }
}