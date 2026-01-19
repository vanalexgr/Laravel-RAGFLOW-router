import os
import logging
import asyncio
from datetime import datetime
from fastapi import FastAPI, Request, HTTPException
from pydantic import BaseModel
from typing import Optional, Literal
import httpx

from semantic_router_service import semantic_router_service, GUIDELINES_CONFIG

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger("ragflow_service")

app = FastAPI(title="RAGFlow Bridge Service")

RAGFLOW_API_KEY = os.getenv("RAGFLOW_API_KEY")
RAGFLOW_BASE_URL = os.getenv("RAGFLOW_ENDPOINT", "https://ragflow.clinicalguidelines.io/api/v1")
SHARED_SECRET = os.getenv("RAGFLOW_BRIDGE_SECRET")

@app.get("/health")
async def health_check():
    """Health check endpoint for service monitoring."""
    return {
        "status": "ok",
        "service": "ragflow_bridge",
        "ragflow_configured": bool(RAGFLOW_API_KEY),
        "ragflow_endpoint": RAGFLOW_BASE_URL,
    }

class RetrieveRequest(BaseModel):
    question: str
    dataset_ids: list[str]
    top_k: int = 1024
    size: int = 10
    page: int = 1
    similarity_threshold: float = 0.2
    vector_similarity_weight: float = 0.3
    keyword: bool = True
    rerank_id: Optional[str] = None
    use_kg: bool = False
    highlight: bool = True

class DatasetInfo(BaseModel):
    id: str
    name: str

class RetrieveMultiRequest(BaseModel):
    question: str
    datasets: list[DatasetInfo]
    top_k: int = 256
    size: int = 10
    max_per_dataset: int = 6
    max_total: int = 12
    page: int = 1
    similarity_threshold: float = 0.2
    vector_similarity_weight: float = 0.3
    keyword: bool = True
    rerank_id: Optional[str] = None
    use_kg: bool = False
    highlight: bool = True

@app.post("/retrieve")
@app.post("/retrieval")
async def retrieve(request: Request, body: RetrieveRequest):
    if SHARED_SECRET:
        provided_secret = request.headers.get("X-Bridge-Secret")
        if provided_secret != SHARED_SECRET:
            raise HTTPException(status_code=403, detail="Unauthorized")

    if not RAGFLOW_API_KEY:
        raise HTTPException(status_code=500, detail="RAGFLOW_API_KEY not configured")

    logger.info(f"Retrieve request: question='{body.question[:50]}...', kb_ids={body.dataset_ids}")

    payload = {
        "question": body.question,
        "dataset_ids": body.dataset_ids,
        "top_k": body.top_k,
        "page": body.page,
        "size": body.size,
        "similarity_threshold": body.similarity_threshold,
        "vector_similarity_weight": body.vector_similarity_weight,
        "keyword": body.keyword,
        "highlight": body.highlight,
    }

    if body.rerank_id:
        payload["rerank_id"] = body.rerank_id
        logger.info(f"Reranking ENABLED: rerank_id={body.rerank_id}")

    if body.use_kg:
        payload["use_kg"] = True
        logger.info("Knowledge Graph ENABLED: use_kg=true")

    logger.info(f"RAGFlow API payload: {payload}")

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
        
        # Log raw response for debugging rerank issues
        if body.rerank_id:
            logger.info(f"RAGFlow raw response keys: {list(result.keys()) if result else 'None'}")
            if result and result.get("code") != 0:
                logger.warning(f"RAGFlow returned error code: {result.get('code')}, message: {result.get('message')}")

        data = result.get("data") if result else None
        chunks = data.get("chunks", []) if data else []
        chunk_count = len(chunks)
        
        top_chunks_summary = []
        for i, chunk in enumerate(chunks[:3]):
            chunk_info = {
                "rank": i + 1,
                "id": chunk.get("id", chunk.get("chunk_id", "unknown"))[:16] + "...",
                "similarity": chunk.get("similarity"),
                "vector_similarity": chunk.get("vector_similarity"),
                "term_similarity": chunk.get("term_similarity"),
                "rerank_score": chunk.get("rerank_score"),
                "score": chunk.get("score"),
            }
            chunk_info = {k: v for k, v in chunk_info.items() if v is not None}
            top_chunks_summary.append(chunk_info)
        
        logger.info(f"RAGFlow response: status={response.status_code}, chunks={chunk_count}, duration={duration_ms:.2f}ms")
        logger.info(f"Top 3 chunks: {top_chunks_summary}")

        if response.status_code != 200:
            logger.error(f"RAGFlow error: {result}")

        return {
            "status": response.status_code,
            "duration_ms": duration_ms,
            "data": result.get("data", {}),
            "code": result.get("code", -1),
            "message": result.get("message"),
            "retrieval_info": {
                "rerank_id": body.rerank_id,
                "use_kg": body.use_kg,
                "top_k": body.top_k,
                "size": body.size,
                "chunk_count": chunk_count,
                "top_chunks": top_chunks_summary,
            }
        }

    except httpx.TimeoutException:
        logger.error("RAGFlow request timed out")
        raise HTTPException(status_code=504, detail="RAGFlow request timed out")
    except Exception as e:
        logger.error(f"RAGFlow request failed: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/retrieve_multi")
