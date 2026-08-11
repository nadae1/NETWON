<?php

namespace App\Service;

use App\Entity\ProcessedSite;

/**
 * ✅ NOUVEAU : centralise la state-machine de calcul d'état/statut d'un
 * ProcessedSite (état, siteStatus, isCritical, taux d'utilisation, action
 * recommandée), reproduite fidèlement depuis
 * api_python/traitement.py::calculer_etat_avance().
 *
 * TOUS les points d'entrée qui modifient une capacité ou un type de
 * liaison après le traitement trafic initial (import capacité séparé,
 * validation superuser, complétion manuelle d'un site, demande de MAJ
 * validée) DOIVENT utiliser ce service plutôt que de recalculer l'état
 * eux-mêmes. Avant cette factorisation, la logique était dupliquée (et
 * divergente) entre trois contrôleurs différents -- source directe des
 * incohérences d'état/statut observées.
 *
 * ⚠️ Les seuils DOIVENT rester synchronisés avec traitement.py.
 */
class SiteStateCalculatorService
{
    private const SEUIL_CONGESTION_PCT = 90.0;
    private const SEUIL_OCCURRENCES_BRIDAGE = 50;
    private const SEUIL_OCCURRENCES_RISQUE_CONGESTION = 57;
    private const CAPACITE_10G_MBPS = 10000.0;

    public const ETATS_CRITIQUES = ['CONGESTION', 'CONGESTION(FDD)', 'CONGESTION(TDD)', 'BRIDAGE'];

    public function isMissingType(?string $type): bool
    {
        $t = strtoupper(trim((string) $type));
        return $t === '' || in_array($t, ['NON_DEFINI', 'UNKNOWN', 'N/A', 'NA', '-'], true);
    }

    /**
     * Recalcule et applique status / siteStatus / isCritical / taux* et
     * la recommandation associée, à partir des valeurs de trafic /
     * capacité / occurrences ACTUELLES du site (déjà mises à jour par
     * l'appelant avant d'invoquer cette méthode). Ne fait pas de flush.
     */
    public function recalculer(ProcessedSite $site): void
    {
        $classification = strtoupper(trim((string) $site->getClassification()));
        $typeTrans = $site->getTypeTrans();

        $maxTrafic = (float) ($site->getMaxTrafic() ?? 0);
        $maxTraficTdd = (float) ($site->getMaxTraficTdd() ?? 0);
        $maxTraficFdd = (float) ($site->getMaxTraficFdd() ?? 0);

        $capaciteTdd = (float) ($site->getCapaciteTddMbps() ?? 0);
        $capaciteFdd = (float) ($site->getCapaciteFddMbps() ?? 0);
        $capaciteGlobale = (float) ($site->getCapaciteMbps() ?? 0);

        $occurrences = (int) $site->getNombreOccurrences();
        $occTdd = (int) ($site->getNombreOccurrencesTdd() ?? 0);
        $occFdd = (int) ($site->getNombreOccurrencesFdd() ?? 0);

        $tauxTdd = ($capaciteTdd > 0 && $maxTraficTdd > 0)
            ? round(($maxTraficTdd / $capaciteTdd) * 100, 2) : null;
        $tauxFdd = ($capaciteFdd > 0 && $maxTraficFdd > 0)
            ? round(($maxTraficFdd / $capaciteFdd) * 100, 2) : null;

        $tauxGlobal = null;
        $etat = 'OK';

        if (in_array($classification, ['COTRANS', 'NO_COTRANS'], true)) {
            $tauxGlobal = null;

            $fddCongest = ($tauxFdd ?? 0) >= self::SEUIL_CONGESTION_PCT && $occFdd >= self::SEUIL_OCCURRENCES_RISQUE_CONGESTION;
            $tddCongest = ($tauxTdd ?? 0) >= self::SEUIL_CONGESTION_PCT && $occTdd >= self::SEUIL_OCCURRENCES_RISQUE_CONGESTION;

            if ($fddCongest && $tddCongest) {
                $etat = 'CONGESTION';
            } elseif ($fddCongest) {
                $etat = 'CONGESTION(FDD)';
            } elseif ($tddCongest) {
                $etat = 'CONGESTION(TDD)';
            } elseif ((($tauxFdd ?? 0) >= self::SEUIL_CONGESTION_PCT && $occFdd < self::SEUIL_OCCURRENCES_RISQUE_CONGESTION)
                || (($tauxTdd ?? 0) >= self::SEUIL_CONGESTION_PCT && $occTdd < self::SEUIL_OCCURRENCES_RISQUE_CONGESTION)) {
                $etat = 'RISQUE_DE_CONGESTION';
            } else {
                $etat = 'OK';
            }
        } elseif ($capaciteGlobale <= 0) {
            $etat = 'OK';
            $tauxGlobal = null;
        } else {
            $tauxGlobal = $maxTrafic > 0 ? round(($maxTrafic / $capaciteGlobale) * 100, 2) : null;
            $taux = $tauxGlobal ?? 0.0;

            if ($classification !== 'TF'
                && abs($capaciteGlobale - self::CAPACITE_10G_MBPS) < 1.0
                && $taux >= self::SEUIL_CONGESTION_PCT
                && $occurrences >= self::SEUIL_OCCURRENCES_RISQUE_CONGESTION) {
                $etat = 'BRIDAGE';
            } elseif ($taux >= self::SEUIL_CONGESTION_PCT && $occurrences >= self::SEUIL_OCCURRENCES_RISQUE_CONGESTION) {
                $etat = 'CONGESTION';
            } elseif ($taux >= self::SEUIL_CONGESTION_PCT && $occurrences < self::SEUIL_OCCURRENCES_RISQUE_CONGESTION) {
                $etat = 'RISQUE_DE_CONGESTION';
            } elseif ($taux < self::SEUIL_CONGESTION_PCT && $occurrences >= self::SEUIL_OCCURRENCES_BRIDAGE) {
                $etat = 'BRIDAGE';
            } else {
                $etat = 'OK';
            }
        }

        $typeManquant = $this->isMissingType($typeTrans);

        if (in_array($etat, self::ETATS_CRITIQUES, true) || $etat === 'RISQUE_DE_CONGESTION') {
            $etatAffiche = $etat;
            $siteStatus = in_array($etat, self::ETATS_CRITIQUES, true) ? 'CRITIQUE' : 'SURVEILLANCE';
        } elseif ($typeManquant) {
            $etatAffiche = 'SANS_TYPE';
            $siteStatus = 'SURVEILLANCE';
        } else {
            $etatAffiche = $etat;
            $siteStatus = 'SECURISE';
        }

        $site->setTauxUtilisation($tauxGlobal);
        $site->setTauxUtilisationTdd($tauxTdd);
        $site->setTauxUtilisationFdd($tauxFdd);
        $site->setStatus($etatAffiche);
        $site->setSiteStatus($siteStatus);
        $site->setIsCritical(in_array($etat, self::ETATS_CRITIQUES, true));

        $recommendation = $this->buildRecommendation(
            $etatAffiche, $siteStatus, $classification, $typeTrans,
            $maxTrafic, $capaciteGlobale, $tauxGlobal, $occurrences
        );
        $site->setRecommendedAction($recommendation['actionType']);
        $site->setFinalActionPlan($recommendation['actionLabel']);
    }

    public function buildRecommendation(
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
}