# api_python/ia_service.py
import os
from datetime import datetime

import psycopg2

from forecasting import (
    predict_raw_site, current_raw_value, HORIZONS, models_ready,
    raw_sites_for_prefix,
)
from traitement import calculer_etat_avance

DB_CONFIG = {
    "host": os.getenv("DB_HOST", "127.0.0.1"),
    "port": int(os.getenv("DB_PORT", "5432")),
    "dbname": os.getenv("DB_NAME", "PFE_5G"),
    "user": os.getenv("DB_USER", "postgres"),
    "password": os.getenv("DB_PASSWORD", "root"),
}


def _connect():
    return psycopg2.connect(**DB_CONFIG)


def _ensure_prediction_table(cur):
    cur.execute("""
        CREATE TABLE IF NOT EXISTS site_prediction (
            id SERIAL PRIMARY KEY,
            site VARCHAR(255) NOT NULL,
            horizon VARCHAR(20) NOT NULL,
            horizon_heures INT NOT NULL,
            trafic_actuel_mbps NUMERIC(15,4),
            trafic_projete_mbps NUMERIC(15,4),
            capacite_mbps NUMERIC(15,4),
            taux_utilisation_projete_pct NUMERIC(8,2),
            facteur_croissance NUMERIC(8,4),
            occurrences_projetees INT,
            etat_predit VARCHAR(40),
            action_priorite VARCHAR(20),
            action_code VARCHAR(60),
            action_description TEXT,
            projection_fiable BOOLEAN DEFAULT true,
            date_prediction TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
        )
    """)
    cur.execute("""
        CREATE UNIQUE INDEX IF NOT EXISTS idx_site_prediction_site_horizon
        ON site_prediction (site, horizon)
    """)
    cur.execute("CREATE INDEX IF NOT EXISTS idx_site_prediction_etat ON site_prediction (etat_predit)")
    cur.execute("""
        SELECT column_name FROM information_schema.columns
        WHERE table_name='site_prediction' AND column_name='projection_fiable'
    """)
    if not cur.fetchone():
        cur.execute("ALTER TABLE site_prediction ADD COLUMN projection_fiable BOOLEAN DEFAULT true")


def _save_prediction(result: dict) -> None:
    conn = _connect()
    try:
        cur = conn.cursor()
        _ensure_prediction_table(cur)
        cur.execute("""
            INSERT INTO site_prediction (
                site, horizon, horizon_heures, trafic_actuel_mbps, trafic_projete_mbps,
                capacite_mbps, taux_utilisation_projete_pct, facteur_croissance,
                occurrences_projetees, etat_predit, action_priorite, action_code,
                action_description, projection_fiable, date_prediction
            ) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
            ON CONFLICT (site, horizon) DO UPDATE SET
                horizon_heures = EXCLUDED.horizon_heures,
                trafic_actuel_mbps = EXCLUDED.trafic_actuel_mbps,
                trafic_projete_mbps = EXCLUDED.trafic_projete_mbps,
                capacite_mbps = EXCLUDED.capacite_mbps,
                taux_utilisation_projete_pct = EXCLUDED.taux_utilisation_projete_pct,
                facteur_croissance = EXCLUDED.facteur_croissance,
                occurrences_projetees = EXCLUDED.occurrences_projetees,
                etat_predit = EXCLUDED.etat_predit,
                action_priorite = EXCLUDED.action_priorite,
                action_code = EXCLUDED.action_code,
                action_description = EXCLUDED.action_description,
                projection_fiable = EXCLUDED.projection_fiable,
                date_prediction = EXCLUDED.date_prediction
        """, (
            result["site"], result["horizon"], result["horizon_heures"],
            result["trafic_actuel_mbps"], result["trafic_projete_mbps"],
            result["capacite_mbps"], result["taux_utilisation_projete_pct"],
            result["facteur_croissance"], result["occurrences_projetees"],
            result["etat_predit"], result["action_recommandee"]["priorite"],
            result["action_recommandee"]["action"], result["action_recommandee"]["description"],
            result.get("projection_fiable", True),
            datetime.now(),
        ))
        conn.commit()
        cur.close()
    finally:
        conn.close()


