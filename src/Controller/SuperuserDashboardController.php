<?php

namespace App\Controller;

use App\Entity\Ticket;
use App\Entity\TicketSite;
use App\Repository\ProcessedSiteRepository;
use App\Service\IaRecommendationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SuperuserDashboardController extends AbstractController
{
    #[Route('/superuser/dashboard', name: 'superuser_dashboard_home')]
    public function superuserDashboard(
        ProcessedSiteRepository $processedSiteRepository
    ): Response {
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
        ProcessedSiteRepository $processedSiteRepository,
        IaRecommendationService $iaService
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        
        $criticalSites = $processedSiteRepository->findCriticalSites(null, 200);
        $allSites = $processedSiteRepository->findLatestSites(null, 500);
        
        // Analyse IA des sites critiques
        $recommendations = $iaService->analyzeSites($criticalSites);
        $globalStats = $iaService->generateGlobalActionPlan($recommendations);
        
        return $this->render('dashboard/superuser/plan_data.html.twig', [
            'criticalSites' => $criticalSites,
            'allSites' => $allSites,
            'recommendations' => $recommendations,
            'globalStats' => $globalStats,
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
        
        // Appel au moteur IA
        $recommendations = $iaService->analyzeSites($sites);
        $globalStats = $iaService->generateGlobalActionPlan($recommendations);
        
        if ($request->isMethod('POST')) {
            $validatedActions = $request->request->all('actions');
            $selectedActions = [];
            
            foreach ($validatedActions as $siteId => $actionData) {
                if (isset($actionData['selected']) && $actionData['selected'] === 'on') {
                    $selectedActions[] = [
                        'siteId' => $siteId,
                        'actionType' => $actionData['action_type'] ?? 'MONITORING',
                        'priority' => $actionData['priority'] ?? 'medium'
                    ];
                }
            }
            
            if (!empty($selectedActions)) {
                $workflow = $iaService->createWorkflowFromRecommendations($selectedActions, $this->getUser());
                
                $this->addFlash('success', sprintf(
                    'Workflow #%d créé avec succès pour %d sites',
                    $workflow->getId(),
                    count($selectedActions)
                ));
                
                return $this->redirectToRoute('superuser_workflow_show', ['id' => $workflow->getId()]);
            } else {
                $this->addFlash('warning', 'Aucune action sélectionnée');
            }
        }
        
        return $this->render('dashboard/superuser/ia_recommendations.html.twig', [
            'recommendations' => $recommendations,
            'sites' => $sites,
            'globalStats' => $globalStats,
        ]);
    }

    #[Route('/superuser/sites', name: 'superuser_dashboard_sites')]
    public function superuserSites(
        Request $request,
        ProcessedSiteRepository $processedSiteRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $page = (int) $request->query->get('page', 1);
        $service = $request->query->get('service');
        $classification = $request->query->get('classification');
        $search = $request->query->get('search');

        $pagination = $processedSiteRepository->findSitesPaginated(
            $service,
            $classification,
            $search,
            $page,
            50
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
            'classifications' => $classifications,
            'services' => $services,
            'totalSites' => $pagination['total'],
            'pageTitle' => 'Sites - Vue globale',
        ]);
    }

    #[Route('/superuser/import', name: 'superuser_dashboard_import')]
    public function importForm(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        
        return $this->render('dashboard/superuser/import.html.twig', [
            'services' => ['FO', 'FH', 'SHARED', 'BACKBONE'],
        ]);
    }

    #[Route('/superuser/export', name: 'superuser_dashboard_export')]
    public function exportForm(
        ProcessedSiteRepository $processedSiteRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        
        $serviceDistribution = $processedSiteRepository->getServiceDistribution();
        $services = array_keys($serviceDistribution);
        
        return $this->render('dashboard/superuser/export.html.twig', [
            'services' => $services,
            'siteNames' => $processedSiteRepository->findDistinctSiteNames(),
        ]);
    }

    #[Route('/superuser/kpis', name: 'superuser_dashboard_kpis')]
    public function kpis(
        ProcessedSiteRepository $processedSiteRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        
        $totalSites = $processedSiteRepository->countAllSites(null);
        $criticalSites = $processedSiteRepository->countCriticalSites(null);
        $serviceDistribution = $processedSiteRepository->getServiceDistribution();
        $classificationStats = $processedSiteRepository->getClassificationStats(null);
        
        return $this->render('dashboard/superuser/kpis.html.twig', [
            'totalSites' => $totalSites,
            'criticalSites' => $criticalSites,
            'criticalPercentage' => $totalSites > 0 ? round(($criticalSites / $totalSites) * 100, 1) : 0,
            'serviceDistribution' => $serviceDistribution,
            'classificationStats' => $classificationStats,
        ]);
    }

    #[Route('/superuser/alerts', name: 'superuser_dashboard_alerts')]
    public function alerts(
        ProcessedSiteRepository $processedSiteRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        
        $criticalSites = $processedSiteRepository->findCriticalSites(null, 50);
        
        return $this->render('dashboard/superuser/alerts.html.twig', [
            'criticalSites' => $criticalSites,
        ]);
    }
}