<?php
// src/Service/SiteDataSyncService.php

namespace App\Service;

use App\Repository\ProcessedSiteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Garantit que processed_site reste à jour même quand la capacité,
 * le type de liaison ou les coordonnées GPS sont importés indépendamment
 * du fichier de trafic (avant ou après, peu importe l'ordre).
 */
class SiteDataSyncService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProcessedSiteRepository $repository,
    ) {
    }

    private function normalize(?string $value): string
    {
        return strtoupper(trim((string) $value));
    }

    /** Retire les suffixes techniques connus pour retomber sur le préfixe du site. */
    private function stripSuffix(string $site): string
    {
        $site = preg_replace('/_(LMB|LM2|LM|TDD|TC|TD|TF|FDD|TT)$/i', '', $site);
        $site = preg_replace('/_(BH|BO|TR|OO|OR)(_[A-Z0-9]+)*$/i', '', $site);
        return trim($site);
    }

    /**
     * Synchronise capacite_tdd_mbps / capacite_fdd_mbps / capacite_mbps / type_trans
     * depuis capacite_site + type_liaison_data vers processed_site.
     */
    public function syncCapacitesAndTypes(): int
    {
        $conn = $this->em->getConnection();

        $capRows = $conn->executeQuery(
            "SELECT site, capacite_mbps, type_trans FROM capacite_site WHERE capacite_mbps > 0"
        )->fetchAllAssociative();
        $capMap = [];
        foreach ($capRows as $row) {
            $capMap[$this->normalize($row['site'])] = $row;
        }

        $typeRows = $conn->executeQuery(
            "SELECT site, type_trans FROM type_liaison_data"
        )->fetchAllAssociative();
        $typeMap = [];
        foreach ($typeRows as $row) {
            if (!empty($row['type_trans'])) {
                $typeMap[$this->normalize($row['site'])] = $row['type_trans'];
            }
        }

        $updated = 0;
        foreach ($this->repository->findAll() as $site) {
            $key = $this->normalize($site->getSiteName());
            $prefixKey = $this->stripSuffix($key);
            $changed = false;

            // --- Type de liaison ---
            $type = $typeMap[$key] ?? $typeMap[$prefixKey] ?? ($capMap[$key]['type_trans'] ?? null) ?? ($capMap[$prefixKey]['type_trans'] ?? null);
            if ($type && $this->normalize($type) !== $this->normalize($site->getTypeTrans())) {
                $site->setTypeTrans($type);
                $changed = true;
            }

            // --- Capacité (résolution TDD / FDD via les sites frères) ---
            $siblings = $this->repository->getSiblingSitesWithCapacities($site->getSiteName());
            $capTdd = $siblings['tdd']['capacity'] ?? ($capMap[$key]['capacite_mbps'] ?? null);
            $capFdd = $siblings['fdd']['capacity'] ?? ($capMap[$key]['capacite_mbps'] ?? null);

            if ($capTdd !== null && $capTdd != $site->getCapaciteTddMbps()) {
                $site->setCapaciteTddMbps((float) $capTdd);
                $changed = true;
            }
            if ($capFdd !== null && $capFdd != $site->getCapaciteFddMbps()) {
                $site->setCapaciteFddMbps((float) $capFdd);
                $changed = true;
            }

            $classification = $this->normalize($site->getClassification());
            $tddVal = $site->getCapaciteTddMbps() ?? 0;
            $fddVal = $site->getCapaciteFddMbps() ?? 0;
            $capaciteGlobale = in_array($classification, ['TF', 'ONLY_FDD', 'FDD', 'UNKNOWN'], true)
                ? ($fddVal > 0 ? $fddVal : $tddVal)
                : ($tddVal + $fddVal);

            if ($capaciteGlobale > 0 && $capaciteGlobale != $site->getCapaciteMbps()) {
                $site->setCapaciteMbps($capaciteGlobale);
                $changed = true;

                // Recalcul simplifié du taux d'utilisation global si trafic connu
                if ($site->getMaxTrafic() > 0 && !in_array($classification, ['COTRANS', 'NO_COTRANS'], true)) {
                    $site->setTauxUtilisation(round(($site->getMaxTrafic() / $capaciteGlobale) * 100, 2));
                }
            }

            if ($changed) {
                $updated++;
            }
        }

        if ($updated > 0) {
            $this->em->flush();
        }

        return $updated;
    }

    /** Synchronise latitude/longitude depuis site_gps vers processed_site. */
    public function syncCoordinates(): int
    {
        $conn = $this->em->getConnection();
        $rows = $conn->executeQuery(
            "SELECT site, latitude, longitude FROM site_gps"
        )->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $map[$this->normalize($row['site'])] = $row;
        }

        $updated = 0;
        foreach ($this->repository->findAll() as $site) {
            $key = $this->normalize($site->getSiteName());
            $prefixKey = $this->stripSuffix($key);
            $gps = $map[$key] ?? $map[$prefixKey] ?? null;

            if ($gps && ($site->getLatitude() === null || $site->getLongitude() === null)) {
                $site->setLatitude((float) $gps['latitude']);
                $site->setLongitude((float) $gps['longitude']);
                $updated++;
            }
        }

        if ($updated > 0) {
            $this->em->flush();
        }

        return $updated;
    }
}