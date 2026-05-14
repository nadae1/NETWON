# traitement.py
import io
import re
from datetime import datetime

import numpy as np
import pandas as pd

pd.set_option("display.float_format", "{:.15f}".format)


def safe_float(value, default=0.0):
    try:
        if pd.isna(value):
            return default
        return float(value)
    except Exception:
        return default


def extraire_tous_prefixes(site):
    if site is None or pd.isna(site):
        return []

    site_str = str(site).strip()
    if not site_str:
        return []

    parts = site_str.split("_")
    prefixes = []

    for i in range(len(parts) - 1, 0, -1):
        prefixes.append("_".join(parts[:i]))

    if len(parts) > 1:
        prefixes.append("_".join(parts[:-1]))

    return list(dict.fromkeys([p for p in prefixes if p]))


def get_type_trans_raw(site, type_dict):
    if site is None or pd.isna(site):
        return "NON_DEFINI"

    prefixes = extraire_tous_prefixes(site)
    prefixes.insert(0, str(site).strip())

    for prefix in prefixes:
        if prefix in type_dict:
            return type_dict[prefix]

    return "NON_DEFINI"


def normaliser_type_trans(valeur: str, site_name: str = None) -> str:
    v = str(valeur).strip().upper()

    if site_name and (v in ["NON_DEFINI", "", "NAN"]):
        site_upper = str(site_name).upper()

        if "_BH_TR" in site_upper or "_BH_TT" in site_upper:
            return "BH_TT"
        if "_BH_FO" in site_upper:
            return "BH_FO"
        if "_BH_OO" in site_upper:
            return "BH_OO"
        if "_BH_" in site_upper:
            return "BH"

    if v.startswith("BH"):
        if "_TT" in v or " TT" in v or "TR" in v:
            return "BH_TT"
        if "_FO" in v or " FO" in v:
            return "BH_FO"
        if "_OO" in v or " OO" in v:
            return "BH_OO"
        return "BH"

    if v in ("FO", "FIBRE", "FIBRE OPTIQUE", "FIBER", "FIBRE-OPTIQUE", "F.O"):
        return "FO"

    if v in (
        "FH",
        "FAISCEAU",
        "FAISCEAUX HERTZIENS",
        "FAISCEAU HERTZIEN",
        "HERTZIEN",
        "HERTZIENS",
        "F.H",
        "FH PARTAGÉ",
        "FH PARTAGE",
    ):
        return "FH"

    return v


def detect_columns(df_type):
    col_site = None
    col_type = None

    for col in df_type.columns:
        lower = str(col).lower()

        if "site" in lower or "node" in lower or "enodeb" in lower:
            col_site = col

        if "type" in lower or "liaison" in lower or "trans" in lower:
            col_type = col

    if col_site is None:
        col_site = df_type.columns[0]

    if col_type is None:
        col_type = df_type.columns[1] if len(df_type.columns) > 1 else df_type.columns[0]

    return col_site, col_type


def detect_date_column(df):
    for col in df.columns:
        if str(col).strip().lower() in ["time", "date", "datetime", "date time"]:
            return col
    raise ValueError("Colonne date/time introuvable dans le fichier trafic.")


