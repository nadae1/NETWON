<?php

namespace App\Controller;

use App\Repository\ProcessedSiteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DataExportController extends AbstractController
{
    #[Route('/dashboard/export/sites', name: 'user_data_export_sites', methods: ['GET', 'POST'])]
    public function exportUserSites(Request $request, ProcessedSiteRepository $processedSiteRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $service = $user->getService();

        if ($request->isMethod('POST')) {
            $mode = $request->request->get('export_mode', 'all');
            $siteNames = $request->request->all('sites');
            $siteSearch = trim((string) $request->request->get('site_search', ''));
            $dateFrom = $request->request->get('date_from');
            $dateTo = $request->request->get('date_to');

            $sites = $processedSiteRepository->findForAdvancedExport(
                $service,
                $mode,
                $siteNames,
                $siteSearch,
                $dateFrom,
                $dateTo
            );

            return $this->buildCsvResponse($sites, 'user_sites_export.csv');
        }

        return $this->render('dashboard/user/export.html.twig', [
            'siteNames' => $processedSiteRepository->findDistinctSiteNames($service),
        ]);
    }

    #[Route('/superuser/export/sites', name: 'superuser_data_export_sites', methods: ['GET', 'POST'])]
    public function exportSuperuserSites(Request $request, ProcessedSiteRepository $processedSiteRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        if ($request->isMethod('POST')) {
            $mode = $request->request->get('export_mode', 'all');
            $siteNames = $request->request->all('sites');
            $siteSearch = trim((string) $request->request->get('site_search', ''));
            $dateFrom = $request->request->get('date_from');
            $dateTo = $request->request->get('date_to');

            $sites = $processedSiteRepository->findForAdvancedExport(
                null,
                $mode,
                $siteNames,
                $siteSearch,
                $dateFrom,
                $dateTo
            );

            return $this->buildCsvResponse($sites, 'superuser_sites_export.csv');
        }

        return $this->render('dashboard/superuser/export.html.twig', [
            'siteNames' => $processedSiteRepository->findDistinctSiteNames(),
        ]);
    }

    private function buildCsvResponse(array $sites, string $filename): Response
    {
        $handle = fopen('php://temp', 'r+');

        // UTF-8 BOM باش Excel يقرأ العربي/الفرنسي صحيح
        fwrite($handle, "\xEF\xBB\xBF");

        // استعمل ; بدل , باش كل colonne تدخل وحدها في Excel
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
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}