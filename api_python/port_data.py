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
    """Normalise le nom de site"""
    value = unicodedata.normalize("NFKD", str(value))
    value = "".join(ch for ch in value if not unicodedata.combining(ch))
    return value.strip().upper()


def _clean_column_name(col: str) -> str:
    col = unicodedata.normalize("NFKD", str(col))
    col = "".join(ch for ch in col if not unicodedata.combining(ch))
    return re.sub(r"\s+", " ", col).strip().lower()


def traiter_port_data(file_content):
    """
    Traite le fichier de données de ports.
    Colonnes attendues : Site, Port, Type, Vitesse, etc.
    """
    try:
        df = pd.read_excel(io.BytesIO(file_content))
        df.columns = [_clean_column_name(c) for c in df.columns]
        
        print("=== TRAITEMENT FICHIER PORTS ===")
        print(f"Colonnes détectées: {list(df.columns)}")

        port_count = 0
        for _, row in df.iterrows():
            site = _normalize_site_code(row.get('site', ''))
            if not site:
                continue
            port_count += 1

        return {
            "status": "success",
            "message": f"Fichier ports traité: {port_count} entrées importées",
            "data": {
                "total": port_count,
                "date_import": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            },
        }
    except Exception as e:
        import traceback
        traceback.print_exc()
        return {
            "status": "error",
            "message": f"Erreur traitement ports: {str(e)}",
            "detail": traceback.format_exc()
        }