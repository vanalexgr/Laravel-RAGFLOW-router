import os
import logging
import asyncio
from datetime import datetime
from fastapi import FastAPI, Request, HTTPException
from pydantic import BaseModel
from typing import Optional, Literal
import httpx

# Language detection for query translation
try:
    from langdetect import detect, LangDetectException
    LANGDETECT_AVAILABLE = True
except ImportError:
    LANGDETECT_AVAILABLE = False
    logging.warning("langdetect not available - all queries will be treated as English")

# OpenAI for translation
try:
    from openai import AzureOpenAI
    OPENAI_AVAILABLE = True
except ImportError:
    OPENAI_AVAILABLE = False
    logging.warning("openai not available - translation disabled")

# Make semantic router optional - may fail if fastembed/onnx dependencies unavailable
try:
    from semantic_router_service import semantic_router_service, GUIDELINES_CONFIG
    SEMANTIC_ROUTER_AVAILABLE = True
except ImportError as e:
    SEMANTIC_ROUTER_AVAILABLE = False
    semantic_router_service = None
    GUIDELINES_CONFIG = {}
    logging.warning(f"Semantic router not available (missing dependencies): {e}")

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger("ragflow_service")

app = FastAPI(title="RAGFlow Bridge Service")

RAGFLOW_API_KEY = os.getenv("RAGFLOW_API_KEY")
RAGFLOW_BASE_URL = os.getenv("RAGFLOW_ENDPOINT", "https://ragflow.clinicalguidelines.io/api/v1")
SHARED_SECRET = os.getenv("RAGFLOW_BRIDGE_SECRET")

# Azure OpenAI configuration for translation
AZURE_OPENAI_API_KEY = os.getenv("AZURE_OPENAI_API_KEY")
AZURE_OPENAI_ENDPOINT = os.getenv("AZURE_OPENAI_ENDPOINT")
AZURE_OPENAI_DEPLOYMENT = os.getenv("AZURE_OPENAI_DEPLOYMENT", "gpt-5-chat")
AZURE_OPENAI_VERSION = os.getenv("AZURE_OPENAI_VERSION", "2024-12-01-preview")

# Initialize Azure OpenAI client for translation
azure_client = None
if OPENAI_AVAILABLE and AZURE_OPENAI_API_KEY and AZURE_OPENAI_ENDPOINT:
    try:
        azure_client = AzureOpenAI(
            api_key=AZURE_OPENAI_API_KEY,
            api_version=AZURE_OPENAI_VERSION,
            azure_endpoint=AZURE_OPENAI_ENDPOINT,
        )
        logger.info("Azure OpenAI client initialized for query translation")
    except Exception as e:
        logger.warning(f"Failed to initialize Azure OpenAI client: {e}")


def detect_language(text: str) -> str:
    """Detect the language of the input text. Returns ISO 639-1 code (e.g., 'en', 'de', 'fr')."""
    if not LANGDETECT_AVAILABLE:
        return "en"
    try:
        lang = detect(text)
        return lang
    except LangDetectException:
        return "en"


def translate_to_english(text: str, source_lang: str) -> tuple[str, float]:
    """
    Translate text to English using Azure OpenAI.
    Returns (translated_text, duration_ms).
    """
    if not azure_client:
        logger.warning("Azure OpenAI client not available for translation")
        return text, 0.0
    
    start_time = datetime.now()
    
    try:
        response = azure_client.chat.completions.create(
            model=AZURE_OPENAI_DEPLOYMENT,
            messages=[
                {
                    "role": "system",
                    "content": "You are a medical translator. Translate the following medical query to English. Preserve all medical terminology and clinical context. Output only the translation, nothing else."
                },
                {
                    "role": "user", 
                    "content": text
                }
            ],
            temperature=0.1,
            max_tokens=500,
        )
        
        translated = response.choices[0].message.content.strip()
        duration_ms = (datetime.now() - start_time).total_seconds() * 1000
        
        logger.info(f"Translated query ({source_lang}->en): '{text[:50]}...' -> '{translated[:50]}...' ({duration_ms:.0f}ms)")
        
        return translated, duration_ms
        
    except Exception as e:
        logger.error(f"Translation failed: {e}")
        duration_ms = (datetime.now() - start_time).total_seconds() * 1000
        return text, duration_ms

