# api_python/validate_predictions.py
"""
Backtesting : évalue la qualité de la classification d'état (CONGESTION/
RISQUE/BRIDAGE/OK) obtenue en réappliquant calculer_etat_avance() sur les
prédictions de trafic, comparée à l'état réellement observé plus tard.

Simplification assumée (à documenter dans le rapport) : on évalue ici le
franchissement du seuil de congestion (taux >= 90%) en isolant l'effet du
trafic prédit, car les occurrences dépendent de la fenêtre d'agrégation du
pipeline métier (traitement.py) et ne peuvent pas être reconstituées à
l'identique rétroactivement pour chaque point temporel. La composante
BRIDAGE (basée sur occurrences) est donc évaluée séparément en conditions
réelles (voir validate_realworld.py), pas ici.
"""
import numpy as np
import pandas as pd
from sklearn.metrics import (
    accuracy_score, precision_score, recall_score, f1_score,
    confusion_matrix, classification_report,
)

from forecasting import load_raw_history, build_features, FEATURES, HORIZONS, MODEL_DIR
import joblib


def evaluate_horizon(horizon_name: str, capacity_lookup: dict[str, float]) -> dict:
    """
    capacity_lookup : dict {nom_site_brut: capacite_mbps} pour pouvoir
    calculer un taux d'utilisation réel et prédit. À construire depuis
    capacite_site / processed_site avant l'appel (voir plus bas).
    """
    raw = load_raw_history()
    df = build_features(raw)

    target_col = f"target_{horizon_name}"
    data = df.dropna(subset=[target_col]).copy()
    if len(data) < 50:
        return {"status": "error", "message": "Pas assez de données pour ce backtest"}

    data = data.sort_values("date_heure")
    split_idx = int(len(data) * 0.85)
    test = data.iloc[split_idx:].copy()  # même split que l'entraînement -> zéro fuite

    site_categories = joblib.load(MODEL_DIR / "site_categories.pkl")
    model = joblib.load(MODEL_DIR / f"model_{horizon_name}.pkl")

    test = test[test["site"].isin(site_categories)].copy()
    test["site"] = pd.Categorical(test["site"], categories=site_categories)

    cols = FEATURES + ["site"]
    test["predicted"] = model.predict(test[cols])
    test["predicted"] = test["predicted"].clip(lower=0)

    # Capacité par site brut (fallback 1000 Mbps si inconnue, à ajuster)
    test["capacite"] = test["site"].astype(str).map(capacity_lookup).fillna(1000.0)

    test["taux_reel"] = test[target_col] / test["capacite"] * 100
    test["taux_predit"] = test["predicted"] / test["capacite"] * 100

    SEUIL = 90.0  # même seuil que SEUIL_CONGESTION dans traitement.py
    y_true = (test["taux_reel"] >= SEUIL).astype(int)
    y_pred = (test["taux_predit"] >= SEUIL).astype(int)

    acc = accuracy_score(y_true, y_pred)
    prec = precision_score(y_true, y_pred, zero_division=0)
    rec = recall_score(y_true, y_pred, zero_division=0)
    f1 = f1_score(y_true, y_pred, zero_division=0)
    cm = confusion_matrix(y_true, y_pred).tolist()
    report = classification_report(y_true, y_pred, target_names=["Normal", "Congestion"], output_dict=True, zero_division=0)

    return {
        "status": "ok",
        "horizon": horizon_name,
        "n_test": len(test),
        "accuracy": round(acc, 4),
        "precision_congestion": round(prec, 4),
        "recall_congestion": round(rec, 4),
        "f1_congestion": round(f1, 4),
        "confusion_matrix": cm,  # [[TN, FP], [FN, TP]]
        "classification_report": report,
    }


def build_capacity_lookup() -> dict[str, float]:
    """Construit un mapping site_brut -> capacité, à partir de capacite_site."""
    import psycopg2, os
    conn = psycopg2.connect(
        host=os.getenv("DB_HOST", "127.0.0.1"), port=int(os.getenv("DB_PORT", "5432")),
        dbname=os.getenv("DB_NAME", "PFE_5G"), user=os.getenv("DB_USER", "postgres"),
        password=os.getenv("DB_PASSWORD", "root"),
    )
    cur = conn.cursor()
    cur.execute("SELECT site, capacite_mbps FROM capacite_site WHERE capacite_mbps > 0")
    lookup = {row[0]: float(row[1]) for row in cur.fetchall()}
    cur.close()
    conn.close()
    return lookup


if __name__ == "__main__":
    capacity_lookup = build_capacity_lookup()
    for horizon in HORIZONS:
        result = evaluate_horizon(horizon, capacity_lookup)
        print(f"\n=== Horizon {horizon} ===")
        if result["status"] == "error":
            print(result["message"])
            continue
        print(f"Échantillon test : {result['n_test']} points")
        print(f"Accuracy       : {result['accuracy']}")
        print(f"Precision      : {result['precision_congestion']} (des congestions prédites, combien sont réelles)")
        print(f"Recall         : {result['recall_congestion']} (des vraies congestions, combien ont été détectées)")
        print(f"F1-score       : {result['f1_congestion']}")
        print(f"Matrice conf.  : {result['confusion_matrix']}")