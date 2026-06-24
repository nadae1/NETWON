# api_python/traitement.py — VERSION COMPLÈTE FINALE
# Support : Excel (openpyxl/xlrd) et CSV (séparateur ; ou ,)
import pandas as pd
import io
import re
import json
from datetime import datetime, timedelta
import psycopg2
from psycopg2.extras import execute_values
import os
import numpy as np
import traceback

# ========== CONFIG BDD ==========
DB_CONFIG = {
    "host":     os.getenv("DB_HOST", "127.0.0.1"),
    "port":     int(os.getenv("DB_PORT", "5432")),
    "dbname":   os.getenv("DB_NAME", "PFE_5G"),
    "user":     os.getenv("DB_USER", "postgres"),
    "password": os.getenv("DB_PASSWORD", "root"),
}

SEUIL_CONGESTION = 0.85
SEUIL_PLATEAU = 0.05
SEUIL_STABILITE_MONTANT = 0.05

# ========== DÉTECTION TYPES ==========
def est_site_tf(site: str) -> bool:
    return bool(re.search(r'_tf$', str(site), re.IGNORECASE))

def est_site_tdd(site: str) -> bool:
    return bool(re.search(r'_(TC|TD|TDD)$', str(site), re.IGNORECASE))

def est_site_fdd(site: str) -> bool:
    return bool(re.search(r'_(LM|LMB|LM2|BO|BH|TR|OO|OR|TT)(_.+)?$', str(site), re.IGNORECASE))

def a_suffixe_connu(site: str) -> bool:
    return est_site_tf(site) or est_site_tdd(site) or est_site_fdd(site)

def normaliser_type_trans(valeur: str) -> str:
    return str(valeur).strip()

def extraire_prefixe(site: str) -> str:
    if not site or (isinstance(site, float) and pd.isna(site)):
        return ''
    s = str(site).strip()
    for suf in ['_LMB', '_LM2', '_LM', '_TDD', '_TC', '_TD', '_TF', '_FDD', '_TT']:
        if s.upper().endswith(suf.upper()):
            return s[:len(s)-len(suf)].strip()
    m = re.search(r'_(BH|BO|TR|OO|OR|TT)(_[A-Z0-9]+)*$', s, re.IGNORECASE)
    if m:
        return s[:m.start()].strip()
    return s

def to_py(val):
    if val is None:
        return None
    try:
        if pd.isna(val):
            return None
    except Exception:
        pass
    if isinstance(val, (np.integer, np.floating)):
        return val.item()
    return val

def get_type_trans_for_prefix(prefix, type_dict, capacite_dict=None):
    if prefix in type_dict:
        return type_dict[prefix]
    parts = prefix.split('_')
    for i in range(len(parts)-1, 0, -1):
        key = '_'.join(parts[:i])
        if key in type_dict:
            return type_dict[key]
    if capacite_dict:
        if prefix in capacite_dict:
            return capacite_dict[prefix]
        for i in range(len(parts)-1, 0, -1):
            key = '_'.join(parts[:i])
            if key in capacite_dict:
                return capacite_dict[key]
    return 'NON_DEFINI'

# ========== FONCTIONS POUR LES CAPACITÉS ==========
def get_capacite_from_port_data(nom_site: str, port_info: dict) -> float:
    if not nom_site or nom_site not in port_info:
        return 0.0
    ports = port_info[nom_site]
    if '_TF' in str(nom_site).upper():
        capacite = ports.get('port0', 0) + ports.get('port1', 0)
    else:
        capacite = max(ports.get('port0', 0), ports.get('port1', 0))
    return float(capacite)

def charger_capacites_from_port_data(port_info: dict) -> dict:
    capacites = {}
    for site, ports in port_info.items():
        if '_TF' in str(site).upper():
            capa = ports.get('port0', 0) + ports.get('port1', 0)
        else:
            capa = max(ports.get('port0', 0), ports.get('port1', 0))
        if capa > 0:
            capacites[site] = capa
            prefix = extraire_prefixe(site)
            if prefix and prefix != site:
                if prefix not in capacites or capacites[prefix] < capa:
                    capacites[prefix] = capa
    return capacites

