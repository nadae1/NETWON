<?php

namespace App\Controller;


use App\Entity\ProcessedSite;
use App\Repository\ProcessedSiteRepository;
use App\Repository\TicketRepository;
use App\Repository\NotificationRepository;
use App\Repository\SiteAlertRepository; // ✅ IMPORTANT : ajout ici
use App\Service\IaRecommendationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SuperuserDashboardController extends AbstractController
{

#[Route('/superuser/dashboard', name: 'superuser_dashboard_home')]
public function superuserDashboard(
    Request $request,
    ProcessedSiteRepository $processedSiteRepository,
    TicketRepository $ticketRepository,
    SiteAlertRepository $siteAlertRepository
): Response {
    $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

    $serviceFilter = $request->query->get('service');
    $classificationFilter = $request->query->get('classification');
    $criticalFilter = $request->query->get('critical');

    $totalSites = $processedSiteRepository->countAllSites($serviceFilter);

    // ✅ Utilisation de siteStatus = 'CRITIQUE' pour correspondre à la page Alertes (117)
    $criticalSites = $processedSiteRepository->countBySiteStatus('CRITIQUE', $serviceFilter);
    $criticalPercentage = $totalSites > 0 ? round(($criticalSites / $totalSites) * 100, 1) : 0;

    // Total des alertes réseau récentes (événements, pas sites distincts)
    $alertCounts = $siteAlertRepository->countByEtat(7);
    $defaults = ['CONGESTION' => 0, 'BRIDAGE' => 0, 'RISQUE_DE_CONGESTION' => 0];
    $alertCounts = array_merge($defaults, $alertCounts);
    $recentAlerts = array_sum($alertCounts);

    $serviceDistribution = $processedSiteRepository->getServiceDistribution();
    $classificationStats = $processedSiteRepository->getClassificationStats($serviceFilter);

    $allServices = array_keys($serviceDistribution);
    $allClassifications = array_keys($processedSiteRepository->getClassificationStats(null));

    // Avancement réel des workflows
    $workflowStats = $ticketRepository->getWorkflowSitesProgress();

    $activeWorkflows = (int) $ticketRepository->createQueryBuilder('t')
        ->select('COUNT(t.id)')
        ->where('t.status NOT IN (:closedStatuses)')
        ->setParameter('closedStatuses', ['completed', 'closed'])
        ->getQuery()
        ->getSingleScalarResult();

    $totalWorkflows = (int) $ticketRepository->createQueryBuilder('t')->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();

    $recentProcessedSites = $ticketRepository->findRecentlyProcessedTicketSites(5);

    return $this->render('dashboard/superuser/home.html.twig', [
        'totalSites' => $totalSites,
        'criticalSites' => $criticalSites,
        'criticalPercentage' => $criticalPercentage,
        'recentAlerts' => $recentAlerts,
        'serviceDistribution' => $serviceDistribution,
        'classificationStats' => $classificationStats,
        'allServices' => $allServices,
        'allClassifications' => $allClassifications,
        'currentService' => $serviceFilter,
        'currentClassification' => $classificationFilter,
        'currentCritical' => $criticalFilter,
        'workflowProgress' => $workflowStats['progress_percent'],
        'workflowCompletedSites' => $workflowStats['completed_sites'],
        'workflowTotalSites' => $workflowStats['total_sites'],
        'activeWorkflows' => $activeWorkflows,
        'totalWorkflows' => $totalWorkflows,
        'recentProcessedSites' => $recentProcessedSites,
    ]);
}
    #[Route('/superuser/plan-data', name: 'superuser_plan_data')]
    public function planData(
        Request $request,
        ProcessedSiteRepository $processedSiteRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $service = $request->query->get('service');
        $classification = $request->query->get('classification');
        $search = trim((string) $request->query->get('search', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $top10 = $request->query->has('top10');
        $needsUpdate = $request->query->has('needsUpdate');

        $allSites = $processedSiteRepository->findAllSitesOrderedByStatus(
            $service ?: null,
            $classification ?: null,
            $search
        );

        if ($needsUpdate) {
            $allSites = array_values(array_filter($allSites, function (ProcessedSite $s) {
                $needType = !$s->getTypeTrans() || $s->needsCapacityOrTypeUpdate();
                $needCapacity = ($s->getCapaciteTddMbps() === null && $s->getCapaciteFddMbps() === null)
                    || $s->getCapaciteMbps() === null || $s->getCapaciteMbps() <= 0;
                return $needType || $needCapacity;
            }));
            $page = 1;
        }

        if ($top10) {
            usort($allSites, function ($a, $b) {
                $occA = $a->getNombreOccurrences() ?? 0;
                $occB = $b->getNombreOccurrences() ?? 0;
                if ($occB !== $occA) return $occB - $occA;
                return ($b->getTauxUtilisation() ?? 0) <=> ($a->getTauxUtilisation() ?? 0);
            });
            $allSites = array_slice($allSites, 0, 10);
            $page = 1;
        }

        $criticalSites = 0;
        $warningSites = 0;
        $secureSites = 0;
        $sansTypeSites = 0;
        $congestionSites = 0;
        $bridageSites = 0;
        $aVerifierSites = 0;

        foreach ($allSites as $site) {
            $status = $site->getSiteStatus() ?? 'NON_EVALUE';
            $etat = $site->getStatus() ?? 'OK';

            if ($status === 'CRITIQUE') {
                $criticalSites++;
            } elseif ($status === 'SURVEILLANCE') {
                $warningSites++;
            } elseif ($status === 'SECURISE') {
                $secureSites++;
            }

            if ($etat === 'SANS_TYPE') {
                $sansTypeSites++;
            } elseif (str_contains($etat, 'CONGESTION')) {
                $congestionSites++;
            } elseif ($etat === 'BRIDAGE') {
                $bridageSites++;
            } elseif ($etat === 'A_VERIFIER_CAPACITE') {
                $aVerifierSites++;
            }
        }

        $totalSites = count($allSites);

        $sitesPerPage = 20;
        $totalPages = (!$top10 && !$needsUpdate && $totalSites > 0) ? (int) ceil($totalSites / $sitesPerPage) : 1;
        if (!$top10 && !$needsUpdate) {
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $sitesPerPage;
            $sites = array_slice($allSites, $offset, $sitesPerPage);
        } else {
            $sites = $allSites;
            $totalPages = 1;
        }

        $services = array_keys($processedSiteRepository->getServiceDistribution());
        $classifications = array_keys($processedSiteRepository->getClassificationStats($service));

        $imported = $request->query->has('imported');

        return $this->render('dashboard/superuser/plan_data.html.twig', [
            'sites' => $sites,
            'allSites' => $allSites,
            'criticalSites' => $criticalSites,
            'warningSites' => $warningSites,
            'secureSites' => $secureSites,
            'sansTypeSites' => $sansTypeSites,
            'congestionSites' => $congestionSites,
            'bridageSites' => $bridageSites,
            'aVerifierSites' => $aVerifierSites,
            'totalSites' => $totalSites,
            'services' => $services,
            'classifications' => $classifications,
            'currentService' => $service,
            'currentClassification' => $classification,
            'currentSearch' => $search,
            'pagination' => [
                'page' => $page,
                'totalPages' => $totalPages,
                'total' => $totalSites,
            ],
            'imported' => $imported,
            'importNeeded' => $totalSites === 0,
            'isTop10' => $top10,
            'isNeedsUpdate' => $needsUpdate,
        ]);
    }
// src/Controller/SuperuserDashboardController.php

#[Route('/superuser/ia-recommendations', name: 'superuser_ia_recommendations', methods: ['GET', 'POST'])]
public function iaRecommendations(
    Request $request,
    ProcessedSiteRepository $processedSiteRepository,
    IaRecommendationService $iaService,
    EntityManagerInterface $em
): Response {
    $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

    $service = $request->query->get('service');
    $classification = $request->query->get('classification');
    $search = trim((string) $request->query->get('search', ''));
    $filter = $request->query->get('filter', 'all');

    // Récupérer tous les sites selon les filtres
    $allSites = $processedSiteRepository->findAllSitesOrderedByStatus(
        $service ?: null,
        $classification ?: null,
        $search
    );

    // Filtrer : garder uniquement les sites non sécurisés (CRITIQUE ou SURVEILLANCE)
    $targetSites = array_filter($allSites, function (ProcessedSite $site) {
        $status = $site->getSiteStatus() ?? 'NON_EVALUE';
        return in_array($status, ['CRITIQUE', 'SURVEILLANCE'], true);
    });

    // Générer les recommandations
    $recommendations = $iaService->analyzeSites($targetSites);

    // Récupérer les données de trafic en batch pour tous les préfixes
    $prefixes = array_map(fn($s) => $s->getSiteName(), $targetSites);
    $batchTraffic = $processedSiteRepository->getTrafficHistoryForPrefixes($prefixes, 30);

    // Alimenter chaque recommandation avec les données réelles
    foreach ($recommendations as &$rec) {
        $siteName = $rec['siteName'];
        $data = $batchTraffic[$siteName] ?? ['labels' => [], 'values' => []];

        $rec['currentTrafficData'] = $data;

        // Projection après action (cible 65% du taux)
        $tauxActuel = $rec['tauxGlobal'];
        $targetUtil = 65.0;
        $ratio = ($tauxActuel > $targetUtil && $tauxActuel > 0) ? ($targetUtil / $tauxActuel) : 1.0;
        $afterValues = array_map(fn($v) => round($v * $ratio, 2), $data['values']);

        $rec['afterActionData'] = ['labels' => $data['labels'], 'values' => $afterValues];
        $rec['hasTrafficData'] = !empty($data['values']);

        $rec['graphAnalysis'] = $rec['hasTrafficData']
            ? $iaService->confirmSeverityFromGraph($data['values'], $rec['severity'])
            : ['confirmed' => null, 'trend' => 'insuffisant', 'variation' => 0, 'label' => 'ℹ️ Aucun historique de trafic disponible pour ce site'];
    }
    unset($rec);

    // Filtre Top 10 (si demandé)
    if ($filter === 'top10') {
        usort($recommendations, function ($a, $b) {
            if ($b['nombreOccurrences'] !== $a['nombreOccurrences']) {
                return $b['nombreOccurrences'] <=> $a['nombreOccurrences'];
            }
            return $b['tauxGlobal'] <=> $a['tauxGlobal'];
        });
        $recommendations = array_slice($recommendations, 0, 10);
    }

    $globalStats = $iaService->generateGlobalActionPlan($recommendations);
    $allActionTypes = $iaService->getAllActionTypes();

    // POST : création du workflow
    if ($request->isMethod('POST')) {
        $selectedSiteIds = $request->request->all('selected_sites');

        if (empty($selectedSiteIds)) {
            $this->addFlash('warning', 'Aucun site sélectionné.');
            return $this->redirectToRoute('superuser_ia_recommendations');
        }

        $deadline = $request->request->get('deadline');
        if (!$deadline) {
            $this->addFlash('error', 'La date limite est obligatoire pour créer le workflow.');
            return $this->redirectToRoute('superuser_ia_recommendations');
        }

        try {
            $deadlineDate = new \DateTime($deadline);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Date limite invalide.');
            return $this->redirectToRoute('superuser_ia_recommendations');
        }

        $priority = $request->request->get('workflow_priority', 'medium');
        $workflowName = trim((string) $request->request->get('workflow_name', ''));

        $actionsInput = $request->request->all('actions');
        $commentsInput = $request->request->all('comments');

        $validatedActions = [];
        foreach ($selectedSiteIds as $siteId) {
            $validatedActions[] = [
                'siteId' => $siteId,
                'actionType' => $actionsInput[$siteId]['action_type'] ?? 'MONITORING',
                'comment' => $commentsInput[$siteId] ?? null,
            ];
        }

        $workflow = $iaService->createWorkflowFromRecommendations(
            $validatedActions,
            $this->getUser(),
            $deadlineDate,
            $priority,
            $workflowName !== '' ? $workflowName : null
        );

        $this->addFlash('success', sprintf('Workflow #%d créé pour %d site(s).', $workflow->getId(), count($validatedActions)));
        return $this->redirectToRoute('superuser_workflow_show', ['id' => $workflow->getId()]);
    }

    return $this->render('dashboard/superuser/ia_recommendations.html.twig', [
        'recommendations' => $recommendations,
        'globalStats' => $globalStats,
        'allActionTypes' => $allActionTypes,
        'services' => array_keys($processedSiteRepository->getServiceDistribution()),
        'classifications' => array_keys($processedSiteRepository->getClassificationStats($service)),
        'currentService' => $service,
        'currentClassification' => $classification,
        'currentSearch' => $search,
        'currentFilter' => $filter,
    ]);
}
    private function buildRealTrafficData(
        ProcessedSite $site,
        ProcessedSiteRepository $repo,
        float $congestionLevel
    ): array {
        $siteName = $site->getSiteName();
        $days = 30;
        $totalSeries = [];

        try {
            $kpiData = $repo->getKpiCurvesDataForPrefix($siteName, null, null, $days);
            $totalSeries = $kpiData['series']['traffic']['total'] ?? [];
        } catch (\Throwable $e) {
            $totalSeries = [];
        }

        if (empty($totalSeries)) {
            $rows = $repo->getTrafficHistoryForSiteExact($siteName, $days);
            foreach ($rows as $row) {
                $timestamp = strtotime((string) ($row['date_heure'] ?? ($row['date_jour'] ?? '')));
                if ($timestamp === false) {
                    continue;
                }
                $totalSeries[] = [
                    'x' => $timestamp * 1000,
                    'y' => round((float) ($row['trafic_total'] ?? 0), 2),
                ];
            }
        }

        if (empty($totalSeries)) {
            return [
                'current' => ['labels' => [], 'values' => []],
                'after' => ['labels' => [], 'values' => []],
                'hasData' => false,
            ];
        }

        $labels = [];
        $currentValues = [];
        foreach ($totalSeries as $point) {
            $labels[] = date('d/m H:i', intdiv((int) $point['x'], 1000));
            $currentValues[] = (float) $point['y'];
        }

        $targetUtilization = 65.0;
        $ratio = ($congestionLevel > $targetUtilization && $congestionLevel > 0)
            ? ($targetUtilization / $congestionLevel)
            : 1.0;

        $afterValues = array_map(fn($v) => round($v * $ratio, 2), $currentValues);

        return [
            'current' => ['labels' => $labels, 'values' => $currentValues],
            'after' => ['labels' => $labels, 'values' => $afterValues],
            'hasData' => true,
        ];
    }

    /**
     * ✅ MODIFIÉ : ajout du filtre "status" (Sans capacité / Sans type /
     * Sécurisé / Critique / Sous observation / Congestion / Bridage /
     * Risque de congestion / À vérifier capacité / Non évalué), transmis
     * à findSitesPaginated() et exposé au template via statusOptions.
     */
    #[Route('/superuser/sites', name: 'superuser_dashboard_sites')]
    public function superuserSites(Request $request, ProcessedSiteRepository $processedSiteRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $page = (int) $request->query->get('page', 1);
        $service = $request->query->get('service');
        $classification = $request->query->get('classification');
        $search = $request->query->get('search');
        $statusFilter = $request->query->get('status');

        $pagination = $processedSiteRepository->findSitesPaginated(
            $service,
            $classification,
            $search,
            $page,
            50,
            $statusFilter
        );

        $classificationStats = $processedSiteRepository->getClassificationStats($service);
        $classifications = array_keys($classificationStats);
        $serviceDistribution = $processedSiteRepository->getServiceDistribution();
        $services = array_keys($serviceDistribution);
        return $this->render('dashboard/superuser/sites.html.twig', [
            'sites' => $pagination['items'],
            'pagination' => $pagination,
            'currentService' => $service,
            'currentClassification' => $classification,
            'currentSearch' => $search,
            'currentStatus' => $statusFilter,
            'classifications' => $classifications,
            'services' => $services,
            'statusOptions' => ProcessedSiteRepository::getStatusFilterOptions(),
            'totalSites' => $pagination['total'],
            'pageTitle' => 'Sites - Vue globale',
        ]);
    }

    #[Route('/superuser/import', name: 'superuser_dashboard_import', methods: ['GET', 'POST'])]
    public function import(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        return $this->render('dashboard/superuser/import.html.twig');
    }

    #[Route('/superuser/export', name: 'superuser_dashboard_export', methods: ['GET'])]
public function exportForm(ProcessedSiteRepository $repo): Response
{
    $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

    // Récupération des périodes d'import disponibles (depuis trafic_historique)
    $periods = $repo->getAvailableImportWeeks(); // à implémenter (voir plus bas)

    // Définition des colonnes disponibles
    $allColumns = $this->getAvailableColumns();
    $defaultColumns = ['site', 'classification', 'typeTrans', 'maxTrafic', 'maxTraficTdd', 'maxTraficFdd']; // colonnes pré-cochées

    return $this->render('dashboard/superuser/export.html.twig', [
        'services' => array_keys($repo->getServiceDistribution()),
        'siteNames' => $repo->findDistinctSiteNames(),
        'periods' => $periods,
        'allColumns' => $allColumns,
        'defaultColumns' => $defaultColumns,
    ]);
}

    #[Route('/superuser/kpis', name: 'superuser_dashboard_kpis')]
    public function kpis(ProcessedSiteRepository $processedSiteRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $user = $this->getUser();
        return $this->render('dashboard/kpis/common.html.twig', [
            'sites' => $processedSiteRepository->findAllSitePairs(),
            'kpiDataRoute' => 'superuser_kpis_data',
            'kpiTitle' => 'KPIs',
            'userIdentifier' => $user->getUserIdentifier(),
        ]);
    }

    #[Route('/superuser/kpis/data', name: 'superuser_kpis_data', methods: ['GET'])]
    public function kpisData(
        Request $request,
        ProcessedSiteRepository $repo
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $prefix = $request->query->get('site');
        $start = $request->query->get('start');
        $end = $request->query->get('end');
        $days = (int) $request->query->get('days', 30);

        if (!$prefix) {
            return $this->json(['error' => 'Site parameter required'], 400);
        }

        try {
            if ($start && $end) {
                $startDate = new \DateTime($start);
                $endDate = new \DateTime($end);
                $endDate->setTime(23, 59, 59);
                $data = $repo->getKpiCurvesDataForPrefix($prefix, $startDate, $endDate);
            } else {
                $data = $repo->getKpiCurvesDataForPrefix($prefix, null, null, $days);
            }
            return $this->json($data);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Erreur interne : ' . $e->getMessage()], 500);
        }
    }

    

#[Route('/superuser/alerts', name: 'superuser_dashboard_alerts')]
public function alerts(
    ProcessedSiteRepository $processedSiteRepository,
    NotificationRepository $notificationRepository,
    SiteAlertRepository $siteAlertRepository
): Response {
    $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

    // Récupération des alertes réseau des 7 derniers jours
    $siteAlerts = $siteAlertRepository->findRecentAlerts(7);

    // Pour chaque alerte, on récupère les notifications liées
    $alertNotifications = [];
    foreach ($siteAlerts as $alert) {
        $notifs = $notificationRepository->findBy(['alert' => $alert]);
        $alertNotifications[$alert->getId()] = $notifs;
    }

    // Compteurs par état
    $siteAlertCounts = $siteAlertRepository->countByEtat(7);
    // Assurer les clés par défaut
    $defaults = ['CONGESTION' => 0, 'BRIDAGE' => 0, 'RISQUE_DE_CONGESTION' => 0];
    $siteAlertCounts = array_merge($defaults, $siteAlertCounts);

    // Notifications de retard (workflow) existantes
    $notifications = $notificationRepository->createQueryBuilder('n')
        ->where('n.type IN (:types)')
        ->setParameter('types', ['deadline_reminder', 'ticket_overdue', 'deadline_overdue', 'deadline_yellow', 'deadline_red'])
        ->orderBy('n.createdAt', 'DESC')
        ->setMaxResults(100)
        ->getQuery()
        ->getResult();

    $nbDelayAlerts = $notificationRepository->createQueryBuilder('n')
        ->select('COUNT(DISTINCT n.ticket)')
        ->where('n.type IN (:types)')
        ->setParameter('types', ['deadline_reminder', 'ticket_overdue', 'deadline_overdue', 'deadline_yellow', 'deadline_red'])
        ->getQuery()
        ->getSingleScalarResult();

    return $this->render('dashboard/superuser/alerts.html.twig', [
        'siteAlerts' => $siteAlerts,
        'alertNotifications' => $alertNotifications,
        'siteAlertCounts' => $siteAlertCounts,
        'notifications' => $notifications,
        'nbDelayAlerts' => $nbDelayAlerts,
    ]);
}

    #[Route('/superuser/fh-workflows', name: 'superuser_fh_workflows')]
    public function fhWorkflows(TicketRepository $ticketRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $tickets = $ticketRepository->findBy(['workflowType' => 'FH']);
        return $this->render('dashboard/superuser/fh_workflows.html.twig', [
            'tickets' => $tickets,
        ]);
    }

    #[Route('/superuser/plan-data/export', name: 'superuser_export_plan_data')]
    public function exportPlanData(
        Request $request,
        ProcessedSiteRepository $repo
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $service = $request->query->get('service');
        $classification = $request->query->get('classification');
        $search = $request->query->get('search');

        $sites = $repo->findAllSitesOrderedByStatus($service, $classification, $search);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            'Site',
            'Service',
            'Classification',
            'Type Trans',
            'Max Trafic Total',
            'Max Trafic TDD',
            'Max Trafic FDD',
            'Capacite TDD',
            'Capacite FDD',
            'Capacite Totale',
            'Taux Util Global',
            'Taux Util TDD',
            'Taux Util FDD',
            'Statut',
            'Etat',
            'Latitude',
            'Longitude'
        ], ';');

        foreach ($sites as $site) {
            fputcsv($handle, [
                $site->getSiteName(),
                $site->getServiceName(),
                $site->getClassification(),
                $site->getTypeTrans(),
                $site->getMaxTrafic(),
                $site->getMaxTraficTdd(),
                $site->getMaxTraficFdd(),
                $site->getCapaciteTddMbps(),
                $site->getCapaciteFddMbps(),
                $site->getCapaciteMbps(),
                $site->getTauxUtilisation(),
                $site->getTauxUtilisationTdd(),
                $site->getTauxUtilisationFdd(),
                $site->getSiteStatus(),
                $site->getStatus(),
                $site->getLatitude(),
                $site->getLongitude()
            ], ';');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="plan_data_' . date('Ymd_His') . '.csv"',
        ]);
    }

    #[Route('/superuser/sites/export', name: 'superuser_dashboard_sites_export', methods: ['GET'])]