def _get_latest_site_snapshot(site_prefix: str) -> dict | None:
    """Lecture SEULE depuis processed_site — aucune écriture."""
    sql = """
        SELECT site_name, paired_site_name, classification, type_trans,
               max_trafic, capacite_mbps, capacite_tdd_mbps, capacite_fdd_mbps,
               nombre_occurrences, nombre_occurrences_tdd, nombre_occurrences_fdd
        FROM processed_site
        WHERE site_name = %s
        ORDER BY id DESC
        LIMIT 1
    """
    with _connect() as conn:
        cur = conn.cursor()
        cur.execute(sql, (site_prefix,))
        row = cur.fetchone()
        if not row:
            return None
        cols = [
            "site_name", "paired_site_name", "classification", "type_trans",
            "max_trafic", "capacite_mbps", "capacite_tdd_mbps", "capacite_fdd_mbps",
            "nombre_occurrences", "nombre_occurrences_tdd", "nombre_occurrences_fdd",
        ]
        return dict(zip(cols, row))


def _growth_factor(site_prefix: str, fallback_raw_names: list[str], horizon_name: str) -> tuple[float, bool]:
    """
    ✅ CORRIGÉ : on résout d'abord les VRAIS noms bruts (avec suffixe) qui
    existent dans trafic_historique pour ce préfixe, via raw_sites_for_prefix().
    C'est ce qui manquait : avant, on utilisait processed_site.site_name
    (= le préfixe) directement comme nom brut, qui ne correspond à AUCUNE
    ligne de trafic_historique (qui stocke les eNodeB_Name avec suffixe) ->
    le facteur retombait systématiquement à 1.0 (croissance = 0%).

    fallback_raw_names sert de filet de sécurité si jamais l'index ne
    trouve rien (ex: table trafic_historique vidée entre deux imports).

    Retourne (facteur, projection_fiable). projection_fiable=False =
    aucune projection réelle possible (pas assez d'historique brut) :
    facteur=1.0 par prudence, ce n'est pas une vraie stagnation mesurée.
    """
    raw_names = raw_sites_for_prefix(site_prefix)
    if not raw_names:
        raw_names = fallback_raw_names

    ratios = []
    for raw_site in raw_names:
        if not raw_site:
            continue
        current = current_raw_value(raw_site)
        predicted = predict_raw_site(raw_site, horizon_name)
        if current and current > 0 and predicted is not None:
            ratios.append(predicted / current)

    if not ratios:
        return 1.0, False
    return sum(ratios) / len(ratios), True


def predict_site_evolution(site_prefix: str, horizon_name: str = "d7", persist: bool = True) -> dict:
    if horizon_name not in HORIZONS:
        return {"status": "error", "message": f"Horizon invalide. Choix: {list(HORIZONS.keys())}"}

    if not models_ready(horizon_name):
        return {
            "status": "error",
            "message": f"Le modèle pour l'horizon '{horizon_name}' n'est pas encore entraîné "
                       f"(historique insuffisant pour cette profondeur). Essayez 'h24' ou 'd7'.",
        }

    snapshot = _get_latest_site_snapshot(site_prefix)
    if not snapshot:
        return {"status": "error", "message": f"Site '{site_prefix}' introuvable dans processed_site"}

    fallback_raw_names = [n for n in {snapshot["site_name"], snapshot["paired_site_name"]} if n]
    growth, growth_ok = _growth_factor(site_prefix, fallback_raw_names, horizon_name)
    growth = max(0.3, min(growth, 3.0))

    trafic_j = float(snapshot["max_trafic"] or 0)
    capacite = float(snapshot["capacite_mbps"] or 0)
    occ_actuel = int(snapshot["nombre_occurrences"] or 0)
    occ_tdd_actuel = int(snapshot["nombre_occurrences_tdd"] or 0)
    occ_fdd_actuel = int(snapshot["nombre_occurrences_fdd"] or 0)
    classification = snapshot["classification"] or ""

    trafic_projete = round(trafic_j * growth, 2)
    horizon_heures = HORIZONS[horizon_name]
    occ_projete = min(horizon_heures, round(occ_actuel * growth))
    occ_tdd_projete = min(horizon_heures, round(occ_tdd_actuel * growth))
    occ_fdd_projete = min(horizon_heures, round(occ_fdd_actuel * growth))

    etat_predit, taux_predit, _, _ = calculer_etat_avance(
        trafic_j=trafic_projete, trafic_j1=trafic_j, trafic_j7=trafic_j,
        capacite=capacite, occurrences=occ_projete, classification=classification,
        occ_tdd=occ_tdd_projete, occ_fdd=occ_fdd_projete,
        taux_tdd=(occ_tdd_projete / max(horizon_heures, 1)) * 100 if occ_tdd_projete else 0,
        taux_fdd=(occ_fdd_projete / max(horizon_heures, 1)) * 100 if occ_fdd_projete else 0,
    )

    action = _recommander_action(etat_predit, snapshot)

    result = {
        "status": "success",
        "site": site_prefix,
        "horizon": horizon_name,
        "horizon_heures": horizon_heures,
        "trafic_actuel_mbps": round(trafic_j, 2),
        "trafic_projete_mbps": trafic_projete,
        "capacite_mbps": capacite,
        "taux_utilisation_projete_pct": round(taux_predit, 2),
        "facteur_croissance": round(growth, 3),
        "projection_fiable": growth_ok,
        "occurrences_projetees": occ_projete,
        "etat_predit": etat_predit,
        "action_recommandee": action,
        "date_prediction": datetime.now().isoformat(),
    }

    if persist:
        _save_prediction(result)

    return result


