<?php
// src/Service/WorkflowAutoAssigner.php

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
        $requiredServices = [];

        foreach ($sites as $site) {
            $services = $this->getRequiredServicesForSite($site);
            foreach ($services as $svc) {
                if (!in_array($svc, $requiredServices, true)) {
                    $requiredServices[] = $svc;
                }
            }
        }

        // Récupérer tous les utilisateurs ayant ROLE_USER et non admin/superuser
        $allUsers = $this->em->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.id != :currentId')
            ->andWhere('JSON_CONTAINS(u.roles, :role) = 1')
            ->setParameter('currentId', $createdBy->getId())
            ->setParameter('role', '"ROLE_USER"')
            ->getQuery()
            ->getResult();

        // Filtrer les superusers et admins
        $normalUsers = array_filter($allUsers, function (User $user) {
            $roles = $user->getRoles();
            return !in_array('ROLE_ADMIN', $roles) && !in_array('ROLE_SUPERUSER', $roles);
        });

        // Grouper par service
        $usersByService = [];
        foreach ($normalUsers as $user) {
            $service = $user->getService() ?? 'GENERAL';
            if (!isset($usersByService[$service])) {
                $usersByService[$service] = [];
            }
            $usersByService[$service][] = $user;
        }

        $assignedUsers = [];
        foreach ($requiredServices as $svc) {
            if (isset($usersByService[$svc]) && !empty($usersByService[$svc])) {
                $user = $usersByService[$svc][0];
                $this->workflowEngine->createInitialIpTask($ticket, $user);
                $assignedUsers[] = $user;
                $this->logger->info("Assigné $svc à l'utilisateur {$user->getUsername()}");
            } else {
                $this->logger->warning("Service requis '$svc' non trouvé parmi les utilisateurs.");
            }
        }

        if (empty($assignedUsers)) {
            throw new \Exception(
                "Aucun utilisateur trouvé pour les services requis : " . implode(', ', $requiredServices) .
                ". Veuillez créer des utilisateurs avec ces services et le rôle ROLE_USER."
            );
        }

        return $assignedUsers;
    }

    private function getRequiredServicesForSite(ProcessedSite $site): array
    {
        $typeTrans = strtoupper($site->getTypeTrans() ?? '');
        $action = $site->getRecommendedAction() ?? 'UPGRADE';

        // Workflow FO
        if (str_contains($typeTrans, 'FO')) {
            $base = ['IP']; // service principal
            if ($site->isCritical() || str_contains($action, 'FO_UPGRADE')) {
                $base[] = 'INGENIERIE_CAPILLAIRE';
            }
            $base[] = 'DEPLOIEMENT';
            $base[] = 'RADIO';
            $base[] = 'BACKHAUL';
            return array_unique($base);
        }

        // Workflow FH
        if (str_contains($typeTrans, 'FH')) {
            return ['TRANSMISSION', 'IP', 'RADIO', 'DEPLOIEMENT_TELECOM'];
        }

        // Shared / Backbone
        if (str_contains($typeTrans, 'SHARED') || str_contains($typeTrans, 'BACKBONE')) {
            return ['BACKBONE', 'OPERATEUR'];
        }

        return ['IP'];
    }
}