public function exportSitesCsv(
    Request $request,
    ProcessedSiteRepository $processedSiteRepository
): Response {
    $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

    // Récupération des filtres depuis la requête
    $service = $request->query->get('service');
    $classification = $request->query->get('classification');
    $statusFilter = $request->query->get('status');
    $search = $request->query->get('search');

    // Récupération des sites selon les filtres (on utilise la même méthode que l'admin)
    $sites = $processedSiteRepository->findSitesForExport(
        $service,
        $classification,
        $statusFilter,
        $search
    );

    // Création du CSV avec BOM UTF-8
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, "\xEF\xBB\xBF");

    // En-têtes
    fputcsv($handle, [
        'Site', 'Classification', 'Type Trans', 'Max TDD (Mbps)', 'Max FDD (Mbps)',
        'Trafic Max (Mbps)', 'Capacité TDD (Mbps)', 'Capacité FDD (Mbps)',
        'Taux Utilisation Global (%)', 'Taux Utilisation TDD (%)', 'Taux Utilisation FDD (%)',
        'Occurrences', 'Occurrence TDD', 'Occurrence FDD',
        'Statut (status)', 'État (siteStatus)', 'Service', 'Critique',
        'DropCong TDD', 'DropCong FDD', 'DropCong TF'
    ], ';');

    foreach ($sites as $site) {
        fputcsv($handle, [
            $site->getSiteName(),
            $site->getClassification() ?? '-',
            $site->getTypeTrans() ?? '-',
            $site->getMaxTraficTdd() ?? '-',
            $site->getMaxTraficFdd() ?? '-',
            $site->getMaxTrafic() ?? '-',
            $site->getCapaciteTddMbps() ?? '-',
            $site->getCapaciteFddMbps() ?? '-',
            $site->getTauxUtilisation() ?? '-',
            $site->getTauxUtilisationTdd() ?? '-',
            $site->getTauxUtilisationFdd() ?? '-',
            $site->getNombreOccurrences() ?? '-',
            $site->getNombreOccurrencesTdd() ?? '-',
            $site->getNombreOccurrencesFdd() ?? '-',
            $site->getStatus() ?? '-',
            $site->getSiteStatus() ?? '-',
            $site->getServiceName() ?? '-',
            $site->isCritical() ? 'Oui' : 'Non',
            $site->getDropCongTdd() ?? '-',
            $site->getDropCongFdd() ?? '-',
            $site->getDropCongTf() ?? '-',
        ], ';');
    }

    rewind($handle);
    $csvContent = stream_get_contents($handle);
    fclose($handle);

    $filename = 'sites_export_' . date('Y-m-d_His') . '.csv';

    return new Response($csvContent, 200, [
        'Content-Type' => 'text/csv; charset=utf-8',
        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
    ]);
}


