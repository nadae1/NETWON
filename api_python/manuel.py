# api_python/manuel.py
import os
from datetime import datetime
import psycopg2

DB_CONFIG = {
    "host": os.getenv("DB_HOST", "127.0.0.1"),
    "port": int(os.getenv("DB_PORT", "5432")),
    "dbname": os.getenv("DB_NAME", "PFE_5G"),
    "user": os.getenv("DB_USER", "postgres"),
    "password": os.getenv("DB_PASSWORD", "root"),
}


def maj_site_manuel(site: str, capacite_tdd=None, capacite_fdd=None, type_trans=None):
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
        cur.execute("CREATE UNIQUE INDEX IF NOT EXISTS idx_capacite_site_site ON capacite_site (site)")
        cur.execute("""
            CREATE TABLE IF NOT EXISTS type_liaison_data (
                id SERIAL PRIMARY KEY,
                site VARCHAR(255) NOT NULL,
                type_trans VARCHAR(255),
                date_import TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
            )
        """)
        cur.execute("CREATE UNIQUE INDEX IF NOT EXISTS idx_type_liaison_site ON type_liaison_data (site)")
        conn.commit()

        now = datetime.now()
        site = str(site).strip()

        total = float(capacite_tdd or 0) + float(capacite_fdd or 0)
        if total > 0:
            cur.execute("""
                INSERT INTO capacite_site (site, capacite_mbps, type_trans, date_mise_ajour)
                VALUES (%s, %s, %s, %s)
                ON CONFLICT (site) DO UPDATE SET
                    capacite_mbps = EXCLUDED.capacite_mbps,
                    date_mise_ajour = EXCLUDED.date_mise_ajour
            """, (site, total, type_trans, now))

        if type_trans:
            cur.execute("""
                INSERT INTO type_liaison_data (site, type_trans, date_import)
                VALUES (%s, %s, %s)
                ON CONFLICT (site) DO UPDATE SET
                    type_trans = EXCLUDED.type_trans,
                    date_import = EXCLUDED.date_import
            """, (site, type_trans, now))

        conn.commit()
        cur.close(); conn.close()
        return {"status": "success", "message": f"Site {site} mis a jour manuellement"}
    except Exception as e:
        import traceback
        traceback.print_exc()
        return {"status": "error", "message": str(e)}