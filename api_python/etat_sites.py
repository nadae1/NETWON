# api_python/etat_sites.py
"""
✅ REFACTORISÉ : la logique de calcul des états/alertes est désormais
centralisée dans traitement.py (analyser_etats_et_alertes / calculer_etat_avance).
Avant, ce fichier contenait une copie quasi identique de cette logique,
avec un risque réel de divergence si l'une des deux était modifiée sans
l'autre. Ce module ne fait plus que ré-exposer les mêmes noms pour ne
rien casser côté appelants existants (ex: script/commande qui importe
`etat_sites.analyser_et_sauvegarder` pour un recalcul manuel sans
réimporter le fichier trafic).
"""

from traitement import (
    DB_CONFIG,
    SEUIL_CONGESTION,
    SEUIL_OCCURRENCES_BRIDAGE,
    SEUIL_OCCURRENCES_VERIFICATION_CAPACITE,
    SEUIL_OCCURRENCES_RISQUE_CONGESTION,
    SEUIL_STABILITE_MONTANT,
    CAPACITE_10G_MBPS,
    extraire_prefixe,
    variation_pct,
    type_trans_manquant,
    construire_message_alerte,
    calculer_etat_avance,
    analyser_etats_et_alertes,
)

SEUIL_PLATEAU = SEUIL_STABILITE_MONTANT  # alias historique


def est_stable(a, b, seuil=SEUIL_STABILITE_MONTANT):
    reference = max(a, b, 1.0)
    return abs(a - b) / reference <= seuil


def type_trans_backbone(type_trans):
    valeur = str(type_trans or '').strip().upper()
    return valeur == 'BACKBONE' or valeur.startswith('BH')


def calculer_etat(trafic_j, trafic_j1, trafic_j7, capacite, occurrences=0, type_trans=''):
    """Conservé pour compatibilité ascendante (API simplifiée sans classification)."""
    etat, taux, var_j1, var_j7 = calculer_etat_avance(
        trafic_j, trafic_j1, trafic_j7, capacite, occurrences, classification=''
    )
    return etat, taux, var_j1, var_j7


def analyser_et_sauvegarder(sites_data):
    """Alias de compatibilité : délègue entièrement à traitement.py."""
    return analyser_etats_et_alertes(sites_data)