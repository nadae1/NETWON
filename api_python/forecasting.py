# api_python/forecasting.py
import os
import time
from datetime import datetime
from pathlib import Path

import numpy as np
import pandas as pd
import psycopg2
import joblib
import lightgbm as lgb
from sklearn.metrics import mean_absolute_error

from traitement import extraire_prefixe

DB_CONFIG = {
    "host": os.getenv("DB_HOST", "127.0.0.1"),
    "port": int(os.getenv("DB_PORT", "5432")),
    "dbname": os.getenv("DB_NAME", "PFE_5G"),
    "user": os.getenv("DB_USER", "postgres"),
    "password": os.getenv("DB_PASSWORD", "root"),
}

MODEL_DIR = Path(__file__).resolve().parent / "models_forecast"
MODEL_DIR.mkdir(exist_ok=True)

HORIZONS = {
    "h24": 24,
    "d7": 168,
    "d30": 720,
}

FEATURES = [
    "hour", "day_of_week", "month", "is_weekend",
    "lag_1h", "lag_24h", "lag_168h",
    "rolling_mean_24h", "rolling_max_24h", "rolling_mean_168h",
]

# ---------------------------------------------------------------------------
# ✅ CORRECTIF : index préfixe -> sites bruts réellement présents dans
# trafic_historique. C'est CE mapping qu'il faut utiliser pour la croissance,
# pas processed_site.site_name/paired_site_name (qui contient le préfixe et
# ne correspond à AUCUNE ligne de trafic_historique, d'où le facteur de
# croissance qui retombait systématiquement à 1.0 / 0%).
# Construit une seule fois par process (ou après /ia/train), pas par site,
# pour éviter de scanner trafic_historique à chaque prédiction.
# ---------------------------------------------------------------------------
_RAW_SITES_INDEX: dict[str, list[str]] = {}
_RAW_SITES_INDEX_TS: float = 0.0
_RAW_SITES_INDEX_TTL = 600  # secondes


def _connect():
    return psycopg2.connect(**DB_CONFIG)


def refresh_raw_sites_index(force: bool = False) -> None:
    global _RAW_SITES_INDEX, _RAW_SITES_INDEX_TS
    if not force and _RAW_SITES_INDEX and (time.time() - _RAW_SITES_INDEX_TS) < _RAW_SITES_INDEX_TTL:
        return
    with _connect() as conn:
        df = pd.read_sql("SELECT DISTINCT site FROM trafic_historique", conn)
    index: dict[str, list[str]] = {}
    for raw_site in df["site"].tolist():
        raw_site = str(raw_site).strip()
        prefix = extraire_prefixe(raw_site) or raw_site
        index.setdefault(prefix, [])
        if raw_site not in index[prefix]:
            index[prefix].append(raw_site)
        # le préfixe lui-même peut aussi exister tel quel comme nom brut
        if raw_site == prefix:
            continue
    _RAW_SITES_INDEX = index
    _RAW_SITES_INDEX_TS = time.time()
    print(f"📇 Index préfixe→sites bruts reconstruit : {len(index)} préfixes")


def raw_sites_for_prefix(prefix: str) -> list[str]:
    """Renvoie les noms bruts (avec suffixe _TF/_TDD/_BO/...) présents dans
    trafic_historique qui correspondent à ce préfixe. C'est la fonction à
    utiliser pour toute prédiction/croissance liée à un site, à la place de
    processed_site.site_name / paired_site_name."""
    refresh_raw_sites_index()
    sites = list(_RAW_SITES_INDEX.get(prefix, []))
    if prefix not in sites and prefix in _RAW_SITES_INDEX.get(prefix, []):
        sites.append(prefix)
    return sites


def load_raw_history() -> pd.DataFrame:
    sql = """
        SELECT site, date_heure, max_speed
        FROM trafic_historique
        WHERE max_speed IS NOT NULL
        ORDER BY site, date_heure ASC
    """
    with _connect() as conn:
        df = pd.read_sql(sql, conn)
    df["date_heure"] = pd.to_datetime(df["date_heure"])
    return df


def build_features(df: pd.DataFrame) -> pd.DataFrame:
    """
    Avec un historique fragmenté (semaines non contiguës), les lags longs
    (168h) sont souvent NaN. Au lieu de supprimer ces lignes, on impute :
    ffill/bfill par site, puis repli sur la valeur courante elle-même.
    """
    df = df.sort_values(["site", "date_heure"]).copy()
    df["hour"] = df["date_heure"].dt.hour
    df["day_of_week"] = df["date_heure"].dt.dayofweek
    df["month"] = df["date_heure"].dt.month
    df["is_weekend"] = df["day_of_week"].isin([4, 5]).astype(int)

    g = df.groupby("site")["max_speed"]
    df["lag_1h"] = g.shift(1)
    df["lag_24h"] = g.shift(24)
    df["lag_168h"] = g.shift(168)
    df["rolling_mean_24h"] = g.transform(lambda x: x.shift(1).rolling(24, min_periods=1).mean())
    df["rolling_max_24h"] = g.transform(lambda x: x.shift(1).rolling(24, min_periods=1).max())
    df["rolling_mean_168h"] = g.transform(lambda x: x.shift(1).rolling(168, min_periods=1).mean())

    lag_cols = ["lag_1h", "lag_24h", "lag_168h", "rolling_mean_24h", "rolling_max_24h", "rolling_mean_168h"]
    df[lag_cols] = df.groupby("site")[lag_cols].transform(lambda x: x.ffill().bfill())

    for col in lag_cols:
        df[col] = df[col].fillna(df["max_speed"])

    for name, h in HORIZONS.items():
        df[f"target_{name}"] = g.shift(-h)

    return df