def charger_coordonnees_gps(file_content) -> dict:
    if not file_content:
        print("   📍 Aucun fichier GPS fourni")
        return {}
    try:
        if isinstance(file_content, bytes):
            df = pd.read_excel(io.BytesIO(file_content))
        else:
            df = pd.read_excel(file_content)
        print(f"   📍 Fichier GPS chargé: {len(df)} lignes")
        df.columns = df.columns.str.strip()
        site_col = None
        for col in df.columns:
            if 'site' in col.lower() or 'enodeb' in col.lower() or 'node' in col.lower():
                site_col = col
                break
        if not site_col:
            site_col = df.columns[0]
        lat_col = None
        lng_col = None
        for col in df.columns:
            if 'lat' in col.lower():
                lat_col = col
            elif 'lon' in col.lower() or 'long' in col.lower():
                lng_col = col
        if not lat_col or not lng_col:
            print("   ⚠️ Colonnes lat/long non trouvées")
            return {}
        coords_dict = {}
        for _, row in df.iterrows():
            site = str(row[site_col]).strip()
            if not site or site == 'nan':
                continue
            try:
                lat = float(row[lat_col])
                lng = float(row[lng_col])
                if lat != 0 and lng != 0:
                    coords_dict[site] = {'latitude': lat, 'longitude': lng}
            except (ValueError, TypeError):
                continue
        print(f"   ✅ GPS chargé: {len(coords_dict)} sites")
        return coords_dict
    except Exception as e:
        print(f"   ❌ Erreur chargement GPS: {e}")
        return {}

# ========== LECTURE TRAFIC (ROBUSTE) ==========
def lire_excel_trafic_directement(file_content: bytes) -> pd.DataFrame:
    df = None
    # 1. Essayer Excel
    for engine in ['openpyxl', 'xlrd', None]:
        try:
            kw = {'engine': engine} if engine else {}
            df = pd.read_excel(io.BytesIO(file_content), **kw)
            if df is not None:
                break
        except:
            continue
    # 2. Fallback CSV (séparateur ; ou ,)
    if df is None:
        try:
            df = pd.read_csv(io.BytesIO(file_content), sep=';', encoding='utf-8')
        except:
            try:
                df = pd.read_csv(io.BytesIO(file_content), sep=',', encoding='utf-8')
            except Exception as e:
                raise ValueError("Impossible de lire le fichier. Vérifiez le format (Excel ou CSV).") from e

    if df is None:
        raise ValueError("Impossible de lire le fichier Excel. Vérifiez le format.")

    df.columns = df.columns.str.strip()
    rename_map = {}
    for col in df.columns:
        cl = col.lower()
        if 'enodeb' in cl or ('name' in cl and 'site' not in cl):
            rename_map[col] = 'eNodeB_Name'
        elif 'maxspeed' in cl or 'rxmaxspeed' in cl or 'mbit' in cl or 'rxmaxspeed_mbs' in cl:
            rename_map[col] = 'MaxSpeed'
    df = df.rename(columns=rename_map)

    time_col = next((c for c in df.columns if 'time' in c.lower() or 'date' in c.lower()), df.columns[0])
    df['DateTime'] = pd.to_datetime(df[time_col], dayfirst=True, errors='coerce')
    df = df.dropna(subset=['DateTime'])

    if 'MaxSpeed' not in df.columns:
        for col in df.columns:
            try:
                test = df[col].iloc[0]
                if isinstance(test, (int, float)) or str(test).replace(',', '').replace('.', '').isdigit():
                    df = df.rename(columns={col: 'MaxSpeed'})
                    break
            except:
                continue
    if 'MaxSpeed' not in df.columns:
        df['MaxSpeed'] = df.iloc[:, -1]

    df['MaxSpeed'] = pd.to_numeric(
        df['MaxSpeed'].astype(str).str.replace(',', '.', regex=False)
                                  .str.replace(r'[^\d.\-]', '', regex=True),
        errors='coerce'
    )
    df = df.dropna(subset=['MaxSpeed'])
    df = df[df['MaxSpeed'] >= 0]

    avant = len(df)
    df = df[~df['eNodeB_Name'].str.contains('L2B', na=False, case=False)]
    print(f"🚫 Sites L2B supprimés : {avant - len(df)}")
    print(f"📊 Trafic chargé : {len(df)} mesures")
    return df

# ========== CHARGEMENT BDD ==========
def charger_port_data_as_dict() -> dict:
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        cur.execute("SELECT site, port_no, trafic FROM port_data")
        rows = cur.fetchall()
        cur.close()
        conn.close()
        port_info = {}
        for site, port_no, trafic in rows:
            s = str(site).strip()
            if s not in port_info:
                port_info[s] = {'port0': 0, 'port1': 0}
            if port_no == 0:
                port_info[s]['port0'] = float(trafic) if trafic else 0
            elif port_no == 1:
                port_info[s]['port1'] = float(trafic) if trafic else 0
        print(f"📡 Port data : {len(port_info)} sites")
        return port_info
    except Exception as e:
        print(f"⚠️ port_data : {e}")
        return {}

def charger_type_liaison_as_dict() -> dict:
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        cur.execute("SELECT site, type_trans FROM type_liaison_data")
        rows = cur.fetchall()
        cur.close()
        conn.close()
        type_dict = {}
        for site, type_trans in rows:
            s = str(site).strip()
            val = normaliser_type_trans(type_trans)
            type_dict[s] = val
            p = extraire_prefixe(s)
            if p and p != s:
                type_dict[p] = val
        print(f"📡 Type liaison : {len(type_dict)} entrées")
        return type_dict
    except Exception as e:
        print(f"⚠️ type_liaison : {e}")
        return {}

