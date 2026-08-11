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
    public const ALL_ACTION_TYPES = [
        'MONITORING'             => 'Maintenir sous surveillance',
        'CAPACITY_UPGRADE_TDD'   => 'Upgrade capacité TDD',
        'CAPACITY_UPGRADE_FDD'   => 'Upgrade capacité FDD',
        'CAPACITY_UPGRADE_BOTH'  => 'Upgrade capacité TDD + FDD',
        'TF_UPGRADE'             => 'Upgrade capacité TF',
        'TF_SWAP'                => 'Swap TF',
        'COTRANS_OPTIMIZATION'   => 'Optimisation COTRANS',
        'NO_COTRANS_REVIEW'      => 'Revue configuration NO_COTRANS',
        'FDD_ANALYSIS'           => 'Analyse FDD approfondie',
        'FO_UPGRADE'             => 'Upgrade fibre optique (FO)',
        'FH_UPGRADE'             => 'Upgrade faisceau hertzien (FH)',
        'BACKBONE_UPGRADE'       => 'Upgrade Backbone',
        'NEW_NEED'               => 'Nouveau besoin de capacité',
        'NO_ACTION'              => 'Aucune action nécessaire',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private ?NotificationService $notificationService = null,
        private ?WorkflowEngineService $workflowEngine = null
    ) {}

    public function getAllActionTypes(): array
    {
        return self::ALL_ACTION_TYPES;
    }

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
        $capaciteMbps = (float) ($site->getCapaciteMbps() ?? 0);
        $classification = $site->getClassification();
        $typeTrans = $site->getTypeTrans();
        $service = $site->getService();
        $occurrences = $site->getNombreOccurrences();

        // Taux d'utilisation (affichage uniquement, ne pilote plus la sévérité)
        $tauxUtilStockee = $site->getTauxUtilisation();
        if ($tauxUtilStockee !== null) {
            $tauxGlobal = round((float) $tauxUtilStockee, 1);
        } else {
            $tauxGlobal = ($capaciteMbps > 0) ? round(($maxTrafic / $capaciteMbps) * 100, 1) : 0.0;
        }

        $tauxTdd = $site->getTauxUtilisationTdd();
        $tauxFdd = $site->getTauxUtilisationFdd();

        // Sévérité basée sur le VRAI état calculé par le pipeline
        $etatReel = $site->getStatus() ?? 'NON_EVALUE';
        $siteStatusReel = $site->getSiteStatus() ?? 'NON_EVALUE';
        $isCriticalReel = $site->isCritical();

        if ($isCriticalReel) {
            $severity = 'critical';
            $statusLabel = 'CRITICAL';
            $borderColor = '#dc2626';
            $description = match (true) {
                str_starts_with($etatReel, 'CONGESTION') => '🔴 Congestion confirmée (taux critique et occurrences répétées)',
                $etatReel === 'BRIDAGE' => '🔴 Bridage suspecté (occurrences élevées à taux modéré)',
                default => '🔴 État critique confirmé',
            };
        } elseif ($etatReel === 'RISQUE_DE_CONGESTION') {
            $severity = 'high';
            $statusLabel = 'HIGH';
            $description = '🟠 Risque de congestion (taux ≥ seuil, occurrences pas encore confirmées)';
            $borderColor = '#ef4444';
        } elseif ($etatReel === 'SANS_TYPE' || $siteStatusReel === 'SURVEILLANCE') {
            $severity = 'medium';
            $statusLabel = 'MEDIUM';
            $description = $etatReel === 'SANS_TYPE'
                ? '🟡 Type de liaison manquant : évaluation non fiabilisée'
                : '🟡 Sous surveillance';
            $borderColor = '#f59e0b';
        } else {
            $severity = 'low';
            $statusLabel = 'LOW';
            $description = '🟢 Taux normal';
            $borderColor = '#10b981';
        }

        // Actions recommandées
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
        } elseif ($classification === 'FDD' || $classification === 'ONLY_FDD') {
            $actions[] = [
                'type' => 'FDD_ANALYSIS',
                'label' => 'Analyse FDD approfondie',
                'priority' => $severity === 'critical' ? 'high' : 'medium',
                'estimatedEffort' => '1 jour',
                'teams' => ['IP']
            ];
        }

        if (stripos($typeTrans ?? '', 'FO') !== false && $tauxGlobal >= 80) {
            $actions[] = [
                'type' => 'FO_UPGRADE',
                'label' => 'Upgrade fibre optique (FO)',
                'priority' => 'high',
                'estimatedEffort' => '3-5 jours',
                'teams' => ['INGENIERIE_CAPILLAIRE', 'DEPLOIEMENT', 'IP']
            ];
        } elseif (stripos($typeTrans ?? '', 'FH') !== false && $tauxGlobal >= 80) {
            $actions[] = [
                'type' => 'FH_UPGRADE',
                'label' => 'Upgrade faisceau hertzien (FH)',
                'priority' => 'high',
                'estimatedEffort' => '2-3 jours',
                'teams' => ['IP', 'RADIO']
            ];
        } elseif ((stripos($typeTrans ?? '', 'BACKBONE') !== false || stripos($typeTrans ?? '', 'BH') !== false) && $tauxGlobal >= 70) {
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

        // ✅ Génération des données de trafic pour les graphiques (simulation)
        $currentValues = [];
        $afterValues = [];
        $labels = [];
        $now = new \DateTime();
        for ($i = 6; $i >= 0; $i--) {
            $date = (clone $now)->modify("-$i days");
            $labels[] = $date->format('d/m');
            $base = $maxTrafic * (0.8 + (mt_rand(0, 40) / 100));
            $currentValues[] = round($base, 2);
            $reduction = in_array($severity, ['critical', 'high']) ? 0.30 : 0.10;
            $afterValues[] = round($base * (1 - $reduction), 2);
        }

        return [
            'siteId' => $site->getId(),
            'siteName' => $site->getSiteName(),
            'pairedSiteName' => $site->getPairedSiteName(),
            'classification' => $classification,
            'service' => $service,
            'typeTrans' => $typeTrans,
            'maxTrafic' => round($maxTrafic, 2),
            'capaciteMbps' => round($capaciteMbps, 2),
            'tauxGlobal' => $tauxGlobal,
            'tauxTdd' => $tauxTdd !== null ? round((float) $tauxTdd, 1) : null,
            'tauxFdd' => $tauxFdd !== null ? round((float) $tauxFdd, 1) : null,
            'status' => $statusLabel,
            'severity' => $severity,
            'description' => $description,
            'borderColor' => $borderColor,
            'recommendedActions' => $actions,
            'nombreOccurrences' => $occurrences,
            'isCritical' => $isCriticalReel,
            'etatReel' => $etatReel,
            'siteStatusReel' => $siteStatusReel,
            // ✅ Données pour les graphiques avant/après
            'currentTrafficData' => [
                'labels' => $labels,
                'values' => $currentValues,
            ],
            'afterActionData' => [
                'labels' => $labels,
                'values' => $afterValues,
            ],
        ];
    }

    public function confirmSeverityFromGraph(array $trafficValues, string $severity): array
    {
        $n = count($trafficValues);
        if ($n < 4) {
            return [
                'confirmed' => null,
                'trend' => 'insuffisant',
                'variation' => 0,
                'label' => 'ℹ️ Données insuffisantes pour confirmation graphique',
            ];
        }

        $mid = intdiv($n, 2);
        $firstHalfAvg = array_sum(array_slice($trafficValues, 0, $mid)) / max(1, $mid);
        $secondHalfAvg = array_sum(array_slice($trafficValues, $mid)) / max(1, $n - $mid);

        $variation = $firstHalfAvg > 0 ? (($secondHalfAvg - $firstHalfAvg) / $firstHalfAvg) * 100 : 0;

        if ($variation > 10) {
            $trend = 'hausse';
        } elseif ($variation < -10) {
            $trend = 'baisse';
        } else {
            $trend = 'stable';
        }

        $isHighSeverity = in_array($severity, ['critical', 'high'], true);
        $confirmed = ($isHighSeverity && in_array($trend, ['hausse', 'stable'], true))
            || (!$isHighSeverity && $trend !== 'hausse');

        $label = $confirmed
            ? '✅ Confirmé par analyse des courbes KPI'
            : '⚠️ À vérifier manuellement (courbe non concordante)';

        return [
            'confirmed' => $confirmed,
            'trend' => $trend,
            'variation' => round($variation, 1),
            'label' => $label,
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

    public function createWorkflowFromRecommendations(
        array $validatedActions,
        User $createdBy,
        ?\DateTimeInterface $deadline = null,
        string $priority = 'medium',
        ?string $title = null
    ): Ticket {
        $ticket = new Ticket();
        $ticket->setTitle($title !== null && $title !== '' ? $title : ('[IA] Plan Data - ' . date('d/m/Y')));
        $ticket->setDescription('Workflow généré automatiquement à partir des recommandations IA - ' . count($validatedActions) . ' sites à traiter');
        $ticket->setActionType('PLAN_DATA_IA');
        $ticket->setStatus('open');
        $ticket->setProgress(0);
        $ticket->setCreatedBy($createdBy);
        $ticket->setCreatedAt(new \DateTime());
        $ticket->setTotalSteps(7);
        $ticket->setCurrentStep(1);
        if ($deadline) {
            $ticket->setDeadline($deadline);
        }
        if (method_exists($ticket, 'setPriority')) {
            $ticket->setPriority($priority);
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

                if (method_exists($ticketSite, 'setActionType') && !empty($action['actionType'])) {
                    $ticketSite->setActionType($action['actionType']);
                }
                if (method_exists($ticketSite, 'setComment') && !empty($action['comment'])) {
                    $ticketSite->setComment($action['comment']);
                }

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