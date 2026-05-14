# main.py
from fastapi import FastAPI, UploadFile, File, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from traitement import traiter_fichiers
import uvicorn

app = FastAPI(title="Orange 5G - Plan Data API", version="1.0.0")

# CORS (باش Symfony ينجم يطلب API)
app.add_middleware(
    CORSMiddleware,
    allow_origins=[
        "http://localhost:8000",
        "http://127.0.0.1:8000",
        "http://localhost:8001",
        "http://127.0.0.1:8001"
    ],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# -----------------------------------
# ROOT
# -----------------------------------
@app.get("/")
async def root():
    return {
        "message": "API Orange 5G - Plan Data",
        "status": "OK"
    }

# -----------------------------------
# HEALTH CHECK
# -----------------------------------
@app.get("/health")
async def health():
    return {
        "status": "ok",
        "service": "FastAPI"
    }

# -----------------------------------
# TRAITEMENT PRINCIPAL
# -----------------------------------
@app.post("/traiter")
async def traiter(
    trafic: UploadFile = File(...),
    port: UploadFile = File(...),
    type_liaison: UploadFile = File(...)
):
    try:
        # قراءة الملفات
        trafic_content = await trafic.read()
        port_content = await port.read()
        type_content = await type_liaison.read()

        # check files empty
        if not trafic_content or not port_content or not type_content:
            raise HTTPException(status_code=400, detail="Fichiers vides ou invalides")

        # appel traitement
        resultats = traiter_fichiers(
            trafic_content,
            port_content,
            type_content
        )

        # check result
        if resultats.get("status") != "success":
            raise HTTPException(status_code=500, detail=resultats.get("message"))

        return resultats

    except Exception as e:
        raise HTTPException(
            status_code=500,
            detail=f"Erreur traitement: {str(e)}"
        )

# -----------------------------------
# RUN SERVER
# -----------------------------------
if __name__ == "__main__":
    uvicorn.run(
        "main:app",
        host="0.0.0.0",
        port=8001,
        reload=True
    )