def charger_capacite_as_dict() -> dict:
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        cur.execute("""
            SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'capacite_site')
        """)
        table_exists = cur.fetchone()[0]
        if not table_exists:
            cur.close()
            conn.close()
            return {}
        cur.execute("SELECT site, type_trans FROM capacite_site WHERE type_trans IS NOT NULL")
        rows = cur.fetchall()
        cur.close()
        conn.close()
        capacite_dict = {}
        for site, type_trans in rows:
            s = str(site).strip()
            if type_trans:
                capacite_dict[s] = normaliser_type_trans(str(type_trans))
                p = extraire_prefixe(s)
                if p and p != s:
                    capacite_dict[p] = normaliser_type_trans(str(type_trans))
        print(f"📡 Capacite_site : {len(capacite_dict)} entrées")
        return capacite_dict
    except Exception as e:
        print(f"⚠️ capacite_site : {e}")
        return {}

def update_port_data(file_content: bytes):
    try:
        df = pd.read_excel(io.BytesIO(file_content))
        df.columns = df.columns.str.strip()
        site_col = next((c for c in df.columns if 'enodeb' in c.lower() or 'name' in c.lower()), df.columns[0])
        port_col = next((c for c in df.columns if 'port' in c.lower()), None)
        traf_col = next((c for c in df.columns if 'speed' in c.lower() or 'trafic' in c.lower() or 'mbit' in c.lower()), None)
        if not port_col or not traf_col:
            print("⚠️ Colonnes port introuvables")
            return
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        n = 0
        for _, row in df.iterrows():
            site = str(row[site_col]).strip()
            if not site or site == 'nan':
                continue
            try:
                cur.execute("""
                    INSERT INTO port_data (site, port_no, trafic, date_import)
                    VALUES (%s,%s,%s,%s)
                    ON CONFLICT (site, port_no) DO UPDATE SET trafic=EXCLUDED.trafic
                """, (site, int(float(row[port_col])),
                      float(str(row[traf_col]).replace(',', '.')), datetime.now()))
                n += 1
            except:
                continue
        conn.commit()
        cur.close()
        conn.close()
        print(f"   ✅ port_data MAJ : {n}")
    except Exception as e:
        print(f"   ❌ port_data: {e}")

def update_type_liaison_data(file_content: bytes):
    try:
        df = pd.read_excel(io.BytesIO(file_content))
        df.columns = df.columns.str.strip()
        col_site = next((c for c in df.columns if 'site' in c.lower() or 'node' in c.lower()), df.columns[0])
        col_type = next((c for c in df.columns if 'type' in c.lower() or 'liaison' in c.lower()),
                        df.columns[1] if len(df.columns) > 1 else col_site)
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        n = 0
        for _, row in df.iterrows():
            site = str(row[col_site]).strip()
            if not site or site == 'nan':
                continue
            t = str(row[col_type]).strip()
            if not t:
                continue
            cur.execute("""
                UPDATE type_liaison_data
                SET type_trans = %s, date_import = %s
                WHERE site = %s
            """, (t, datetime.now(), site))
            if cur.rowcount == 0:
                cur.execute("""
                    INSERT INTO type_liaison_data (site, type_trans, date_import)
                    VALUES (%s, %s, %s)
                """, (site, t, datetime.now()))
            n += 1
        conn.commit()
        cur.close()
        conn.close()
        print(f"   ✅ type_liaison MAJ : {n}")
    except Exception as e:
        print(f"   ❌ type_liaison: {e}")

def inserer_analyse_resultat(sites_data):
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        for col, defn in [
            ('stats_json', 'JSONB'),
            ('max_trafic_fdd', 'NUMERIC(15,4)'),
            ('max_trafic_tdd', 'NUMERIC(15,4)'),
            ('capacite_mbps', 'NUMERIC(15,4) DEFAULT 0'),
        ]:
            cur.execute(f"""
                SELECT column_name FROM information_schema.columns
                WHERE table_name='analyse_resultat' AND column_name='{col}'
            """)
            if not cur.fetchone():
                cur.execute(f"ALTER TABLE analyse_resultat ADD COLUMN {col} {defn}")
                conn.commit()
                print(f"✅ Colonne {col} ajoutée")

        date_analyse = datetime.now()
        count = 0
        for site in sites_data:
            cur.execute("""
                INSERT INTO analyse_resultat
                (site, classification, type_trans, max_trafic, seuil_critique,
                 nombre_occurrences, total_measures, date_max, site_tdd, site_fdd,
                 somme_fdd_tdd, date_analyse, latitude, longitude,
                 max_trafic_fdd, max_trafic_tdd, capacite_mbps)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
            """, (
                to_py(site.get('Site')),
                to_py(site.get('Classification')),
                to_py(site.get('Type_Trans')),
                to_py(site.get('Max_Trafic_Final', 0)),
                to_py(site.get('Seuil_Critique', 0)),
                to_py(site.get('Nombre_Occurrences', 0)),
                to_py(site.get('Total_Measures', 0)),
                to_py(site.get('Date_Max')),
                to_py(site.get('Site_TDD')),
                to_py(site.get('Site_FDD')),
                to_py(site.get('Somme_FDD_TDD')),
                date_analyse,
                to_py(site.get('Latitude')),
                to_py(site.get('Longitude')),
                to_py(site.get('Max_Trafic_FDD', 0)),
                to_py(site.get('Max_Trafic_TDD', 0)),
                to_py(site.get('Capacite_Mbps', 0)),
            ))
            count += 1
        conn.commit()
        cur.close()
        conn.close()
        print(f"✅ {count} lignes insérées dans analyse_resultat")
        return count
    except Exception as e:
        print(f"⚠️ Insertion analyse_resultat : {e}")
        traceback.print_exc()
        return 0

