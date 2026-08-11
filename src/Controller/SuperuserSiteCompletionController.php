<?php

namespace App\Controller;

use App\Repository\ProcessedSiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/superuser/sites-completion')]
class SuperuserSiteCompletionController extends AbstractController
{
    #[Route('/incomplete', name: 'superuser_sites_incomplete', methods: ['GET'])]
    public function incomplete(ProcessedSiteRepository $repo): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $sites = $repo->findSitesNeedingCapacityUpdate(200);

        return $this->json(array_map(function ($site) {
            return [
                'id' => $site->getId(),
                'siteName' => $site->getSiteName(),
                'classification' => $site->getClassification(),
                'typeTrans' => $site->getTypeTrans(),
                'capaciteTddMbps' => $site->getCapaciteTddMbps(),
                'capaciteFddMbps' => $site->getCapaciteFddMbps(),
                'isCritical' => $site->isCritical(),
            ];
        }, $sites));
    }

    #[Route('/{id}/update', name: 'superuser_sites_update_completion', methods: ['POST'])]
    public function update(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        ProcessedSiteRepository $repo
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $site = $repo->find($id);
        if (!$site) {
            return $this->json(['status' => 'error', 'message' => 'Site introuvable.'], 404);
        }

        $payload = json_decode($request->getContent(), true) ?? [];

        if (!empty($payload['remindLater'])) {
            $reminderDate = $this->parseReminderDate($payload['remindUntil'] ?? null);
            $site->setCapacityReminderUntil($reminderDate);
            $em->flush();
            return $this->json([
                'status' => 'success',
                'message' => 'Rappel reporté au ' . $reminderDate->format('d/m/Y') . '.',
            ]);
        }

        $capaciteTdd = isset($payload['capaciteTddMbps']) && $payload['capaciteTddMbps'] !== ''
            ? (float) $payload['capaciteTddMbps'] : null;
        $capaciteFdd = isset($payload['capaciteFddMbps']) && $payload['capaciteFddMbps'] !== ''
            ? (float) $payload['capaciteFddMbps'] : null;
        $typeTrans = isset($payload['typeTrans']) && trim((string) $payload['typeTrans']) !== ''
            ? strtoupper(trim((string) $payload['typeTrans'])) : null;

        if ($capaciteTdd === null && $capaciteFdd === null && $typeTrans === null) {
            return $this->json(['status' => 'error', 'message' => 'Aucune donnée fournie.'], 400);
        }

        if ($capaciteTdd !== null && $capaciteTdd > 0) {
            $site->setCapaciteTddMbps($capaciteTdd);
        }
        if ($capaciteFdd !== null && $capaciteFdd > 0) {
            $site->setCapaciteFddMbps($capaciteFdd);
        }
        if ($typeTrans !== null) {
            $site->setTypeTrans($typeTrans);
        }

        $capTdd = $site->getCapaciteTddMbps() ?? 0.0;
        $capFdd = $site->getCapaciteFddMbps() ?? 0.0;
        // ✅ CORRIGÉ : capacité globale = MAX(TDD, FDD), pas la somme.
        // Un site peut avoir une capacité déclarée sur un seul port ou
        // sur les deux ; la capacité "totale" retenue est toujours la
        // plus grande des deux, jamais leur addition.
        $capaciteGlobale = max($capTdd, $capFdd);
        if ($capaciteGlobale > 0) {
            $site->setCapaciteMbps($capaciteGlobale);
            $maxTrafic = $site->getMaxTrafic() ?? 0.0;
            if ($maxTrafic > 0) {
                $site->setTauxUtilisation(round(($maxTrafic / $capaciteGlobale) * 100, 2));
            }
        }

        $site->setCapacityReminderUntil(null);
        $em->flush();

        $this->syncIntoSourceTables($em, $site->getSiteName(), $capaciteGlobale, $typeTrans);

        return $this->json(['status' => 'success', 'message' => 'Site mis à jour.']);
    }

    #[Route('/bulk-remind', name: 'superuser_sites_bulk_remind', methods: ['POST'])]
    public function bulkRemind(
        Request $request,
        EntityManagerInterface $em,
        ProcessedSiteRepository $repo
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $payload = json_decode($request->getContent(), true) ?? [];
        $ids = array_map('intval', $payload['siteIds'] ?? []);
        if (empty($ids)) {
            return $this->json(['status' => 'error', 'message' => 'Aucun site sélectionné.'], 400);
        }

        $reminderDate = $this->parseReminderDate($payload['remindUntil'] ?? null);

        $updated = 0;
        foreach ($ids as $id) {
            $site = $repo->find($id);
            if (!$site) {
                continue;
            }
            $site->setCapacityReminderUntil($reminderDate);
            $updated++;
        }

        $em->flush();

        return $this->json([
            'status' => 'success',
            'message' => sprintf('%d site(s) reporté(s) au %s.', $updated, $reminderDate->format('d/m/Y')),
            'updated' => $updated,
        ]);
    }

    private function parseReminderDate(?string $raw): \DateTime
    {
        if ($raw) {
            try {
                $date = new \DateTime($raw);
                if ($date > new \DateTime()) {
                    return $date;
                }
            } catch (\Exception $e) {
                // ignore, fallback ci-dessous
            }
        }
        return (new \DateTime())->modify('+7 days');
    }

    private function syncIntoSourceTables(
        EntityManagerInterface $em,
        string $siteName,
        float $capaciteGlobale,
        ?string $typeTrans
    ): void {
        $conn = $em->getConnection();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        if ($capaciteGlobale > 0) {
            $conn->executeStatement(
                "INSERT INTO capacite_site (site, capacite_mbps, type_trans, date_mise_ajour)
                 VALUES (:site, :capacite, :type, :date)
                 ON CONFLICT (site) DO UPDATE SET
                    capacite_mbps = EXCLUDED.capacite_mbps,
                    type_trans = COALESCE(EXCLUDED.type_trans, capacite_site.type_trans),
                    date_mise_ajour = EXCLUDED.date_mise_ajour",
                [
                    'site' => $siteName,
                    'capacite' => $capaciteGlobale,
                    'type' => $typeTrans,
                    'date' => $now,
                ]
            );
        }

        if ($typeTrans !== null) {
            $updated = $conn->executeStatement(
                "UPDATE type_liaison_data SET type_trans = :type, date_import = :date WHERE site = :site",
                ['type' => $typeTrans, 'date' => $now, 'site' => $siteName]
            );
            if ($updated === 0) {
                $conn->executeStatement(
                    "INSERT INTO type_liaison_data (site, type_trans, date_import) VALUES (:site, :type, :date)",
                    ['site' => $siteName, 'type' => $typeTrans, 'date' => $now]
                );
            }
        }
    }
}