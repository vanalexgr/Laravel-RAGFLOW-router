# RAGFlow & Vascular Expert Configuration Guide

This document explains all configurable settings for the medical guidelines consultation system.

## Environment Variables

Add these to your `.env` file to customize behavior.

### RAGFlow Connection

| Variable | Default | Description |
|----------|---------|-------------|
| `RAGFLOW_API_KEY` | (required) | Your RAGFlow API key |
| `RAGFLOW_ENDPOINT` | `http://localhost/api/v1` | RAGFlow API endpoint (must include `/api/v1` suffix) |
| `RAGFLOW_REQUEST_TIMEOUT` | `30` | Request timeout in seconds |

**Example:**
```env
RAGFLOW_API_KEY=ragflow-your-api-key-here
RAGFLOW_ENDPOINT=https://ragflow.clinicalguidelines.io/api/v1
RAGFLOW_REQUEST_TIMEOUT=60
```

---

### API Authentication

| Variable | Default | Description |
|----------|---------|-------------|
| `API_SECRET_KEY` | (required) | API key required for `POST /api/v1/vascular-consult` |

**Example:**
```env
API_SECRET_KEY=your-api-key-here
```

---

### Retrieval Settings

These control how documents are retrieved from RAGFlow datasets.

#### Citation quality (filtering research statements)

Some guideline exports include non-actionable "Good research statement" items. These can be useful for methodology questions but often distract from clinical questions. The bridge filters these out from `citation_chunks` when enough actionable citations remain; if filtering would drop below `citation_min`, the original set is kept.

| Variable | Default | Description |
|----------|---------|-------------|
| `RAGFLOW_TOP_K` | `40` | Number of candidate chunks retrieved before reranking |
| `RAGFLOW_SIZE` | `10` | Number of chunks returned per dataset |
| `RAGFLOW_PAGE` | `1` | Pagination page for retrieval |
| `RAGFLOW_NARRATIVE_MAX` | `10` | Max narrative chunks returned per response |
| `RAGFLOW_CITATION_MAX` | `4` | Max citation chunks returned per response |
| `RAGFLOW_SIMILARITY_THRESHOLD` | `0.2` | Minimum similarity score (0.0-1.0). Lower = more results |
| `RAGFLOW_KEYWORD_MODE` | `true` | Enable hybrid search (keyword + vector) |
| `RAGFLOW_VECTOR_WEIGHT` | `0.5` | Weight for vector similarity in hybrid search (0.0-1.0) |
| `RAGFLOW_RERANK_ID` | `Cohere-rerank-v4.0-pro___OpenAI-API` | Reranker ID string (must match tenant-authorized model name exactly) |
| `RAGFLOW_USE_KG` | `false` | Enable knowledge graph expansion (disabled by default; often noisy/brittle) |
| `RAGFLOW_CITATION_TOP_K` | `10` | Candidate pool size for citation-only retrieval |
| `RAGFLOW_HIGHLIGHT` | `false` | Include highlight snippets in results (disabled by default to reduce payload bloat) |
| `RAGFLOW_NARRATIVE_EXCERPT_MAX_CHARS` | `1000` | Max characters for narrative snippets (trimmed around query matches) |
| `RAGFLOW_QUERY_BOOSTS_ENABLED` | `true` | Enable short, rule-based phrase boosts for edge-case recall |
| `RAGFLOW_NON_A_NON_B_BOOST_ENABLED` | `true` | Add arch-focused phrases when query includes non-A non-B dissection |
| `RAGFLOW_FOCUSED_RECALL_ENABLED` | `true` | Enable focused second-pass retrieval for edge cases |
| `RAGFLOW_NON_A_NON_B_RECALL_ENABLED` | `true` | Trigger focused recall pass for non-A non-B dissection queries |
| `RAGFLOW_NON_A_NON_B_SIMILARITY_THRESHOLD` | `0.18` | Lower similarity threshold used in focused recall pass |
| `RAGFLOW_NON_A_NON_B_TOP_K` | `120` | Candidate pool size for focused recall pass |
| `RAGFLOW_NON_A_NON_B_NARRATIVE_MAX` | `40` | Narrative chunk cap for focused recall pass |
| `RAGFLOW_NON_A_NON_B_CITATION_MAX` | `30` | Citation chunk cap for focused recall pass |
| `RAGFLOW_NON_A_NON_B_KEYWORD_MODE` | `false` | Enable hybrid keyword+vector only for non-A non-B focused recall |
| `RAGFLOW_NON_A_NON_B_VECTOR_WEIGHT` | `0.5` | Vector similarity weight for non-A non-B focused recall |
| `RAGFLOW_QUALITY_PASS_ENABLED` | `false` | Enable a high-recall pass (RAGFlow UI-like hybrid settings) |
| `RAGFLOW_QUALITY_PASS_MIN_NARRATIVE` | `0` | Minimum narrative chunk count required to skip the quality pass |
| `RAGFLOW_QUALITY_PASS_MIN_CITATION` | `0` | Minimum citation chunk count required to skip the quality pass |
| `RAGFLOW_QUALITY_PASS_SIMILARITY_THRESHOLD` | `0.2` | Similarity threshold for the quality pass |
| `RAGFLOW_QUALITY_PASS_TOP_K` | `1024` | Candidate pool size for the quality pass |
| `RAGFLOW_QUALITY_PASS_KEYWORD_MODE` | `true` | Enable hybrid keyword+vector for the quality pass |
| `RAGFLOW_QUALITY_PASS_VECTOR_WEIGHT` | `0.2` | Vector similarity weight for the quality pass |
| `RAGFLOW_QUALITY_PASS_NARRATIVE_MAX` | `80` | Narrative chunk cap for the quality pass |
| `RAGFLOW_QUALITY_PASS_CITATION_MAX` | `80` | Citation chunk cap for the quality pass |