def inserer_trafic_historique(sites_data):
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        date_jour = datetime.now().date()
        count = 0
        cur.execute("""
            SELECT column_name FROM information_schema.columns 
            WHERE table_name = 'trafic_historique' AND column_name = 'date_jour'
        """)
        has_date_jour = cur.fetchone() is not None
        for site in sites_data:
            site_name = site.get('Site')
            max_trafic = site.get('Max_Trafic_Final', 0)
            capacite = site.get('Capacite_Mbps', 0)
            classification = site.get('Classification', '')
            type_trans = site.get('Type_Trans', '')
            if not site_name:
                continue
            if has_date_jour:
                cur.execute("""
                    INSERT INTO trafic_historique 
                        (site, date_jour, max_trafic, capacite_mbps, classification, type_trans)
                    VALUES (%s, %s, %s, %s, %s, %s)
                    ON CONFLICT (site, date_jour) DO UPDATE SET
                        max_trafic = EXCLUDED.max_trafic,
                        capacite_mbps = EXCLUDED.capacite_mbps,
                        classification = EXCLUDED.classification,
                        type_trans = EXCLUDED.type_trans
                """, (site_name, date_jour, float(max_trafic), float(capacite), classification, type_trans))
            else:
                continue
            count += 1
        conn.commit()
        cur.close()
        conn.close()
        print(f"✅ Historique inséré : {count} sites")
        return count
    except Exception as e:
        print(f"⚠️ Erreur insertion historique : {e}")
        traceback.print_exc()
        return 0

def inserer_trafic_historique_brut(df_trafic: pd.DataFrame) -> int:
    try:
        if df_trafic.empty:
            return 0
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        date_import = datetime.now()
        count = 0
        raw = df_trafic[['eNodeB_Name', 'DateTime', 'MaxSpeed']].copy()
        raw['eNodeB_Name'] = raw['eNodeB_Name'].astype(str).str.strip()
        raw = raw.dropna(subset=['DateTime', 'MaxSpeed'])
        raw = raw[raw['eNodeB_Name'] != '']
        raw = raw.drop_duplicates(subset=['eNodeB_Name', 'DateTime'], keep='last')
        sites = raw['eNodeB_Name'].dropna().unique().tolist()
        min_date = raw['DateTime'].min()
        max_date = raw['DateTime'].max()
        if hasattr(min_date, 'to_pydatetime'):
            min_date = min_date.to_pydatetime()
        if hasattr(max_date, 'to_pydatetime'):
            max_date = max_date.to_pydatetime()
        if sites:
            cur.execute(
                """
                DELETE FROM trafic_historique
                WHERE site = ANY(%s)
                  AND date_heure BETWEEN %s AND %s
                """,
                (sites, min_date, max_date)
            )
        rows = []
        for row in raw.itertuples(index=False):
            date_heure = row.DateTime
            if hasattr(date_heure, 'to_pydatetime'):
                date_heure = date_heure.to_pydatetime()
            rows.append((str(row.eNodeB_Name).strip(), date_heure, float(row.MaxSpeed), date_import))
        if rows:
            execute_values(
                cur,
                """
                INSERT INTO trafic_historique
                    (site, date_heure, max_speed, date_importation)
                VALUES %s
                """,
                rows,
                page_size=10000
            )
            count = len(rows)
        conn.commit()
        cur.close()
        conn.close()
        print(f"✅ Historique brut inséré : {count} mesures")
        return count
    except Exception as e:
        print(f"⚠️ Erreur insertion historique brut : {e}")
        traceback.print_exc()
        return 0

# ========== ANALYSE ÉTATS ET ALERTES ==========
def variation_pct(a: float, b: float) -> float:
    if a <= 0:
        return 0
    return abs(a - b) / a

