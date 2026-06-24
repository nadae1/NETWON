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

    public function analyzeSites(array $sites): array
    {
        $recommendations = [];
        foreach ($sites as $site) {
            $rec = $this->analyzeSingleSite($site);
            if ($rec) {
                $recommendations[] = $rec;
            }
        }
        usort($recommendations, fn($a, $b) => $this->getSeverityOrder($a['severity']) <=> $this->getSeverityOrder($b['severity']));
        return $recommendations;
    }

    private function getSeverityOrder(string $severity): int
    {
        return match ($severity) {
            'critical' => 0,
            'high' => 1,
            'medium' => 2,
            'low' => 3,
            default => 4,
        };
    }

    private function analyzeSingleSite(ProcessedSite $site): ?array
    {
        $maxTrafic = (float) $site->getMaxTrafic();
        $seuil = (float) $site->getSeuilCritique();
        $classification = $site->getClassification();
        $typeTrans = $site->getTypeTrans();
        $service = $site->getService();
        $occurrences = $site->getNombreOccurrences();

        $congestionLevel = $seuil > 0 ? ($maxTrafic / $seuil) * 100 : 0;

        if ($congestionLevel >= 95 || $occurrences > 100) {
            $status = 'CRITICAL_CONGESTION';
            $severity = 'critical';
            $description = '⚠️ Site très congestionné (>95% du seuil ou >100 occurrences)';
            $borderColor = '#dc2626';
        } elseif ($congestionLevel >= 80 || $occurrences > 50) {
            $status = 'CONGESTION';
            $severity = 'high';
            $description = '🔴 Site congestionné (>80% du seuil)';
            $borderColor = '#ef4444';
        } elseif ($congestionLevel >= 60 || $occurrences > 20) {
            $status = 'WARNING';
            $severity = 'medium';
            $description = '🟠 Site sous tension (>60% du seuil)';
            $borderColor = '#f59e0b';
        } else {
            $status = 'NORMAL';
            $severity = 'low';
            $description = '✅ Site normal';
            $borderColor = '#10b981';
        }

        $actions = [];

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

        if (stripos($typeTrans ?? '', 'FO') !== false && $congestionLevel >= 80) {
            $actions[] = [
                'type' => 'FO_UPGRADE',
                'label' => 'Upgrade fibre optique (FO)',
                'priority' => 'high',
                'estimatedEffort' => '3-5 jours',
                'teams' => ['INGENIERIE_CAPILLAIRE', 'DEPLOIEMENT', 'IP']
            ];
        } elseif (stripos($typeTrans ?? '', 'FH') !== false && $congestionLevel >= 80) {
            $actions[] = [
                'type' => 'FH_UPGRADE',
                'label' => 'Upgrade faisceau hertzien (FH)',
                'priority' => 'high',
                'estimatedEffort' => '2-3 jours',
                'teams' => ['IP', 'RADIO']
            ];
        } elseif ((stripos($typeTrans ?? '', 'BACKBONE') !== false || stripos($typeTrans ?? '', 'BH') !== false) && $congestionLevel >= 70) {
            $actions[] = [
                'type' => 'BACKBONE_UPGRADE',
                'label' => 'Upgrade Backbone',
                'priority' => 'high',
                'estimatedEffort' => '3 jours',
                'teams' => ['IP', 'BACKBONE']
            ];
        }

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
            'borderColor' => $borderColor,
            'recommendedActions' => $actions,
            'nombreOccurrences' => $occurrences,
            'isCritical' => $site->isCritical()
        ];
    }

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
                $stats['byActionType'][$type] = ($stats['byActionType'][$type] ?? 0) + 1;
                $effort = $action['estimatedEffort'];
                if ($effort === '0.5 jour') $stats['totalEstimatedDays'] += 0.5;
                elseif ($effort === '1 jour') $stats['totalEstimatedDays'] += 1;
                elseif ($effort === '2 jours') $stats['totalEstimatedDays'] += 2;
                elseif ($effort === '3 jours') $stats['totalEstimatedDays'] += 3;
                elseif ($effort === '3-5 jours') $stats['totalEstimatedDays'] += 4;
            }
        }
        return $stats;
    }

    public function createWorkflowFromRecommendations(array $validatedActions, User $createdBy, ?\DateTimeInterface $deadline = null): Ticket
    {
        $ticket = new Ticket();
        $ticket->setTitle('[IA] Plan Data - ' . date('d/m/Y'));
        $ticket->setDescription('Workflow généré automatiquement à partir des recommandations IA - ' . count($validatedActions) . ' sites à traiter');
        $ticket->setActionType('PLAN_DATA_IA');
        $ticket->setStatus('open');
        $ticket->setProgress(0);
        $ticket->setCreatedBy($createdBy);
        $ticket->setCreatedAt(new \DateTime());
        if ($deadline) {
            $ticket->setDeadline($deadline);
        }

        $this->em->persist($ticket);

        $sites = [];
        foreach ($validatedActions as $action) {
            $site = $this->em->getRepository(ProcessedSite::class)->find($action['siteId']);
            if ($site) {
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

        $assignedUsers = $this->assignUsersToWorkflow($ticket, $sites, $createdBy);
        if (!empty($assignedUsers) && $this->notificationService) {
            $this->notificationService->notifyWorkflowAssignment($ticket, $assignedUsers, count($assignedUsers));
            $this->em->flush();
        }
        return $ticket;
    }

    private function assignUsersToWorkflow(Ticket $ticket, array $sites, User $createdBy): array
    {
        if (!$this->workflowEngine) {
            return [];
        }

        $availableUsers = $this->em->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.id != :currentId')
            ->setParameter('currentId', $createdBy->getId())
            ->orderBy('u.service', 'ASC')
            ->getQuery()
            ->getResult();

        $filtered = array_filter($availableUsers, function (User $user): bool {
            return !in_array('ROLE_ADMIN', $user->getRoles(), true)
                && !in_array('ROLE_SUPERUSER', $user->getRoles(), true);
        });

        $usersByService = [];
        foreach ($filtered as $user) {
            $service = $user->getService() ?? 'GENERAL';
            $usersByService[$service][] = $user;
        }

        $services = array_unique(array_map(fn($s) => $s->getService() ?? 'GENERAL', $sites));
        $assignedUsers = [];
        foreach ($services as $service) {
            if (!empty($usersByService[$service])) {
                $user = $usersByService[$service][0];
                $this->workflowEngine->createInitialIpTask($ticket, $user);
                $assignedUsers[] = $user;
            }
        }
        return $assignedUsers;
    }

    public function getWorkflowBlocker(Ticket $ticket): ?array
    {
        foreach ($ticket->getTasks() as $task) {
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

    public function getCurrentResponsible(Ticket $ticket): ?User
    {
        foreach ($ticket->getTasks() as $task) {
            if (in_array($task->getStatus(), [TicketTask::STATUS_PENDING, TicketTask::STATUS_IN_PROGRESS])) {
                return $task->getAssignedTo();
            }
        }
        return null;
    }
}