import os
import logging
import asyncio
from datetime import datetime
from fastapi import FastAPI, Request, HTTPException
from pydantic import BaseModel
from typing import Optional
import httpx
import re


logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)

logger = logging.getLogger("ragflow_service")

# Initialize Local Reranker (FlashRank)
try:
    from flashrank import Ranker, RerankRequest
    HAS_FLASHRANK = True
    # Use Nano model (~4MB) for speed, cache in ./models
    RANKER = Ranker(model_name="ms-marco-TinyBERT-L-2-v2", cache_dir="./models")
    logger.info("Local Reranker (FlashRank) initialized successfully.")
except ImportError:
    HAS_FLASHRANK = False
    RANKER = None
    logger.warning("FlashRank not installed. Local reranking disabled.")
except Exception as e:
    HAS_FLASHRANK = False
    RANKER = None
    logger.warning(f"Failed to initialize FlashRank: {str(e)}")

app = FastAPI(title="RAGFlow Bridge Service")

RAGFLOW_API_KEY = os.getenv("RAGFLOW_API_KEY")
RAGFLOW_BASE_URL = os.getenv("RAGFLOW_ENDPOINT", "https://ragflow.clinicalguidelines.io/api/v1")
SHARED_SECRET = os.getenv("RAGFLOW_BRIDGE_SECRET")
STANDARD_TOP_K_CEILING = max(1, int(os.getenv("RAGFLOW_TOP_K_CEILING", "80")))
HIGH_RECALL_TOP_K_CEILING = max(
    STANDARD_TOP_K_CEILING,
    int(os.getenv("RAGFLOW_HIGH_RECALL_TOP_K_CEILING", "1024")),
)
SIZE_CEILING = max(1, int(os.getenv("RAGFLOW_SIZE_CEILING", "12")))


def clamp_top_k(value: int, high_recall: bool = False) -> int:
    ceiling = HIGH_RECALL_TOP_K_CEILING if high_recall else STANDARD_TOP_K_CEILING
    return max(1, min(int(value), ceiling))


def clamp_size(value: int) -> int:
    return max(1, min(int(value), SIZE_CEILING))


@app.get("/health")
async def health_check():
    """Health check endpoint for service monitoring."""
    return {
        "status": "ok",
        "service": "ragflow_bridge",
        "ragflow_configured": bool(RAGFLOW_API_KEY),
        "ragflow_endpoint": RAGFLOW_BASE_URL,
    }

@app.get("/status")
async def status():
    return {
        "service": "RAGFlow Bridge API",
        "status": "healthy",
        "version": "1.0.0",
    }

class RetrieveRequest(BaseModel):
    question: str
    dataset_ids: list[str]
    top_k: int = 60
    size: int = 10
    page: int = 1
    similarity_threshold: float = 0.2
    vector_similarity_weight: float = 0.3
    keyword: bool = True
    rerank_id: Optional[str] = None
    use_kg: bool = False
    high_recall: bool = False
    highlight: bool = False

class DatasetInfo(BaseModel):
    id: str
    name: str
    score: Optional[float] = None  # Semantic router confidence score for proportional allocation

