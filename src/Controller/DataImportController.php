<?php

namespace App\Controller;

use App\Entity\ProcessedImport;
use App\Entity\ProcessedSite;
use App\Repository\ProcessedSiteRepository;
use App\Service\PythonApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DataImportController extends AbstractController
{
    #[Route('/dashboard/import/process', name: 'user_data_import_process', methods: ['POST'])]
    public function processUserImport(
        Request $request,
        PythonApiClient $pythonApiClient,
        EntityManagerInterface $em,
        ProcessedSiteRepository $processedSiteRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();

        $trafic = $request->files->get('trafic') ?? $request->files->get('trafic_file');
        $port = $request->files->get('port') ?? $request->files->get('port_file');
        $liaison = $request->files->get('liaison') ?? $request->files->get('type_liaison_file');
        $gps = $request->files->get('gps') ?? $request->files->get('gps_file');

        if (!$this->validateFiles($trafic, $port, $liaison)) {
            $this->addFlash('error', 'Le fichier trafic est obligatoire.');
            return $this->redirectToRoute('dashboard_import');
        }

        $portFileName = $port ? $port->getClientOriginalName() : '';
        $liaisonFileName = $liaison ? $liaison->getClientOriginalName() : '';

        try {
            $data = $pythonApiClient->processFiles($trafic, $port, $liaison, $gps);

            if (($data['status'] ?? '') !== 'success') {
                throw new \Exception($data['message'] ?? 'Erreur inconnue du service Python');
            }

            $stats = $this->saveProcessedData(
                $data,
                $user,
                $trafic->getClientOriginalName(),
                $portFileName,
                $liaisonFileName,
                $em,
                $processedSiteRepository
            );

            $this->addFlash('success', sprintf(
                'Import terminé avec succès. %d sites traités, %d occurrences critiques.',
                $stats['totalSites'],
                $stats['totalCriticalOccurrences']
            ));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur traitement: ' . $e->getMessage());
        }

        return $this->redirectToRoute('user_dashboard_sites');
    }

    #[Route('/superuser/import/process', name: 'superuser_data_import_process', methods: ['POST'])]
    public function processSuperuserImport(
        Request $request,
        PythonApiClient $pythonApiClient,
        EntityManagerInterface $em,
        ProcessedSiteRepository $processedSiteRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $user = $this->getUser();

        $trafic = $request->files->get('trafic') ?? $request->files->get('trafic_file');
        $port = $request->files->get('port') ?? $request->files->get('port_file');
        $liaison = $request->files->get('liaison') ?? $request->files->get('type_liaison_file');
        $gps = $request->files->get('gps') ?? $request->files->get('gps_file');

        if (!$this->validateFiles($trafic, $port, $liaison)) {
            $this->addFlash('error', 'Le fichier trafic est obligatoire.');
            return $this->redirectToRoute('superuser_dashboard_import');
        }

        $portFileName = $port ? $port->getClientOriginalName() : '';
        $liaisonFileName = $liaison ? $liaison->getClientOriginalName() : '';

        try {
            $data = $pythonApiClient->processFiles($trafic, $port, $liaison, $gps);

            if (($data['status'] ?? '') !== 'success') {
                throw new \Exception($data['message'] ?? 'Erreur inconnue du service Python');
            }

            $stats = $this->saveProcessedData(
                $data,
                $user,
                $trafic->getClientOriginalName(),
                $portFileName,
                $liaisonFileName,
                $em,
                $processedSiteRepository
            );

            $this->addFlash('success', sprintf(
                'Import terminé avec succès. %d sites traités, %d occurrences critiques.',
                $stats['totalSites'],
                $stats['totalCriticalOccurrences']
            ));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur traitement: ' . $e->getMessage());
        }

        return $this->redirectToRoute('superuser_dashboard_sites');
    }

    private function validateFiles(?UploadedFile $trafic, ?UploadedFile $port, ?UploadedFile $liaison): bool
    {
        return $trafic !== null && $trafic->getSize() > 0;
    }

    private function saveProcessedData(
        array $payload,
        $user,
        string $trafficFileName,
        string $portsFileName,
        string $liaisonFileName,
        EntityManagerInterface $em,
        ProcessedSiteRepository $processedSiteRepository
    ): array {
        $stats = $payload['data']['stats'] ?? [];

        $processedImport = new ProcessedImport();
        $processedImport->setImportedBy($user);
        $processedImport->setServiceScope($user->getService() ?? 'GLOBAL');
        $processedImport->setTrafficFileName($trafficFileName);
        $processedImport->setPortsFileName($portsFileName);
        $processedImport->setLiaisonFileName($liaisonFileName);
        $processedImport->setStatus($payload['status'] ?? 'success');
        $processedImport->setTotalSites((int) ($stats['total_sites'] ?? 0));
        $processedImport->setTotalOccurrences((int) ($stats['total_occurrences'] ?? 0));

        $em->persist($processedImport);

        $groups = [
            'sites_tf' => 'TF',
            'sites_cotrans' => 'COTRANS',
            'sites_nocotrans' => 'NO_COTRANS',
            'sites_fdd' => 'FDD',
            'sites_non_classes' => 'UNKNOWN',
        ];

        $totalSaved = 0;
        $totalCriticalOccurrences = 0;
        $totalProcessed = 0;

        $items = [];
        if (!empty($payload['data']['tous_les_sites']) && is_array($payload['data']['tous_les_sites'])) {
            $items = $payload['data']['tous_les_sites'];
        } else {
            foreach ($groups as $group => $defaultClassification) {
                foreach (($payload['data'][$group] ?? []) as $item) {
                    $item['Classification'] = $item['Classification'] ?? $item['classification'] ?? $defaultClassification;
                    $items[] = $item;
                }
            }
        }

        foreach ($items as $item) {
            $siteName = trim((string) ($item['Site'] ?? $item['siteName'] ?? 'UNKNOWN'));
            if ($siteName === '') {
                continue;
            }

            $pairedSiteName = trim((string) ($item['Site_FDD'] ?? $item['siteFdd'] ?? $item['PairedSiteName'] ?? '')) ?: null;

            $classification = strtoupper(trim((string) ($item['Classification'] ?? $item['classification'] ?? '')));
            if ($classification === '' || $classification === 'UNKNOWN') {
                $classification = trim((string) ($item['Classification'] ?? $item['classification'] ?? 'UNKNOWN'));
            }
            if ($classification === '' || $classification === 'UNKNOWN') {
                $classification = 'UNKNOWN';
            }

            $typeTrans = $item['Type_Trans'] ?? $item['typeTrans'] ?? null;

            $maxTrafic = (float) ($item['Max_Trafic_Final'] ?? $item['maxTrafic'] ?? $item['Max_Trafic'] ?? 0);
            $dateMax = null;
            if (!empty($item['Date_Max'])) {
                $dateMax = new \DateTime($item['Date_Max']);
            } elseif (!empty($item['dateMax'])) {
                $dateMax = new \DateTime($item['dateMax']);
            }
            $seuilCritique = (float) ($item['Seuil_Critique'] ?? $item['seuilCritique'] ?? 0);
            $nombreOccurrences = (int) ($item['Nombre_Occurrences'] ?? $item['nombreOccurrences'] ?? 0);
            $totalMeasures = (int) ($item['Total_Measures'] ?? $item['totalMeasures'] ?? 0);

            $isCritical = $nombreOccurrences > 0;
            if ($isCritical) {
                $totalCriticalOccurrences += $nombreOccurrences;
            }

            // Service unifié via normalizeService
            $resolvedService = $this->normalizeService($typeTrans);

            $hash = hash('sha256', implode('|', [
                mb_strtolower($siteName),
                mb_strtolower($pairedSiteName ?? ''),
                mb_strtolower($classification),
                mb_strtolower(trim((string) ($typeTrans ?? ''))),
                number_format($maxTrafic, 6, '.', ''),
                $dateMax ? $dateMax->format('Y-m-d H:i:s') : '',
                number_format($seuilCritique, 6, '.', ''),
                $nombreOccurrences,
                $totalMeasures,
                mb_strtolower($resolvedService),
            ]));

            $totalProcessed++;
            $existingSite = $processedSiteRepository->findOneBySiteName($siteName);
            if ($existingSite && $existingSite->getDataHash() === $hash) {
                continue;
            }

            if ($existingSite) {
                $processedSite = $existingSite;
                $processedSite->setProcessedImport($processedImport);
            } else {
                $processedSite = new ProcessedSite();
                $processedSite->setProcessedImport($processedImport);
            }

            $processedSite->setSiteName($siteName);
            $processedSite->setPairedSiteName($pairedSiteName);
            $processedSite->setClassification($classification);
            $processedSite->setTypeTrans($typeTrans);
            $processedSite->setMaxTrafic($maxTrafic);
            $processedSite->setDateMax($dateMax);
            $processedSite->setSeuilCritique($seuilCritique);
            $processedSite->setNombreOccurrences($nombreOccurrences);
            $processedSite->setTotalMeasures($totalMeasures);
            $processedSite->setService($resolvedService);
            $processedSite->setIsCritical($isCritical);
            $processedSite->setDataHash($hash);

            if (!$existingSite) {
                $em->persist($processedSite);
                $totalSaved++;
            }
        }

        $em->flush();

        return [
            'totalSites' => $totalProcessed,
            'totalCriticalOccurrences' => $totalCriticalOccurrences,
        ];
    }

    private function normalizeService(?string $typeTrans): string
    {
        $type = strtoupper(trim((string) $typeTrans));

        if ($type === '') {
            return 'SHARED';
        }

        // Support BACKBONE
        if (str_contains($type, 'BACKBONE') || str_starts_with($type, 'BH') || $type === 'BACKBONE') {
            return 'BACKBONE';
        }

        $clean = preg_replace('/[^A-Z0-9]+/', '_', $type);
        $parts = array_values(array_filter(explode('_', $clean)));

        if (in_array('FO', $parts, true) || str_starts_with($clean, 'FO_') || $clean === 'FO') {
            return 'FO';
        }

        if (in_array('FH', $parts, true) || str_starts_with($clean, 'FH_') || $clean === 'FH') {
            return 'FH';
        }

        return 'SHARED';
    }
}