def train_all_horizons(min_rows_per_site: int = 15) -> dict:
    raw = load_raw_history()
    if raw.empty:
        return {"status": "error", "message": "trafic_historique est vide"}

    counts = raw.groupby("site").size()
    valid_sites = counts[counts >= min_rows_per_site].index
    raw = raw[raw["site"].isin(valid_sites)]

    if raw.empty:
        return {
            "status": "error",
            "message": f"Aucun site n'a au moins {min_rows_per_site} mesures. "
                       f"Nombre max de mesures pour un site : {int(counts.max()) if len(counts) else 0}",
        }

    df = build_features(raw)
    df["site"] = df["site"].astype("category")
    site_categories = df["site"].cat.categories.tolist()
    joblib.dump(site_categories, MODEL_DIR / "site_categories.pkl")

    results = {}
    for name, h in HORIZONS.items():
        target_col = f"target_{name}"
        data = df.dropna(subset=[target_col]).copy()

        print(f"[{name}] lignes disponibles après filtrage cible : {len(data)}")

        if len(data) < 50:
            results[name] = {
                "status": "error",
                "message": f"Pas assez de données ({len(data)} lignes). "
                           f"Il faut au moins {h}h de recul par site pour calculer cette cible.",
            }
            continue

        data = data.sort_values("date_heure")
        split_idx = int(len(data) * 0.85)
        train, test = data.iloc[:split_idx], data.iloc[split_idx:]

        if len(test) < 5:
            test = train.copy()

        cols = FEATURES + ["site"]
        train_set = lgb.Dataset(train[cols], label=train[target_col], categorical_feature=["site"])
        test_set = lgb.Dataset(test[cols], label=test[target_col], categorical_feature=["site"], reference=train_set)

        params = {
            "objective": "regression",
            "metric": "mae",
            "learning_rate": 0.05,
            "num_leaves": 31,
            "feature_fraction": 0.85,
            "bagging_fraction": 0.85,
            "bagging_freq": 5,
            "min_data_in_leaf": 10,
            "verbosity": -1,
        }

        model = lgb.train(
            params, train_set, num_boost_round=300,
            valid_sets=[test_set],
            callbacks=[lgb.early_stopping(20, verbose=False), lgb.log_evaluation(0)],
        )

        preds = model.predict(test[cols], num_iteration=model.best_iteration)
        mae = float(mean_absolute_error(test[target_col], preds))
        wape = float(np.sum(np.abs(test[target_col] - preds)) / max(np.sum(np.abs(test[target_col])), 1))

        joblib.dump(model, MODEL_DIR / f"model_{name}.pkl")
        results[name] = {
            "status": "ok",
            "mae_mbps": round(mae, 2),
            "wape_pct": round(wape * 100, 2),
            "train_rows": len(train),
            "test_rows": len(test),
        }
        print(f"[{name}] MAE={mae:.2f} Mbps | WAPE={wape*100:.1f}% | train={len(train)} test={len(test)}")

    meta = {
        "trained_at": datetime.now().isoformat(),
        "total_sites": int(len(valid_sites)),
        "results": results,
    }
    joblib.dump(meta, MODEL_DIR / "training_meta.pkl")

    # ✅ on force la reconstruction de l'index préfixe->sites bruts après un
    # (ré)entraînement, puisque l'historique a pu changer entre-temps.
    refresh_raw_sites_index(force=True)

    return {"status": "success", **meta}


def models_ready(horizon_name: str | None = None) -> bool:
    if not (MODEL_DIR / "site_categories.pkl").exists():
        return False
    if horizon_name:
        return (MODEL_DIR / f"model_{horizon_name}.pkl").exists()
    return any((MODEL_DIR / f"model_{n}.pkl").exists() for n in HORIZONS)


def _latest_features_for_site(site: str) -> pd.DataFrame | None:
    sql = """
        SELECT site, date_heure, max_speed
        FROM trafic_historique
        WHERE site = %s
        ORDER BY date_heure DESC
        LIMIT 200
    """
    with _connect() as conn:
        df = pd.read_sql(sql, conn, params=(site,))
    if df.empty:
        return None
    df = df.sort_values("date_heure")
    df["date_heure"] = pd.to_datetime(df["date_heure"])
    feat = build_features(df)
    return feat.iloc[[-1]]


def predict_raw_site(site: str, horizon_name: str) -> float | None:
    if not models_ready(horizon_name):
        return None
    row = _latest_features_for_site(site)
    if row is None:
        return None

    site_categories = joblib.load(MODEL_DIR / "site_categories.pkl")
    row = row.copy()
    if site not in site_categories:
        # Le site n'a pas assez d'historique pour avoir été inclus à
        # l'entraînement (< min_rows_per_site). C'est normal, pas une
        # erreur : l'appelant doit basculer sur le fallback (growth=1.0,
        # projection_fiable=False).
        return None
    row["site"] = pd.Categorical([site], categories=site_categories)

    model_path = MODEL_DIR / f"model_{horizon_name}.pkl"
    if not model_path.exists():
        return None
    model = joblib.load(model_path)
    cols = FEATURES + ["site"]
    for c in cols:
        if c not in row.columns:
            row[c] = 0
    pred = float(model.predict(row[cols])[0])
    return max(0.0, pred)


def current_raw_value(site: str) -> float:
    row = _latest_features_for_site(site)
    if row is None or row.empty:
        return 0.0
    return float(row["max_speed"].iloc[0])