def est_stable(a: float, b: float, seuil: float = SEUIL_STABILITE_MONTANT) -> bool:
    reference = max(a, b, 1.0)
    return abs(a - b) / reference <= seuil

def calculer_etat(trafic_j: float, trafic_j1: float, trafic_j7: float, capacite: float) -> tuple:
    taux = (trafic_j / capacite * 100) if capacite > 0 else 0
    var_j1 = variation_pct(trafic_j, trafic_j1) * 100
    var_j7 = variation_pct(trafic_j, trafic_j7) * 100
    if capacite <= 0:
        return 'OK', taux, var_j1, var_j7
    if trafic_j >= capacite * SEUIL_CONGESTION and est_stable(trafic_j, trafic_j1):
        if trafic_j1 >= trafic_j7:
            return 'CONGESTIONNE', taux, var_j1, var_j7
    if trafic_j7 > trafic_j1 and est_stable(trafic_j, trafic_j1):
        return 'BRIDE', taux, var_j1, var_j7
    return 'OK', taux, var_j1, var_j7

def type_trans_manquant(type_trans: str) -> bool:
    if type_trans is None:
        return True
    valeur = str(type_trans).strip().upper()
    return valeur in ('', 'NON_DEFINI', 'NONE', 'N/A', 'NA', '-')

def construire_message_alerte(etat: str, nom: str, trafic_j: float, capacite: float, taux: float, type_trans: str) -> str:
    if etat == 'SANS_TYPE':
        detail_type = 'type de liaison non defini'
        if type_trans and str(type_trans).strip():
            detail_type = f'type de liaison manquant ({str(type_trans).strip()})'
        return f"⚪ SITE SANS TYPE - {nom}: {detail_type}"
    if etat == 'CONGESTIONNE':
        return f"🔴 CONGESTION - {nom}: {trafic_j:.0f}/{capacite:.0f} Mbps ({taux:.1f}%)"
    return f"🟠 BRIDAGE - {nom}: trafic stable {trafic_j:.0f} Mbps ({taux:.1f}% capacité)"

def analyser_etats_et_alertes(sites_data: list) -> int:
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        date_j = datetime.now().date()
        date_j1 = date_j - timedelta(days=1)
        date_j7 = date_j - timedelta(days=7)
        date_j1_next = date_j1 + timedelta(days=1)
        date_j7_next = date_j7 + timedelta(days=1)
        cur.execute("""
            SELECT site, date_heure::date AS date_jour, MAX(max_speed) AS max_trafic
            FROM trafic_historique 
            WHERE (date_heure >= %s AND date_heure < %s)
               OR (date_heure >= %s AND date_heure < %s)
            GROUP BY site, date_heure::date
        """, (date_j1, date_j1_next, date_j7, date_j7_next))
        hist = {}
        for site, dj, mt in cur.fetchall():
            trafic = float(mt or 0)
            for key in {site, extraire_prefixe(site)}:
                if not key:
                    continue
                hist.setdefault(key, {})
                hist[key][dj] = max(hist[key].get(dj, 0), trafic)
        alertes_count = 0
        for site in sites_data:
            nom = site.get('Site')
            trafic_j = float(site.get('Max_Trafic_Final', 0) or 0)
            capacite = float(site.get('Capacite_Mbps', 0) or 0)
            type_trans = site.get('Type_Trans', '')
            classification = site.get('Classification', '')
            if not nom or trafic_j <= 0 or capacite <= 0:
                continue
            trafic_j1 = hist.get(nom, {}).get(date_j1, 0)
            trafic_j7 = hist.get(nom, {}).get(date_j7, 0)
            etat, taux, var_j1, var_j7 = calculer_etat(trafic_j, trafic_j1, trafic_j7, capacite)
            cur.execute("""
                INSERT INTO site_etat 
                    (site, etat, trafic_j, trafic_j1, trafic_j7, capacite_mbps,
                     taux_utilisation, variation_j1, variation_j7, date_j)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON CONFLICT (site) DO UPDATE SET
                    etat = EXCLUDED.etat,
                    trafic_j = EXCLUDED.trafic_j,
                    trafic_j1 = EXCLUDED.trafic_j1,
                    trafic_j7 = EXCLUDED.trafic_j7,
                    capacite_mbps = EXCLUDED.capacite_mbps,
                    taux_utilisation = EXCLUDED.taux_utilisation,
                    variation_j1 = EXCLUDED.variation_j1,
                    variation_j7 = EXCLUDED.variation_j7,
                    date_j = EXCLUDED.date_j,
                    date_calcul = NOW()
            """, (nom, etat, trafic_j, trafic_j1, trafic_j7, capacite,
                  round(taux, 2), round(var_j1, 2), round(var_j7, 2), date_j))
            alerte_a_creer = []
            if type_trans_manquant(type_trans):
                alerte_a_creer.append('SANS_TYPE')
            if etat in ('CONGESTIONNE', 'BRIDE'):
                alerte_a_creer.append(etat)
            for etat_alerte in alerte_a_creer:
                cur.execute("""
                SELECT id FROM site_alert
                WHERE site = %s
                  AND etat = %s
                  AND date_alerte::date = %s
                LIMIT 1
            """, (nom, etat_alerte, date_j))
                if not cur.fetchone():
                    msg = construire_message_alerte(etat_alerte, nom, trafic_j, capacite, taux, type_trans)
                    cur.execute("""
                        INSERT INTO site_alert 
                            (site, etat, trafic_j, capacite_mbps, taux_utilisation,
                             variation_j1, variation_j7, type_trans, classification, message)
                        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    """, (nom, etat_alerte, trafic_j, capacite, round(taux, 2),
                          round(var_j1, 2), round(var_j7, 2), type_trans, classification, msg))
                    alertes_count += 1
        conn.commit()
        cur.close()
        conn.close()
        print(f"   🔔 Alertes insérées: {alertes_count}")
        return alertes_count
    except Exception as e:
        print(f"⚠️ Erreur analyse états: {e}")
        traceback.print_exc()
        return 0

