<?php

namespace App\Controller;

use App\Entity\ProcessedSite;
use App\Repository\ProcessedSiteRepository;
use App\Repository\TicketRepository;
use App\Repository\NotificationRepository;
use App\Service\IaRecommendationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SuperuserDashboardController extends AbstractController
{
    #[Route('/superuser/dashboard', name: 'superuser_dashboard_home')]
    public function superuserDashboard(ProcessedSiteRepository $processedSiteRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $sites = $processedSiteRepository->findLatestSites(null, 100);
        $totalSites = $processedSiteRepository->countAllSites(null);
        $criticalSites = $processedSiteRepository->countCriticalSites(null);
        $criticalPercentage = $totalSites > 0 ? round(($criticalSites / $totalSites) * 100, 1) : 0;
        $classificationStats = $processedSiteRepository->getClassificationStats(null);
        $serviceDistribution = $processedSiteRepository->getServiceDistribution();
        $avgTraffic = $processedSiteRepository->getAverageTraffic(null);
        return $this->render('dashboard/superuser/home.html.twig', [
            'sites' => $sites,
            'totalSites' => $totalSites,
            'criticalSites' => $criticalSites,
            'criticalPercentage' => $criticalPercentage,
            'classificationStats' => $classificationStats,
            'serviceDistribution' => $serviceDistribution,
            'avgTraffic' => round($avgTraffic, 1),
            'activeWorkflows' => 3,
            'blockedWorkflows' => 1,
        ]);
    }

    #[Route('/superuser/plan-data', name: 'superuser_plan_data')]
    public function planData(
        Request $request,
        ProcessedSiteRepository $processedSiteRepository,
        IaRecommendationService $iaService
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $service = $request->query->get('service');
        $search = trim((string) $request->query->get('search', ''));
        $page = max(1, (int) $request->query->get('page', 1));

        $criticalSites = $processedSiteRepository->findCriticalSites($service ?: null, 200);
        $allSites = $processedSiteRepository->findLatestSites($service ?: null, 500);

        if ($search !== '') {
            $allSites = array_values(array_filter($allSites, function ($site) use ($search) {
                return stripos((string) $site->getSiteName(), $search) !== false
                    || stripos((string) $site->getService(), $search) !== false;
            }));
        }

        $recommendations = $iaService->analyzeSites($criticalSites);
        $globalStats = $iaService->generateGlobalActionPlan($recommendations);

        $sitesPerPage = 20;
        $totalSites = count($allSites);
        $totalPages = $totalSites > 0 ? (int) ceil($totalSites / $sitesPerPage) : 1;
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $sitesPerPage;
        $sites = array_slice($allSites, $offset, $sitesPerPage);

        $warningSites = count(array_filter($allSites, function ($site) {
            if ($site->isCritical()) {
                return false;
            }

            $capacity = (float) $site->getCapaciteMbps();
            $traffic = (float) $site->getMaxTrafic();

            return $capacity > 0 && ($traffic / $capacity) * 100 > 80;
        }));

        $secureSites = max(0, $totalSites - count($criticalSites) - $warningSites);

        return $this->render('dashboard/superuser/plan_data.html.twig', [
            'sites' => $sites,
            'allSites' => $allSites,
            'criticalSites' => count($criticalSites),
            'totalSites' => $totalSites,
            'secureSites' => $secureSites,
            'warningSites' => $warningSites,
            'recommendations' => $recommendations,
            'globalStats' => $globalStats,
            'services' => array_keys($processedSiteRepository->getServiceDistribution()),
            'currentService' => $service,
            'currentSearch' => $search,
            'pagination' => [
                'page' => $page,
                'totalPages' => $totalPages,
                'total' => $totalSites,
            ],
            'importNeeded' => $totalSites === 0,
        ]);
    }

    #[Route('/superuser/ia-recommendations', name: 'superuser_ia_recommendations', methods: ['GET', 'POST'])]
    public function iaRecommendations(
        Request $request,
        ProcessedSiteRepository $processedSiteRepository,
        IaRecommendationService $iaService,
        EntityManagerInterface $em
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $sites = $processedSiteRepository->findCriticalSites(null, 200);
        $recommendations = $iaService->analyzeSites($sites);
        $globalStats = $iaService->generateGlobalActionPlan($recommendations);

        foreach ($recommendations as &$rec) {
            $site = $processedSiteRepository->find($rec['siteId']);
            if ($site) {
                $trafficData = $this->generateSiteSpecificTrafficData($site);
                $rec['currentTrafficData'] = $trafficData['current'];
                $rec['afterActionData'] = $trafficData['after'];
            } else {
                $rec['currentTrafficData'] = ['labels' => range(0, 23), 'values' => array_fill(0, 24, 0)];
                $rec['afterActionData'] = ['labels' => range(0, 23), 'values' => array_fill(0, 24, 0)];
            }
        }

        if ($request->isMethod('POST')) {
            $selectionMode = $request->request->get('selection_mode');
            $selectedSiteIds = [];

            if ($selectionMode === 'top10') {
                $top10 = array_slice($recommendations, 0, 10);
                foreach ($top10 as $rec) {
                    $selectedSiteIds[] = $rec['siteId'];
                }
            } elseif ($selectionMode === 'manual') {
                $selectedSiteIds = $request->request->all('selected_sites');
            }

            if (empty($selectedSiteIds)) {
                $this->addFlash('warning', 'Aucun site sélectionné.');
                return $this->redirectToRoute('superuser_ia_recommendations');
            }

            $validatedActions = [];
            foreach ($selectedSiteIds as $siteId) {
                $actionData = $request->request->all('actions')[$siteId] ?? [];
                $validatedActions[] = [
                    'siteId' => $siteId,
                    'actionType' => $actionData['action_type'] ?? 'MONITORING',
                    'priority' => $actionData['priority'] ?? 'medium'
                ];
            }

            $deadline = $request->request->get('deadline');
            $deadlineDate = $deadline ? new \DateTime($deadline) : null;

            $workflow = $iaService->createWorkflowFromRecommendations($validatedActions, $this->getUser(), $deadlineDate);
            $this->addFlash('success', sprintf('Workflow #%d créé pour %d sites', $workflow->getId(), count($validatedActions)));
            return $this->redirectToRoute('superuser_workflow_show', ['id' => $workflow->getId()]);
        }

        return $this->render('dashboard/superuser/ia_recommendations.html.twig', [
            'recommendations' => $recommendations,
            'globalStats' => $globalStats,
        ]);
    }

    private function generateSiteSpecificTrafficData(ProcessedSite $site): array
    {
        $maxTrafic = $site->getMaxTrafic();
        if ($maxTrafic <= 0) {
            $maxTrafic = 500;
        }
        $hours = range(0, 23);
        $currentValues = [];
        $afterValues = [];

        for ($h = 0; $h < 24; $h++) {
            $multiplier = 0.3 + 0.7 * (1 - abs($h - 20) / 20);
            $current = $maxTrafic * $multiplier * (0.8 + rand(0, 40) / 100);
            $after = $current * (0.3 + rand(30, 70) / 100);
            $currentValues[] = round($current, 2);
            $afterValues[] = round($after, 2);
        }

        return [
            'current' => ['labels' => $hours, 'values' => $currentValues],
            'after' => ['labels' => $hours, 'values' => $afterValues],
        ];
    }

    #[Route('/superuser/sites', name: 'superuser_dashboard_sites')]
    public function superuserSites(Request $request, ProcessedSiteRepository $processedSiteRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $page = (int) $request->query->get('page', 1);
        $service = $request->query->get('service');
        $classification = $request->query->get('classification');
        $search = $request->query->get('search');
        $pagination = $processedSiteRepository->findSitesPaginated($service, $classification, $search, $page, 50);
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
            'classifications' => $classifications,
            'services' => $services,
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

    #[Route('/superuser/export', name: 'superuser_dashboard_export')]
    public function exportForm(ProcessedSiteRepository $processedSiteRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $serviceDistribution = $processedSiteRepository->getServiceDistribution();
        $services = array_keys($serviceDistribution);
        return $this->render('dashboard/superuser/export.html.twig', [
            'services' => $services,
            'siteNames' => $processedSiteRepository->findDistinctSiteNames(),
        ]);
    }

    #[Route('/superuser/kpis', name: 'superuser_dashboard_kpis')]
    public function kpis(ProcessedSiteRepository $processedSiteRepository, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $serviceFilter = $request->query->get('service', 'all');
        $totalSites = $processedSiteRepository->countAllSites($serviceFilter === 'all' ? null : $serviceFilter);
        $criticalSites = $processedSiteRepository->countCriticalSites($serviceFilter === 'all' ? null : $serviceFilter);
        $classificationStats = $processedSiteRepository->getClassificationStats($serviceFilter === 'all' ? null : $serviceFilter);
        $serviceDistribution = $processedSiteRepository->getServiceDistribution();
        $avgTraffic = $processedSiteRepository->getAverageTraffic($serviceFilter === 'all' ? null : $serviceFilter);
        $sitesForGraph = $processedSiteRepository->findLatestSites($serviceFilter === 'all' ? null : $serviceFilter, 20);
        $siteNames = [];
        $trafficValues = [];
        foreach ($sitesForGraph as $site) {
            $siteNames[] = $site->getSiteName();
            $trafficValues[] = round($site->getMaxTrafic(), 2);
        }
        return $this->render('dashboard/superuser/kpis.html.twig', [
            'totalSites' => $totalSites,
            'criticalSites' => $criticalSites,
            'criticalPercentage' => $totalSites > 0 ? round(($criticalSites / $totalSites) * 100, 1) : 0,
            'serviceDistribution' => $serviceDistribution,
            'classificationStats' => $classificationStats,
            'avgTraffic' => round($avgTraffic, 1),
            'siteNames' => $siteNames,
            'trafficValues' => $trafficValues,
            'currentService' => $serviceFilter,
        ]);
    }





    // Dans src/Controller/SuperuserDashboardController.php

#[Route('/superuser/alerts', name: 'superuser_dashboard_alerts')]
public function alerts(
    ProcessedSiteRepository $processedSiteRepository,
    NotificationRepository $notificationRepository
): Response {
    $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
    $criticalSites = $processedSiteRepository->findCriticalSites(null, 50);
    
    $notifications = $notificationRepository->createQueryBuilder('n')
        ->where('n.type LIKE :type')
        ->setParameter('type', 'deadline_%')
        ->orderBy('n.createdAt', 'DESC')
        ->setMaxResults(100)
        ->getQuery()
        ->getResult();

    // Nombre de tickets en retard (distincts)
    $nbDelayAlerts = $notificationRepository->createQueryBuilder('n')
        ->select('COUNT(DISTINCT n.ticket)')
        ->where('n.type LIKE :type')
        ->setParameter('type', 'deadline_%')
        ->getQuery()
        ->getSingleScalarResult();

    $nbCriticalSites = count($criticalSites);

    return $this->render('dashboard/superuser/alerts.html.twig', [
        'criticalSites' => $criticalSites,
        'notifications' => $notifications,
        'nbDelayAlerts' => $nbDelayAlerts,
        'nbCriticalSites' => $nbCriticalSites,
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
}