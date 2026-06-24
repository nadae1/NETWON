# api_python/main.py
from fastapi import FastAPI, UploadFile, File
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from typing import Optional
from traitement import traiter_fichiers
from capacite import traiter_capacite, traiter_capacite_fo, traiter_capacite_fh, traiter_capacite_backbone
import uvicorn
import os
from datetime import datetime

app = FastAPI(title="Orange 5G - Plan Data API", version="1.0.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

@app.get("/")
async def root():
    return {"message": "API Orange 5G - Plan Data", "status": "OK"}

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
        trafic_content = await trafic.read() if trafic else None
        port_content = await port.read() if port else None
        type_content = await type_liaison.read() if type_liaison else None
        gps_content = await gps.read() if gps else None
        result = traiter_fichiers(trafic_content, port_content, type_content, gps_content)
        return JSONResponse(content=result)
    except Exception as e:
        import traceback
        return JSONResponse(
            status_code=500,
            content={"status": "error", "message": str(e), "detail": traceback.format_exc()}
        )

@app.post("/capacite/fo")
async def importer_capacite_fo(fichier: UploadFile = File(...)):
    content = await fichier.read()
    return traiter_capacite_fo(content)

@app.post("/capacite/fh")
async def importer_capacite_fh(fichier: UploadFile = File(...)):
    content = await fichier.read()
    return traiter_capacite_fh(content)

@app.post("/capacite/backbone")
async def importer_capacite_backbone(fichier: UploadFile = File(...)):
    content = await fichier.read()
    return traiter_capacite_backbone(content)

# Endpoint pour un fichier unique (tous services)
@app.post("/capacite/all")
async def importer_capacite_unique(fichier: UploadFile = File(...)):
    from capacite import traiter_capacite_unique
    content = await fichier.read()
    return traiter_capacite_unique(content)

if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8001)