**Example:**
```env
RAGFLOW_TOP_K=40
RAGFLOW_SIZE=10
RAGFLOW_PAGE=1
RAGFLOW_NARRATIVE_MAX=10
RAGFLOW_CITATION_MAX=4
RAGFLOW_SIMILARITY_THRESHOLD=0.2
RAGFLOW_KEYWORD_MODE=true
RAGFLOW_VECTOR_WEIGHT=0.5
RAGFLOW_RERANK_ID=Cohere-rerank-v4.0-pro___OpenAI-API
RAGFLOW_USE_KG=false
RAGFLOW_CITATION_TOP_K=10
RAGFLOW_HIGHLIGHT=false
RAGFLOW_NARRATIVE_EXCERPT_MAX_CHARS=1000
RAGFLOW_QUERY_BOOSTS_ENABLED=true
RAGFLOW_NON_A_NON_B_BOOST_ENABLED=true
RAGFLOW_FOCUSED_RECALL_ENABLED=true
RAGFLOW_NON_A_NON_B_RECALL_ENABLED=true
RAGFLOW_NON_A_NON_B_SIMILARITY_THRESHOLD=0.18
RAGFLOW_NON_A_NON_B_TOP_K=120
RAGFLOW_NON_A_NON_B_NARRATIVE_MAX=40
RAGFLOW_NON_A_NON_B_CITATION_MAX=30
RAGFLOW_NON_A_NON_B_KEYWORD_MODE=false
RAGFLOW_NON_A_NON_B_VECTOR_WEIGHT=0.5
RAGFLOW_QUALITY_PASS_ENABLED=false
RAGFLOW_QUALITY_PASS_MIN_NARRATIVE=0
RAGFLOW_QUALITY_PASS_MIN_CITATION=0
RAGFLOW_QUALITY_PASS_SIMILARITY_THRESHOLD=0.2
RAGFLOW_QUALITY_PASS_TOP_K=1024
RAGFLOW_QUALITY_PASS_KEYWORD_MODE=true
RAGFLOW_QUALITY_PASS_VECTOR_WEIGHT=0.2
RAGFLOW_QUALITY_PASS_NARRATIVE_MAX=80
RAGFLOW_QUALITY_PASS_CITATION_MAX=80
```

