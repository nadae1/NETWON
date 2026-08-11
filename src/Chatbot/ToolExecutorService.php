<?php

namespace App\Chatbot;

use App\Repository\ProcessedSiteRepository;
use App\Repository\TicketRepository;

class ToolExecutorService
{
    public function __construct(
        private ProcessedSiteRepository $siteRepository,
        private TicketRepository $ticketRepository,
    ) {}

    public function execute(string $toolName, array $args): array
    {
        return match ($toolName) {
            'get_sites_overview' => $this->getSitesOverview($args['service'] ?? null),
            'get_site_details' => $this->getSiteDetails($args['site_name'] ?? ''),
            'get_critical_sites' => $this->getCriticalSites($args['service'] ?? null, (int) ($args['limit'] ?? 20)),
            'get_tickets_by_status' => $this->getTicketsByStatus($args['status'] ?? null),
            'get_ticket_details' => $this->getTicketDetails((int) ($args['ticket_id'] ?? 0)),
            'get_monthly_ticket_stats' => $this->getMonthlyTicketStats((int) ($args['month'] ?? date('m')), (int) ($args['year'] ?? date('Y'))),
            'get_site_ticket_membership' => $this->getSiteTicketMembership($args['site_name'] ?? ''),
            'get_classification_breakdown' => $this->getClassificationBreakdown($args['classification'] ?? null, $args['service'] ?? null),
            'get_total_workflows_count' => $this->getTotalWorkflowsCount($args['status'] ?? null),
            'get_ticket_assigned_users' => $this->getTicketAssignedUsers((int) ($args['ticket_id'] ?? 0)),
            default => ['error' => "Outil inconnu : $toolName"],
        };
    }
private function getSitesOverview(?string $service): array
{
    $total = $this->siteRepository->countAllSites($service);
    $critical = $this->siteRepository->countCriticalSites($service);

    $minMax = $this->siteRepository->createQueryBuilder('s')
        ->select('MIN(s.maxTrafic) as minT, MAX(s.maxTrafic) as maxT');
    if ($service) {
        $minMax->andWhere('s.service = :service')->setParameter('service', $service);
    }
    $mm = $minMax->getQuery()->getSingleResult();

    return [
        'filtre_service_demande' => $service ?? 'aucun (tous services)',
        'total_sites_pour_ce_filtre' => $total,
        'sites_critiques_pour_ce_filtre' => $critical,
        'pourcentage_critique_pour_ce_filtre' => $total > 0 ? round(($critical / $total) * 100, 1) : 0,
        'trafic_min_mbps_pour_ce_filtre' => $mm['minT'] !== null ? round((float) $mm['minT'], 1) : null,
        'trafic_moyen_mbps_pour_ce_filtre' => round($this->siteRepository->getAverageTraffic($service), 1),
        'trafic_max_mbps_pour_ce_filtre' => $mm['maxT'] !== null ? round((float) $mm['maxT'], 1) : null,
        'repartition_globale_par_service_TOUS_SITES' => $this->siteRepository->getServiceDistribution(),
        'repartition_par_classification_pour_ce_filtre' => $this->siteRepository->getClassificationStats($service),
    ];
}

private function getClassificationBreakdown(?string $classification, ?string $service): array
{
    $qb = $this->siteRepository->createQueryBuilder('s')
        ->select('s.typeTrans as typeTrans, COUNT(s.id) as total')
        ->groupBy('s.typeTrans');

    if ($classification) {
        $qb->andWhere('s.classification = :classification')->setParameter('classification', $classification);
    }
    if ($service) {
        $qb->andWhere('s.service = :service')->setParameter('service', $service);
    }

    $rows = $qb->getQuery()->getArrayResult();
    $breakdown = [];
    $sum = 0;
    foreach ($rows as $row) {
        $breakdown[$row['typeTrans'] ?? 'NON_DEFINI'] = (int) $row['total'];
        $sum += (int) $row['total'];
    }

    return [
        'classification_filtree' => $classification ?? 'toutes',
        'service_filtre' => $service ?? 'tous',
        'total_sites_correspondants' => $sum,
        'repartition_par_type_trans' => $breakdown,
    ];
}

private function getTotalWorkflowsCount(?string $status): array
{
    $qb = $this->ticketRepository->createQueryBuilder('t')->select('COUNT(t.id)');
    if ($status) {
        $qb->andWhere('t.status = :status')->setParameter('status', $status);
    }
    $total = (int) $qb->getQuery()->getSingleScalarResult();

    return [
        'filtre_statut' => $status ?? 'tous statuts confondus',
        'nombre_total_de_workflows' => $total,
    ];
}

private function getTicketAssignedUsers(int $ticketId): array
{
    $ticket = $this->ticketRepository->find($ticketId);
    if (!$ticket) {
        return ['error' => "Ticket #$ticketId introuvable"];
    }

    $users = [];
    foreach ($ticket->getAssignedUsers() as $u) {
        $users[] = ['username' => $u->getUserIdentifier(), 'roles' => implode(', ', $u->getRoles())];
    }

    return [
        'ticket_id' => $ticket->getId(),
        'titre' => $ticket->getTitle(),
        'cree_par' => $ticket->getCreatedBy()?->getUsername(),
        'nombre_utilisateurs_assignes' => count($users),
        'utilisateurs_assignes' => $users,
    ];
}

