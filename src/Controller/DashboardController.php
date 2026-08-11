<?php

namespace App\Controller;

use App\Repository\ProcessedSiteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'legacy_user_dashboard')]
    public function userDashboard(
        ProcessedSiteRepository $processedSiteRepository
    ): Response {
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

#[Route('/dashboard/sites', name: 'legacy_user_dashboard_sites')]
public function userSites(Request $request, ProcessedSiteRepository $processedSiteRepository): Response
{
    $this->denyAccessUnlessGranted('ROLE_USER');

    /** @var \App\Entity\User $user */
    $user = $this->getUser();
    $userService = $user->getService();

    $page = (int) $request->query->get('page', 1);
    $classification = $request->query->get('classification');
    $search = $request->query->get('search');
    $statusFilter = $request->query->get('status'); // ✅ récupération du filtre

    $pagination = $processedSiteRepository->findSitesPaginated(
        $userService,
        $classification,
        $search,
        $page,
        50,
        $statusFilter // ✅ transmission du filtre
    );

    $classificationStats = $processedSiteRepository->getClassificationStats($userService);
    $classifications = array_keys($classificationStats);

    return $this->render('dashboard/user/sites.html.twig', [
        'sites' => $pagination['items'],
        'pagination' => $pagination,
        'currentClassification' => $classification,
        'currentSearch' => $search,
        'currentStatus' => $statusFilter, // ✅ ajout de la variable
        'classifications' => $classifications,
        'statusOptions' => ProcessedSiteRepository::getStatusFilterOptions(),
        'totalSites' => $pagination['total'],
        'pageTitle' => 'Sites - ' . ($userService ?: 'SHARED'),
        'serviceName' => $userService ?: 'SHARED',
    ]);
}

    #[Route('/dashboard/import', name: 'legacy_dashboard_import')]
    public function importForm(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        
        return $this->render('dashboard/user/import.html.twig');
    }
}