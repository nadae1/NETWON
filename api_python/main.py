# api_python/main.py
import traceback
from fastapi import FastAPI, UploadFile, File, Request
from fastapi.exceptions import RequestValidationError
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from typing import Optional
from traitement import traiter_fichiers
from capacite import traiter_capacite
from dropcong import traiter_dropcong
import uvicorn
from datetime import datetime
from gps import traiter_gps

from fastapi import Form
from manuel import maj_site_manuel
from forecasting import train_all_horizons, models_ready
from ia_service import predict_site_evolution, predict_all_sites
from fastapi import Query



app = FastAPI(title="NetWON - Traitement Réseau API", version="1.0.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

@app.exception_handler(RequestValidationError)
async def validation_error_handler(request: Request, exc: RequestValidationError):
    return JSONResponse(status_code=200, content={"status": "error", "message": str(exc)})

@app.exception_handler(Exception)
async def global_error_handler(request: Request, exc: Exception):
    return JSONResponse(status_code=200, content={"status": "error", "message": str(exc), "detail": traceback.format_exc()})


@app.get("/")
async def root():
    return {"message": "NetWON - API Traitement Réseau", "status": "OK"}

@app.get("/health")
async def health():
    return {"status": "ok", "timestamp": datetime.now().isoformat()}

@app.post("/traiter")
async def traiter(
    trafic: UploadFile = File(...),
    port: Optional[UploadFile] = File(None),
    type_liaison: Optional[UploadFile] = File(None),
    gps: Optional[UploadFile] = File(None),
):
    try:
        trafic_content = await trafic.read()
        port_content = await port.read() if port else None
        type_content = await type_liaison.read() if type_liaison else None
        gps_content = await gps.read() if gps else None
        result = traiter_fichiers(trafic_content, port_content, type_content, gps_content)
        return JSONResponse(content=result)
    except Exception as e:
        return JSONResponse(status_code=500, content={"status": "error", "message": str(e), "detail": traceback.format_exc()})

@app.post("/capacite/fo")
async def importer_capacite_fo(fichier: UploadFile = File(...)):
    return traiter_capacite(await fichier.read())

@app.post("/capacite/fh")
async def importer_capacite_fh(fichier: UploadFile = File(...)):
    return traiter_capacite(await fichier.read())

@app.post("/capacite/backbone")
async def importer_capacite_backbone(fichier: UploadFile = File(...)):
    return traiter_capacite(await fichier.read())

@app.post("/dropcong")
async def importer_dropcong(
    fichier: Optional[UploadFile] = File(None),
    drop1: Optional[UploadFile] = File(None),
    drop2: Optional[UploadFile] = File(None),
):
    contents = []
    for uploaded in (fichier, drop1, drop2):
        if uploaded:
            contents.append(await uploaded.read())
    return traiter_dropcong(*contents)

@app.post("/gps/import")
async def importer_gps(fichier: UploadFile = File(...)):
    return traiter_gps(await fichier.read())

@app.post("/site/manuel")
async def maj_manuel(
    site: str = Form(...),
    capacite_tdd: Optional[float] = Form(None),
    capacite_fdd: Optional[float] = Form(None),
    type_trans: Optional[str] = Form(None),
):
    return maj_site_manuel(site, capacite_tdd, capacite_fdd, type_trans)


@app.post("/ia/train")
async def ia_train():
    try:
        return JSONResponse(content=train_all_horizons())
    except Exception as e:
        return JSONResponse(status_code=200, content={"status": "error", "message": str(e), "detail": traceback.format_exc()})

@app.get("/ia/status")
async def ia_status():
    return JSONResponse(content={"models_ready": models_ready()})

@app.get("/ia/predict/{site}")
async def ia_predict_site(site: str, horizon: str = Query("d7"), persist: bool = Query(True)):
    try:
        return JSONResponse(content=predict_site_evolution(site, horizon, persist=persist))
    except Exception as e:
        return JSONResponse(status_code=200, content={"status": "error", "message": str(e), "detail": traceback.format_exc()})

@app.get("/ia/predict/batch/all")
async def ia_predict_batch(horizon: str = Query("d7"), limit: int = Query(500, ge=1, le=3000), persist: bool = Query(True)):
    try:
        return JSONResponse(content=predict_all_sites(horizon, limit, persist=persist))
    except Exception as e:
        return JSONResponse(status_code=200, content={"status": "error", "message": str(e), "detail": traceback.format_exc()})
    
if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8001)