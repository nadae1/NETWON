<?php

namespace App\Controller;

use App\Repository\ProcessedSiteRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class AdminDashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'admin_dashboard_home')]
    public function adminDashboard(ProcessedSiteRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $totalSites = $repo->countAllSites(null);
        $criticalSites = $repo->countCriticalSites(null);

        return $this->render('dashboard/admin/home.html.twig', [
            'sites' => $repo->findLatestSites(null, 100),
            'totalSites' => $totalSites,
            'criticalSites' => $criticalSites,
            'classificationStats' => $repo->getClassificationStats(null),
            'serviceDistribution' => $repo->getServiceDistribution(),
            'avgTraffic' => round($repo->getAverageTraffic(null), 1),
            'criticalPercentage' => $totalSites > 0 ? round(($criticalSites / $totalSites) * 100, 1) : 0,
        ]);
    }

    #[Route('/sites', name: 'admin_dashboard_sites')]
    public function adminSites(Request $request, ProcessedSiteRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $page = (int) $request->query->get('page', 1);
        $service = $request->query->get('service');
        $classification = $request->query->get('classification');
        $search = $request->query->get('search');

        $pagination = $repo->findSitesPaginated($service, $classification, $search, $page, 50);

        return $this->render('dashboard/admin/sites.html.twig', [
            'sites' => $pagination['items'],
            'pagination' => $pagination,
            'currentService' => $service,
            'currentClassification' => $classification,
            'currentSearch' => $search,
            'classifications' => array_keys($repo->getClassificationStats($service)),
            'services' => array_keys($repo->getServiceDistribution()),
            'totalSites' => $pagination['total'],
            'pageTitle' => 'Sites - Vue Administrateur',
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

    #[Route('/kpis', name: 'admin_dashboard_kpis')]
    public function kpis(ProcessedSiteRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $totalSites = $repo->countAllSites(null);
        $criticalSites = $repo->countCriticalSites(null);

        return $this->render('dashboard/admin/kpis.html.twig', [
            'totalSites' => $totalSites,
            'criticalSites' => $criticalSites,
            'criticalPercentage' => $totalSites > 0 ? round(($criticalSites / $totalSites) * 100, 1) : 0,
            'classificationStats' => $repo->getClassificationStats(null),
            'serviceDistribution' => $repo->getServiceDistribution(),
            'avgTraffic' => round($repo->getAverageTraffic(null), 1),
        ]);
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
            'Max_Trafic',
            'Date_Max',
            'Seuil_Critique',
            'Nombre_Occurrences',
            'Total_Measures',
            'Service',
            'Critique'
        ], ';');

        foreach ($sites as $site) {
            fputcsv($handle, [
                $site->getSiteName(),
                $site->getPairedSiteName(),
                $site->getClassification(),
                $site->getTypeTrans(),
                $site->getMaxTrafic(),
                $site->getDateMax()?->format('Y-m-d H:i:s'),
                $site->getSeuilCritique(),
                $site->getNombreOccurrences(),
                $site->getTotalMeasures(),
                $site->getServiceName(),
                $site->isCritical() ? 'Oui' : 'Non',
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
}