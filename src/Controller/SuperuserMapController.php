<?php

namespace App\Controller;

use App\Entity\ProcessedSite;
use App\Util\SiteNameHelper;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SuperuserMapController extends AbstractController
{
    // Route partagée entre admin, superuser et user, pour éviter de dupliquer ce contrôleur.
    #[Route('/superuser/map/data', name: 'superuser_map_data', methods: ['GET'])]
    #[Route('/admin/map/data', name: 'admin_map_data', methods: ['GET'])]
    #[Route('/user/map/data', name: 'user_map_data', methods: ['GET'])]
    public function mapData(Request $request, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isGranted('ROLE_SUPERUSER') && !$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_USER')) {
            throw $this->createAccessDeniedException();
        }

        $serviceFilter = $request->query->get('filter', 'all');
        $statusFilter = $request->query->get('status', 'all');

        // Un simple utilisateur (ROLE_USER, ni admin ni superuser) ne doit voir que les sites
        // de son propre service, quel que soit le paramètre "filter" envoyé dans l'URL.
        if ($this->isGranted('ROLE_USER') && !$this->isGranted('ROLE_SUPERUSER') && !$this->isGranted('ROLE_ADMIN')) {
            $user = $this->getUser();
            $serviceFilter = $user->getService() ?: 'all';
        }

        $qb = $em->createQueryBuilder()
            ->select('s.siteName', 's.latitude', 's.longitude', 's.isCritical', 's.siteStatus', 's.service', 's.maxTrafic')
            ->from(ProcessedSite::class, 's')
            ->where('s.latitude IS NOT NULL AND s.longitude IS NOT NULL');

        if ($serviceFilter !== 'all') {
            $qb->andWhere('s.service = :service')->setParameter('service', $serviceFilter);
        }

        if ($statusFilter !== 'all') {
            $qb->andWhere('UPPER(s.siteStatus) = :status')->setParameter('status', strtoupper($statusFilter));
        }

        $sites = $qb->getQuery()->getResult();
        $features = [];

        foreach ($sites as $site) {
            $siteStatus = strtoupper((string) $site['siteStatus']);
            switch ($siteStatus) {
                case 'CRITIQUE':
                    $color = 'red';
                    $status = 'Critique';
                    break;
                case 'SURVEILLANCE':
                    $color = 'yellow';
                    $status = 'Sous observation';
                    break;
                case 'SECURISE':
                    $color = 'green';
                    $status = 'Sécurisé';
                    break;
                default:
                    $color = 'gray';
                    $status = 'Non évalué';
                    break;
            }

            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [(float) $site['longitude'], (float) $site['latitude']],
                ],
                'properties' => [
                    'name' => $site['siteName'],
                    'service' => $site['service'],
                    'status' => $status,
                    'color' => $color,
                    'traffic' => $site['maxTrafic'],
                ],
            ];
        }

        return $this->json(['type' => 'FeatureCollection', 'features' => $features]);
    }

    #[Route('/superuser/map/import', name: 'superuser_map_import', methods: ['POST'])]
    public function import(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $file = $request->files->get('gps_file');
        if (!$file) {
            $this->addFlash('error', 'Aucun fichier fourni.');
            return $this->redirectToRoute('superuser_dashboard_home');
        }

        try {
            $spreadsheet = IOFactory::load($file->getPathname());
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            if (empty($rows)) {
                $this->addFlash('error', 'Le fichier est vide.');
                return $this->redirectToRoute('superuser_dashboard_home');
            }

            $header = array_shift($rows);
            $siteCol = null;
            $latCol = null;
            $lngCol = null;

            foreach ($header as $idx => $col) {
                $colLower = strtolower(trim((string) $col));
                if (in_array($colLower, ['site', 'sites', 'nom site', 'enodeb name', 'site name', 'site_name'])) {
                    $siteCol = $idx;
                }
                if (str_contains($colLower, 'latitude') || str_contains($colLower, 'lat')) {
                    $latCol = $idx;
                }
                if (str_contains($colLower, 'longitude') || str_contains($colLower, 'lon') || str_contains($colLower, 'long')) {
                    $lngCol = $idx;
                }
            }

            if ($siteCol === null || $latCol === null || $lngCol === null) {
                $this->addFlash('error', 'Colonnes introuvables. Attendu: site, latitude, longitude.');
                return $this->redirectToRoute('superuser_dashboard_home');
            }

            $updated = 0;
            $notFound = 0;
            $invalidCoords = 0;

            foreach ($rows as $row) {
                $siteNameRaw = trim((string) ($row[$siteCol] ?? ''));
                if (!$siteNameRaw) continue;

                $lat = (float) ($row[$latCol] ?? 0);
                $lng = (float) ($row[$lngCol] ?? 0);
                if ($lat == 0 || $lng == 0) {
                    $invalidCoords++;
                    continue;
                }

                $cleanName = SiteNameHelper::extractPrefix($siteNameRaw);

                $site = $em->getRepository(ProcessedSite::class)->findOneBy(['siteName' => $cleanName]);
                if (!$site) {
                    $site = $em->getRepository(ProcessedSite::class)->findOneBy(['siteName' => $siteNameRaw]);
                }

                if ($site) {
                    $site->setLatitude($lat);
                    $site->setLongitude($lng);
                    $updated++;
                } else {
                    $notFound++;
                }
            }

            $em->flush();

            $this->addFlash('success', "$updated sites mis à jour avec leurs coordonnées. $notFound sites non trouvés. $invalidCoords lignes avec coordonnées invalides.");
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de l\'import : ' . $e->getMessage());
        }

        return $this->redirectToRoute('superuser_dashboard_home');
    }
}