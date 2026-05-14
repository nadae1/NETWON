<?php

namespace App\Controller;

use App\Repository\ProcessedSiteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class UserDashboardController extends AbstractController
{
    #[Route('/user/dashboard', name: 'user_dashboard_home')]
    public function home(ProcessedSiteRepository $processedSiteRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $userService = $user->getService();

        // Get sites filtered by user's service
        $sites = $processedSiteRepository->findLatestSites($userService, 100);
        
        $totalSites = $processedSiteRepository->countAllSites($userService);
        $criticalSites = $processedSiteRepository->countCriticalSites($userService);
        
        // Calculate critical percentage
        $criticalPercentage = $totalSites > 0 ? round(($criticalSites / $totalSites) * 100, 1) : 0;
        
        // Get classification stats for charts
        $classificationStats = $processedSiteRepository->getClassificationStats($userService);
        
        // Get average traffic
        $avgTraffic = $processedSiteRepository->getAverageTraffic($userService);

        return $this->render('dashboard/user/home.html.twig', [
            'sites' => $sites,
            'totalSites' => $totalSites,
            'criticalSites' => $criticalSites,
            'criticalPercentage' => $criticalPercentage,
            'serviceName' => $userService ?: 'SHARED',
            'classificationStats' => $classificationStats,
            'avgTraffic' => round($avgTraffic, 1),
        ]);
    }

    #[Route('/user/sites', name: 'user_dashboard_sites')]
    public function sites(
        Request $request,
        ProcessedSiteRepository $processedSiteRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $userService = $user->getService();

        $page = (int) $request->query->get('page', 1);
        $classification = $request->query->get('classification');
        $search = $request->query->get('search');

        $pagination = $processedSiteRepository->findSitesPaginated(
            $userService,
            $classification,
            $search,
            $page,
            50
        );

        // Get classification options for filter
        $classificationStats = $processedSiteRepository->getClassificationStats($userService);
        $classifications = array_keys($classificationStats);

        return $this->render('dashboard/user/sites.html.twig', [
            'sites' => $pagination['items'],
            'pagination' => $pagination,
            'currentClassification' => $classification,
            'currentSearch' => $search,
            'classifications' => $classifications,
            'totalSites' => $pagination['total'],
            'pageTitle' => 'Sites - ' . ($userService ?: 'SHARED'),
            'serviceName' => $userService ?: 'SHARED',
        ]);
    }

    #[Route('/user/import', name: 'dashboard_import')]
    public function importForm(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        
        return $this->render('dashboard/user/import.html.twig');
    }

    #[Route('/user/export', name: 'dashboard_export')]
    public function exportForm(
        ProcessedSiteRepository $processedSiteRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $userService = $user->getService();
        
        return $this->render('dashboard/user/export.html.twig', [
            'serviceName' => $userService ?: 'SHARED',
            'siteNames' => $processedSiteRepository->findDistinctSiteNames($userService),
        ]);
    }

    #[Route('/user/kpis', name: 'dashboard_kpis')]
    public function kpis(
        ProcessedSiteRepository $processedSiteRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $userService = $user->getService();
        
        $totalSites = $processedSiteRepository->countAllSites($userService);
        $criticalSites = $processedSiteRepository->countCriticalSites($userService);
        $classificationStats = $processedSiteRepository->getClassificationStats($userService);
        $avgTraffic = $processedSiteRepository->getAverageTraffic($userService);
        
        return $this->render('dashboard/user/kpis.html.twig', [
            'totalSites' => $totalSites,
            'criticalSites' => $criticalSites,
            'criticalPercentage' => $totalSites > 0 ? round(($criticalSites / $totalSites) * 100, 1) : 0,
            'classificationStats' => $classificationStats,
            'avgTraffic' => round($avgTraffic, 1),
            'serviceName' => $userService ?: 'SHARED',
        ]);
    }

    #[Route('/user/alerts', name: 'user_dashboard_alerts')]
    public function alerts(
        ProcessedSiteRepository $processedSiteRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $userService = $user->getService();
        
        $criticalSites = $processedSiteRepository->findCriticalSites($userService, 50);
        
        return $this->render('dashboard/user/alerts.html.twig', [
            'criticalSites' => $criticalSites,
            'serviceName' => $userService ?: 'SHARED',
        ]);
    }
}