#[Route('/superuser/export/generate', name: 'superuser_export_generate', methods: ['POST'])]
public function exportSites(
    Request $request,
    ProcessedSiteRepository $repo
): Response {
    $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

    // Récupération des filtres
    $service = $request->request->get('service_filter');
    $classification = $request->request->get('classification_filter');
    $search = $request->request->get('site_search');
    $periodStart = $request->request->get('period_start');
    $periodEnd = $request->request->get('period_end');
    $selectedColumns = $request->request->all('columns', []);

    // Récupération des sites selon les filtres
    $sites = $repo->findForAdvancedExport(
        $service && $service !== 'all' ? $service : null,
        'all',
        [],
        $search ?: '',
        $periodStart ?: null,
        $periodEnd ?: null
    );

    // Filtre supplémentaire par classification si précisé
    if ($classification && $classification !== 'all') {
        $sites = array_filter($sites, function ($site) use ($classification) {
            return strtoupper((string) $site->getClassification()) === strtoupper($classification);
        });
    }

    // Construction du CSV avec les colonnes sélectionnées
    return $this->buildCsvResponse($sites, $selectedColumns);
}

/**
 * Retourne la liste des colonnes disponibles pour l'export.
 */
private function getAvailableColumns(): array
{
    return [
        'site' => ['label' => 'Site', 'getter' => 'getSiteName'],
        'classification' => ['label' => 'Classification', 'getter' => 'getClassification'],
        'typeTrans' => ['label' => 'Type Trans', 'getter' => 'getTypeTrans'],
        'maxTraficTdd' => ['label' => 'Max TDD (Mbps)', 'getter' => 'getMaxTraficTdd'],
        'maxTraficFdd' => ['label' => 'Max FDD (Mbps)', 'getter' => 'getMaxTraficFdd'],
        'maxTrafic' => ['label' => 'Trafic Max (Mbps)', 'getter' => 'getMaxTrafic'],
        'capaciteTdd' => ['label' => 'Capacité TDD (Mbps)', 'getter' => 'getCapaciteTddMbps'],
        'capaciteFdd' => ['label' => 'Capacité FDD (Mbps)', 'getter' => 'getCapaciteFddMbps'],
        'tauxUtilisation' => ['label' => 'Taux Utilisation Global (%)', 'getter' => 'getTauxUtilisation'],
        'tauxUtilisationTdd' => ['label' => 'Taux Utilisation TDD (%)', 'getter' => 'getTauxUtilisationTdd'],
        'tauxUtilisationFdd' => ['label' => 'Taux Utilisation FDD (%)', 'getter' => 'getTauxUtilisationFdd'],
        'nombreOccurrences' => ['label' => 'Occurrences', 'getter' => 'getNombreOccurrences'],
        'nombreOccurrencesTdd' => ['label' => 'Occurrence TDD', 'getter' => 'getNombreOccurrencesTdd'],
        'nombreOccurrencesFdd' => ['label' => 'Occurrence FDD', 'getter' => 'getNombreOccurrencesFdd'],
        'status' => ['label' => 'Statut (status)', 'getter' => 'getStatus'],
        'siteStatus' => ['label' => 'État (siteStatus)', 'getter' => 'getSiteStatus'],
        'service' => ['label' => 'Service', 'getter' => 'getServiceName'],
        'isCritical' => ['label' => 'Critique', 'getter' => 'isCritical'],
        'dropCongTdd' => ['label' => 'DropCong TDD', 'getter' => 'getDropCongTdd'],
        'dropCongFdd' => ['label' => 'DropCong FDD', 'getter' => 'getDropCongFdd'],
        'dropCongTf' => ['label' => 'DropCong TF', 'getter' => 'getDropCongTf'],
    ];
}

