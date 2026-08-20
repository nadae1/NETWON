<?php

namespace App\Controller;

use App\Entity\ProcessedImport;
use App\Entity\ProcessedSite;
use App\Repository\ProcessedSiteRepository;
use App\Repository\SiteAlertRepository;
use App\Service\NotificationService;
use App\Service\PythonApiClient;
use App\Service\SiteStateCalculatorService;
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
        ProcessedSiteRepository $processedSiteRepository,
        SiteAlertRepository $siteAlertRepository,
        NotificationService $notificationService
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
                $data, $user, $trafic->getClientOriginalName(),
                $portFileName, $liaisonFileName, $em, $processedSiteRepository,
                $siteAlertRepository, $notificationService
            );

            $this->addFlash('success', sprintf(
                'Import termine avec succes. %d sites traites, %d occurrences critiques.',
                $stats['totalSites'], $stats['totalCriticalOccurrences']
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
        ProcessedSiteRepository $processedSiteRepository,
        SiteAlertRepository $siteAlertRepository,
        NotificationService $notificationService
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
                $data, $user, $trafic->getClientOriginalName(),
                $portFileName, $liaisonFileName, $em, $processedSiteRepository,
                $siteAlertRepository, $notificationService
            );

            $this->addFlash('success', sprintf(
                'Import terminé avec succès. %d sites traités, %d occurrences critiques.',
                $stats['totalSites'], $stats['totalCriticalOccurrences']
            ));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur traitement: ' . $e->getMessage());
        }

        return $this->redirectToRoute('superuser_plan_data', ['imported' => 1]);
    }

    private function validateFiles(?UploadedFile $trafic, ?UploadedFile $port, ?UploadedFile $liaison): bool
    {
        return $trafic !== null && $trafic->getSize() > 0;
    }

    private function saveProcessedData(
        array $payload, $user, string $trafficFileName,
        string $portsFileName, string $liaisonFileName,
        EntityManagerInterface $em, ProcessedSiteRepository $processedSiteRepository,
        SiteAlertRepository $siteAlertRepository, NotificationService $notificationService
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
        $processedImport->setTotalOccurrences(0);

        $em->persist($processedImport);

        $items = [];
        if (!empty($payload['data']['tous_les_sites']) && is_array($payload['data']['tous_les_sites'])) {
            $items = $payload['data']['tous_les_sites'];
        }

        $totalSaved = 0;
        $totalCriticalOccurrences = 0;
        $totalProcessed = 0;
        $pendingAlerts = [];

        foreach ($items as $item) {
            $siteName = trim((string) ($item['Site'] ?? $item['siteName'] ?? 'UNKNOWN'));
            if ($siteName === '') continue;

            $existingSite = $processedSiteRepository->findOneBySiteName($siteName);

            $pairedSiteName = trim((string) ($item['Site_FDD'] ?? $item['siteFdd'] ?? $item['PairedSiteName'] ?? '')) ?: null;

            $classification = trim((string) ($item['Classification'] ?? $item['classification'] ?? ''));
            if ($classification === '' || $classification === '-') {
                $classification = 'UNKNOWN';
            }
            $classification = strtoupper($classification);

            $typeTrans = $item['Type_Trans'] ?? $item['typeTrans'] ?? null;
            if ($this->isMissingType($typeTrans) && $existingSite && !$this->isMissingType($existingSite->getTypeTrans())) {
                $typeTrans = $existingSite->getTypeTrans();
            }

            $maxTrafic = (float) ($item['Max_Trafic_Final'] ?? $item['maxTrafic'] ?? 0);
            $maxTraficTdd = (float) ($item['Max_Trafic_TDD'] ?? $item['maxTraficTdd'] ?? 0);
            $maxTraficFdd = (float) ($item['Max_Trafic_FDD'] ?? $item['maxTraficFdd'] ?? 0);

            $dateMax = null;
            if (!empty($item['Date_Max'])) {
                $dateMax = new \DateTime($item['Date_Max']);
            } elseif (!empty($item['dateMax'])) {
                $dateMax = new \DateTime($item['dateMax']);
            }

            $seuilCritique = (float) ($item['Seuil_Critique'] ?? $item['seuilCritique'] ?? 0);
            $nombreOccurrences = (int) ($item['Nombre_Occurrences'] ?? $item['nombreOccurrences'] ?? 0);
            $nombreOccurrencesTdd = (int) ($item['Nombre_Occurrences_TDD'] ?? $item['nombreOccurrencesTdd'] ?? 0);
            $nombreOccurrencesFdd = (int) ($item['Nombre_Occurrences_FDD'] ?? $item['nombreOccurrencesFdd'] ?? 0);
            $totalMeasures = (int) ($item['Total_Measures'] ?? $item['totalMeasures'] ?? 0);

            $capaciteMbps = (float) ($item['Capacite_Mbps'] ?? $item['capaciteMbps'] ?? 0);
            $capaciteTddMbps = (float) ($item['Capacite_TDD_Mbps'] ?? $item['capaciteTddMbps'] ?? 0);
            $capaciteFddMbps = (float) ($item['Capacite_FDD_Mbps'] ?? $item['capaciteFddMbps'] ?? 0);
            if ($capaciteMbps <= 0 && $existingSite && $existingSite->getCapaciteMbps() > 0) {
                $capaciteMbps = $existingSite->getCapaciteMbps();
                $capaciteTddMbps = $existingSite->getCapaciteTddMbps() ?? 0.0;
                $capaciteFddMbps = $existingSite->getCapaciteFddMbps() ?? 0.0;
            }

            $tauxUtilisation = $this->extractNullableFloat($item, ['taux_utilisation', 'tauxUtilisation']);
            $tauxUtilisationTdd = $this->extractNullableFloat($item, ['taux_utilisation_tdd', 'tauxUtilisationTdd']);
            $tauxUtilisationFdd = $this->extractNullableFloat($item, ['taux_utilisation_fdd', 'tauxUtilisationFdd']);
            $dropCongTdd = (int) ($item['DropCong_TDD'] ?? $item['dropcong_tdd'] ?? 0);
            $dropCongFdd = (int) ($item['DropCong_FDD'] ?? $item['dropcong_fdd'] ?? 0);
            $dropCongTf = (int) ($item['DropCong_TF'] ?? $item['dropcong_tf'] ?? 0);

            // ✅ NOUVEAU : KPI indisponibilité S1
            $s1FailDuration = (float) ($item['S1_Fail_Duration'] ?? $item['s1FailDuration'] ?? 0);
            $s1FailDateRaw = $item['S1_Fail_Date'] ?? $item['s1FailDate'] ?? null;
            $s1FailDate = null;
            if (!empty($s1FailDateRaw)) {
                try {
                    $s1FailDate = new \DateTime((string) $s1FailDateRaw);
                } catch (\Throwable $e) {
                    $s1FailDate = null;
                }
            }

            $latitude = $this->extractNullableFloat($item, ['Latitude', 'latitude']);
            $longitude = $this->extractNullableFloat($item, ['Longitude', 'longitude']);
            if (($latitude === null || $longitude === null) && $existingSite) {
                $latitude = $latitude ?? $existingSite->getLatitude();
                $longitude = $longitude ?? $existingSite->getLongitude();
            }

            $etatSite = trim((string) ($item['etat_site'] ?? $item['etatSite'] ?? 'OK'));
            $siteStatus = trim((string) ($item['site_status'] ?? $item['siteStatus'] ?? 'SECURISE'));
            $isCritical = (bool) ($item['is_critical'] ?? $item['isCritical'] ?? false);

            $recommendation = $this->buildRecommendationFromState(
                $etatSite, $siteStatus, $classification, $typeTrans,
                $maxTrafic, $capaciteMbps, $tauxUtilisation, $nombreOccurrences
            );

            $status = $etatSite;
            $recommendedAction = $recommendation['actionType'];
            $finalActionPlan = $recommendation['actionLabel'];

            if ($isCritical) {
                $totalCriticalOccurrences += $nombreOccurrences;
            }

            $resolvedService = $this->normalizeService($typeTrans, $classification, $siteName);

            $hash = hash('sha256', implode('|', [
                mb_strtolower($siteName),
                mb_strtolower($pairedSiteName ?? ''),
                mb_strtolower($classification),
                mb_strtolower(trim((string) ($typeTrans ?? ''))),
                sprintf('%.6f', $maxTrafic),
                sprintf('%.6f', $maxTraficTdd),
                sprintf('%.6f', $maxTraficFdd),
                $dateMax ? $dateMax->format('Y-m-d H:i:s') : '',
                sprintf('%.6f', $seuilCritique),
                $nombreOccurrences,
                $nombreOccurrencesTdd,
                $nombreOccurrencesFdd,
                $totalMeasures,
                sprintf('%.6f', $capaciteMbps),
                sprintf('%.6f', $capaciteTddMbps),
                sprintf('%.6f', $capaciteFddMbps),
                $tauxUtilisation === null ? 'null' : sprintf('%.6f', (float) $tauxUtilisation),
                $tauxUtilisationTdd === null ? 'null' : sprintf('%.6f', (float) $tauxUtilisationTdd),
                $tauxUtilisationFdd === null ? 'null' : sprintf('%.6f', (float) $tauxUtilisationFdd),
                $dropCongTdd, $dropCongFdd, $dropCongTf,
                sprintf('%.4f', $s1FailDuration),
                $s1FailDate ? $s1FailDate->format('Y-m-d H:i:s') : '',
                $latitude === null ? 'null' : sprintf('%.6f', (float) $latitude),
                $longitude === null ? 'null' : sprintf('%.6f', (float) $longitude),
                mb_strtolower((string) $status),
                mb_strtolower((string) $siteStatus),
                mb_strtolower(trim((string) ($recommendedAction ?? ''))),
                mb_strtolower(trim((string) ($finalActionPlan ?? ''))),
                mb_strtolower((string) ($resolvedService ?? '')),
                mb_strtolower((string) $etatSite),
            ]));

            $totalProcessed++;
            if ($existingSite && $existingSite->getDataHash() === $hash) {
                continue;
            }

            $etatsSurveilles = ['CONGESTION', 'CONGESTION(FDD)', 'CONGESTION(TDD)', 'BRIDAGE', 'RISQUE_DE_CONGESTION', 'COUPURE_S1'];
            $ancienEtat = $existingSite ? $existingSite->getStatus() : null;
            if (in_array($etatSite, $etatsSurveilles, true) && $ancienEtat !== $etatSite) {
                $alertMessage = sprintf(
                    "Trafic: %.2f Mbps / Capacité: %.2f Mbps (%.1f%%)\nOccurrences: %d\nClassification: %s / Type: %s%s",
                    $maxTrafic,
                    $capaciteMbps,
                    $tauxUtilisation ?? 0,
                    $nombreOccurrences,
                    $classification,
                    $typeTrans ?: 'NON_DEFINI',
                    $s1FailDuration > 0 ? sprintf("\nS1 indisponible: %.0fs", $s1FailDuration) : ''
                );
                $pendingAlerts[] = [
                    'site' => $siteName,
                    'etat' => $etatSite,
                    'service' => $resolvedService,
                    'message' => $alertMessage,
                ];
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
            $processedSite->setMaxTraficTdd($maxTraficTdd > 0 ? $maxTraficTdd : null);
            $processedSite->setMaxTraficFdd($maxTraficFdd > 0 ? $maxTraficFdd : null);
            $processedSite->setDateMax($dateMax);
            $processedSite->setSeuilCritique($seuilCritique);
            $processedSite->setNombreOccurrences($nombreOccurrences);
            $processedSite->setNombreOccurrencesTdd($nombreOccurrencesTdd > 0 ? $nombreOccurrencesTdd : null);
            $processedSite->setNombreOccurrencesFdd($nombreOccurrencesFdd > 0 ? $nombreOccurrencesFdd : null);
            $processedSite->setTotalMeasures($totalMeasures);
            $processedSite->setCapaciteMbps($capaciteMbps > 0 ? $capaciteMbps : null);
            $processedSite->setCapaciteTddMbps($capaciteTddMbps > 0 ? $capaciteTddMbps : null);
            $processedSite->setCapaciteFddMbps($capaciteFddMbps > 0 ? $capaciteFddMbps : null);
            $processedSite->setTauxUtilisation($tauxUtilisation);
            $processedSite->setTauxUtilisationTdd($tauxUtilisationTdd);
            $processedSite->setTauxUtilisationFdd($tauxUtilisationFdd);
            $processedSite->setDropCongTdd($dropCongTdd);
            $processedSite->setDropCongFdd($dropCongFdd);
            $processedSite->setDropCongTf($dropCongTf);
            $processedSite->setS1FailDuration($s1FailDuration > 0 ? $s1FailDuration : null);
            $processedSite->setS1FailDate($s1FailDate);
            $processedSite->setLatitude($latitude);
            $processedSite->setLongitude($longitude);
            $processedSite->setService($resolvedService);
            $processedSite->setStatus($status);
            $processedSite->setSiteStatus($siteStatus);
            $processedSite->setIsCritical($isCritical);
            $processedSite->setDataHash($hash);
            $processedSite->setRecommendedAction($recommendedAction);
            $processedSite->setFinalActionPlan($finalActionPlan);

            if (!$existingSite) {
                $em->persist($processedSite);
                $totalSaved++;
            }
        }

        $processedImport->setTotalOccurrences($totalCriticalOccurrences);
        $em->flush();

        foreach ($pendingAlerts as $alert) {
            $alertEntity = $siteAlertRepository->createQueryBuilder('a')
                ->andWhere('a.site = :site')
                ->andWhere('a.etat = :etat')
                ->setParameter('site', $alert['site'])
                ->setParameter('etat', $alert['etat'])
                ->orderBy('a.dateAlerte', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if (!$alertEntity) {
                continue;
            }

            try {
                $notificationService->notifySiteAlert($alertEntity);
            } catch (\Throwable $e) {
                // Une erreur de notification ne doit jamais faire échouer
                // un import déjà sauvegardé avec succès.
            }
        }

        return [
            'totalSites' => $totalProcessed,
            'totalCriticalOccurrences' => $totalCriticalOccurrences,
        ];
    }

    private function isMissingType(?string $type): bool
    {
        $t = strtoupper(trim((string) $type));
        return $t === '' || in_array($t, ['NON_DEFINI', 'UNKNOWN', 'N/A', 'NA', '-'], true);
    }

    private function buildRecommendationFromState(
        string $etatSite, string $siteStatus, ?string $classification,
        ?string $typeTrans, float $maxTrafic, float $capaciteMbps,
        ?float $tauxUtilisation, int $nombreOccurrences
    ): array {
        $classification = strtoupper(trim((string) $classification));
        $typeTrans = strtoupper(trim((string) $typeTrans));
        $taux = $tauxUtilisation ?? 0;

        $actionType = 'MONITORING';
        $actionLabel = 'Maintenir sous surveillance';

        switch ($etatSite) {
            case 'COUPURE_S1':
                $actionType = 'URGENT_S1_CHECK';
                $actionLabel = 'Verifier immediatement la liaison S1 (coupure detectee)';
                break;
            case 'CONGESTION':
                $actionType = 'URGENT_UPGRADE';
                $actionLabel = 'Upgrade urgent de capacite';
                break;
            case 'CONGESTION(FDD)':
                $actionType = 'UPGRADE_FDD';
                $actionLabel = 'Upgrade porteuse FDD';
                break;
            case 'CONGESTION(TDD)':
                $actionType = 'UPGRADE_TDD';
                $actionLabel = 'Upgrade porteuse TDD';
                break;
            case 'RISQUE_DE_CONGESTION':
                $actionType = 'SURVEILLANCE_RENFORCEE';
                $actionLabel = 'Surveiller le site (proche du seuil de congestion)';
                break;
            case 'BRIDAGE':
                $actionType = 'INVESTIGATE_BRIDAGE';
                $actionLabel = 'Investiguer le bridage de trafic';
                break;
            case 'A_VERIFIER_CAPACITE':
                $actionType = 'VERIFY_CAPACITY';
                $actionLabel = 'Verifier la capacite declaree';
                break;
            case 'SANS_TYPE':
                $actionType = 'DEFINE_TYPE';
                $actionLabel = 'Definir le type de liaison';
                break;
            case 'OK':
            default:
                if ($classification === 'TF') {
                    $actionType = 'TF_MONITORING';
                    $actionLabel = 'Surveillance TF renforcee';
                } elseif ($classification === 'COTRANS') {
                    $actionType = 'COTRANS_OPTIMIZATION';
                    $actionLabel = 'Optimisation COTRANS';
                } elseif ($classification === 'NO_COTRANS') {
                    $actionType = 'NO_COTRANS_REVIEW';
                    $actionLabel = 'Revue configuration NO_COTRANS';
                } elseif (in_array($classification, ['FDD', 'ONLY_FDD'])) {
                    $actionType = 'FDD_ANALYSIS';
                    $actionLabel = 'Analyse FDD approfondie';
                }
                break;
        }

        if (in_array($etatSite, ['CONGESTION', 'CONGESTION(FDD)', 'CONGESTION(TDD)'])) {
            if (str_contains($typeTrans, 'FO')) {
                $actionType = 'FO_UPGRADE';
                $actionLabel = 'Upgrade fibre optique (FO)';
            } elseif (str_contains($typeTrans, 'FH')) {
                $actionType = 'FH_UPGRADE';
                $actionLabel = 'Upgrade faisceau hertzien (FH)';
            } elseif ((str_contains($typeTrans, 'BACKBONE') || str_contains($typeTrans, 'BH')) && $taux >= 70) {
                $actionType = 'BACKBONE_UPGRADE';
                $actionLabel = 'Upgrade Backbone';
            }
        }

        return [
            'status' => $etatSite,
            'siteStatus' => $siteStatus,
            'actionType' => $actionType,
            'actionLabel' => $actionLabel,
        ];
    }

    private function normalizeService(?string $typeTrans, ?string $classification = null, ?string $siteName = null): ?string
    {
        $type = strtoupper(trim((string) $typeTrans));
        if ($type === '' || in_array($type, ['NON_DEFINI', 'UNKNOWN', 'N/A', 'NA', '-'])) {
            return null;
        }
        if (str_contains($type, 'FO') && str_contains($type, 'FH')) return null;
        if (str_contains($type, 'FO')) return 'FO';
        if (str_contains($type, 'FH')) return 'FH';
        if (str_contains($type, 'BACKBONE') || str_contains($type, 'BACKHAUL') || str_contains($type, 'BH')
            || str_contains($type, 'BO') || str_contains($type, 'OO') || str_contains($type, 'OR')
            || str_contains($type, 'TR') || str_contains($type, 'TT')) {
            return 'SHARED';
        }
        return null;
    }

    private function extractNullableFloat(array $item, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $item) || $item[$key] === null || $item[$key] === '') {
                continue;
            }
            return (float) $item[$key];
        }
        return null;
    }

    #[Route('/superuser/import/capacite/{type}', name: 'superuser_import_capacite', methods: ['POST'])]
    public function importCapacite(
        string $type,
        Request $request,
        PythonApiClient $pythonApiClient,
        EntityManagerInterface $em,
        ProcessedSiteRepository $processedSiteRepository,
        SiteStateCalculatorService $stateCalculator
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $file = $request->files->get('capacite_file');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Aucun fichier fourni.');
            return $this->redirectToRoute('superuser_dashboard_import');
        }

        try {
            $allowedExtensions = ['xlsx', 'xls', 'csv'];
            $extension = strtolower($file->getClientOriginalExtension());
            if (!in_array($extension, $allowedExtensions)) {
                throw new \Exception('Format de fichier non supporte. Utilisez .xlsx, .xls ou .csv.');
            }

            $content = file_get_contents($file->getPathname());
            $result = match ($type) {
                'fo' => $pythonApiClient->importCapaciteFo($content),
                'fh' => $pythonApiClient->importCapaciteFh($content),
                'backbone' => $pythonApiClient->importCapaciteBackbone($content),
                default => throw new \Exception('Type de capacite invalide.'),
            };

            if (($result['status'] ?? '') === 'success') {
                $capacites = $result['data']['capacites'] ?? [];
                $syncedCount = $this->syncCapacitiesIntoProcessedSites($capacites, $em, $processedSiteRepository, $stateCalculator, strtoupper($type));

                $message = $result['message'] ?? 'Import reussi.';
                $this->addFlash('success', $message . ' (' . ($result['data']['total'] ?? 0) . ' sites traites, ' . $syncedCount . ' site(s) existant(s) mis à jour immédiatement, état recalculé)');
            } else {
                $this->addFlash('error', $result['message'] ?? 'Erreur lors de l\'import.');
            }
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur : ' . $e->getMessage());
        }

        return $this->redirectToRoute('superuser_dashboard_import');
    }

    private function syncCapacitiesIntoProcessedSites(
        array $capacites,
        EntityManagerInterface $em,
        ProcessedSiteRepository $processedSiteRepository,
        SiteStateCalculatorService $stateCalculator,
        string $importType
    ): int {
        if (empty($capacites)) {
            return 0;
        }

        $now = new \DateTime();
        $count = 0;
        foreach ($capacites as $cap) {
            $rawSite = trim((string) ($cap['site'] ?? ''));
            $value = (float) ($cap['capacite_mbps'] ?? 0);
            $typeTrans = trim((string) ($cap['type_trans'] ?? ''));
            if ($rawSite === '' || $value <= 0) {
                continue;
            }

            $site = $processedSiteRepository->findOneBySiteName($rawSite);
            if (!$site) {
                $prefix = \App\Util\SiteNameHelper::extractPrefix($rawSite);
                if ($prefix !== $rawSite) {
                    $site = $processedSiteRepository->findOneBySiteName($prefix);
                }
            }
            if (!$site) {
                continue;
            }

            $capaciteAvant = $site->getCapaciteMbps();

            if (\App\Util\SiteNameHelper::isTdd($rawSite)) {
                $site->setCapaciteTddMbps($value);
            } elseif (\App\Util\SiteNameHelper::isTf($rawSite) || \App\Util\SiteNameHelper::isFdd($rawSite) || !\App\Util\SiteNameHelper::isTdd($rawSite)) {
                $site->setCapaciteFddMbps($value);
            }

            $capTdd = $site->getCapaciteTddMbps() ?? 0.0;
            $capFdd = $site->getCapaciteFddMbps() ?? 0.0;
            $capaciteGlobale = max($capTdd, $capFdd);
            $site->setCapaciteMbps($capaciteGlobale > 0 ? $capaciteGlobale : null);

            if ($typeTrans !== '' && $this->isMissingType($site->getTypeTrans())) {
                $site->setTypeTrans($typeTrans);
            }

            $stateCalculator->recalculer($site);

            if ($capaciteAvant !== $capaciteGlobale) {
                $site->setCapaciteUpdatedAt($now);
                $site->setLastActionPerformed('Import capacité ' . $importType . ' (' . number_format($value, 0) . ' Mbps)');
            }

            $site->setCapacityReminderUntil(null);
            $count++;
        }

        if ($count > 0) {
            $em->flush();
        }

        return $count;
    }

#[Route('/superuser/import/dropcong', name: 'superuser_import_dropcong', methods: ['POST'])]
public function importDropcong(Request $request, PythonApiClient $pythonApiClient): Response
{
    $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

    $fichier = $request->files->get('fichier');
    if (!$fichier instanceof UploadedFile) {
        $this->addFlash('error', 'Le fichier principal est obligatoire.');
        return $this->redirectToRoute('superuser_dashboard_import');
    }

    $drop1 = $request->files->get('drop1');
    $drop2 = $request->files->get('drop2');

    try {
        $result = $pythonApiClient->importDropcong($fichier, $drop1, $drop2);

        if (($result['status'] ?? '') === 'success') {
            $this->addFlash('success', $result['message'] ?? 'Import DropCong réussi.');
        } else {
            $this->addFlash('error', $result['message'] ?? 'Erreur lors de l\'import DropCong.');
        }
    } catch (\Throwable $e) {
        $this->addFlash('error', 'Erreur technique : ' . $e->getMessage());
    }

    return $this->redirectToRoute('superuser_dashboard_import');
}


}