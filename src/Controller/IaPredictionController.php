<?php
// src/Controller/IaPredictionController.php

namespace App\Controller;

use App\Repository\SitePredictionRepository;
use App\Repository\TraficHistoriqueRepository;
use App\Repository\ProcessedSiteRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/superuser/ia')]
class IaPredictionController extends AbstractController
{
    public function __construct(
        private SitePredictionRepository $predictionRepo,
        private TraficHistoriqueRepository $historiqueRepo,
        private ProcessedSiteRepository $processedSiteRepo,
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'PYTHON_API_BASE_URL')] private string $mlServiceUrl,
    ) {}

#[Route('', name: 'superuser_ia_dashboard', methods: ['GET'])]
public function dashboard(Request $request): Response
{
    $horizon = $request->query->get('horizon', 'd7');

    $predictions = $this->predictionRepo->findLatestByHorizon($horizon);
    $etatStats = $this->predictionRepo->countByEtatPredit($horizon);
    $actionStats = $this->predictionRepo->countByActionCode($horizon);
    $topCritiques = $this->predictionRepo->findTopCritiques($horizon, 10);
    $total = $this->predictionRepo->countTotal($horizon);

    $countByEtat = [];
    foreach ($etatStats as $row) {
        $countByEtat[$row['etat'] ?? 'INCONNU'] = (int) $row['total'];
    }

    $avgTaux = 0;
    if (count($predictions) > 0) {
        $validValues = array_filter(
            array_map(fn($p) => $p->getTauxUtilisationProjetePct(), $predictions),
            fn($v) => $v !== null
        );
        if (count($validValues) > 0) {
            $avgTaux = round(array_sum($validValues) / count($validValues), 1);
        }
    }

    // Correspondance site -> classification, pour le filtre côté client (SitePrediction n'a pas ce champ directement)
    $siteNames = array_map(fn($p) => $p->getSite(), $predictions);
    $classificationsMap = $this->processedSiteRepo->findClassificationsForSiteNames($siteNames);

    $uniqueClassifications = array_values(array_unique(array_filter($classificationsMap)));
    sort($uniqueClassifications);

    $uniqueEtats = array_values(array_unique(array_map(fn($p) => $p->getEtatPredit(), $predictions)));
    sort($uniqueEtats);

    return $this->render('ia/dashboard.html.twig', [
        'horizon' => $horizon,
        'predictions' => $predictions,
        'etat_stats' => $etatStats,
        'action_stats' => $actionStats,
        'top_critiques' => $topCritiques,
        'total_sites' => $total,
        'avg_taux' => $avgTaux,
        'count_congestion' => ($countByEtat['CONGESTION'] ?? 0) + ($countByEtat['CONGESTION(FDD)'] ?? 0) + ($countByEtat['CONGESTION(TDD)'] ?? 0),
        'count_risque' => $countByEtat['RISQUE_DE_CONGESTION'] ?? 0,
        'count_bridage' => $countByEtat['BRIDAGE'] ?? 0,
        'count_ok' => $countByEtat['OK'] ?? 0,
        'classifications_map' => $classificationsMap,
        'unique_classifications' => $uniqueClassifications,
        'unique_etats' => $uniqueEtats,
    ]);
}

    #[Route('/site/{site}', name: 'superuser_ia_site_detail', methods: ['GET'])]
    public function siteDetail(string $site, Request $request): Response
    {
        $horizon = $request->query->get('horizon', 'd7');

        $prediction = $this->predictionRepo->findOneBySiteAndHorizon($site, $horizon);
        $allHorizons = $this->predictionRepo->findAllHorizonsForSite($site);

        $snapshot = $this->processedSiteRepo->createQueryBuilder('s')
            ->andWhere('s.siteName = :site')
            ->setParameter('site', $site)
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $rawSites = array_filter([
            $snapshot?->getSiteName(),
            $snapshot?->getPairedSiteName(),
        ]);

        $historique = $this->historiqueRepo->findHistoryForSites($rawSites, 30);

        return $this->render('ia/site_detail.html.twig', [
            'site' => $site,
            'horizon' => $horizon,
            'prediction' => $prediction,
            'all_horizons' => $allHorizons,
            'snapshot' => $snapshot,
            'historique_json' => json_encode($historique),
        ]);
    }

    #[Route('/train', name: 'superuser_ia_train', methods: ['POST'])]
    public function train(): JsonResponse
    {
        try {
            $response = $this->httpClient->request('POST', $this->mlServiceUrl . '/ia/train', ['timeout' => 300]);
            return new JsonResponse($response->toArray());
        } catch (\Exception $e) {
            return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    #[Route('/predict-batch', name: 'superuser_ia_predict_batch', methods: ['POST'])]
    public function predictBatch(Request $request): JsonResponse
    {
        $horizon = $request->request->get('horizon', 'd7');
        try {
            $response = $this->httpClient->request('GET', $this->mlServiceUrl . '/ia/predict/batch/all', [
                'query' => ['horizon' => $horizon, 'limit' => 3000, 'persist' => 'true'],
                'timeout' => 600,
            ]);
            return new JsonResponse($response->toArray());
        } catch (\Exception $e) {
            return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/stats/{horizon}', name: 'superuser_ia_api_stats', methods: ['GET'])]
    public function apiStats(string $horizon): JsonResponse
    {
        return new JsonResponse([
            'etat_stats' => $this->predictionRepo->countByEtatPredit($horizon),
            'action_stats' => $this->predictionRepo->countByActionCode($horizon),
        ]);
    }

    #[Route('/export/{horizon}', name: 'superuser_ia_export_pdf', methods: ['GET'])]
    public function exportDashboardPdf(string $horizon): Response
    {
        // Le rendu de grands tableaux HTML dans Dompdf est très coûteux en mémoire (~ plusieurs Ko par cellule).
        // On augmente la limite uniquement pour cette requête, sans toucher à la config globale du serveur.
        ini_set('memory_limit', '1024M');

        $predictions = $this->predictionRepo->findLatestByHorizon($horizon);
        $etatStats = $this->predictionRepo->countByEtatPredit($horizon);
        $topCritiques = $this->predictionRepo->findTopCritiques($horizon, 10);
        $total = $this->predictionRepo->countTotal($horizon);

        $countByEtat = [];
        foreach ($etatStats as $row) {
            $countByEtat[$row['etat'] ?? 'INCONNU'] = (int) $row['total'];
        }

        $avgTaux = 0;
        if (count($predictions) > 0) {
            $validValues = array_filter(
                array_map(fn($p) => $p->getTauxUtilisationProjetePct(), $predictions),
                fn($v) => $v !== null
            );
            if (count($validValues) > 0) {
                $avgTaux = round(array_sum($validValues) / count($validValues), 1);
            }
        }

        $countCongestion = ($countByEtat['CONGESTION'] ?? 0) + ($countByEtat['CONGESTION(FDD)'] ?? 0) + ($countByEtat['CONGESTION(TDD)'] ?? 0);
        $countRisque = $countByEtat['RISQUE_DE_CONGESTION'] ?? 0;
        $countBridage = $countByEtat['BRIDAGE'] ?? 0;
        $countOk = $countByEtat['OK'] ?? 0;

        $horizonLabels = ['h24' => 'Prochain jour', 'd7' => '7 jours', 'd30' => '30 jours'];
        $horizonLabel = $horizonLabels[$horizon] ?? $horizon;

        $criticalRows = '';
        foreach ($topCritiques as $p) {
            $criticalRows .= '<tr>
                <td>' . htmlspecialchars($p->getSite()) . '</td>
                <td>' . number_format($p->getTraficActuelMbps() ?? 0, 1) . ' Mbps</td>
                <td>' . number_format($p->getTraficProjeteMbps() ?? 0, 1) . ' Mbps</td>
                <td>' . number_format($p->getTauxUtilisationProjetePct() ?? 0, 1) . ' %</td>
                <td>' . htmlspecialchars($p->getEtatPredit() ?? '-') . '</td>
                <td>' . htmlspecialchars($p->getActionPriorite() ?? '-') . '</td>
            </tr>';
        }

        // Plafond du tableau complet : au-delà de 300 lignes, Dompdf devient instable en mémoire
        // et un PDF de 2000+ lignes n'est de toute façon pas exploitable à l'impression.
        $maxFullListRows = 300;
        $truncated = count($predictions) > $maxFullListRows;
        $displayedPredictions = $truncated ? array_slice($predictions, 0, $maxFullListRows) : $predictions;

        $allRows = '';
        foreach ($displayedPredictions as $p) {
            $allRows .= '<tr>
                <td>' . htmlspecialchars($p->getSite()) . '</td>
                <td>' . number_format($p->getTraficActuelMbps() ?? 0, 1) . '</td>
                <td>' . number_format($p->getTraficProjeteMbps() ?? 0, 1) . '</td>
                <td>' . number_format((($p->getFacteurCroissance() ?? 1) * 100 - 100), 1) . ' %</td>
                <td>' . number_format($p->getTauxUtilisationProjetePct() ?? 0, 1) . ' %</td>
                <td>' . htmlspecialchars($p->getEtatPredit() ?? '-') . '</td>
            </tr>';
        }

        $truncationNote = $truncated
            ? '<p style="color:#92400e; background:#fef3c7; padding:8px 12px; border-radius:6px; font-size:11px;">
                ⚠️ Affichage limité aux ' . $maxFullListRows . ' premiers sites sur ' . count($predictions) . ' au total (limite technique du rendu PDF).
                Pour la liste complète, utilise l\'export CSV depuis la page Sites, ou consulte-la directement dans l\'interface.
               </p>'
            : '';

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 12px; }
.header { text-align: center; margin-bottom: 25px; }
.header h1 { color: #ff7900; margin-bottom: 5px; }
.date { color: #64748b; }
.cards { width: 100%; margin-bottom: 25px; }
.card { width: 24%; display: inline-block; vertical-align: top; padding: 12px; border-radius: 8px; margin-right: 1%; box-sizing: border-box; }
.blue { background: #eff6ff; border: 1px solid #bfdbfe; }
.green { background: #ecfdf5; border: 1px solid #a7f3d0; }
.orange { background: #fff7ed; border: 1px solid #fed7aa; }
.red { background: #fff1f2; border: 1px solid #fecaca; }
.label { font-size: 10px; color: #64748b; font-weight: bold; text-transform: uppercase; }
.value { font-size: 24px; font-weight: bold; margin-top: 6px; }
.small { color: #475569; margin-top: 4px; font-size: 10px; }
.section-title { font-size: 16px; margin-top: 22px; margin-bottom: 8px; color: #020617; }
table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
th { background: #f1f5f9; padding: 8px; text-align: left; border: 1px solid #cbd5e1; font-size: 10px; }
td { padding: 7px; border: 1px solid #e2e8f0; font-size: 10px; }
.footer { margin-top: 25px; text-align: center; color: #64748b; font-size: 10px; }
</style></head><body>
    <div class="header">
        <h1>Rapport Prédictions IA — ' . htmlspecialchars($horizonLabel) . '</h1>
        <div class="date">Généré le ' . date('d/m/Y H:i') . '</div>
    </div>
    <div class="cards">
        <div class="card blue"><div class="label">Sites suivis</div><div class="value">' . $total . '</div><div class="small">Taux moyen projeté : ' . $avgTaux . '%</div></div>
        <div class="card green"><div class="label">Sites OK</div><div class="value">' . $countOk . '</div><div class="small">Aucune anomalie prévue</div></div>
        <div class="card orange"><div class="label">Risque / Bridage</div><div class="value">' . ($countRisque + $countBridage) . '</div><div class="small">' . $countRisque . ' risque • ' . $countBridage . ' bridage</div></div>
        <div class="card red"><div class="label">Congestion prévue</div><div class="value">' . $countCongestion . '</div><div class="small">Action urgente requise</div></div>
    </div>
    <div class="section-title">Top 10 sites les plus critiques</div>
    <table><thead><tr><th>Site</th><th>Trafic actuel</th><th>Trafic projeté</th><th>Taux projeté</th><th>État prédit</th><th>Priorité</th></tr></thead><tbody>' . $criticalRows . '</tbody></table>
    <div class="section-title">Prédictions détaillées (' . count($displayedPredictions) . ' / ' . count($predictions) . ' sites)</div>
    ' . $truncationNote . '
    <table><thead><tr><th>Site</th><th>Trafic actuel</th><th>Trafic projeté</th><th>Croissance</th><th>Taux projeté</th><th>État</th></tr></thead><tbody>' . $allRows . '</tbody></table>
    <div class="footer">Rapport généré automatiquement depuis le dashboard IA NetWON.</div>
</body></html>';

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="predictions_ia_' . $horizon . '_' . date('Ymd_His') . '.pdf"',
        ]);
    }

    #[Route('/site/{site}/export', name: 'superuser_ia_site_export_pdf', methods: ['GET'])]
    public function exportSiteDetailPdf(string $site, Request $request): Response
    {
        ini_set('memory_limit', '512M');

        $horizon = $request->query->get('horizon', 'd7');
        $prediction = $this->predictionRepo->findOneBySiteAndHorizon($site, $horizon);

        $snapshot = $this->processedSiteRepo->createQueryBuilder('s')
            ->andWhere('s.siteName = :site')
            ->setParameter('site', $site)
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $horizonLabels = ['h24' => 'Prochain jour', 'd7' => '7 jours', 'd30' => '30 jours'];
        $horizonLabel = $horizonLabels[$horizon] ?? $horizon;

        $predictionRows = '';
        if ($prediction) {
            $predictionRows = '
                <tr><td>Trafic actuel</td><td>' . number_format($prediction->getTraficActuelMbps() ?? 0, 1) . ' Mbps</td></tr>
                <tr><td>Trafic projeté</td><td>' . number_format($prediction->getTraficProjeteMbps() ?? 0, 1) . ' Mbps</td></tr>
                <tr><td>Capacité</td><td>' . number_format($prediction->getCapaciteMbps() ?? 0, 0) . ' Mbps</td></tr>
                <tr><td>Taux projeté</td><td>' . number_format($prediction->getTauxUtilisationProjetePct() ?? 0, 1) . ' %</td></tr>
                <tr><td>Croissance estimée</td><td>' . number_format((($prediction->getFacteurCroissance() ?? 1) * 100 - 100), 1) . ' %</td></tr>
                <tr><td>État prédit</td><td>' . htmlspecialchars($prediction->getEtatPredit() ?? '-') . '</td></tr>
                <tr><td>Action recommandée</td><td>' . htmlspecialchars($prediction->getActionCode() ?? '-') . ' — ' . htmlspecialchars($prediction->getActionDescription() ?? '-') . '</td></tr>
                <tr><td>Priorité</td><td>' . htmlspecialchars($prediction->getActionPriorite() ?? '-') . '</td></tr>';
        } else {
            $predictionRows = '<tr><td colspan="2">Aucune prédiction disponible pour cet horizon.</td></tr>';
        }

        $snapshotRows = '';
        if ($snapshot) {
            $snapshotRows = '
                <tr><td>Classification</td><td>' . htmlspecialchars($snapshot->getClassification() ?? '-') . '</td></tr>
                <tr><td>Type transmission</td><td>' . htmlspecialchars($snapshot->getTypeTrans() ?? '-') . '</td></tr>
                <tr><td>Occurrences actuelles</td><td>' . ($snapshot->getNombreOccurrences() ?? '-') . '</td></tr>';
        }

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 12px; }
.header { text-align: center; margin-bottom: 25px; }
.header h1 { color: #ff7900; margin-bottom: 5px; }
.date { color: #64748b; }
.section-title { font-size: 16px; margin-top: 22px; margin-bottom: 8px; color: #020617; }
table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
td { padding: 9px; border: 1px solid #e2e8f0; }
td:first-child { font-weight: bold; background: #f8fafc; width: 40%; }
.footer { margin-top: 25px; text-align: center; color: #64748b; font-size: 10px; }
</style></head><body>
    <div class="header">
        <h1>Fiche Prédiction IA — ' . htmlspecialchars($site) . '</h1>
        <div class="date">Horizon : ' . htmlspecialchars($horizonLabel) . ' — Généré le ' . date('d/m/Y H:i') . '</div>
    </div>
    <div class="section-title">Résumé de la prédiction</div>
    <table><tbody>' . $predictionRows . '</tbody></table>
    ' . ($snapshotRows ? '<div class="section-title">Informations site</div><table><tbody>' . $snapshotRows . '</tbody></table>' : '') . '
    <div class="footer">Rapport généré automatiquement depuis le dashboard IA NetWON.</div>
</body></html>';

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="prediction_' . $site . '_' . $horizon . '_' . date('Ymd_His') . '.pdf"',
        ]);
    }
}