/**
 * Génère un CSV avec les colonnes sélectionnées.
 */
private function buildCsvResponse(array $sites, array $selectedColumns = []): Response
{
    $availableColumns = $this->getAvailableColumns();

    // Si aucune colonne sélectionnée, on prend toutes par défaut
    if (empty($selectedColumns)) {
        $selectedColumns = array_keys($availableColumns);
    }

    // Filtrer les colonnes valides
    $selectedColumns = array_intersect($selectedColumns, array_keys($availableColumns));

    $handle = fopen('php://temp', 'r+');
    fwrite($handle, "\xEF\xBB\xBF"); // BOM pour Excel

    // En-têtes
    $headers = [];
    foreach ($selectedColumns as $key) {
        $headers[] = $availableColumns[$key]['label'];
    }
    fputcsv($handle, $headers, ';');

    // Données
    foreach ($sites as $site) {
        $row = [];
        foreach ($selectedColumns as $key) {
            $getter = $availableColumns[$key]['getter'];
            $value = $site->$getter();

            // Traitement spécial pour la colonne Critique
            if ($key === 'isCritical') {
                $value = $value ? 'Oui' : 'Non';
            }

            // Si null, on affiche '-'
            if ($value === null || $value === '') {
                $value = '-';
            }

            // Formatage des nombres (sauf booléens)
            if (is_numeric($value) && !is_bool($value) && $key !== 'isCritical') {
                $value = number_format((float) $value, 2, '.', '');
            }

            $row[] = $value;
        }
        fputcsv($handle, $row, ';');
    }

    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    $filename = 'sites_export_' . date('Y-m-d_His') . '.csv';

    return new Response($csv, 200, [
        'Content-Type' => 'text/csv; charset=UTF-8',
        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
    ]);
}

}