def traiter_fichiers(file_trafic_content, file_port_content, file_type_content):
    try:
        # ============================================================
        # 1) LECTURE DES FICHIERS
        # ============================================================
        df_trafic = pd.read_excel(io.BytesIO(file_trafic_content))
        df_port = pd.read_excel(io.BytesIO(file_port_content))
        df_type = pd.read_excel(io.BytesIO(file_type_content))

        # ============================================================
        # 2) NETTOYAGE TRAFIC
        # ============================================================
        df_trafic.columns = df_trafic.columns.str.strip()

        df_trafic = df_trafic.rename(
            columns={
                "eNodeB Name": "eNodeB_Name",
                "VS.FEGE.RxMaxSpeed_Mbs(Mbit/s)": "MaxSpeed",
            }
        )

        if "eNodeB_Name" not in df_trafic.columns:
            raise ValueError("Colonne 'eNodeB Name' introuvable dans le fichier trafic.")

        if "MaxSpeed" not in df_trafic.columns:
            raise ValueError("Colonne trafic 'VS.FEGE.RxMaxSpeed_Mbs(Mbit/s)' introuvable.")

        date_col = detect_date_column(df_trafic)

        df_trafic["eNodeB_Name"] = df_trafic["eNodeB_Name"].astype(str).str.strip()
        df_trafic["MaxSpeed"] = pd.to_numeric(df_trafic["MaxSpeed"], errors="coerce")
        df_trafic = df_trafic.dropna(subset=["MaxSpeed"])

        df_trafic["DateTime"] = pd.to_datetime(df_trafic[date_col], errors="coerce", dayfirst=True)

        if df_trafic["DateTime"].isna().all():
            df_trafic["DateTime"] = pd.to_datetime(df_trafic[date_col], errors="coerce")

        df_trafic = df_trafic.dropna(subset=["DateTime"])
        df_trafic["Date"] = df_trafic["DateTime"].dt.date

        # ============================================================
        # 3) NETTOYAGE PORT
        # ============================================================
        df_port.columns = df_port.columns.str.strip()

        df_port = df_port.rename(
            columns={
                "eNodeB Name": "eNodeB_Name",
                "Port No": "Port_No",
                "VS.FEGE.RxMaxSpeed_Mbs(Mbit/s)": "Trafic",
            }
        )

        if "eNodeB_Name" not in df_port.columns:
            raise ValueError("Colonne 'eNodeB Name' introuvable dans le fichier port.")

        if "Port_No" not in df_port.columns:
            raise ValueError("Colonne 'Port No' introuvable dans le fichier port.")

        if "Trafic" not in df_port.columns:
            raise ValueError("Colonne trafic introuvable dans le fichier port.")

        df_port["eNodeB_Name"] = df_port["eNodeB_Name"].astype(str).str.strip()
        df_port["Port_No"] = pd.to_numeric(df_port["Port_No"], errors="coerce").fillna(-1).astype(int)
        df_port["Trafic"] = pd.to_numeric(df_port["Trafic"], errors="coerce").fillna(0)

        port_info = {}

        for _, row in df_port.iterrows():
            site = row["eNodeB_Name"]
            port = row["Port_No"]
            trafic = row["Trafic"]

            if site not in port_info:
                port_info[site] = {
                    "port0": 0.0,
                    "port1": 0.0,
                    "has_data": True,
                }

            if port == 0:
                port_info[site]["port0"] = safe_float(trafic)
            elif port == 1:
                port_info[site]["port1"] = safe_float(trafic)

        # ============================================================
        # 4) NETTOYAGE TYPE LIAISON
        # ============================================================
        df_type.columns = df_type.columns.str.strip()

        col_site, col_type = detect_columns(df_type)

        df_type = df_type.rename(
            columns={
                col_site: "Site",
                col_type: "Type_Trans",
            }
        )

        df_type["Site"] = df_type["Site"].astype(str).str.strip()
        df_type["Type_Trans"] = df_type["Type_Trans"].astype(str).str.strip()

        type_dict = dict(zip(df_type["Site"], df_type["Type_Trans"]))

        # ============================================================
        # 5) CLASSIFICATION
        # ============================================================
        tous_les_sites = []
        sites_uniques = df_trafic["eNodeB_Name"].dropna().unique().tolist()

        for site in sites_uniques:
            site_trafic = df_trafic[df_trafic["eNodeB_Name"] == site]

            if site_trafic.empty:
                continue

            max_trafic = safe_float(site_trafic["MaxSpeed"].max())
            row_max = site_trafic.loc[site_trafic["MaxSpeed"].idxmax()]
            date_max = row_max["DateTime"]
            total_measures = int(len(site_trafic))

            type_raw = get_type_trans_raw(site, type_dict)
            type_trans = normaliser_type_trans(type_raw, site_name=site)

            classification = "-"
            site_fdd = ""
            max_tdd = 0.0
            max_fdd = max_trafic
            max_final = max_trafic
            seuil = max_trafic * 0.9
            occurrences = int(len(site_trafic[site_trafic["MaxSpeed"] >= seuil]))

            site_str = str(site)

            if re.search(r"_tf$", site_str, re.IGNORECASE):
                classification = "TF"
                max_tdd = max_trafic
                max_fdd = 0.0
                max_final = max_trafic

            elif re.search(r"_TC$|_TD$|_TDD$", site_str, re.IGNORECASE):
                max_tdd = max_trafic
                max_fdd = 0.0
                max_final = max_trafic

                info = port_info.get(site, {})

                if info.get("has_data"):
                    trafic0 = safe_float(info.get("port0", 0))
                    trafic1 = safe_float(info.get("port1", 0))

                    if trafic0 > 0:
                        classification = "COTRANS"
                    elif trafic1 > 0:
                        classification = "NO_COTRANS"
                    else:
                        classification = "FDD"
                else:
                    classification = "FDD"

            elif re.search(r"_LM$|_BO_|_BH_", site_str, re.IGNORECASE):
                max_tdd = 0.0
                max_fdd = max_trafic
                max_final = max_trafic

                prefix = "_".join(site_str.split("_")[:-1])
                tdd_candidate = None

                for s in port_info.keys():
                    if str(s).startswith(prefix) and re.search(r"_TC$|_TD$|_TDD$", str(s), re.IGNORECASE):
                        tdd_candidate = s
                        break

                if tdd_candidate:
                    info = port_info.get(tdd_candidate, {})

                    if info.get("has_data"):
                        trafic0 = safe_float(info.get("port0", 0))
                        trafic1 = safe_float(info.get("port1", 0))

                        if trafic0 > 0:
                            classification = "COTRANS"
                        elif trafic1 > 0:
                            classification = "NO_COTRANS"
                        else:
                            classification = "FDD"

                        site_fdd = tdd_candidate
                    else:
                        classification = "FDD"
                else:
                    classification = "FDD"

            else:
                classification = "-"
                max_tdd = 0.0
                max_fdd = max_trafic
                max_final = max_trafic

            if occurrences > 50:
                statut_text = "CRITIQUE"
                is_critical = True
            elif occurrences > 20:
                statut_text = "SURVEILLANCE"
                is_critical = True
            elif occurrences > 0:
                statut_text = "OK"
                is_critical = False
            else:
                statut_text = "AUCUNE OCCURRENCE"
                is_critical = False

            tous_les_sites.append(
                {
                    "Site": site,
                    "siteName": site,
                    "Classification": classification,
                    "classification": classification,
                    "Type_Trans": type_trans,
                    "typeTrans": type_trans,
                    "Site_FDD": site_fdd,
                    "Max_Trafic_TDD": float(max_tdd),
                    "Max_Trafic_FDD": float(max_fdd),
                    "Max_Trafic_Final": float(max_final),
                    "maxTrafic": float(max_final),
                    "Date_Max": date_max.strftime("%Y-%m-%d %H:%M:%S") if pd.notna(date_max) else None,
                    "dateMax": date_max.strftime("%Y-%m-%d %H:%M:%S") if pd.notna(date_max) else None,
                    "Seuil_Critique": float(seuil),
                    "seuilCritique": float(seuil),
                    "Nombre_Occurrences": int(occurrences),
                    "nombreOccurrences": int(occurrences),
                    "Total_Measures": int(total_measures),
                    "totalMeasures": int(total_measures),
                    "Statut": statut_text,
                    "status": statut_text,
                    "isCritical": bool(is_critical),
                }
            )

        df_tous_sites = pd.DataFrame(tous_les_sites)

        if df_tous_sites.empty:
            return {
                "status": "success",
                "message": "Traitement terminé, aucun site trouvé.",
                "data": {
                    "sites": [],
                    "sites_tf": [],
                    "sites_cotrans": [],
                    "sites_nocotrans": [],
                    "sites_fdd": [],
                    "stats": {
                        "total_sites": 0,
                        "sites_tf": 0,
                        "sites_cotrans": 0,
                        "sites_nocotrans": 0,
                        "sites_fdd": 0,
                        "total_occurrences": 0,
                        "date_analyse": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                    },
                },
            }

        sites_tf = df_tous_sites[df_tous_sites["Classification"] == "TF"].to_dict(orient="records")
        sites_cotrans = df_tous_sites[df_tous_sites["Classification"] == "COTRANS"].to_dict(orient="records")
        sites_nocotrans = df_tous_sites[df_tous_sites["Classification"] == "NO_COTRANS"].to_dict(orient="records")
        sites_fdd = df_tous_sites[df_tous_sites["Classification"] == "FDD"].to_dict(orient="records")
        sites_non_classes = df_tous_sites[df_tous_sites["Classification"] == "-"].to_dict(orient="records")

        stats = {
            "total_sites": int(len(df_tous_sites)),
            "sites_tf": int(len(sites_tf)),
            "sites_cotrans": int(len(sites_cotrans)),
            "sites_nocotrans": int(len(sites_nocotrans)),
            "sites_fdd": int(len(sites_fdd)),
            "sites_non_classes": int(len(sites_non_classes)),
            "total_occurrences": int(df_tous_sites["Nombre_Occurrences"].sum()),
            "date_analyse": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        }

        return {
            "status": "success",
            "message": "Traitement terminé avec succès",
            "data": {
                "sites": tous_les_sites,
                "sites_tf": sites_tf,
                "sites_cotrans": sites_cotrans,
                "sites_nocotrans": sites_nocotrans,
                "sites_fdd": sites_fdd,
                "sites_non_classes": sites_non_classes,
                "stats": stats,
            },
        }

    except Exception as e:
        return {
            "status": "error",
            "message": str(e),
            "data": None,
        }