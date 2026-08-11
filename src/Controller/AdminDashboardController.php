<?php

namespace App\Controller;

use App\Repository\ProcessedSiteRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\ProcessedSite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Repository\TicketRepository;


#[Route('/admin')]
class AdminDashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'admin_dashboard_home')]
public function adminDashboard(
    Request $request,
    ProcessedSiteRepository $repo,
    TicketRepository $ticketRepo
): Response {
    $this->denyAccessUnlessGranted('ROLE_ADMIN');

    $serviceFilter = $request->query->get('service');
    $classificationFilter = $request->query->get('classification');
    $criticalFilter = $request->query->get('critical');

    $totalSites = $repo->countAllSites($serviceFilter);
    $criticalSites = $repo->countCriticalSites($serviceFilter);
    $criticalPercentage = $totalSites > 0 ? round(($criticalSites / $totalSites) * 100, 1) : 0;

    $serviceDistribution = $repo->getServiceDistribution();
    $classificationStats = $repo->getClassificationStats($serviceFilter);

    $allServices = array_keys($serviceDistribution);
    $allClassifications = array_keys($repo->getClassificationStats(null));

    // Avancement réel des workflows, basé sur le comptage effectif des TicketSite (pas les compteurs stockés processedSites/totalSites)
    $workflowStats = $ticketRepo->getWorkflowSitesProgress();

    $activeWorkflows = (int) $ticketRepo->createQueryBuilder('t')
        ->select('COUNT(t.id)')
        ->where('t.status NOT IN (:closedStatuses)')
        ->setParameter('closedStatuses', ['completed', 'closed'])
        ->getQuery()
        ->getSingleScalarResult();

    $totalWorkflows = (int) $ticketRepo->createQueryBuilder('t')->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();

    // Sites réellement traités via un workflow (TicketSite au statut "completed"), pas les derniers imports bruts
    $recentProcessedSites = $ticketRepo->findRecentlyProcessedTicketSites(5);

    return $this->render('dashboard/admin/home.html.twig', [
        'totalSites' => $totalSites,
        'criticalSites' => $criticalSites,
        'criticalPercentage' => $criticalPercentage,
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

  #[Route('/admin/sites', name: 'admin_dashboard_sites')]
    public function adminSites(Request $request, ProcessedSiteRepository $processedSiteRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

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

        return $this->render('dashboard/admin/sites.html.twig', [
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
    
    #[Route('/export', name: 'admin_dashboard_export')]
    public function exportForm(ProcessedSiteRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        return $this->render('dashboard/admin/export.html.twig', [
            'services' => array_keys($repo->getServiceDistribution()),
            'siteNames' => $repo->findDistinctSiteNames(),
        ]);
    }

    #[Route('/export/generate', name: 'admin_export_generate', methods: ['POST'])]
    public function generateExport(Request $request, ProcessedSiteRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $service = $request->request->get('service_filter');
        $classification = $request->request->get('classification_filter');
        $dateFrom = $request->request->get('date_from');
        $dateTo = $request->request->get('date_to');

        $sites = $repo->findForAdvancedExport(
            $service && $service !== 'all' ? $service : null,
            'all',
            [],
            '',
            $dateFrom ?: null,
            $dateTo ?: null
        );

        if ($classification && $classification !== 'all') {
            $sites = array_filter($sites, fn ($site) =>
                strtoupper((string) $site->getClassification()) === strtoupper($classification)
            );
        }

        return $this->exportToCsv(array_values($sites));
    }

   


    #[Route('/export/capacities/generate', name: 'admin_export_capacities_generate', methods: ['POST'])]
public function exportCapacitiesGenerate(ProcessedSiteRepository $repo): Response
{
    $this->denyAccessUnlessGranted('ROLE_ADMIN');
    $data = $repo->getCapacitiesExportData();

    $handle = fopen('php://temp', 'r+');
    fwrite($handle, "\xEF\xBB\xBF");

    fputcsv($handle, [
        'Site',
        'Type_Trans',
        'Capacite_TDD_(Mbps)',
        'Capacite_FDD_(Mbps)',
        'Derniere_Mise_A_Jour',
        'Mis_A_Jour_Par'
    ], ';');

    foreach ($data as $row) {
        // Gérer la date
        $date = $row['derniere_mise_a_jour'];
        if ($date instanceof \DateTimeInterface) {
            $dateFormatted = $date->format('Y-m-d H:i:s');
        } elseif (is_string($date) && $date) {
            try {
                $dateFormatted = (new \DateTime($date))->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                $dateFormatted = '';
            }
        } else {
            $dateFormatted = '';
        }

        fputcsv($handle, [
            $row['site'],
            $row['type_trans'] ?? '',
            $row['capacite_tdd'] ?? '',
            $row['capacite_fdd'] ?? '',
            $dateFormatted,
            $row['mis_a_jour_par'] ?? 'Inconnu'
        ], ';');
    }

    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    return new Response($csv, 200, [
        'Content-Type' => 'text/csv; charset=UTF-8',
        'Content-Disposition' => 'attachment; filename="capacites_export_' . date('Ymd_His') . '.csv"',
    ]);
}
    #[Route('/export/statistics-pdf', name: 'admin_export_statistics_pdf', methods: ['GET'])]
    public function exportStatisticsPdf(ProcessedSiteRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $totalSites = $repo->countAllSites(null);
        $criticalSites = $repo->countCriticalSites(null);
        $avgTraffic = round($repo->getAverageTraffic(null), 1);
        $serviceDistribution = $repo->getServiceDistribution();
        $classificationStats = $repo->getClassificationStats(null);

        $criticalPercentage = $totalSites > 0 ? round(($criticalSites / $totalSites) * 100, 1) : 0;

        $html = $this->buildStatisticsPdfHtml(
            $totalSites,
            $criticalSites,
            $criticalPercentage,
            $avgTraffic,
            $serviceDistribution,
            $classificationStats
        );

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="statistiques_sites_' . date('Ymd_His') . '.pdf"',
        ]);
    }

    #[Route('/kpis/data', name: 'admin_kpis_data', methods: ['GET'])]
    public function kpisData(
        Request $request,
        ProcessedSiteRepository $repo
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

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

    #[Route('/kpis', name: 'admin_dashboard_kpis')]
    public function kpis(ProcessedSiteRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $user = $this->getUser();
        return $this->render('dashboard/kpis/common.html.twig', [
            'sites' => $repo->findAllSitePairs(),
            'kpiDataRoute' => 'admin_kpis_data',
            'kpiTitle' => 'KPIs',
            'userIdentifier' => $user->getUserIdentifier(),
        ]);
    }

    #[Route('/map/data', name: 'admin_map_data', methods: ['GET'])]
    public function mapData(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $filter = $request->query->get('filter', 'all');

        $qb = $em->createQueryBuilder()
            ->select('s.siteName', 's.latitude', 's.longitude', 's.isCritical', 's.maxTrafic', 's.service')
            ->from(ProcessedSite::class, 's')
            ->where('s.latitude IS NOT NULL')
            ->andWhere('s.longitude IS NOT NULL');

        if ($filter !== 'all') {
            $qb->andWhere('s.service = :service')
               ->setParameter('service', $filter);
        }

        $sites = $qb->getQuery()->getResult();
        $features = [];

        foreach ($sites as $site) {
            if ($site['isCritical']) {
                $color = 'red';
                $status = 'Critique';
            } elseif ($site['maxTrafic'] > 1000) {
                $color = 'yellow';
                $status = 'Sous observation';
            } else {
                $color = 'green';
                $status = 'Sécurisé';
            }

            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [(float)$site['longitude'], (float)$site['latitude']]
                ],
                'properties' => [
                    'name' => $site['siteName'],
                    'service' => $site['service'],
                    'status' => $status,
                    'color' => $color,
                    'traffic' => $site['maxTrafic']
                ]
            ];
        }

        return $this->json(['type' => 'FeatureCollection', 'features' => $features]);
    }

    private function buildStatisticsPdfHtml(
        int $totalSites,
        int $criticalSites,
        float $criticalPercentage,
        float $avgTraffic,
        array $serviceDistribution,
        array $classificationStats
    ): string {
        $fo = (int) ($serviceDistribution['FO'] ?? 0);
        $fh = (int) ($serviceDistribution['FH'] ?? 0);
        $shared = (int) ($serviceDistribution['SHARED'] ?? 0);

        $classificationRows = '';

        foreach ($classificationStats as $classification => $stats) {
            $classificationRows .= '
                <tr>
                    <td>' . htmlspecialchars((string) $classification) . '</td>
                    <td>' . (int) ($stats['total'] ?? 0) . '</td>
                    <td>' . (int) ($stats['critical'] ?? 0) . '</td>
                </tr>';
        }

        return '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 12px; }
.header { text-align: center; margin-bottom: 25px; }
.header h1 { color: #ff7900; margin-bottom: 5px; }
.date { color: #64748b; }
.cards { width: 100%; margin-bottom: 25px; }
.card { width: 24%; display: inline-block; vertical-align: top; padding: 14px; border-radius: 8px; margin-right: 1%; box-sizing: border-box; }
.blue { background: #eff6ff; border: 1px solid #bfdbfe; }
.red { background: #fff1f2; border: 1px solid #fecaca; }
.green { background: #ecfeff; border: 1px solid #99f6e4; }
.purple { background: #f5f3ff; border: 1px solid #ddd6fe; }
.label { font-size: 11px; color: #64748b; font-weight: bold; text-transform: uppercase; }
.value { font-size: 28px; font-weight: bold; margin-top: 6px; }
.small { color: #475569; margin-top: 5px; }
.section-title { font-size: 18px; margin-top: 25px; margin-bottom: 10px; color: #020617; }
table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
th { background: #f1f5f9; padding: 10px; text-align: left; border: 1px solid #cbd5e1; }
td { padding: 9px; border: 1px solid #e2e8f0; }
.footer { margin-top: 30px; text-align: center; color: #64748b; font-size: 10px; }
</style>
</head>
<body>
    <div class="header">
        <h1>Rapport Statistiques des Sites</h1>
        <div class="date">Généré le ' . date('d/m/Y H:i') . '</div>
    </div>

    <div class="cards">
        <div class="card blue">
            <div class="label">Total Sites</div>
            <div class="value">' . $totalSites . '</div>
            <div class="small">' . $fo . ' FO - ' . $fh . ' FH - ' . $shared . ' SHARED</div>
        </div>

        <div class="card red">
            <div class="label">Sites Critiques</div>
            <div class="value">' . $criticalSites . '</div>
            <div class="small">' . $criticalPercentage . '% du total</div>
        </div>

        <div class="card green">
            <div class="label">Trafic Moyen</div>
            <div class="value">' . $avgTraffic . '</div>
            <div class="small">Mbps</div>
        </div>

        <div class="card purple">
            <div class="label">Services Actifs</div>
            <div class="value">3</div>
            <div class="small">FO / FH / SHARED</div>
        </div>
    </div>

    <div class="section-title">Distribution par service</div>
    <table>
        <thead>
            <tr>
                <th>Service</th>
                <th>Nombre de sites</th>
                <th>Pourcentage</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>FO</td><td>' . $fo . '</td><td>' . ($totalSites > 0 ? round(($fo / $totalSites) * 100, 1) : 0) . '%</td></tr>
            <tr><td>FH</td><td>' . $fh . '</td><td>' . ($totalSites > 0 ? round(($fh / $totalSites) * 100, 1) : 0) . '%</td></tr>
            <tr><td>SHARED</td><td>' . $shared . '</td><td>' . ($totalSites > 0 ? round(($shared / $totalSites) * 100, 1) : 0) . '%</td></tr>
        </tbody>
    </table>

    <div class="section-title">Classification des sites</div>
    <table>
        <thead>
            <tr>
                <th>Classification</th>
                <th>Total</th>
                <th>Critiques</th>
            </tr>
        </thead>
        <tbody>
            ' . $classificationRows . '
        </tbody>
    </table>

    <div class="footer">
        Rapport généré automatiquement depuis le dashboard administrateur.
    </div>
</body>
</html>';
    }

    private function exportToCsv(array $sites): Response
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            'Site',
            'Site_Paire',
            'Classification',
            'Type_Trans',
            'Max_Trafic_Total',
            'Max_Trafic_TDD',
            'Max_Trafic_FDD',
            'Capacite_TDD',
            'Capacite_FDD',
            'Capacite_Totale',
            'Taux_Utilisation_Global',
            'Taux_Utilisation_TDD',
            'Taux_Utilisation_FDD',
            'Nombre_Occurrences',
            'Total_Measures',
            'Date_Max',
            'Service',
            'Critique',
            'Site_Status',
            'Latitude',
            'Longitude'
        ], ';');

        foreach ($sites as $site) {
            fputcsv($handle, [
                $site->getSiteName(),
                $site->getPairedSiteName(),
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
                $site->getNombreOccurrences(),
                $site->getTotalMeasures(),
                $site->getDateMax()?->format('Y-m-d H:i:s'),
                $site->getServiceName(),
                $site->isCritical() ? 'Oui' : 'Non',
                $site->getSiteStatus(),
                $site->getLatitude(),
                $site->getLongitude()
            ], ';');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="export_sites_' . date('Ymd_His') . '.csv"',
        ]);
    }


    #[Route('/admin/sites/export', name: 'admin_dashboard_sites_export', methods: ['GET'])]
public function exportSitesCsv(
    Request $request,
    ProcessedSiteRepository $processedSiteRepository
): Response {
    $this->denyAccessUnlessGranted('ROLE_ADMIN');

    // Récupération des filtres depuis la requête
    $service = $request->query->get('service');
    $classification = $request->query->get('classification');
    $statusFilter = $request->query->get('status');
    $search = $request->query->get('search');

    // Récupération des sites selon les filtres
    $sites = $processedSiteRepository->findSitesForExport(
        $service,
        $classification,
        $statusFilter,
        $search
    );

    // Création du CSV avec BOM UTF-8
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, "\xEF\xBB\xBF"); // BOM pour Excel

    // En-têtes
    fputcsv($handle, [
        'Site',
        'Classification',
        'Type Trans',
        'Max TDD (Mbps)',
        'Max FDD (Mbps)',
        'Trafic Max (Mbps)',
        'Capacité TDD (Mbps)',
        'Capacité FDD (Mbps)',
        'Taux Utilisation Global (%)',
        'Taux Utilisation TDD (%)',
        'Taux Utilisation FDD (%)',
        'Occurrences',
        'Occurrence TDD',
        'Occurrence FDD',
        'Statut (status)',
        'État (siteStatus)',
        'Service',
        'Critique',
        'DropCong TDD',
        'DropCong FDD',
        'DropCong TF'
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

}