# ========== TRAITEMENT PRINCIPAL ==========
def traiter_fichiers(file_trafic_content, file_port_content=None,
                     file_type_content=None, file_gps_content=None):
    import time
    start_total = time.time()
    print("📂 Début du traitement...")
    try:
        start = time.time()
        df_trafic = lire_excel_trafic_directement(file_trafic_content)
        print(f"   ✅ Lecture trafic : {time.time()-start:.2f}s")
        if df_trafic.empty:
            return {"status": "error", "message": "Aucune donnée valide"}

        # Mise à jour des tables optionnelles
        if file_port_content:
            start = time.time()
            update_port_data(file_port_content)
            print(f"   ✅ port_data : {time.time()-start:.2f}s")
        if file_type_content:
            start = time.time()
            update_type_liaison_data(file_type_content)
            print(f"   ✅ type_liaison : {time.time()-start:.2f}s")

        # Chargement des données depuis la BDD
        start = time.time()
        port_info = charger_port_data_as_dict()
        type_dict = charger_type_liaison_as_dict()
        capacite_dict = charger_capacite_as_dict()
        capacites_from_ports = charger_capacites_from_port_data(port_info)
        print(f"   ✅ BDD chargée : {time.time()-start:.2f}s")
        print(f"   📡 Capacités depuis port_data : {len(capacites_from_ports)} sites")

        # GPS
        coords_dict = charger_coordonnees_gps(file_gps_content) if file_gps_content else {}
        print(f"   📍 GPS chargé: {len(coords_dict)} sites")
        print(f"📄 Trafic : {len(df_trafic)} mesures")
        print("\n📊 CLASSIFICATION...")

        # Préparation des données pour la classification
        df_trafic['Prefixe'] = df_trafic['eNodeB_Name'].apply(extraire_prefixe)
        empty_trafic = pd.DataFrame(columns=df_trafic.columns)
        trafic_by_site = {site: group for site, group in df_trafic.groupby('eNodeB_Name', sort=False)}
        trafic_names_by_prefix = {prefix: list(values) for prefix, values in df_trafic.groupby('Prefixe', sort=False)['eNodeB_Name'].unique().items()}
        port_names_by_prefix = {}
        for site in port_info.keys():
            port_names_by_prefix.setdefault(extraire_prefixe(site), []).append(site)

        prefixes_trafic = set(trafic_names_by_prefix.keys())
        prefixes_port = set(port_names_by_prefix.keys())
        prefixes_uniques = [p for p in (prefixes_trafic | prefixes_port) if p]
        print(f"📌 Préfixes uniques : {len(prefixes_uniques)}")

        resultats = {}
        for i, prefix in enumerate(prefixes_uniques):
            if (i + 1) % 500 == 0:
                print(f"   Traitement {i+1}/{len(prefixes_uniques)}...")

            noms_trafic = trafic_names_by_prefix.get(prefix, [])
            noms_port = port_names_by_prefix.get(prefix, [])
            tous_noms = list(set(noms_trafic + noms_port))
            if not tous_noms:
                continue

            site_fdd = None
            site_tdd = None
            site_tf = None
            site_simple = None
            has_known_suffix = False
            for nom in tous_noms:
                n = str(nom)
                if est_site_tf(n):
                    site_tf = n
                    has_known_suffix = True
                elif est_site_tdd(n):
                    site_tdd = n
                    has_known_suffix = True
                elif est_site_fdd(n):
                    site_fdd = n
                    has_known_suffix = True
                else:
                    site_simple = n

            if not has_known_suffix:
                classification = '-'
            elif site_tf:
                classification = 'TF'
            elif site_tdd:
                info = port_info.get(site_tdd, {'port0': 0, 'port1': 0})
                if info.get('port0', 0) > 0:
                    classification = 'COTRANS'
                elif info.get('port1', 0) > 0:
                    classification = 'NO_COTRANS'
                else:
                    classification = 'ONLY_FDD'
            else:
                classification = 'ONLY_FDD'

            type_trans = 'NON_DEFINI'
            for candidate in [site_fdd, site_tdd, site_tf, site_simple]:
                if candidate:
                    t = get_type_trans_for_prefix(extraire_prefixe(candidate), type_dict, capacite_dict)
                    if t != 'NON_DEFINI':
                        type_trans = t
                        break
            if type_trans == 'NON_DEFINI':
                type_trans = get_type_trans_for_prefix(prefix, type_dict, capacite_dict)

            fdd_data = trafic_by_site.get(site_fdd, empty_trafic) if site_fdd else empty_trafic
            tdd_data = trafic_by_site.get(site_tdd, empty_trafic) if site_tdd else empty_trafic
            tf_data = trafic_by_site.get(site_tf, empty_trafic) if site_tf else empty_trafic
            simple_data = trafic_by_site.get(site_simple, empty_trafic) if site_simple else empty_trafic

            max_fdd = max_tdd = max_final = seuil = 0.0
            occ = total_measures = 0
            date_max = somme_fdd_tdd = None
            capacite_mbps = 0.0
            if site_tf and site_tf in capacites_from_ports:
                capacite_mbps = capacites_from_ports[site_tf]
            elif prefix in capacites_from_ports:
                capacite_mbps = capacites_from_ports[prefix]
            elif site_fdd and site_fdd in capacites_from_ports:
                capacite_mbps = capacites_from_ports[site_fdd]
            elif site_tdd and site_tdd in capacites_from_ports:
                capacite_mbps = capacites_from_ports[site_tdd]

            lat, lng = None, None
            if coords_dict:
                for nom_candidat in [site_tf, site_fdd, site_tdd, site_simple, prefix]:
                    if nom_candidat and nom_candidat in coords_dict:
                        lat = coords_dict[nom_candidat]['latitude']
                        lng = coords_dict[nom_candidat]['longitude']
                        break

            if classification == '-':
                ref = simple_data
                if not ref.empty:
                    max_val = float(ref['MaxSpeed'].max())
                    max_final = max_val
                    seuil = max_val * 0.9
                    occ = int((ref['MaxSpeed'] >= seuil).sum())
                    date_max = ref.loc[ref['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                    total_measures = len(ref)
            elif classification == 'TF' and not tf_data.empty:
                max_final = float(tf_data['MaxSpeed'].max())
                seuil = max_final * 0.9
                occ = int((tf_data['MaxSpeed'] >= seuil).sum())
                date_max = tf_data.loc[tf_data['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                total_measures = len(tf_data)
            elif classification == 'COTRANS':
                ref = fdd_data if not fdd_data.empty else tdd_data
                if not ref.empty:
                    max_fdd = float(fdd_data['MaxSpeed'].max()) if not fdd_data.empty else 0
                    max_tdd = float(tdd_data['MaxSpeed'].max()) if not tdd_data.empty else 0
                    max_final = max_fdd if not fdd_data.empty else max_tdd
                    seuil = max_final * 0.9
                    occ = int((ref['MaxSpeed'] >= seuil).sum())
                    date_max = ref.loc[ref['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                    total_measures = len(ref)
            elif classification == 'NO_COTRANS':
                if not fdd_data.empty and not tdd_data.empty:
                    max_fdd = float(fdd_data['MaxSpeed'].max())
                    max_tdd = float(tdd_data['MaxSpeed'].max())
                    fdd_h = fdd_data[['DateTime', 'MaxSpeed']].copy()
                    tdd_h = tdd_data[['DateTime', 'MaxSpeed']].copy()
                    fdd_h['H'] = fdd_h['DateTime'].dt.strftime('%Y-%m-%d %H:00:00')
                    tdd_h['H'] = tdd_h['DateTime'].dt.strftime('%Y-%m-%d %H:00:00')
                    fa = fdd_h.groupby('H')['MaxSpeed'].max().reset_index()
                    ta = tdd_h.groupby('H')['MaxSpeed'].max().reset_index()
                    mg = pd.merge(fa, ta, on='H', how='inner', suffixes=('_F', '_T'))
                    if not mg.empty:
                        mg['S'] = mg['MaxSpeed_F'] + mg['MaxSpeed_T']
                        mr = mg.loc[mg['S'].idxmax()]
                        max_final = float(mr['S'])
                        somme_fdd_tdd = max_final
                        date_max = mr['H']
                        seuil = max_final * 0.9
                        occ = int((mg['S'] >= seuil).sum())
                        total_measures = len(mg)
                    else:
                        max_final = max_fdd + max_tdd
                        somme_fdd_tdd = max_final
                        seuil = max_final * 0.9
                elif not fdd_data.empty:
                    max_fdd = float(fdd_data['MaxSpeed'].max())
                    max_final = max_fdd
                    seuil = max_fdd * 0.9
                    occ = int((fdd_data['MaxSpeed'] >= seuil).sum())
                    date_max = fdd_data.loc[fdd_data['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                    total_measures = len(fdd_data)
                elif not tdd_data.empty:
                    max_tdd = float(tdd_data['MaxSpeed'].max())
                    max_final = max_tdd
                    seuil = max_tdd * 0.9
                    occ = int((tdd_data['MaxSpeed'] >= seuil).sum())
                    date_max = tdd_data.loc[tdd_data['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                    total_measures = len(tdd_data)
            else:
                ref = fdd_data if not fdd_data.empty else (simple_data if not simple_data.empty else tdd_data)
                if not ref.empty:
                    max_val = float(ref['MaxSpeed'].max())
                    if not fdd_data.empty:
                        max_fdd = max_val
                    elif not tdd_data.empty:
                        max_tdd = max_val
                    max_final = max_val
                    seuil = max_val * 0.9
                    occ = int((ref['MaxSpeed'] >= seuil).sum())
                    date_max = ref.loc[ref['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                    total_measures = len(ref)

            if max_final <= 0:
                continue

            resultats[prefix] = {
                'Site': prefix,
                'Site_TDD': site_tdd,
                'Site_FDD': site_fdd or site_simple,
                'Classification': classification,
                'Type_Trans': type_trans,
                'Max_Trafic_Final': round(max_final, 4),
                'Max_Trafic_FDD': round(max_fdd, 4),
                'Max_Trafic_TDD': round(max_tdd, 4),
                'Somme_FDD_TDD': round(somme_fdd_tdd, 4) if somme_fdd_tdd else None,
                'Seuil_Critique': round(seuil, 4),
                'Nombre_Occurrences': int(occ),
                'Total_Measures': total_measures,
                'Date_Max': date_max,
                'Capacite_Mbps': round(capacite_mbps, 2),
                'Latitude': lat,
                'Longitude': lng,
            }

        tous_les_sites = list(resultats.values())

        stats = {
            'total_sites': len(tous_les_sites),
            'sites_inconnus': sum(1 for s in tous_les_sites if s['Classification'] == '-'),
            'sites_tf': sum(1 for s in tous_les_sites if s['Classification'] == 'TF'),
            'sites_cotrans': sum(1 for s in tous_les_sites if s['Classification'] == 'COTRANS'),
            'sites_nocotrans': sum(1 for s in tous_les_sites if s['Classification'] == 'NO_COTRANS'),
            'sites_only_fdd': sum(1 for s in tous_les_sites if s['Classification'] in ('ONLY_FDD', 'FDD')),
            'date_analyse': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
        }

        print(f"\n📊 STATISTIQUES FINALES:")
        print(f"   ✅ '-' (sans suffixe) : {stats['sites_inconnus']}")
        print(f"   ✅ TF                 : {stats['sites_tf']}")
        print(f"   ✅ COTRANS            : {stats['sites_cotrans']}")
        print(f"   ✅ NO_COTRANS         : {stats['sites_nocotrans']}")
        print(f"   ✅ ONLY_FDD           : {stats['sites_only_fdd']}")
        print(f"   📊 TOTAL              : {stats['total_sites']}")
        print(f"   📡 Avec capacité      : {sum(1 for s in tous_les_sites if s['Capacite_Mbps'] > 0)}")
        print(f"   📍 Avec GPS           : {sum(1 for s in tous_les_sites if s['Latitude'] is not None)}")

        # Insertions finales
        print("\n💾 Insertion dans analyse_resultat...")
        inserer_analyse_resultat(tous_les_sites)
        print("\n💾 Insertion dans trafic_historique brut...")
        inserer_trafic_historique_brut(df_trafic)
        print("\n💾 Insertion dans trafic_historique journalier...")
        inserer_trafic_historique(tous_les_sites)
        print("\n🔍 ANALYSE DES ÉTATS ET ALERTES...")
        alertes = analyser_etats_et_alertes(tous_les_sites)
        if alertes > 0:
            print(f"   📋 {alertes} alertes générées dans site_alert")
        else:
            print("   📋 Aucune nouvelle alerte")
        print(f"\n✅ Terminé en {time.time()-start_total:.1f}s")

        return {
            "status": "success",
            "message": "Traitement terminé avec succès",
            "data": {
                "tous_les_sites": tous_les_sites,
                "stats": stats,
                "alertes": alertes
            }
        }
    except Exception as e:
        print(f"❌ ERREUR : {e}")
        traceback.print_exc()
        return {"status": "error", "message": str(e), "detail": traceback.format_exc()}