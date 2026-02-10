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
| `RAGFLOW_TOP_K` | `256` | Number of candidate chunks retrieved before reranking |
| `RAGFLOW_SIZE` | `10` | Number of chunks returned per dataset |
| `RAGFLOW_PAGE` | `1` | Pagination page for retrieval |
| `RAGFLOW_SIMILARITY_THRESHOLD` | `0.2` | Minimum similarity score (0.0-1.0). Lower = more results |
| `RAGFLOW_KEYWORD_MODE` | `true` | Enable hybrid search (keyword + vector) |
| `RAGFLOW_VECTOR_WEIGHT` | `0.3` | Weight for vector similarity in hybrid search (0.0-1.0) |
| `RAGFLOW_RERANK_ID` | `Cohere-rerank-v4.0-pro___OpenAI-API` | Reranker ID string (must match tenant-authorized model name exactly) |
| `RAGFLOW_USE_KG` | `true` | Enable knowledge graph expansion |
| `RAGFLOW_HIGHLIGHT` | `true` | Include highlight snippets in results |

**Example:**
```env
RAGFLOW_TOP_K=256
RAGFLOW_SIZE=10
RAGFLOW_PAGE=1
RAGFLOW_SIMILARITY_THRESHOLD=0.2
RAGFLOW_KEYWORD_MODE=true
RAGFLOW_VECTOR_WEIGHT=0.3
RAGFLOW_RERANK_ID=Cohere-rerank-v4.0-pro___OpenAI-API
RAGFLOW_USE_KG=true
RAGFLOW_HIGHLIGHT=true
```

**Note:** TOC (Table of Contents) and Auto Keywords & Meta must be configured in the RAGFlow UI when setting up the dataset, not via API.

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
        'top_k' => (int) env('RAGFLOW_TOP_K', 256),
        'size' => (int) env('RAGFLOW_SIZE', 10),
        'page' => (int) env('RAGFLOW_PAGE', 1),
        'similarity_threshold' => (float) env('RAGFLOW_SIMILARITY_THRESHOLD', 0.2),
        'keyword_mode' => filter_var(env('RAGFLOW_KEYWORD_MODE', true), FILTER_VALIDATE_BOOLEAN),
        'vector_similarity_weight' => (float) env('RAGFLOW_VECTOR_WEIGHT', 0.3),
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
- Increase `RAGFLOW_TOP_K` (try 20)
- Enable `RAGFLOW_KEYWORD_MODE=true`
