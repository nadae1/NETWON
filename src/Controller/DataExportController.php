<?php

namespace App\Controller;

use App\Repository\ProcessedSiteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DataExportController extends AbstractController
{
    #[Route('/superuser/export/sites', name: 'superuser_data_export_sites', methods: ['GET', 'POST'])]
    public function exportSuperuserSites(Request $request, ProcessedSiteRepository $processedSiteRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        if ($request->isMethod('POST')) {
            $mode = $request->request->get('export_mode', 'all');
            $siteNames = $request->request->all('sites');
            $siteSearch = trim((string) $request->request->get('site_search', ''));
            
            // ✅ Récupération des colonnes sélectionnées
            $selectedColumns = $request->request->all('columns', []);
            
            // ✅ Récupération de la période sélectionnée
            $periodStart = $request->request->get('period_start');
            $periodEnd = $request->request->get('period_end');
            
            // Récupération des sites
            $sites = $processedSiteRepository->findForAdvancedExport(
                null,
                $mode,
                $siteNames,
                $siteSearch,
                $periodStart ?: null,
                $periodEnd ?: null
            );

            return $this->buildCsvResponse($sites, 'superuser_sites_export.csv', $selectedColumns);
        }

        // ✅ Récupération des périodes disponibles
        $periods = $processedSiteRepository->getAvailableImportWeeks();

        return $this->render('dashboard/superuser/export.html.twig', [
            'siteNames' => $processedSiteRepository->findDistinctSiteNames(),
            'periods' => $periods,
            'allColumns' => $this->getAvailableColumns(),
            'defaultColumns' => ['site', 'classification', 'typeTrans', 'maxTrafic'],
        ]);
    }

    #[Route('/superuser/export/capacities/generate', name: 'superuser_export_capacities_generate', methods: ['POST'])]
    public function superuserExportCapacitiesGenerate(ProcessedSiteRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
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

    /**
     * ✅ Définition des colonnes disponibles pour l'export
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
     * ✅ Construction du CSV avec colonnes sélectionnées
     */
    private function buildCsvResponse(array $sites, string $filename, array $selectedColumns = []): Response
    {
        $availableColumns = $this->getAvailableColumns();

        // Si aucune colonne sélectionnée, on prend toutes les colonnes par défaut
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

                // Gestion spéciale des booléens
                if ($key === 'isCritical') {
                    $value = $value ? 'Oui' : 'Non';
                }

                // Gestion des null
                if ($value === null || $value === '') {
                    $value = '-';
                }

                // Formatage des nombres
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

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}