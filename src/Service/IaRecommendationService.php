<?php

namespace App\Service;

use App\Entity\ProcessedSite;
use App\Entity\Ticket;
use App\Entity\TicketSite;
use App\Entity\TicketTask;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class IaRecommendationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ?NotificationService $notificationService = null,
        private ?WorkflowEngineService $workflowEngine = null
    ) {}
    
    /**
     * Analyse les sites et retourne des recommandations
     */
    public function analyzeSites(array $sites): array
    {
        $recommendations = [];
        
        foreach ($sites as $site) {
            $recommendation = $this->analyzeSingleSite($site);
            if ($recommendation) {
                $recommendations[] = $recommendation;
            }
        }
        
        // Trier par sévérité
        usort($recommendations, function($a, $b) {
            $severityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
            return $severityOrder[$a['severity']] <=> $severityOrder[$b['severity']];
        });
        
        return $recommendations;
    }
    
    /**
     * Analyse un site individuel
     */
    private function analyzeSingleSite(ProcessedSite $site): ?array
    {
        $maxTrafic = (float) $site->getMaxTrafic();
        $seuil = (float) $site->getSeuilCritique();
        $classification = $site->getClassification();
        $typeTrans = $site->getTypeTrans();
        $service = $site->getService();
        $occurrences = $site->getNombreOccurrences();
        
        // Calcul du niveau de congestion
        $congestionLevel = $seuil > 0 ? ($maxTrafic / $seuil) * 100 : 0;
        
        // Déterminer le statut et sévérité
        if ($congestionLevel >= 95 || $occurrences > 100) {
            $status = 'CRITICAL_CONGESTION';
            $severity = 'critical';
            $description = '⚠️ Site très congestionné (>95% du seuil ou >100 occurrences)';
            $bgColor = '#fee2e2';
            $borderColor = '#dc2626';
        } elseif ($congestionLevel >= 80 || $occurrences > 50) {
            $status = 'CONGESTION';
            $severity = 'high';
            $description = '🔴 Site congestionné (>80% du seuil)';
            $bgColor = '#fff1f2';
            $borderColor = '#ef4444';
        } elseif ($congestionLevel >= 60 || $occurrences > 20) {
            $status = 'WARNING';
            $severity = 'medium';
            $description = '🟠 Site sous tension (>60% du seuil)';
            $bgColor = '#fffbeb';
            $borderColor = '#f59e0b';
        } else {
            $status = 'NORMAL';
            $severity = 'low';
            $description = '✅ Site normal';
            $bgColor = '#ecfdf5';
            $borderColor = '#10b981';
        }
        
        // Actions recommandées
        $actions = [];
        
        // Actions basées sur la classification
        if ($classification === 'TF') {
            $actions[] = [
                'type' => 'TF_UPGRADE',
                'label' => 'Upgrade capacité TF',
                'priority' => $severity === 'critical' ? 'urgent' : ($severity === 'high' ? 'high' : 'medium'),
                'estimatedEffort' => '2 jours',
                'teams' => ['IP', 'DEPLOIEMENT']
            ];
        } elseif ($classification === 'COTRANS') {
            $actions[] = [
                'type' => 'COTRANS_OPTIMIZATION',
                'label' => 'Optimisation COTRANS',
                'priority' => $severity === 'critical' ? 'high' : 'medium',
                'estimatedEffort' => '1 jour',
                'teams' => ['IP']
            ];
        } elseif ($classification === 'NO_COTRANS') {
            $actions[] = [
                'type' => 'NO_COTRANS_REVIEW',
                'label' => 'Revue configuration NO_COTRANS',
                'priority' => 'medium',
                'estimatedEffort' => '1 jour',
                'teams' => ['IP']
            ];
        } elseif ($classification === 'FDD') {
            $actions[] = [
                'type' => 'FDD_ANALYSIS',
                'label' => 'Analyse FDD approfondie',
                'priority' => $severity === 'critical' ? 'high' : 'medium',
                'estimatedEffort' => '1 jour',
                'teams' => ['IP']
            ];
        }
        
        // Actions basées sur le type de transmission
        if (stripos($typeTrans ?? '', 'FO') !== false) {
            if ($congestionLevel >= 80) {
                $actions[] = [
                    'type' => 'FO_UPGRADE',
                    'label' => 'Upgrade fibre optique (FO)',
                    'priority' => 'high',
                    'estimatedEffort' => '3-5 jours',
                    'teams' => ['INGENIERIE_CAPILLAIRE', 'DEPLOIEMENT', 'IP']
                ];
            }
        } elseif (stripos($typeTrans ?? '', 'FH') !== false) {
            if ($congestionLevel >= 80) {
                $actions[] = [
                    'type' => 'FH_UPGRADE',
                    'label' => 'Upgrade faisceau hertzien (FH)',
                    'priority' => 'high',
                    'estimatedEffort' => '2-3 jours',
                    'teams' => ['IP', 'RADIO']
                ];
            }
        } elseif (stripos($typeTrans ?? '', 'BACKBONE') !== false || stripos($typeTrans ?? '', 'BH') !== false) {
            if ($congestionLevel >= 70) {
                $actions[] = [
                    'type' => 'BACKBONE_UPGRADE',
                    'label' => 'Upgrade Backbone',
                    'priority' => 'high',
                    'estimatedEffort' => '3 jours',
                    'teams' => ['IP', 'BACKBONE']
                ];
            }
        }
        
        // Action par défaut si aucune autre
        if (empty($actions)) {
            $actions[] = [
                'type' => 'MONITORING',
                'label' => 'Maintenir sous surveillance',
                'priority' => 'low',
                'estimatedEffort' => '0.5 jour',
                'teams' => ['MONITORING']
            ];
        }
        
        return [
            'siteId' => $site->getId(),
            'siteName' => $site->getSiteName(),
            'pairedSiteName' => $site->getPairedSiteName(),
            'classification' => $classification,
            'service' => $service,
            'typeTrans' => $typeTrans,
            'maxTrafic' => round($maxTrafic, 2),
            'seuilCritique' => round($seuil, 2),
            'congestionLevel' => round($congestionLevel, 1),
            'status' => $status,
            'severity' => $severity,
            'description' => $description,
            'bgColor' => $bgColor,
            'borderColor' => $borderColor,
            'recommendedActions' => $actions,
            'nombreOccurrences' => $occurrences,
            'isCritical' => $site->isCritical()
        ];
    }
    
    /**
     * Génère un plan d'action global
     */
    public function generateGlobalActionPlan(array $recommendations): array
    {
        $stats = [
            'total' => count($recommendations),
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'byActionType' => [],
            'byService' => ['FO' => 0, 'FH' => 0, 'SHARED' => 0, 'BACKBONE' => 0],
            'totalEstimatedDays' => 0
        ];
        
        foreach ($recommendations as $rec) {
            $stats[$rec['severity']]++;
            
            if (isset($stats['byService'][$rec['service']])) {
                $stats['byService'][$rec['service']]++;
            }
            
            foreach ($rec['recommendedActions'] as $action) {
                $type = $action['type'];
                if (!isset($stats['byActionType'][$type])) {
                    $stats['byActionType'][$type] = 0;
                }
                $stats['byActionType'][$type]++;
                
                // Estimation grossière des jours
                if ($action['estimatedEffort'] === '0.5 jour') $stats['totalEstimatedDays'] += 0.5;
                elseif ($action['estimatedEffort'] === '1 jour') $stats['totalEstimatedDays'] += 1;
                elseif ($action['estimatedEffort'] === '2 jours') $stats['totalEstimatedDays'] += 2;
                elseif ($action['estimatedEffort'] === '3 jours') $stats['totalEstimatedDays'] += 3;
                elseif ($action['estimatedEffort'] === '3-5 jours') $stats['totalEstimatedDays'] += 4;
            }
        }
        
        return $stats;
    }
    
    /**
     * Crée un workflow à partir des recommandations validées
     */
    public function createWorkflowFromRecommendations(array $validatedActions, User $createdBy): Ticket
    {
        $ticket = new Ticket();
        $ticket->setTitle('[IA] Plan Data - ' . date('d/m/Y'));
        $ticket->setDescription('Workflow généré automatiquement à partir des recommandations IA - ' . count($validatedActions) . ' sites à traiter');
        $ticket->setActionType('PLAN_DATA_IA');
        $ticket->setStatus('open');
        $ticket->setProgress(0);
        $ticket->setCreatedBy($createdBy);
        $ticket->setCreatedAt(new \DateTime());
        
        $this->em->persist($ticket);
        
        // Ajouter les sites et stocker l'action recommandée
        $sites = [];
        foreach ($validatedActions as $action) {
            $site = $this->em->getRepository(ProcessedSite::class)->find($action['siteId']);
            
            if ($site) {
                $site->setRecommendedAction($action['actionType'] ?? $site->getRecommendedAction());
                $this->em->persist($site);

                $ticketSite = new TicketSite();
                $ticketSite->setTicket($ticket);
                $ticketSite->setSiteName($site->getSiteName());
                $ticketSite->setTypeTrans($site->getTypeTrans());
                $ticketSite->setServiceName($site->getService());
                $this->em->persist($ticketSite);
                
                $sites[] = $site;
            }
        }
        
        $this->em->flush();
        
        // Assigner des utilisateurs disponibles et envoyer les notifications
        $assignedUsers = $this->assignUsersToWorkflow($ticket, $sites, $createdBy);

        if (!empty($assignedUsers) && $this->notificationService) {
            $this->notificationService->notifyWorkflowAssignment($ticket, $assignedUsers, count($assignedUsers));
        }

        if ($this->workflowEngine) {
            $this->workflowEngine->refreshTicketProgress($ticket);
        }

        $this->em->flush();
        
        return $ticket;
    }

    /**
     * Assigne les utilisateurs disponibles au workflow IA
     *
     * @param array<ProcessedSite> $sites
     * @return array<User>
     */
    private function assignUsersToWorkflow(Ticket $ticket, array $sites, User $createdBy): array
    {
        $assignedUsers = [];
        
        if (!$this->workflowEngine) {
            return $assignedUsers;
        }

        // Récupérer tous les utilisateurs non-admin et non-superuser
        $availableUsers = $this->em->getRepository(User::class)
            ->createQueryBuilder('u')
            ->andWhere('u.id != :currentId')
            ->setParameter('currentId', $createdBy->getId())
            ->orderBy('u.service', 'ASC')
            ->addOrderBy('u.department', 'ASC')
            ->addOrderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();

        $filtered = array_filter($availableUsers, function (User $user): bool {
            return !in_array('ROLE_ADMIN', $user->getRoles(), true)
                && !in_array('ROLE_SUPERUSER', $user->getRoles(), true);
        });

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
        $services = array_unique(array_map(fn($s) => $s->getService() ?? 'GENERAL', $sites));

        // Assigner les utilisateurs des services concernés
        foreach ($services as $service) {
            if (isset($usersByService[$service]) && !empty($usersByService[$service])) {
                // Assigner le premier utilisateur disponible pour ce service
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

        // En dernier recours, assigner le créateur du workflow
        if (empty($assignedUsers)) {
            $this->workflowEngine->createInitialIpTask($ticket, $createdBy);
            $assignedUsers[] = $createdBy;
        }

        return $assignedUsers;
    }
    
    /**
     * Détermine où le workflow est bloqué
     */
    public function getWorkflowBlocker(Ticket $ticket): ?array
    {
        $tasks = $ticket->getTasks();
        
        foreach ($tasks as $task) {
            if ($task->getStatus() === TicketTask::STATUS_BLOCKED) {
                return [
                    'user' => $task->getAssignedTo(),
                    'service' => $task->getServiceName(),
                    'since' => $task->getUpdatedAt() ?? $task->getCreatedAt(),
                    'task' => $task,
                    'reason' => $task->getComment()
                ];
            }
            
            if ($task->getStatus() === TicketTask::STATUS_PENDING) {
                return [
                    'user' => $task->getAssignedTo(),
                    'service' => $task->getServiceName(),
                    'since' => $task->getCreatedAt(),
                    'task' => $task,
                    'reason' => 'En attente de traitement'
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Récupère le responsable actuel du workflow
     */
    public function getCurrentResponsible(Ticket $ticket): ?User
    {
        $tasks = $ticket->getTasks();
        
        foreach ($tasks as $task) {
            if (in_array($task->getStatus(), [TicketTask::STATUS_PENDING, TicketTask::STATUS_IN_PROGRESS])) {
                return $task->getAssignedTo();
            }
        }
        
        return null;
    }
}