def _recommander_action(etat: str, snapshot: dict) -> dict:
    classification = (snapshot.get("classification") or "").upper()
    type_trans = (snapshot.get("type_trans") or "").upper().strip()
    type_manquant = type_trans in ("", "NON_DEFINI", "NONE", "N/A", "NA", "-")

    if type_manquant:
        return {
            "priorite": "MOYENNE",
            "action": "RENSEIGNER_TYPE_LIAISON",
            "description": "Type de transmission manquant : impossible de fiabiliser la décision de swap/upgrade tant que cette information n'est pas complétée.",
        }

    if etat == "CONGESTION":
        if classification in ("NO_COTRANS", "COTRANS"):
            return {
                "priorite": "URGENTE",
                "action": "SWAP_OU_AJOUT_LIEN",
                "description": "Congestion prévue sur un site à liens multiples (TDD/FDD) : envisager un swap vers un lien à plus forte capacité ou l'ajout d'un lien complémentaire.",
            }
        return {
            "priorite": "URGENTE",
            "action": "UPGRADE_CAPACITE",
            "description": "Congestion prévue : planifier un upgrade de capacité avant l'échéance de l'horizon prédit.",
        }

    if etat == "RISQUE_DE_CONGESTION":
        return {
            "priorite": "HAUTE",
            "action": "SURVEILLANCE_RENFORCEE",
            "description": "Le trafic projeté dépasse le seuil critique mais le nombre d'occurrences reste sous le seuil de confirmation.",
        }

    if etat == "BRIDAGE":
        return {
            "priorite": "HAUTE",
            "action": "VERIFIER_BRIDAGE_TRANSPORT",
            "description": "Occurrences élevées à taux modéré : suspicion de bridage transport/QoS.",
        }

    if etat.startswith("CONGESTION("):
        cote = "FDD" if "FDD" in etat else "TDD"
        return {
            "priorite": "URGENTE",
            "action": f"UPGRADE_CAPACITE_{cote}",
            "description": f"Congestion prévue côté {cote} : cibler l'upgrade sur cette liaison.",
        }

    return {"priorite": "FAIBLE", "action": "AUCUNE", "description": "Aucune anomalie prévue. Surveillance normale."}


def predict_all_sites(horizon_name: str = "d7", limit: int = 500, persist: bool = True) -> dict:
    if not models_ready(horizon_name):
        return {
            "status": "error",
            "message": f"Le modèle pour l'horizon '{horizon_name}' n'est pas disponible.",
            "total": 0, "errors": 0, "predictions": [],
        }

    sql = """
        SELECT DISTINCT ON (site_name) site_name
        FROM processed_site
        ORDER BY site_name, id DESC
        LIMIT %s
    """
    with _connect() as conn:
        cur = conn.cursor()
        cur.execute(sql, (limit,))
        sites = [r[0] for r in cur.fetchall()]

    results = []
    errors = 0
    fiables = 0
    for site in sites:
        try:
            res = predict_site_evolution(site, horizon_name, persist=persist)
            if res.get("status") == "success":
                results.append(res)
                if res.get("projection_fiable"):
                    fiables += 1
            else:
                errors += 1
        except Exception as e:
            errors += 1
            print(f"⚠️ Erreur prédiction {site}: {e}")

    print(f"📊 Batch {horizon_name} : {len(results)} sites, dont {fiables} avec projection fiable "
          f"({len(results) - fiables} en fallback growth=1.0)")

    return {"status": "success", "total": len(results), "errors": errors, "predictions": results}