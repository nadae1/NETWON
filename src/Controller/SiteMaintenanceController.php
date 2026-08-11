<?php
// src/Controller/SiteMaintenanceController.php

namespace App\Controller;

use App\Repository\ProcessedSiteRepository;
use App\Service\PythonApiClient;
use App\Service\SiteDataSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SiteMaintenanceController extends AbstractController
{
    #[Route('/superuser/import/gps', name: 'superuser_import_gps', methods: ['POST'])]
    public function importGps(Request $request, PythonApiClient $pythonApiClient, SiteDataSyncService $syncService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $file = $request->files->get('gps_file');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Aucun fichier GPS fourni.');
            return $this->redirectToRoute('superuser_dashboard_import');
        }

        try {
            $result = $pythonApiClient->importGps($file);
            if (($result['status'] ?? '') === 'success') {
                $updated = $syncService->syncCoordinates();
                $this->addFlash('success', ($result['message'] ?? 'Import GPS reussi') . " ({$updated} site(s) localises sur la carte)");
            } else {
                $this->addFlash('error', $result['message'] ?? 'Erreur import GPS.');
            }
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur : ' . $e->getMessage());
        }

        return $this->redirectToRoute('superuser_dashboard_import');
    }

    // ===== Popup "capacité / type manquant" =====

    #[Route('/superuser/sites/missing-data', name: 'superuser_sites_missing_data', methods: ['GET'])]
    public function missingData(ProcessedSiteRepository $repo): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $sites = $repo->findSitesMissingCapaciteOrType(50);

        $data = array_map(static fn ($s) => [
            'id' => $s->getId(),
            'siteName' => $s->getSiteName(),
            'classification' => $s->getClassification(),
            'typeTrans' => $s->getTypeTrans(),
            'capaciteTddMbps' => $s->getCapaciteTddMbps(),
            'capaciteFddMbps' => $s->getCapaciteFddMbps(),
        ], $sites);

        return $this->json(['sites' => $data, 'total' => count($data)]);
    }

    #[Route('/superuser/sites/{id}/remind-later', name: 'superuser_sites_remind_later', methods: ['POST'])]
    public function remindLater(int $id, ProcessedSiteRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $site = $repo->find($id);
        if (!$site) {
            return $this->json(['status' => 'error', 'message' => 'Site introuvable'], 404);
        }
        $site->setReminderSnoozedUntil((new \DateTime())->modify('+7 days'));
        $em->flush();
        return $this->json(['status' => 'success']);
    }

    #[Route('/superuser/sites/{id}/update-manuel', name: 'superuser_sites_update_manuel', methods: ['POST'])]
    public function updateManuel(
        int $id,
        Request $request,
        ProcessedSiteRepository $repo,
        EntityManagerInterface $em,
        PythonApiClient $pythonApiClient
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $site = $repo->find($id);
        if (!$site) {
            return $this->json(['status' => 'error', 'message' => 'Site introuvable'], 404);
        }

        $capaciteTdd = $request->request->get('capacite_tdd');
        $capaciteFdd = $request->request->get('capacite_fdd');
        $typeTrans = $request->request->get('type_trans');

        // Formulaire laissé vide = équivalent d'un "remind me later"
        if (($capaciteTdd === null || $capaciteTdd === '')
            && ($capaciteFdd === null || $capaciteFdd === '')
            && !$typeTrans) {
            $site->setReminderSnoozedUntil((new \DateTime())->modify('+7 days'));
            $em->flush();
            return $this->json(['status' => 'success', 'message' => 'Rappel reporte']);
        }

        if ($capaciteTdd !== null && $capaciteTdd !== '') {
            $site->setCapaciteTddMbps((float) $capaciteTdd);
        }
        if ($capaciteFdd !== null && $capaciteFdd !== '') {
            $site->setCapaciteFddMbps((float) $capaciteFdd);
        }
        if ($typeTrans) {
            $site->setTypeTrans($typeTrans);
        }

        $total = ($site->getCapaciteTddMbps() ?? 0) + ($site->getCapaciteFddMbps() ?? 0);
        if ($total > 0) {
            $site->setCapaciteMbps($total);
        }
        $site->setReminderSnoozedUntil(null);
        $em->flush();

        try {
            $pythonApiClient->updateSiteManuel(
                $site->getSiteName(),
                $capaciteTdd !== '' ? $capaciteTdd : null,
                $capaciteFdd !== '' ? $capaciteFdd : null,
                $typeTrans ?: null
            );
        } catch (\Throwable $e) {
            // non bloquant : la mise à jour locale processed_site a réussi ;
            // seule la persistance vers capacite_site/type_liaison_data (Python) a échoué
        }

        return $this->json(['status' => 'success']);
    }
}