async def retrieve_multi(request: Request, body: RetrieveMultiRequest):
    """Parallel retrieval across multiple datasets with per-dataset capping."""
    if SHARED_SECRET:
        provided_secret = request.headers.get("X-Bridge-Secret")
        if provided_secret != SHARED_SECRET:
            raise HTTPException(status_code=403, detail="Unauthorized")

    if not RAGFLOW_API_KEY:
        raise HTTPException(status_code=500, detail="RAGFLOW_API_KEY not configured")

    if not body.datasets:
        raise HTTPException(status_code=400, detail="No datasets provided")

    logger.info(f"Retrieve MULTI request: question='{body.question[:50]}...', datasets={len(body.datasets)}")
    for ds in body.datasets:
        logger.info(f"  - {ds.name}: {ds.id}")

    start_time = datetime.now()

    async def fetch_single_dataset(client: httpx.AsyncClient, dataset: DatasetInfo) -> dict:
        """Fetch from a single dataset and return with metadata."""
        ds_start = datetime.now()
        payload = {
            "question": body.question,
            "dataset_ids": [dataset.id],
            "top_k": body.top_k,
            "page": body.page,
            "size": body.size,
            "similarity_threshold": body.similarity_threshold,
            "vector_similarity_weight": body.vector_similarity_weight,
            "keyword": body.keyword,
            "highlight": body.highlight,
        }
        if body.rerank_id:
            payload["rerank_id"] = body.rerank_id
        if body.use_kg:
            payload["use_kg"] = True

        try:
            response = await client.post(
                f"{RAGFLOW_BASE_URL}/retrieval",
                json=payload,
                headers={
                    "Authorization": f"Bearer {RAGFLOW_API_KEY}",
                    "Content-Type": "application/json",
                }
            )
            ds_duration = (datetime.now() - ds_start).total_seconds() * 1000
            result = response.json()
            chunks = result.get("data", {}).get("chunks", [])
            
            # Tag chunks with source
            for chunk in chunks:
                chunk["_source_guideline"] = dataset.name
                chunk["_source_dataset_id"] = dataset.id
            
            logger.info(f"  {dataset.name}: retrieved={len(chunks)} capped={min(len(chunks), body.max_per_dataset)} ({ds_duration:.0f}ms)")
            
            return {
                "dataset_id": dataset.id,
                "dataset_name": dataset.name,
                "chunks": chunks[:body.max_per_dataset],
                "total_retrieved": len(chunks),
                "duration_ms": ds_duration,
                "status": response.status_code,
            }
        except Exception as e:
            logger.warning(f"  {dataset.name}: FAILED - {str(e)}")
            return {
                "dataset_id": dataset.id,
                "dataset_name": dataset.name,
                "chunks": [],
                "total_retrieved": 0,
                "error": str(e),
            }

    # Parallel fetch all datasets
    try:
        async with httpx.AsyncClient(timeout=60.0) as client:
            tasks = [fetch_single_dataset(client, ds) for ds in body.datasets]
            results = await asyncio.gather(*tasks)

        total_duration = (datetime.now() - start_time).total_seconds() * 1000

        # Interleave chunks from all datasets (round-robin)
        all_dataset_chunks = [r["chunks"] for r in results]
        max_rounds = max(len(chunks) for chunks in all_dataset_chunks) if all_dataset_chunks else 0
        interleaved = []
        for i in range(max_rounds):
            for chunks in all_dataset_chunks:
                if i < len(chunks):
                    interleaved.append(chunks[i])
        
        # Apply global cap
        capped = interleaved[:body.max_total]

        per_dataset_stats = []
        for r in results:
            per_dataset_stats.append({
                "name": r["dataset_name"],
                "retrieved": r["total_retrieved"],
                "capped": len(r["chunks"]),
                "duration_ms": r.get("duration_ms", 0),
            })

        logger.info(f"Retrieve MULTI complete: combined_after_per_cap={len(interleaved)} combined_after_global_cap={len(capped)} total_duration={total_duration:.0f}ms")

        return {
            "status": 200,
            "duration_ms": total_duration,
            "data": {
                "chunks": capped,
            },
            "retrieval_info": {
                "rerank_id": body.rerank_id,
                "use_kg": body.use_kg,
                "top_k": body.top_k,
                "max_per_dataset": body.max_per_dataset,
                "max_total": body.max_total,
                "dataset_count": len(body.datasets),
                "per_dataset": per_dataset_stats,
                "combined_pre_global_cap": len(interleaved),
                "combined_after_global_cap": len(capped),
            }
        }

    except Exception as e:
        logger.error(f"Retrieve MULTI failed: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

class RetrieveDualRequest(BaseModel):
    """Dual retrieval: narrative chunks (KG on) + citation chunks (KG off, metatags)."""
    question: str
    narrative_datasets: list[DatasetInfo]
    citation_dataset_id: str
    narrative_max: int = 8
    citation_max: int = 4
    top_k: int = 256
    similarity_threshold: float = 0.2
    vector_similarity_weight: float = 0.3
    keyword: bool = True
    rerank_id: Optional[str] = None
    highlight: bool = True

@app.post("/retrieve_dual")
async def retrieve_dual(request: Request, body: RetrieveDualRequest):
    """
    Parallel dual retrieval:
    - Narrative chunks: from full guideline datasets WITH KG enabled (for synthesis)
    - Citation chunks: from recommendations-only dataset WITHOUT KG (for verbatim citations)
    """
    if SHARED_SECRET:
        provided_secret = request.headers.get("X-Bridge-Secret")
        if provided_secret != SHARED_SECRET:
            raise HTTPException(status_code=403, detail="Unauthorized")

    if not RAGFLOW_API_KEY:
        raise HTTPException(status_code=500, detail="RAGFLOW_API_KEY not configured")

    logger.info(f"Retrieve DUAL request: question='{body.question[:50]}...'")
    logger.info(f"  Narrative datasets: {len(body.narrative_datasets)}, Citation dataset: {body.citation_dataset_id}")
    if body.rerank_id:
        logger.info(f"  Reranking ENABLED for DUAL: rerank_id={body.rerank_id}")

    start_time = datetime.now()

    async def fetch_narrative_chunks(client: httpx.AsyncClient) -> dict:
        """Fetch narrative chunks with KG enabled from full guideline datasets."""
        if not body.narrative_datasets:
            return {"chunks": [], "duration_ms": 0, "per_dataset": []}

        # Parallel fetch per narrative dataset
        async def fetch_single(ds: DatasetInfo) -> dict:
            ds_start = datetime.now()
            payload = {
                "question": body.question,
                "dataset_ids": [ds.id],
                "top_k": body.top_k,
                "page": 1,
                "size": body.narrative_max,
                "similarity_threshold": body.similarity_threshold,
                "vector_similarity_weight": body.vector_similarity_weight,
                "keyword": body.keyword,
                "highlight": body.highlight,
                "use_kg": True,  # KG enabled for narrative
            }
            if body.rerank_id:
                payload["rerank_id"] = body.rerank_id

            try:
                response = await client.post(
                    f"{RAGFLOW_BASE_URL}/retrieval",
                    json=payload,
                    headers={
                        "Authorization": f"Bearer {RAGFLOW_API_KEY}",
                        "Content-Type": "application/json",
                    }
                )
                ds_duration = (datetime.now() - ds_start).total_seconds() * 1000
                result = response.json()
                chunks = result.get("data", {}).get("chunks", [])
                
                # Tag chunks with source
                for chunk in chunks:
                    chunk["_source_guideline"] = ds.name
                    chunk["_source_dataset_id"] = ds.id
                    chunk["_chunk_type"] = "narrative"
                
                max_per = min(6, body.narrative_max // max(1, len(body.narrative_datasets)))
                capped = chunks[:max_per]
                logger.info(f"  Narrative {ds.name}: retrieved={len(chunks)} capped={len(capped)} ({ds_duration:.0f}ms)")
                return {
                    "dataset_name": ds.name,
                    "chunks": capped,
                    "total": len(chunks),
                    "duration_ms": ds_duration,
                }
            except Exception as e:
                logger.warning(f"  Narrative {ds.name}: FAILED - {str(e)}")
                return {"dataset_name": ds.name, "chunks": [], "total": 0, "error": str(e)}

        tasks = [fetch_single(ds) for ds in body.narrative_datasets]
        results = await asyncio.gather(*tasks)
        
        # Interleave chunks from all datasets
        all_chunks_lists = [r["chunks"] for r in results]
        max_rounds = max((len(c) for c in all_chunks_lists), default=0)
        interleaved = []
        for i in range(max_rounds):
            for chunks in all_chunks_lists:
                if i < len(chunks):
                    interleaved.append(chunks[i])
        
        capped = interleaved[:body.narrative_max]
        total_duration = max((r.get("duration_ms", 0) for r in results), default=0)
        
        return {
            "chunks": capped,
            "duration_ms": total_duration,
            "per_dataset": [{"name": r["dataset_name"], "count": len(r["chunks"])} for r in results],
        }

    async def fetch_citation_chunks(client: httpx.AsyncClient) -> dict:
        """Fetch citation chunks WITHOUT KG from recommendations-only dataset."""
        cit_start = datetime.now()
        payload = {
            "question": body.question,
            "dataset_ids": [body.citation_dataset_id],
            "top_k": body.top_k,
            "page": 1,
            "size": body.citation_max,
            "similarity_threshold": body.similarity_threshold,
            "vector_similarity_weight": body.vector_similarity_weight,
            "keyword": body.keyword,
            "highlight": body.highlight,
            # NO use_kg - recommendations dataset doesn't have KG
        }
        if body.rerank_id:
            payload["rerank_id"] = body.rerank_id

        try:
            response = await client.post(
                f"{RAGFLOW_BASE_URL}/retrieval",
                json=payload,
                headers={
                    "Authorization": f"Bearer {RAGFLOW_API_KEY}",
                    "Content-Type": "application/json",
                }
            )
            cit_duration = (datetime.now() - cit_start).total_seconds() * 1000
            result = response.json()
            chunks = result.get("data", {}).get("chunks", [])
            
            # Tag chunks
            for chunk in chunks:
                chunk["_source_guideline"] = "ESVS Recommendations"
                chunk["_source_dataset_id"] = body.citation_dataset_id
                chunk["_chunk_type"] = "citation"
            
            capped = chunks[:body.citation_max]
            logger.info(f"  Citations: retrieved={len(chunks)} capped={len(capped)} ({cit_duration:.0f}ms)")
            return {
                "chunks": capped,
                "total": len(chunks),
                "duration_ms": cit_duration,
            }
        except Exception as e:
            logger.warning(f"  Citations: FAILED - {str(e)}")
            return {"chunks": [], "total": 0, "error": str(e), "duration_ms": 0}

    try:
        async with httpx.AsyncClient(timeout=60.0) as client:
            narrative_task = fetch_narrative_chunks(client)
            citation_task = fetch_citation_chunks(client)
            narrative_result, citation_result = await asyncio.gather(narrative_task, citation_task)

        total_duration = (datetime.now() - start_time).total_seconds() * 1000

        logger.info(f"Retrieve DUAL complete: narrative={len(narrative_result['chunks'])} citations={len(citation_result['chunks'])} total_duration={total_duration:.0f}ms")

        return {
            "status": 200,
            "duration_ms": total_duration,
            "narrative": {
                "chunks": narrative_result["chunks"],
                "count": len(narrative_result["chunks"]),
                "per_dataset": narrative_result.get("per_dataset", []),
                "duration_ms": narrative_result.get("duration_ms", 0),
            },
            "citations": {
                "chunks": citation_result["chunks"],
                "count": len(citation_result["chunks"]),
                "duration_ms": citation_result.get("duration_ms", 0),
            },
            "retrieval_info": {
                "rerank_id": body.rerank_id,
                "narrative_use_kg": True,
                "citation_use_kg": False,
                "narrative_max": body.narrative_max,
                "citation_max": body.citation_max,
            }
        }

    except Exception as e:
        logger.error(f"Retrieve DUAL failed: {str(e)}")
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


class RouteRequest(BaseModel):
    query: str
    max_routes: int = 3
    min_confidence: float = 0.35
    relative_margin: float = 0.02


class RouteResponse(BaseModel):
    method: str
    guidelines: list[dict]
    duration_ms: float
    router_available: bool


@app.on_event("startup")
async def startup_event():
    try:
        semantic_router_service.initialize()
        logger.info("Semantic router initialized on startup")
    except Exception as e:
        logger.warning(f"Failed to initialize semantic router: {e}")


@app.post("/route")
async def route_query(request: Request, body: RouteRequest):
    start_time = datetime.now()

    if not semantic_router_service.is_initialized:
        duration = (datetime.now() - start_time).total_seconds() * 1000
        return RouteResponse(
            method="none",
            guidelines=[],
            duration_ms=duration,
            router_available=False,
        )

    try:
        results = semantic_router_service.route_multi(
            body.query, 
            max_routes=body.max_routes, 
            min_confidence=body.min_confidence,
            relative_margin=body.relative_margin
        )
        duration = (datetime.now() - start_time).total_seconds() * 1000

        keys = [r['guideline_key'] for r in results]
        scores = [r.get('confidence', 'N/A') for r in results]
        logger.info(f"Semantic route: query='{body.query[:50]}...' -> {keys} (scores: {scores}, {duration:.0f}ms)")

        return RouteResponse(
            method="semantic",
            guidelines=results,
            duration_ms=duration,
            router_available=True,
        )

    except Exception as e:
        logger.error(f"Semantic routing failed: {e}")
        duration = (datetime.now() - start_time).total_seconds() * 1000
        return RouteResponse(
            method="error",
            guidelines=[],
            duration_ms=duration,
            router_available=True,
        )


@app.get("/route/guidelines")
async def list_guideline_routes():
    return {
        "guidelines": semantic_router_service.get_all_guidelines(),
        "router_available": semantic_router_service.is_initialized,
    }
