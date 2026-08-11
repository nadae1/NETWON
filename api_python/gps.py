# api_python/gps.py
import io, os, re, unicodedata
from datetime import datetime
import pandas as pd
import psycopg2

DB_CONFIG = {
    "host": os.getenv("DB_HOST", "127.0.0.1"),
    "port": int(os.getenv("DB_PORT", "5432")),
    "dbname": os.getenv("DB_NAME", "PFE_5G"),
    "user": os.getenv("DB_USER", "postgres"),
    "password": os.getenv("DB_PASSWORD", "root"),
}


def _normalize_site(value: str) -> str:
    value = unicodedata.normalize("NFKD", str(value))
    value = "".join(ch for ch in value if not unicodedata.combining(ch))
    return value.strip().upper()


def _ensure_site_gps_table(cur):
    cur.execute("""
        CREATE TABLE IF NOT EXISTS site_gps (
            id SERIAL PRIMARY KEY,
            site VARCHAR(255) NOT NULL,
            latitude DOUBLE PRECISION NOT NULL,
            longitude DOUBLE PRECISION NOT NULL,
            date_import TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
        )
    """)
    cur.execute("CREATE UNIQUE INDEX IF NOT EXISTS idx_site_gps_site ON site_gps (site)")


def traiter_gps(file_content: bytes):
    try:
        df = pd.read_excel(io.BytesIO(file_content))
        df.columns = df.columns.str.strip()
        site_col = next((c for c in df.columns if 'site' in c.lower() or 'enodeb' in c.lower() or 'node' in c.lower()), df.columns[0])
        lat_col = next((c for c in df.columns if 'lat' in c.lower()), None)
        lng_col = next((c for c in df.columns if 'lon' in c.lower() or 'long' in c.lower()), None)

        if not lat_col or not lng_col:
            return {"status": "error", "message": "Colonnes latitude/longitude introuvables"}

        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        _ensure_site_gps_table(cur)
        conn.commit()

        now = datetime.now()
        n = 0
        for _, row in df.iterrows():
            site = _normalize_site(row[site_col])
            if not site or site == 'NAN':
                continue
            try:
                lat = float(row[lat_col])
                lng = float(row[lng_col])
            except (ValueError, TypeError):
                continue
            if lat == 0 or lng == 0:
                continue
            cur.execute("""
                INSERT INTO site_gps (site, latitude, longitude, date_import)
                VALUES (%s, %s, %s, %s)
                ON CONFLICT (site) DO UPDATE SET
                    latitude = EXCLUDED.latitude,
                    longitude = EXCLUDED.longitude,
                    date_import = EXCLUDED.date_import
            """, (site, lat, lng, now))
            n += 1

            # Reflète aussi sur la dernière analyse pour l'export / API existants
            cur.execute("""
                UPDATE analyse_resultat
                SET latitude = %s, longitude = %s
                WHERE date_analyse = (SELECT MAX(date_analyse) FROM analyse_resultat)
                  AND (UPPER(site) = %s OR UPPER(site_fdd) = %s OR UPPER(site_tdd) = %s)
            """, (lat, lng, site, site, site))

        conn.commit()
        cur.close(); conn.close()

        return {
            "status": "success",
            "message": f"GPS importe : {n} site(s) mis a jour",
            "data": {"total": n, "date_import": now.strftime('%Y-%m-%d %H:%M:%S')},
        }
    except Exception as e:
        import traceback
        traceback.print_exc()
        return {"status": "error", "message": str(e), "detail": traceback.format_exc()}