@app.get("/health")
async def health_check():
    """Health check endpoint for service monitoring."""
    router_status = {}
    if SEMANTIC_ROUTER_AVAILABLE and semantic_router_service:
        try:
            router_status = semantic_router_service.get_status()
        except Exception:
            router_status = {"initialized": False, "model_name": "error"}
    
    return {
        "status": "ok",
        "service": "ragflow_bridge",
        "ragflow_configured": bool(RAGFLOW_API_KEY),
        "ragflow_endpoint": RAGFLOW_BASE_URL,
        "semantic_router_available": SEMANTIC_ROUTER_AVAILABLE,
        "semantic_router": router_status,
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
    score: Optional[float] = None  # Semantic router confidence score for proportional allocation

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
    logger.info(f"RETRIEVE: q='{body.question}' ds={body.dataset_ids} kg={body.use_kg}")
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
    narrative_max: int = 15  # Increased from 8 for better context coverage
    citation_max: int = 4
    top_k: int = 256
    similarity_threshold: float = 0.2
    vector_similarity_weight: float = 0.3
    keyword: bool = True
    rerank_id: Optional[str] = None
    highlight: bool = True
    
    def get_proportional_allocation(self) -> dict[str, int]:
        """
        Calculate weighted chunk allocation: primary guideline gets 60%, secondaries share 40%.
        
        Strategy:
        - Primary guideline (highest score / first in list): ~60% of narrative_max
        - Secondary guidelines: share remaining ~40% proportionally based on their scores
        - Minimum 2 chunks per secondary guideline to ensure useful context
        
        Returns dict mapping dataset name to allocated chunk count.
        """
        if not self.narrative_datasets:
            return {}
        
        n = len(self.narrative_datasets)
        
        # Single guideline gets everything
        if n == 1:
            return {self.narrative_datasets[0].name: self.narrative_max}
        
        # Primary gets 60%, secondaries share 40%
        primary_share = int(self.narrative_max * 0.60)
        secondary_pool = self.narrative_max - primary_share
        
        # First dataset is primary (highest scoring from semantic router)
        primary_ds = self.narrative_datasets[0]
        allocation = {primary_ds.name: primary_share}
        
        # Distribute secondary pool among remaining datasets
        secondary_datasets = self.narrative_datasets[1:]
        if not secondary_datasets:
            return allocation
        
        # Get scores for secondaries (default 0.5 if not provided)
        secondary_scores = [(ds.name, ds.score or 0.5) for ds in secondary_datasets]
        total_secondary_score = sum(s for _, s in secondary_scores)
        
        if total_secondary_score == 0:
            # Even split among secondaries
            per_secondary = max(2, secondary_pool // len(secondary_datasets))
            for name, _ in secondary_scores:
                allocation[name] = per_secondary
        else:
            # Proportional split based on scores
            remaining = secondary_pool
            for i, (name, score) in enumerate(secondary_scores):
                if i == len(secondary_scores) - 1:
                    # Last one gets remaining
                    allocation[name] = max(2, remaining)
                else:
                    share = int((score / total_secondary_score) * secondary_pool)
                    share = max(2, share)  # At least 2 chunks per secondary
                    allocation[name] = share
                    remaining -= share
        
        return allocation

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

        # Calculate proportional allocation based on semantic router scores
        allocation = body.get_proportional_allocation()
        logger.info(f"  Proportional allocation: {allocation}")

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
                
                # Use proportional allocation based on semantic score
                max_per = allocation.get(ds.name, max(1, body.narrative_max // max(1, len(body.narrative_datasets))))
                capped = chunks[:max_per]
                logger.info(f"  Narrative {ds.name}: retrieved={len(chunks)} allocated={max_per} capped={len(capped)} score={ds.score} ({ds_duration:.0f}ms)")
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
        
        # Isolation: Ensure citations belong to the guidelines selected by the router.
        # We inject guideline names as mandatory keywords to prevent the global search from leaking noise.
        guideline_names = [ds.name for ds in body.narrative_datasets]
        if guideline_names:
            # Use +() for mandatory inclusion in RAGFlow/ES syntax
            # If multiple guidelines, we search for EITHER name + question
            filter_str = f" +({' OR '.join([f'\"{name}\"' for name in guideline_names])})"
            isolated_question = body.question + filter_str
        else:
            isolated_question = body.question

        payload = {
            "question": isolated_question,
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
    max_routes: int = 4
    min_confidence: float = 0.35
    min_score_threshold: float = 0.68  # Absolute score floor - include all guidelines above this
    auto_translate: bool = True  # Automatically translate non-English queries before routing


class RouteResponse(BaseModel):
    method: str
    guidelines: list[dict]
    duration_ms: float
    router_available: bool
    original_query: Optional[str] = None  # Original query if translation was used
    detected_language: Optional[str] = None  # Detected source language
    translated_query: Optional[str] = None  # English translation if used
    translation_ms: Optional[float] = None  # Translation duration


@app.on_event("startup")
async def startup_event():
    if not SEMANTIC_ROUTER_AVAILABLE:
        logger.warning("Semantic router not available - LLM fallback will be used")
        return
    try:
        logger.info("Initializing semantic router (this may download ~1.2GB multilingual model on first run)...")
        semantic_router_service.initialize()
        logger.info("Semantic router initialized on startup")
        
        # Warm-up: Run a test query to ensure model is fully loaded and cached
        # This forces the embedding model to download if not already cached
        logger.info("Running warm-up query to pre-load embeddings...")
        warmup_result = semantic_router_service.route_multi("aortic aneurysm repair", max_routes=1)
        if warmup_result:
            logger.info(f"Warm-up complete - model ready. Test route: {warmup_result[0].get('guideline_key', 'unknown')}")
        else:
            logger.info("Warm-up complete - model ready (no route matched)")
            
    except Exception as e:
        logger.warning(f"Failed to initialize semantic router: {e}")


@app.post("/route")
async def route_query(request: Request, body: RouteRequest):
    start_time = datetime.now()
    
    # Translation tracking
    original_query = None
    detected_lang = None
    translated_query = None
    translation_duration = None
    query_for_routing = body.query

    if not SEMANTIC_ROUTER_AVAILABLE or not semantic_router_service or not semantic_router_service.is_initialized:
        duration = (datetime.now() - start_time).total_seconds() * 1000
        return RouteResponse(
            method="none",
            guidelines=[],
            duration_ms=duration,
            router_available=False,
        )

    try:
        # Detect language and translate if needed
        if body.auto_translate and azure_client:
            detected_lang = detect_language(body.query)
            
            if detected_lang != "en":
                original_query = body.query
                translated_query, translation_duration = translate_to_english(body.query, detected_lang)
                query_for_routing = translated_query
                logger.info(f"Query translated for routing: {detected_lang} -> en ({translation_duration:.0f}ms)")
        
        results = semantic_router_service.route_multi(
            query_for_routing, 
            max_routes=body.max_routes, 
            min_score_threshold=body.min_score_threshold,
            min_confidence=body.min_confidence,
        )
        duration = (datetime.now() - start_time).total_seconds() * 1000

        keys = [r['guideline_key'] for r in results]
        scores = [r.get('confidence', 'N/A') for r in results]
        primaries = [r.get('is_primary', False) for r in results]
        
        log_query = translated_query if translated_query else body.query
        lang_info = f" (translated from {detected_lang})" if detected_lang and detected_lang != "en" else ""
        logger.info(f"Semantic route: query='{log_query[:50]}...'{lang_info} -> {keys} (scores: {scores}, primary: {primaries}, threshold: {body.min_score_threshold}, {duration:.0f}ms)")

        return RouteResponse(
            method="semantic",
            guidelines=results,
            duration_ms=duration,
            router_available=True,
            original_query=original_query,
            detected_language=detected_lang,
            translated_query=translated_query,
            translation_ms=translation_duration,
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
    if not SEMANTIC_ROUTER_AVAILABLE or not semantic_router_service:
        return {
            "guidelines": [],
            "router_available": False,
        }
    return {
        "guidelines": semantic_router_service.get_all_guidelines(),
        "router_available": semantic_router_service.is_initialized,
    }
