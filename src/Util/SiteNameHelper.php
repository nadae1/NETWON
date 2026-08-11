<?php

namespace App\Util;

/**
 * Reproduit exactement la logique de découpage des noms de site utilisée
 * côté Python (traitement.py::extraire_prefixe / est_site_tf / est_site_tdd / est_site_fdd).
 * ✅ Centralise ce qui était dupliqué (et légèrement divergent) dans
 * SuperuserMapController, ProcessedSiteRepository, etc.
 */
final class SiteNameHelper
{
    private const KNOWN_SUFFIXES = ['_LMB', '_LM2', '_LM', '_TDD', '_TC', '_TD', '_TF', '_FDD', '_TT'];
    private const BACKHAUL_SUFFIX_REGEX = '/_(BH|BO|TR|OO|OR|TT)(_[A-Z0-9]+)*$/i';

    public static function isTf(string $site): bool
    {
        return (bool) preg_match('/_tf$/i', $site);
    }

    public static function isTdd(string $site): bool
    {
        return (bool) preg_match('/_(TC|TD|TDD)$/i', $site);
    }

    public static function isFdd(string $site): bool
    {
        return (bool) preg_match('/_(LM|LMB|LM2|BO|BH|TR|OO|OR|TT)(_.+)?$/i', $site);
    }

    public static function extractPrefix(?string $site): string
    {
        $s = trim((string) $site);
        if ($s === '') {
            return '';
        }

        foreach (self::KNOWN_SUFFIXES as $suffix) {
            if (strcasecmp(substr($s, -strlen($suffix)), $suffix) === 0) {
                return trim(substr($s, 0, -strlen($suffix)));
            }
        }

        if (preg_match(self::BACKHAUL_SUFFIX_REGEX, $s, $m, PREG_OFFSET_CAPTURE)) {
            return trim(substr($s, 0, $m[0][1]));
        }

        return $s;
    }

    /**
     * Nettoyage utilisé pour matcher un nom de site brut issu d'un fichier
     * Excel (GPS, capacité...) avec les sites déjà connus en base.
     */
    public static function cleanSiteName(?string $site): string
    {
        return strtoupper(self::extractPrefix($site));
    }
}