    private function getSiteDetails(string $siteName): array
    {
        if (!$siteName) {
            return ['error' => 'Nom de site manquant'];
        }

        $exact = $this->siteRepository->findOneBySiteName($siteName);
        if ($exact) {
            return [$this->serializeSite($exact)];
        }

        $pagination = $this->siteRepository->findSitesPaginated(null, null, $siteName, 1, 5);
        if (empty($pagination['items'])) {
            return ['error' => "Aucun site trouvé pour '$siteName'"];
        }

        return array_map(fn($s) => $this->serializeSite($s), $pagination['items']);
    }

    private function getCriticalSites(?string $service, int $limit): array
    {
        $sites = $this->siteRepository->findCriticalSites($service, $limit);

        if (empty($sites)) {
            return ['error' => 'Aucun site critique trouvé' . ($service ? " pour le service $service" : '')];
        }

        return array_map(fn($s) => $this->serializeSite($s), $sites);
    }

    private function serializeSite($s): array
    {
        return [
            'site' => $s->getSiteName(),
            'service' => $s->getServiceName(),
            'classification' => $s->getClassification(),
            'type_trans' => $s->getTypeTrans(),
            'trafic_max_mbps' => $s->getMaxTrafic(),
            'capacite_totale_mbps' => $s->getCapaciteMbps(),
            'taux_utilisation_pct' => $s->getTauxUtilisation(),
            'critique' => $s->isCritical() ? 'Oui' : 'Non',
            'statut' => $s->getSiteStatus(),
            'derniere_maj' => $s->getDateMax()?->format('d/m/Y H:i'),
        ];
    }

    private function getTicketsByStatus(?string $status): array
    {
        $tickets = $this->ticketRepository->findByStatusOrdered($status);

        return array_map(fn($t) => [
            'id' => $t->getId(),
            'titre' => $t->getTitle(),
            'statut' => $t->getStatus(),
            'action' => $t->getActionType(),
            'progression_pct' => $t->getProgress(),
            'cree_par' => $t->getCreatedBy()?->getUsername(),
            'deadline' => $t->getDeadlineAt()?->format('d/m/Y H:i'),
        ], $tickets);
    }

    private function getTicketDetails(int $ticketId): array
    {
        $ticket = $this->ticketRepository->find($ticketId);
        if (!$ticket) {
            return ['error' => "Ticket #$ticketId introuvable"];
        }

        $tasks = [];
        foreach ($ticket->getTasks() as $task) {
            $tasks[] = [
                'service' => $task->getServiceName(),
                'statut' => $task->getStatus(),
                'assigne_a' => $task->getAssignedTo()?->getUsername(),
            ];
        }

        $sites = [];
        foreach ($ticket->getTicketSites() as $site) {
            $sites[] = ['statut' => $site->getStatus()];
        }

        return [
            'id' => $ticket->getId(),
            'titre' => $ticket->getTitle(),
            'statut' => $ticket->getStatus(),
            'progression_pct' => $ticket->getProgress(),
            'deadline' => $ticket->getDeadlineAt()?->format('d/m/Y H:i'),
            'nombre_taches' => count($tasks),
            'taches' => $tasks,
            'nombre_sites' => count($sites),
            'sites' => $sites,
        ];
    }

    private function getMonthlyTicketStats(int $month, int $year): array
    {
        $start = new \DateTime("$year-$month-01 00:00:00");
        $end = (clone $start)->modify('last day of this month')->setTime(23, 59, 59);

        $stats = $this->ticketRepository->getMonthlyStats($start, $end);
        $workflows = $this->ticketRepository->findWorkflowsForMonth($start, $end);

        $totalSites = 0;
        foreach ($workflows as $wf) {
            $totalSites += $wf->getTicketSites()->count();
        }

        return [
            'periode' => "$month/$year",
            'stats' => $stats,
            'total_sites_traites' => $totalSites,
            'nombre_workflows' => count($workflows),
        ];
    }
    private function getSiteTicketMembership(string $siteName): array
    {
        if (!$siteName) {
            return ['error' => 'Nom de site manquant'];
        }

        $tickets = $this->ticketRepository->findTicketsBySiteName($siteName);
        if (empty($tickets)) {
            return ['error' => "Aucun workflow/ticket trouvé contenant le site '$siteName'"];
        }

        return array_map(function ($t) use ($siteName) {
            $siteStatus = null;
            foreach ($t->getTicketSites() as $ts) {
                if (stripos($ts->getSiteName() ?? '', $siteName) !== false) {
                    $siteStatus = $ts->getStatus();
                    break;
                }
            }
            return [
                'ticket_id' => $t->getId(),
                'ticket_titre' => $t->getTitle(),
                'ticket_statut_global' => $t->getStatus(),
                'statut_du_site_dans_ce_ticket' => $siteStatus,
                'progression_pct' => $t->getProgress(),
            ];
        }, $tickets);
    }
}
