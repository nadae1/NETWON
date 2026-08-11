import io
import re
import unicodedata
from datetime import datetime

import pandas as pd
import psycopg2
import os

DB_CONFIG = {
    "host": os.getenv("DB_HOST", "127.0.0.1"),
    "port": int(os.getenv("DB_PORT", "5432")),
    "dbname": os.getenv("DB_NAME", "PFE_5G"),
    "user": os.getenv("DB_USER", "postgres"),
    "password": os.getenv("DB_PASSWORD", "root"),
}


def _normalize_site_code(value) -> str:
    """Normalise le nom de site : accents supprimés, casse uniforme."""
    value = unicodedata.normalize("NFKD", str(value))
    value = "".join(ch for ch in value if not unicodedata.combining(ch))
    return value.strip().upper()


def _clean_column_name(col: str) -> str:
    col = unicodedata.normalize("NFKD", str(col))
    col = "".join(ch for ch in col if not unicodedata.combining(ch))
    return re.sub(r"\s+", " ", col).strip().lower()


def _convert_comma_to_dot(val):
    if isinstance(val, str):
        val = val.replace(",", ".")
    return pd.to_numeric(val, errors="coerce")


def _inserer_capacites_unifie_db(capacites):
    """
    Insère/MAJ dans capacite_site à partir du fichier unifié.
    Colonnes attendues : Site, Capacité TDD (Mbps), Capacité FDD (Mbps), Type Trans
    """
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        cur.execute("""
            CREATE TABLE IF NOT EXISTS capacite_site (
                id SERIAL PRIMARY KEY,
                site TEXT NOT NULL,
                capacite_mbps NUMERIC,
                type_trans TEXT,
                date_mise_ajour TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
            )
        """)
        cur.execute("""
            CREATE UNIQUE INDEX IF NOT EXISTS idx_capacite_site_site
            ON capacite_site (site)
        """)
        conn.commit()

        now = datetime.now()
        inserted_count = 0
        
        for cap in capacites:
            site = str(cap.get('site', '')).strip().upper()
            if not site:
                continue

            # Priorité : capacité TDD si présente, sinon FDD
            cap_value = float(cap.get('capacite_tdd', 0) or 0)
            if cap_value <= 0:
                cap_value = float(cap.get('capacite_fdd', 0) or 0)
            
            if cap_value <= 0:
                continue

            type_trans = str(cap.get('type_trans', 'NON_DEFINI')).strip()

            cur.execute("""
                INSERT INTO capacite_site (site, capacite_mbps, type_trans, date_mise_ajour)
                VALUES (%s, %s, %s, %s)
                ON CONFLICT (site) DO UPDATE SET
                    capacite_mbps = EXCLUDED.capacite_mbps,
                    type_trans = EXCLUDED.type_trans,
                    date_mise_ajour = EXCLUDED.date_mise_ajour
            """, (site, cap_value, type_trans, now))
            inserted_count += 1

        conn.commit()
        cur.close()
        conn.close()
        print(f"✅ {inserted_count} capacités insérées/mises à jour dans capacite_site")
        return inserted_count
    except Exception as e:
        print(f"⚠️ Erreur insertion capacite_site : {e}")
        import traceback
        traceback.print_exc()
        return 0


def traiter_capacite_unifie(file_content):
    """
    Traite le fichier unifié contenant les colonnes :
    Site, Classification, Type Trans, Max TDD, Max FDD, Trafic Max,
    Capacité TDD (Mbps), Capacité FDD (Mbps), Taux Utilisation %, etc.
    """
    try:
        workbook = pd.read_excel(io.BytesIO(file_content), sheet_name=None)
        print("=== TRAITEMENT FICHIER CAPACITÉS UNIFIÉ ===")
        print(f"Onglets trouvés: {list(workbook.keys())}")

        capacites = []
        total_lines = 0

        for sheet_name, df in workbook.items():
            df = df.copy()
            df.columns = [_clean_column_name(c) for c in df.columns]
            print(f"--- Onglet: {sheet_name} ---")
            
            # Colonnes attendues (variations tolérées)
            col_site = None
            col_cap_tdd = None
            col_cap_fdd = None
            col_type = None
            col_classification = None

            for col in df.columns:
                col_lower = col.lower()
                if 'site' in col_lower and col_site is None:
                    col_site = col
                if 'capacité tdd' in col_lower or ('capacite' in col_lower and 'tdd' in col_lower):
                    col_cap_tdd = col
                if 'capacité fdd' in col_lower or ('capacite' in col_lower and 'fdd' in col_lower):
                    col_cap_fdd = col
                if 'type trans' in col_lower or ('type' in col_lower and 'trans' in col_lower):
                    col_type = col
                if 'classification' in col_lower:
                    col_classification = col

            if not col_site:
                print(f"   ⚠️ Colonne 'Site' introuvable dans {sheet_name}")
                continue

            if not col_cap_tdd and not col_cap_fdd:
                print(f"   ⚠️ Aucune colonne de capacité trouvée dans {sheet_name}")
                continue

            sheet_count = 0
            for _, row in df.iterrows():
                site = _normalize_site_code(row.get(col_site))
                if not site or site == 'NAN':
                    continue

                cap_tdd = _convert_comma_to_dot(row.get(col_cap_tdd)) if col_cap_tdd else 0
                cap_fdd = _convert_comma_to_dot(row.get(col_cap_fdd)) if col_cap_fdd else 0
                
                cap_tdd = float(cap_tdd) if pd.notna(cap_tdd) else 0.0
                cap_fdd = float(cap_fdd) if pd.notna(cap_fdd) else 0.0

                if cap_tdd <= 0 and cap_fdd <= 0:
                    continue

                type_trans = str(row.get(col_type, 'NON_DEFINI')).strip() if col_type else 'NON_DEFINI'
                classification = str(row.get(col_classification, '')).strip() if col_classification else ''

                capacites.append({
                    'site': site,
                    'capacite_tdd': cap_tdd,
                    'capacite_fdd': cap_fdd,
                    'type_trans': type_trans,
                    'classification': classification,
                })
                sheet_count += 1
                total_lines += 1

            print(f"   📊 {sheet_count} lignes traitées dans {sheet_name}")

        # Déduplication : garder la dernière valeur pour chaque site
        dedup = {}
        for cap in capacites:
            site = cap['site']
            dedup[site] = cap

        capacites = list(dedup.values())

        if not capacites:
            return {
                "status": "error",
                "message": "Aucune donnée valide trouvée dans le fichier."
            }

        print(f"✅ {len(capacites)} capacités prêtes à être importées")

        inserted = _inserer_capacites_unifie_db(capacites)

        return {
            "status": "success",
            "message": f"Fichier unifié traité: {len(capacites)} sites ({inserted} insérés/mis à jour)",
            "data": {
                "capacites": capacites,
                "total": len(capacites),
                "inserted": inserted,
                "date_import": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            },
        }
    except Exception as e:
        import traceback
        traceback.print_exc()
        return {
            "status": "error",
            "message": f"Erreur: {str(e)}",
            "detail": traceback.format_exc()
        }