**Query boosts:** Small, deterministic phrase additions used only for retrieval (not answer generation). They help with edge cases like non-A non-B aortic dissection without enabling keyword mode. Set either env var to `false` for immediate rollback.

**Focused recall:** Runs a second retrieval pass only when the query contains non-A non-B and the initial evidence set lacks that phrase. This pass uses a lower similarity threshold and larger candidate pool; you can optionally enable hybrid search via `RAGFLOW_NON_A_NON_B_KEYWORD_MODE=true` for higher recall. Disable via `RAGFLOW_FOCUSED_RECALL_ENABLED=false` or `RAGFLOW_NON_A_NON_B_RECALL_ENABLED=false` for instant rollback.

**Quality pass (optional):** When enabled, runs an additional high-recall retrieval pass using RAGFlow UI-like hybrid settings. Use `RAGFLOW_QUALITY_PASS_MIN_NARRATIVE` / `RAGFLOW_QUALITY_PASS_MIN_CITATION` to only trigger when coverage is thin. Keep disabled for low latency; enable selectively during evaluation.

**Note:** TOC (Table of Contents) and Auto Keywords & Meta must be configured in the RAGFlow UI when setting up the dataset, not via API.

**Operational note:** In production we keep `RAGFLOW_TOP_K` modest (e.g. 20-60), `RAGFLOW_HIGHLIGHT=false`, and a tenant-authorized `RAGFLOW_RERANK_ID` set. This keeps candidate pools tight so reranking improves quality instead of amplifying noise.

#### Bridge-side reranking (Laravel)

If RAGFlow-side reranking is too slow or unstable, you can disable `RAGFLOW_RERANK_ID` and rerank inside the Laravel bridge instead. When bridge reranking is enabled, the bridge will *not* forward `rerank_id` to RAGFlow.

| Variable | Default | Description |
|----------|---------|-------------|
| `BRIDGE_RERANK_ENABLED` | `false` | Enable bridge-side reranking |
| `BRIDGE_RERANK_PROVIDER` | `cohere` | Rerank provider (currently only `cohere` supported) |
| `BRIDGE_RERANK_ENDPOINT` | `https://api.cohere.com/v1/rerank` | Rerank API endpoint |
| `BRIDGE_RERANK_API_KEY` | _(empty)_ | API key for the rerank provider |
| `BRIDGE_RERANK_MODEL` | `rerank-english-v3.0` | Rerank model name |
| `BRIDGE_RERANK_TOP_N` | `20` | Number of top chunks to prioritize |
| `BRIDGE_RERANK_TIMEOUT` | `20` | Rerank API timeout (seconds) |

**Example:**
```env
RAGFLOW_RERANK_ID=
BRIDGE_RERANK_ENABLED=true
BRIDGE_RERANK_PROVIDER=cohere
BRIDGE_RERANK_ENDPOINT=https://api.cohere.com/v1/rerank
BRIDGE_RERANK_API_KEY=your_key_here
BRIDGE_RERANK_MODEL=rerank-english-v3.0
BRIDGE_RERANK_TOP_N=20
BRIDGE_RERANK_TIMEOUT=20
```

#### Understanding Retrieval Settings

**Top-K (RAGFLOW_TOP_K)**
- Higher values return more results but may include less relevant content
- Lower values are more focused but may miss relevant information
- Recommended: 50-256 depending on reranker capacity

**Size (RAGFLOW_SIZE)**
- Number of chunks returned per dataset after reranking
- Recommended: 6-12 depending on response length

**Similarity Threshold (RAGFLOW_SIMILARITY_THRESHOLD)**
- `0.0` = Return all results regardless of relevance
- `0.5` = Only return moderately similar results
- `1.0` = Only exact matches (rarely useful)
- Recommended: 0.2-0.4 for medical guidelines

**Keyword Mode (RAGFLOW_KEYWORD_MODE)**
- `true` = Combines keyword matching with vector search (hybrid)
- `false` = Pure vector/semantic search only
- Recommended: `true` for structured medical content

