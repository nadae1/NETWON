# api_python/validate_realworld.py
"""
Compare les prédictions faites il y a exactement `horizon_heures` avec
l'état réellement observé aujourd'hui (calculé par le pipeline métier
sur les vraies données, via analyser_etats_et_alertes / site_etat).

À exécuter périodiquement (ex: chaque jour) une fois que tu as accumulé
plusieurs semaines de prédictions ET de nouveaux imports réels.
"""
import os
from datetime import datetime, timedelta
import psycopg2

DB_CONFIG = {
    "host": os.getenv("DB_HOST", "127.0.0.1"),
    "port": int(os.getenv("DB_PORT", "5432")),
    "dbname": os.getenv("DB_NAME", "PFE_5G"),
    "user": os.getenv("DB_USER", "postgres"),
    "password": os.getenv("DB_PASSWORD", "root"),
}

HORIZON_DAYS = {"h24": 1, "d7": 7, "d30": 30}


def compare_predictions_vs_reality(horizon_name: str, tolerance_hours: int = 12) -> dict:
    days = HORIZON_DAYS[horizon_name]
    target_date = datetime.now() - timedelta(days=days)
    window_start = target_date - timedelta(hours=tolerance_hours)
    window_end = target_date + timedelta(hours=tolerance_hours)

    conn = psycopg2.connect(**DB_CONFIG)
    cur = conn.cursor()

    # Prédictions faites il y a exactement `days` jours pour cet horizon
    cur.execute("""
        SELECT site, etat_predit
        FROM site_prediction
        WHERE horizon = %s
          AND date_prediction BETWEEN %s AND %s
    """, (horizon_name, window_start, window_end))
    predicted = {row[0]: row[1] for row in cur.fetchall()}

    if not predicted:
        cur.close(); conn.close()
        return {"status": "error", "message": f"Aucune prédiction '{horizon_name}' trouvée à comparer (il y a {days}j)."}

    # État réel actuel (calculé par le pipeline métier sur les vraies données)
    sites = list(predicted.keys())
    cur.execute("""
        SELECT site, etat FROM site_etat WHERE site = ANY(%s)
    """, (sites,))
    actual = {row[0]: row[1] for row in cur.fetchall()}
    cur.close(); conn.close()

    matches, mismatches, missing = 0, 0, 0
    details = []
    for site, pred_etat in predicted.items():
        real_etat = actual.get(site)
        if real_etat is None:
            missing += 1
            continue
        is_match = (pred_etat == real_etat)
        if is_match:
            matches += 1
        else:
            mismatches += 1
        details.append({"site": site, "predit": pred_etat, "reel": real_etat, "match": is_match})

    total_compared = matches + mismatches
    accuracy = round(matches / total_compared, 4) if total_compared else None

    return {
        "status": "ok",
        "horizon": horizon_name,
        "total_predictions": len(predicted),
        "compares": total_compared,
        "sites_sans_donnee_recente": missing,
        "matches": matches,
        "mismatches": mismatches,
        "accuracy_etat": accuracy,
        "details_divergences": [d for d in details if not d["match"]][:30],  # échantillon
    }


if __name__ == "__main__":
    for horizon in ["h24", "d7", "d30"]:
        result = compare_predictions_vs_reality(horizon)
        print(f"\n=== {horizon} ===")
        print(result if result["status"] == "error" else {
            k: v for k, v in result.items() if k != "details_divergences"
        })
        if result.get("status") == "ok" and result["details_divergences"]:
            print("Exemples de divergences :")
            for d in result["details_divergences"][:5]:
                print(f"  {d['site']}: prédit={d['predit']} vs réel={d['reel']}")