class RetrieveMultiRequest(BaseModel):
    question: str
    datasets: list[DatasetInfo]
    top_k: int = 60
    size: int = 10
    max_per_dataset: int = 6
    max_total: int = 12
    page: int = 1
    similarity_threshold: float = 0.2
    vector_similarity_weight: float = 0.3
    keyword: bool = True
    rerank_id: Optional[str] = None
    use_kg: bool = False
    high_recall: bool = False
    highlight: bool = False

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

    effective_top_k = clamp_top_k(body.top_k, body.high_recall)
    effective_size = clamp_size(body.size)
    if effective_top_k != body.top_k:
        logger.warning(
            f"Retrieve top_k clamped from {body.top_k} to {effective_top_k} (high_recall={body.high_recall})"
        )
    if effective_size != body.size:
        logger.warning(f"Retrieve size clamped from {body.size} to {effective_size}")

    payload = {
        "question": body.question,
        "dataset_ids": body.dataset_ids,
        "top_k": effective_top_k,
        "page": body.page,
        "size": effective_size,
        "similarity_threshold": body.similarity_threshold,
        "vector_similarity_weight": body.vector_similarity_weight,
        "keyword": body.keyword,
        "highlight": body.highlight,
    }

    if body.rerank_id and body.rerank_id != "local":
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
                "top_k": effective_top_k,
                "size": effective_size,
                "high_recall": body.high_recall,
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

    effective_top_k = clamp_top_k(body.top_k, body.high_recall)
    effective_size = clamp_size(body.size)
    if effective_top_k != body.top_k:
        logger.warning(
            f"Retrieve MULTI top_k clamped from {body.top_k} to {effective_top_k} (high_recall={body.high_recall})"
        )
    if effective_size != body.size:
        logger.warning(f"Retrieve MULTI size clamped from {body.size} to {effective_size}")

    start_time = datetime.now()

    async def fetch_single_dataset(client: httpx.AsyncClient, dataset: DatasetInfo) -> dict:
        """Fetch from a single dataset and return with metadata."""
        ds_start = datetime.now()
        payload = {
            "question": body.question,
            "dataset_ids": [dataset.id],
            "top_k": effective_top_k,
            "page": body.page,
            "size": effective_size,
            "similarity_threshold": body.similarity_threshold,
            "vector_similarity_weight": body.vector_similarity_weight,
            "keyword": body.keyword,
            "highlight": body.highlight,
        }
        if body.rerank_id and body.rerank_id != "local":
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
                "top_k": effective_top_k,
                "size": effective_size,
                "high_recall": body.high_recall,
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
    citation_query: Optional[str] = None
    narrative_datasets: list[DatasetInfo]
    citation_dataset_id: str
    citation_document_ids: Optional[list[str]] = None  # NEW: for hard scoping by document ID
    narrative_max: int = 10
    citation_max: int = 4
    citation_min: int = 2
    top_k: int = 60
    similarity_threshold: float = 0.2
    citation_similarity_threshold: Optional[float] = None
    vector_similarity_weight: float = 0.3
    keyword: bool = True
    citation_top_k: Optional[int] = None
    rerank_id: Optional[str] = None
    high_recall: bool = False
    highlight: bool = False
    use_kg: bool = False  # Whether to enable Knowledge Graph for narrative retrieval
    
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
    for ds in body.narrative_datasets:
        logger.info(f"    - {ds.name}: ID={ds.id}, score={ds.score}")
    if body.rerank_id:
        logger.info(f"  Reranking ENABLED for DUAL: rerank_id={body.rerank_id}")

    effective_top_k = clamp_top_k(body.top_k, body.high_recall)
    requested_citation_top_k = body.citation_top_k if body.citation_top_k is not None else body.top_k
    effective_citation_top_k = clamp_top_k(requested_citation_top_k, body.high_recall)
    if effective_top_k != body.top_k:
        logger.warning(
            f"Retrieve DUAL narrative top_k clamped from {body.top_k} to {effective_top_k} (high_recall={body.high_recall})"
        )
    if effective_citation_top_k != requested_citation_top_k:
        logger.warning(
            f"Retrieve DUAL citation top_k clamped from {requested_citation_top_k} to {effective_citation_top_k} (high_recall={body.high_recall})"
        )

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
                "top_k": effective_top_k,
                "page": 1,
                "size": body.narrative_max,
                "similarity_threshold": body.similarity_threshold,
                "vector_similarity_weight": body.vector_similarity_weight,
                "keyword": body.keyword,
                "use_kg": bool(body.use_kg),
                "highlight": body.highlight,
            }
            # Only enable KG if explicitly requested (some datasets don't have KG or it may error)
            if body.use_kg:
                logger.info("Knowledge Graph ENABLED: use_kg=true")
            if body.rerank_id and body.rerank_id != "local":
                payload["rerank_id"] = body.rerank_id
            
            # Log full payload for debugging
            logger.info(f"  Narrative payload for {ds.name}: {payload}")

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

                # VERIFICATION: Log top 3 chunk scores to prove reranking
                for i, c in enumerate(chunks[:3]):
                    sim = c.get('similarity', 'N/A')
                    snippet = (c.get('content_with_weight') or c.get('content') or '')[:60].replace('\n', ' ')
                    logger.info(f"  [Chunk {i+1}] Score: {sim} | {snippet}...")

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
        
        if body.rerank_id == "local" and HAS_FLASHRANK and RANKER and results:
            logger.info(f"  Performing Local Reranking on retrieved chunks...")
            all_chunks = []
            for r in results:
                all_chunks.extend(r["chunks"])
            
            try:
                start_rank = datetime.now()
                passages = [
                    {
                        "id": str(i), 
                        "text": (c.get("content_with_weight") or c.get("content") or "")[:2000], 
                        "meta": {"original_index": i}
                    }
                    for i, c in enumerate(all_chunks)
                ]
                
                if passages:
                    rerank_req = RerankRequest(query=body.question, passages=passages)
                    res = RANKER.rerank(rerank_req)
                    
                    # Apply new scores
                    score_map = {r["id"]: r["score"] for r in res}
                    for i, c in enumerate(all_chunks):
                        c["similarity"] = score_map.get(str(i), c.get("similarity", 0))
                        c["_reranked"] = True
                    
                    # Sort descending by new score
                    all_chunks.sort(key=lambda x: x["similarity"], reverse=True)
                    
                    # Log top 3
                    for i, c in enumerate(all_chunks[:3]):
                        logger.info(f"  [LocalRank {i+1}] Score: {c.get('similarity'):.4f} | {(c.get('content') or '')[:50]}...")
                        
                    logger.info(f"  Local Reranking completed in {(datetime.now() - start_rank).total_seconds()*1000:.0f}ms")
                    interleaved = all_chunks
                else:
                    interleaved = []
                    
            except Exception as e:
                logger.error(f"  Local Reranking Failed: {str(e)}")
                # Fallback to interleaving
                all_chunks_lists = [r["chunks"] for r in results]
                max_rounds = max((len(c) for c in all_chunks_lists), default=0)
                interleaved = []
                for i in range(max_rounds):
                    for chunks in all_chunks_lists:
                        if i < len(chunks):
                            interleaved.append(chunks[i])
        else:
            # Standard Interleaving
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
        if body.citation_max <= 0:
            logger.info("  Citation retrieval skipped: citation_max<=0 (definition-first mode)")
            return {
                "chunks": [],
                "total": 0,
                "duration_ms": 0,
                "skipped": True,
            }

        cit_start = datetime.now()

        def _chunk_text(c: dict) -> str:
            # Prefer weighted content if present; otherwise fall back to raw content.
            return (c.get("content_with_weight") or c.get("content") or "")

        def _is_research_statement(c: dict) -> bool:
            # Many guidelines include "Good research statement" items that are not
            # actionable clinical recommendations and can distract the model.
            t = _chunk_text(c)
            if not t:
                return False
            # Match both key:value and plain text variants.
            return bool(re.search(r"\bclass\s*:\s*Good\s+research\s+statement\b", t, flags=re.I)) or (
                "good research statement" in t.lower()
            )
        
        # NEW: Get citation document IDs for hard scoping
        citation_document_ids = body.citation_document_ids or []
        
        # Adjust retrieval size based on scoping method
        if citation_document_ids:
            # With hard scoping, we don't need to over-retrieve for filtering
            retrieve_size = max(30, body.citation_max * 6)
        else:
            # Without scoping, retrieve more for potential filtering (old behavior)
            retrieve_size = body.citation_max * 3
        
        payload = {
            "question": body.citation_query or body.question,
            "dataset_ids": [body.citation_dataset_id],
            "top_k": effective_citation_top_k,
            "page": 1,
            "size": retrieve_size,
            "similarity_threshold": body.citation_similarity_threshold or body.similarity_threshold,
            "vector_similarity_weight": body.vector_similarity_weight,
            "keyword": body.keyword,
            "use_kg": False,
            "highlight": body.highlight,
            # NO use_kg - recommendations dataset doesn't have KG
        }
        
        # NEW: Add document_ids scoping if available
        if citation_document_ids:
            payload["document_ids"] = citation_document_ids
            logger.info(f"  Citation Scoping: {len(citation_document_ids)} document IDs - {citation_document_ids}")
        else:
            logger.warning("  No citation_document_ids provided - using unscoped retrieval (old behavior)")
        
        if body.rerank_id and body.rerank_id != "local":
            payload["rerank_id"] = body.rerank_id

        logger.info(f"  Citation Query: '{payload['question'][:60]}...' (retrieve {retrieve_size}, final {body.citation_max})")

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
            logger.info(f"  Raw Citations Retrieved: {len(chunks)}")
            
            # Debug: Verify all chunks are from allowed document IDs
            if citation_document_ids:
                for i, chunk in enumerate(chunks[:3]):  # Check first 3
                    doc_id = chunk.get("document_id") or chunk.get("doc_id") or chunk.get("DocumentID")
                    if doc_id:
                        if doc_id not in citation_document_ids:
                            logger.error(f"  ⚠️ SCOPE VIOLATION: chunk doc_id={doc_id} not in allowed {citation_document_ids}")
                        else:
                            logger.debug(f"  ✓ Chunk [{i}] doc_id={doc_id} (within scope)")
                    else:
                        logger.warning(f"  No document_id found in chunk [{i}]: {chunk.get('id', 'unknown')}")
            
            # Tag chunks
            for chunk in chunks:
                chunk["_source_guideline"] = "ESVS Recommendations"
                chunk["_source_dataset_id"] = body.citation_dataset_id
                chunk["_chunk_type"] = "citation"

            # Prefer clinically actionable citations over "Good research statement" citations.
            filtered = [c for c in chunks if not _is_research_statement(c)]
            if filtered and len(filtered) >= max(1, body.citation_min):
                if len(filtered) != len(chunks):
                    logger.info(
                        f"  Citation Filter: removed_research_statements={len(chunks)-len(filtered)} kept={len(filtered)}"
                    )
                chunks_for_cap = filtered
            else:
                # If filtering would leave us with too few citations, keep the original list.
                chunks_for_cap = chunks

            capped = chunks_for_cap[:body.citation_max]
            logger.info(f"  Citations: final={len(capped)} scoped_by_doc_ids={bool(citation_document_ids)} ({cit_duration:.0f}ms)")

            if len(capped) < max(1, body.citation_min):
                retry_threshold = max(0.05, (payload["similarity_threshold"] or 0.2) * 0.5)
                retry_size = max(retrieve_size * 2, body.citation_max * 8)
                retry_payload = dict(payload)
                retry_payload["similarity_threshold"] = retry_threshold
                retry_payload["size"] = retry_size
                logger.info(f"  Citation Retry: threshold={retry_threshold} size={retry_size}")

                try:
                    retry_response = await client.post(
                        f"{RAGFLOW_BASE_URL}/retrieval",
                        json=retry_payload,
                        headers={
                            "Authorization": f"Bearer {RAGFLOW_API_KEY}",
                            "Content-Type": "application/json",
                        }
                    )
                    retry_result = retry_response.json()
                    retry_chunks = retry_result.get("data", {}).get("chunks", [])
                    for chunk in retry_chunks:
                        chunk["_source_guideline"] = "ESVS Recommendations"
                        chunk["_source_dataset_id"] = body.citation_dataset_id
                        chunk["_chunk_type"] = "citation"
                    retry_capped = retry_chunks[:body.citation_max]
                    if len(retry_capped) > len(capped):
                        capped = retry_capped
                        logger.info(f"  Citation Retry Improved: final={len(capped)}")
                except Exception as e:
                    logger.warning(f"  Citation Retry FAILED - {str(e)}")

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
            if body.citation_max <= 0:
                narrative_result = await fetch_narrative_chunks(client)
                citation_result = {
                    "chunks": [],
                    "total": 0,
                    "duration_ms": 0,
                    "skipped": True,
                }
            else:
                narrative_task = fetch_narrative_chunks(client)
                citation_task = fetch_citation_chunks(client)
                narrative_result, citation_result = await asyncio.gather(narrative_task, citation_task)

        total_duration = (datetime.now() - start_time).total_seconds() * 1000

        # Cap sources for UI display (default to requested maxes)
        max_narrative_display = max(1, body.narrative_max)
        max_citation_display = max(0, body.citation_max)
        
        narrative_for_ui = narrative_result["chunks"][:max_narrative_display]
        citations_for_ui = citation_result["chunks"][:max_citation_display]
        
        logger.info(f"Retrieve DUAL complete: narrative={len(narrative_result['chunks'])}→{len(narrative_for_ui)} citations={len(citation_result['chunks'])}→{len(citations_for_ui)} total_duration={total_duration:.0f}ms")

        return {
            "status": 200,
            "duration_ms": total_duration,
            "narrative": {
                "chunks": narrative_for_ui,  # ← Capped for UI
                "total_retrieved": len(narrative_result["chunks"]),
                "count": len(narrative_for_ui),
                "per_dataset": narrative_result.get("per_dataset", []),
                "duration_ms": narrative_result.get("duration_ms", 0),
            },
            "citations": {
                "chunks": citations_for_ui,  # ← Capped for UI
                "total_retrieved": len(citation_result["chunks"]),
                "count": len(citations_for_ui),
                "duration_ms": citation_result.get("duration_ms", 0),
            },
            "retrieval_info": {
                "rerank_id": body.rerank_id,
                "narrative_use_kg": body.use_kg,
                "citation_use_kg": False,
                "narrative_max": body.narrative_max,
                "citation_max": body.citation_max,
                "citation_document_ids": body.citation_document_ids,  # ← NEW: for debugging
                "citation_query": body.citation_query or body.question,
                "citation_similarity_threshold": body.citation_similarity_threshold or body.similarity_threshold,
                "top_k": effective_top_k,
                "citation_top_k": effective_citation_top_k,
                "high_recall": body.high_recall,
                "citation_min": body.citation_min,
                "citation_skipped": bool(citation_result.get("skipped")),
                "ui_capped": True,  # ← NEW: flag indicating capping was applied
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



if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8000)