**Vector Similarity Weight (RAGFLOW_VECTOR_WEIGHT)**
- `0.0` = Pure keyword matching
- `1.0` = Pure vector/semantic matching
- `0.3` = Balanced with slight keyword preference
- Recommended: 0.3-0.5 for medical guidelines

---

### Iterative Gap Detection (Exploratory)

These flags control the second-pass retrieval loop and strict output template.

| Variable | Default | Description |
|----------|---------|-------------|
| `GAP_DETECTION_ENABLED` | `true` | Enable gap detection + focused second retrieval pass |
| `GAP_DETECTION_MAX_PASSES` | `1` | Max additional retrieval passes |
| `GAP_DETECTION_NARRATIVE_MAX` | `4` | Narrative chunk cap for second pass |
| `GAP_DETECTION_CITATION_MAX` | `3` | Citation chunk cap for second pass |
| `STRICT_TEMPLATE_ENABLED` | `true` | Enforce strict output structure hints |
| `GAP_DETECTION_DEBUG` | `false` | Include gap debug info in API response |

**Quick rollback to previous behavior:**
```env
GAP_DETECTION_ENABLED=false
STRICT_TEMPLATE_ENABLED=false
```

---

### RAGFlow Bridge (Optional)

Use the local bridge for parallel retrieval and tighter latency control.

| Variable | Default | Description |
|----------|---------|-------------|
| `RAGFLOW_USE_BRIDGE` | `false` | Route retrieval via the local bridge |
| `RAGFLOW_BRIDGE_URL` | `http://localhost:8000` | Bridge base URL |
| `RAGFLOW_BRIDGE_SECRET` | (optional) | Shared secret for bridge access |

---

### Azure OpenAI Connection

Azure OpenAI is used for guideline routing and query expansion.

| Variable | Default | Description |
|----------|---------|-------------|
| `AZURE_OPENAI_API_KEY` | (required) | Your Azure OpenAI API key |
| `AZURE_OPENAI_ENDPOINT` | (required) | Azure OpenAI endpoint URL |
| `AZURE_OPENAI_DEPLOYMENT` | `gpt-5-chat` | Deployment name |
| `AZURE_OPENAI_VERSION` | `2024-12-01-preview` | API version |

**Example:**
```env
AZURE_OPENAI_API_KEY=your-azure-key
AZURE_OPENAI_ENDPOINT=https://your-resource.cognitiveservices.azure.com
AZURE_OPENAI_DEPLOYMENT=gpt-5-chat
AZURE_OPENAI_VERSION=2024-12-01-preview
```

---

## Config Files

### config/ragflow.php

```php
return [
    'api_key' => env('RAGFLOW_API_KEY'),
    'api_endpoint' => env('RAGFLOW_ENDPOINT', 'http://localhost/api/v1'),
    'request_timeout' => env('RAGFLOW_REQUEST_TIMEOUT', 30),

    'retrieval' => [
        'top_k' => (int) env('RAGFLOW_TOP_K', 40),
        'size' => (int) env('RAGFLOW_SIZE', 10),
        'page' => (int) env('RAGFLOW_PAGE', 1),
        'similarity_threshold' => (float) env('RAGFLOW_SIMILARITY_THRESHOLD', 0.2),
        'keyword_mode' => filter_var(env('RAGFLOW_KEYWORD_MODE', true), FILTER_VALIDATE_BOOLEAN),
        'vector_similarity_weight' => (float) env('RAGFLOW_VECTOR_WEIGHT', 0.5),
        'rerank_id' => env('RAGFLOW_RERANK_ID', 'Cohere-rerank-v4.0-pro___OpenAI-API'),
        'use_kg' => filter_var(env('RAGFLOW_USE_KG', true), FILTER_VALIDATE_BOOLEAN),
        'highlight' => filter_var(env('RAGFLOW_HIGHLIGHT', true), FILTER_VALIDATE_BOOLEAN),
    ],
    // Dataset registry is defined in config/guidelines.php
];
```

### Adding New Datasets

Edit `config/guidelines.php` to add or update guideline datasets and their recommendation document IDs:

```php
'recommendations_dataset' => 'your-recommendations-dataset-id',

'categories' => [
    'peripheral_carotid' => [
        'name' => 'Peripheral & Carotid',
        'guidelines' => [
            'carotid_vertebral' => [
                'id' => 'your-guideline-dataset-id',
                'name' => 'Carotid & Vertebral',
                'recs_doc_id' => 'your-recommendations-doc-id',
                'key_concepts' => ['CEA', 'CAS', 'TIA'],
            ],
        ],
    ],
],
```

---

## OpenWebUI Tool Integration

This project integrates with OpenWebUI using a custom tool in `openwebui_tools/vascular_expert.py`.

### Tool Endpoint

- `POST /api/v1/vascular-consult`
- Requires `API_SECRET_KEY` via `Authorization: Bearer YOUR_API_KEY`
- OpenAPI spec: `public/openapi.json`

### Configure the Tool in OpenWebUI

1. Open OpenWebUI and go to **Admin Panel → Tools**.
2. Upload `openwebui_tools/vascular_expert.py`.
3. Configure tool valves:
   - `VASCULAR_API_BASE_URL`: `https://your-domain.com`
   - `VASCULAR_API_KEY`: your `API_SECRET_KEY`
4. Enable the tool for your model and use `consult_vascular_guidelines`.

### Test the Tool Endpoint

```bash
curl -X POST https://YOUR-DOMAIN.com/api/v1/vascular-consult \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -d '{
    "question": "What is the timing for CEA in symptomatic carotid stenosis?",
    "guidelines": ["carotid_vertebral"]
  }'
```

---

## MCP Integration (Optional)

This app also exposes an MCP server for integration with other clients.

- SSE stream: `GET /vascular`
- Message endpoint: `POST /vascular`
- Tool name: `consult_vascular_guidelines`

---

## Automatic Metadata Extraction

Retrieved chunks automatically extract these metadata fields:

| Field | Source | Example |
|-------|--------|---------|
| Guideline ID | Content | `ESVS_TRAUMA_2025` |
| Guideline Year | Content | `2025` |
| Recommendation ID | Content | `Rec 31` |
| Class | Content | `Class I`, `Class IIa`, `Class IIIb` |
| Level | Content | `Level A`, `Level B`, `Level C` |
| Territory | Content | `Neck`, `Pediatric`, `Thorax` |
| Similarity | RAGFlow | `257.0%` (combined score) |
| Vector Similarity | RAGFlow | `82.4%` |
| Term Similarity | RAGFlow | `11.1%` |
| Document | RAGFlow | `Rec_031.txt` |

---

## Quick Start

1. Copy `.env.example` to `.env`
2. Set your API keys:
   ```env
   RAGFLOW_API_KEY=your-ragflow-key
   RAGFLOW_ENDPOINT=https://your-ragflow-instance/api/v1
   API_SECRET_KEY=your-api-secret-key
   AZURE_OPENAI_API_KEY=your-azure-key
   AZURE_OPENAI_ENDPOINT=https://your-resource.cognitiveservices.azure.com
   AZURE_OPENAI_VERSION=2024-12-01-preview
   ```
3. Optionally customize retrieval:
   ```env
   RAGFLOW_TOP_K=10
   RAGFLOW_SIMILARITY_THRESHOLD=0.2
   RAGFLOW_KEYWORD_MODE=true
   ```
4. Clear config cache:
   ```bash
   php artisan config:clear
   ```
5. Test the tool endpoint:
   ```bash
   curl -X POST http://localhost/api/v1/vascular-consult \
     -H "Content-Type: application/json" \
     -H "Authorization: Bearer YOUR_API_KEY" \
     -d '{\"question\": \"What is carotid stenosis?\"}'
   ```

## Troubleshooting

### RAGFlow Connection Issues
- Ensure `RAGFLOW_ENDPOINT` includes `/api/v1` suffix
- Check API key is valid
- Verify dataset ID exists and you have access

### No Results Returned
- Lower `RAGFLOW_SIMILARITY_THRESHOLD` (try 0.1)
- Increase `RAGFLOW_TOP_K` (try 40)
- Enable `RAGFLOW_KEYWORD_MODE=true`
