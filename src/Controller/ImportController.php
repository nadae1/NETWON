<?php

namespace App\Controller;

use App\Entity\AnalyseResultat;
use App\Repository\SiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * NOTICE: This controller handles the legacy /import/excel-post endpoint which writes
 * directly to the AnalyseResultat entity. For new imports, prefer DataImportController
 * which writes to the ProcessedImport/ProcessedSite pipeline.
 * 
 * 
 * 
 * 
 * hetha à supprimer not used
 */
class ImportController extends AbstractController
{
    #[Route('/import/excel-post', name: 'import_excel_post', methods: ['POST'])]
    public function importExcel(
        Request $request,
        HttpClientInterface $httpClient,
        EntityManagerInterface $em,
        SiteRepository $siteRepository
    ): JsonResponse {
        try {
            $trafic  = $request->files->get('traffic_file');
            $port    = $request->files->get('port_file');
            $liaison = $request->files->get('liaison_file');

            if (!$trafic || !$port || !$liaison) {
                return new JsonResponse(['success' => false, 'message' => '3 fichiers obligatoires']);
            }

            $response = $httpClient->request('POST', 'http://127.0.0.1:8001/traiter', [
                'body' => [
                    'trafic'       => fopen($trafic->getPathname(), 'r'),
                    'port'         => fopen($port->getPathname(), 'r'),
                    'type_liaison' => fopen($liaison->getPathname(), 'r'),
                ],
            ]);

            $data = $response->toArray();

            if (!isset($data['data'])) {
                return new JsonResponse(['success' => false, 'message' => 'Erreur Python']);
            }

            foreach ($data['data'] as $row) {
                $site = new AnalyseResultat();

                // BUG FIX: was setSiteName() and setSitePaire() — those methods do not exist.
                // AnalyseResultat only has setSite(). There is no pairedSite field on this entity.
                $site->setSite($row['Site'] ?? 'UNKNOWN');
                $site->setClassification($row['Classification'] ?? 'UNKNOWN');
                $site->setTypeTrans($row['Type_Trans'] ?? null);
                $site->setMaxTrafic((float) ($row['Max_Trafic'] ?? 0));
                $site->setDateMax(!empty($row['Date_Max']) ? new \DateTime($row['Date_Max']) : null);
                $site->setSeuilCritique((float) ($row['Seuil_Critique'] ?? 0));
                $site->setNombreOccurrences((int) ($row['Nombre_Occurrences'] ?? 0));
                $site->setTotalMeasures((int) ($row['Total_Measures'] ?? 0));
                $site->setIsCritical(($row['Critique'] ?? '') === 'Oui');
                // BUG FIX: dateAnalyse is non-nullable — must always be set
                $site->setDateAnalyse(new \DateTime());
                $site->setServiceName($this->normalizeService($row['Type_Trans'] ?? null));

                $em->persist($site);
            }

            $em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Import réussi',
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function normalizeService(?string $type): string
    {
        $type = strtoupper(trim((string) $type));

        if (str_contains($type, 'FO') && str_contains($type, 'FH')) {
            return 'SHARED';
        }
        if (str_contains($type, 'FO')) {
            return 'FO';
        }
        if (str_contains($type, 'FH')) {
            return 'FH';
        }
        if (str_contains($type, 'BACKBONE') || str_starts_with($type, 'BH')) {
            return 'BACKBONE';
        }

        return 'SHARED';
    }
}