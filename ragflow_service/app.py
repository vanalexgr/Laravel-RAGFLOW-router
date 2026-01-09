import os
import logging
from datetime import datetime
from fastapi import FastAPI, Request, HTTPException
from pydantic import BaseModel
from typing import Optional
import httpx

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger("ragflow_service")

app = FastAPI(title="RAGFlow Bridge Service")

RAGFLOW_API_KEY = os.getenv("RAGFLOW_API_KEY")
RAGFLOW_BASE_URL = os.getenv("RAGFLOW_ENDPOINT", "https://ragflow.clinicalguidelines.io/api/v1")
SHARED_SECRET = os.getenv("RAGFLOW_BRIDGE_SECRET")

class RetrieveRequest(BaseModel):
    question: str
    dataset_ids: list[str]
    top_k: int = 20
    top_n: int = 6
    similarity_threshold: float = 0.2
    vector_similarity_weight: float = 0.3
    keyword: bool = True
    rerank_id: Optional[str] = None
    use_knowledge_graph: bool = False
    use_toc: bool = True

@app.post("/retrieve")
async def retrieve(request: Request, body: RetrieveRequest):
    if SHARED_SECRET:
        provided_secret = request.headers.get("X-Bridge-Secret")
        if provided_secret != SHARED_SECRET:
            raise HTTPException(status_code=403, detail="Unauthorized")

    if not RAGFLOW_API_KEY:
        raise HTTPException(status_code=500, detail="RAGFLOW_API_KEY not configured")

    logger.info(f"Retrieve request: question='{body.question[:50]}...', datasets={body.dataset_ids}")

    payload = {
        "question": body.question,
        "dataset_ids": body.dataset_ids,
        "top_k": body.top_k,
        "top_n": body.top_n,
        "similarity_threshold": body.similarity_threshold,
        "vector_similarity_weight": body.vector_similarity_weight,
        "keyword": body.keyword,
    }

    if body.rerank_id:
        payload["rerank_id"] = body.rerank_id
        logger.info(f"Using rerank_id: {body.rerank_id}")

    if body.use_knowledge_graph:
        payload["use_knowledge_graph"] = True
        logger.info("Knowledge graph enabled")

    if body.use_toc:
        payload["use_toc"] = True

    logger.info(f"RAGFlow payload: {payload}")

    start_time = datetime.now()

    try:
        async with httpx.AsyncClient(timeout=60.0) as client:
            response = await client.post(
                f"{RAGFLOW_BASE_URL}/retrieval",
                json=payload,
                headers={
                    "Authorization": f"Bearer {RAGFLOW_API_KEY}",
                    "Content-Type": "application/json",
                }
            )

        duration_ms = (datetime.now() - start_time).total_seconds() * 1000

        result = response.json()

        chunk_count = len(result.get("data", {}).get("chunks", []))
        logger.info(f"RAGFlow response: status={response.status_code}, chunks={chunk_count}, duration={duration_ms:.2f}ms")

        if response.status_code != 200:
            logger.error(f"RAGFlow error: {result}")

        return {
            "status": response.status_code,
            "duration_ms": duration_ms,
            "data": result.get("data", {}),
            "code": result.get("code", -1),
            "message": result.get("message"),
        }

    except httpx.TimeoutException:
        logger.error("RAGFlow request timed out")
        raise HTTPException(status_code=504, detail="RAGFlow request timed out")
    except Exception as e:
        logger.error(f"RAGFlow request failed: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/health")
async def health():
    return {"status": "ok", "service": "ragflow-bridge"}

@app.get("/datasets")
async def list_datasets(request: Request):
    if SHARED_SECRET:
        provided_secret = request.headers.get("X-Bridge-Secret")
        if provided_secret != SHARED_SECRET:
            raise HTTPException(status_code=403, detail="Unauthorized")

    if not RAGFLOW_API_KEY:
        raise HTTPException(status_code=500, detail="RAGFLOW_API_KEY not configured")

    try:
        async with httpx.AsyncClient(timeout=30.0) as client:
            response = await client.get(
                f"{RAGFLOW_BASE_URL}/datasets",
                headers={
                    "Authorization": f"Bearer {RAGFLOW_API_KEY}",
                    "Content-Type": "application/json",
                }
            )

        return response.json()

    except Exception as e:
        logger.error(f"Failed to list datasets: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/datasets/{dataset_id}")
async def get_dataset(dataset_id: str, request: Request):
    if SHARED_SECRET:
        provided_secret = request.headers.get("X-Bridge-Secret")
        if provided_secret != SHARED_SECRET:
            raise HTTPException(status_code=403, detail="Unauthorized")

    if not RAGFLOW_API_KEY:
        raise HTTPException(status_code=500, detail="RAGFLOW_API_KEY not configured")

    try:
        async with httpx.AsyncClient(timeout=30.0) as client:
            response = await client.get(
                f"{RAGFLOW_BASE_URL}/datasets/{dataset_id}",
                headers={
                    "Authorization": f"Bearer {RAGFLOW_API_KEY}",
                    "Content-Type": "application/json",
                }
            )

        return response.json()

    except Exception as e:
        logger.error(f"Failed to get dataset: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))
