# RAGFlow & VascularExpert Agent Configuration Guide

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

### Retrieval Settings

These control how documents are retrieved from RAGFlow datasets.

| Variable | Default | Description |
|----------|---------|-------------|
| `RAGFLOW_TOP_K` | `20` | Number of chunks to retrieve initially (before reranking) |
| `RAGFLOW_TOP_N` | `6` | Number of chunks to return after reranking |
| `RAGFLOW_SIMILARITY_THRESHOLD` | `0.2` | Minimum similarity score (0.0-1.0). Lower = more results |
| `RAGFLOW_KEYWORD_MODE` | `true` | Enable hybrid search (keyword + vector) |
| `RAGFLOW_VECTOR_WEIGHT` | `0.3` | Weight for vector similarity in hybrid search (0.0-1.0) |
| `RAGFLOW_RERANK_MODEL` | `Cohere-rerank-v3-5-rdrns` | Reranking model for improved relevance |
| `RAGFLOW_USE_KNOWLEDGE_GRAPH` | `true` | Enable knowledge graph for multi-hop QA |

**Example:**
```env
RAGFLOW_TOP_K=20
RAGFLOW_TOP_N=6
RAGFLOW_SIMILARITY_THRESHOLD=0.2
RAGFLOW_KEYWORD_MODE=true
RAGFLOW_VECTOR_WEIGHT=0.3
RAGFLOW_RERANK_MODEL=Cohere-rerank-v3-5-rdrns
RAGFLOW_USE_KNOWLEDGE_GRAPH=true
```

**Note:** TOC (Table of Contents) and Auto Keywords & Meta must be configured in the RAGFlow UI when setting up the dataset, not via API.

#### Understanding Retrieval Settings

**Top-K (RAGFLOW_TOP_K)**
- Higher values return more results but may include less relevant content
- Lower values are more focused but may miss relevant information
- Recommended: 5-15 for most queries

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

### Azure OpenAI Connection

| Variable | Default | Description |
|----------|---------|-------------|
| `AZURE_OPENAI_API_KEY` | (required) | Your Azure OpenAI API key |
| `AZURE_OPENAI_ENDPOINT` | (required) | Azure OpenAI endpoint URL |
| `AZURE_OPENAI_DEPLOYMENT` | `gpt-5-chat` | Deployment name |
| `AZURE_OPENAI_API_VERSION` | `2024-12-01-preview` | API version |

**Example:**
```env
AZURE_OPENAI_API_KEY=your-azure-key
AZURE_OPENAI_ENDPOINT=https://your-resource.cognitiveservices.azure.com
AZURE_OPENAI_DEPLOYMENT=gpt-5-chat
AZURE_OPENAI_API_VERSION=2024-12-01-preview
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
        'top_k' => (int) env('RAGFLOW_TOP_K', 10),
        'similarity_threshold' => (float) env('RAGFLOW_SIMILARITY_THRESHOLD', 0.2),
        'keyword_mode' => filter_var(env('RAGFLOW_KEYWORD_MODE', true), FILTER_VALIDATE_BOOLEAN),
        'vector_similarity_weight' => (float) env('RAGFLOW_VECTOR_WEIGHT', 0.3),
    ],

    'datasets' => [
        'esvs_guidelines' => '4fff3622eb1b11f09021f2381272676b',
    ],
];
```

### Adding New Datasets

Edit `config/ragflow.php` to add more datasets:

```php
'datasets' => [
    'esvs_guidelines' => '4fff3622eb1b11f09021f2381272676b',
    'aha_guidelines' => 'your-aha-dataset-id',
    'esc_guidelines' => 'your-esc-dataset-id',
],
```

---

## Agent Configuration

### VascularExpertAgent Settings

Located in `app/Agents/VascularExpertAgent.php`:

| Property | Value | Description |
|----------|-------|-------------|
| `$provider` | `'azure'` | LLM provider (must be 'azure' for Azure OpenAI) |
| `$model` | `'gpt-5-chat'` | Model deployment name |
| `$includeHistory` | `true` | Enable multi-turn conversation memory |
| `$contextStrategy` | `'full'` | Include full conversation history |
| `$maxSteps` | `5` | Maximum tool call iterations per query |

### Memory & Multi-turn Behavior

- **includeHistory**: When `true`, the agent remembers previous messages in the session
- **contextStrategy**: 
  - `'full'` = Include all previous messages
  - `'summary'` = Include summarized history
  - `'none'` = No history (stateless)
- **maxSteps**: Limits how many times the agent can call tools in a single query (prevents infinite loops)

---

## Runtime Overrides

The `consult_guideline` tool accepts runtime overrides for retrieval settings:

```php
// The agent can override settings per-query:
{
    "topic": "Carotid",
    "question": "timing for CEA",
    "top_k": 5,                    // Override top_k
    "similarity_threshold": 0.5,   // Override threshold
    "keyword_mode": false          // Override keyword mode
}
```

This allows the agent to adjust retrieval behavior based on query complexity.

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
   AZURE_OPENAI_API_KEY=your-azure-key
   AZURE_OPENAI_ENDPOINT=https://your-resource.cognitiveservices.azure.com
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
5. Test the agent:
   ```bash
   php artisan vizra:chat vascular_expert
   ```

---

## OpenWebUI Integration

Your VascularExpert agent is exposed as an OpenAI-compatible API endpoint.

### API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v1/models` | GET | List available models |
| `/api/v1/models/{model}` | GET | Get model info |
| `/api/v1/chat/completions` | POST | Chat completions (streaming supported) |

### API Authentication

The OpenAI-compatible endpoints require API key authentication.

| Variable | Description |
|----------|-------------|
| `API_SECRET_KEY` | Required. Your API key for authenticating requests |

Pass the API key using one of these methods:
- **Authorization header**: `Authorization: Bearer YOUR_API_KEY`
- **X-API-Key header**: `X-API-Key: YOUR_API_KEY`

### Connecting OpenWebUI

1. Open OpenWebUI in your browser
2. Go to **Settings** > **Connections** > **OpenAI**
3. Click the wrench icon to **Manage**
4. Click **Add Connection**
5. Fill in:
   ```
   API URL: https://9287bb87-c6fb-4044-8c84-59a9d5d40a6c-00-bbjfytvmszqy.kirk.replit.dev/api/v1
   API Key: your-api-secret-key
   Model IDs: vascular_expert
   ```
6. Save and start chatting!

### Testing the API

```bash
# List models (requires API key)
curl https://YOUR-REPLIT-URL/api/v1/models \
  -H "Authorization: Bearer YOUR_API_KEY"

# Chat completion
curl -X POST https://YOUR-REPLIT-URL/api/v1/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -d '{
    "model": "vascular_expert",
    "messages": [{"role": "user", "content": "What is carotid stenosis?"}]
  }'

# Streaming
curl -X POST https://YOUR-REPLIT-URL/api/v1/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -d '{
    "model": "vascular_expert",
    "messages": [{"role": "user", "content": "What is carotid stenosis?"}],
    "stream": true
  }'
```

### Multi-Turn Conversations

The API automatically handles conversation context from the messages array. OpenWebUI sends the full conversation history, which is passed to the agent for context-aware responses.

For additional session persistence, you can pass a session ID header:
```bash
curl -X POST https://YOUR-REPLIT-URL/api/v1/chat/completions \
  -H "Content-Type: application/json" \
  -H "X-Session-ID: my-session-123" \
  -d '{...}'
```

---

## Troubleshooting

### RAGFlow Connection Issues
- Ensure `RAGFLOW_ENDPOINT` includes `/api/v1` suffix
- Check API key is valid
- Verify dataset ID exists and you have access

### No Results Returned
- Lower `RAGFLOW_SIMILARITY_THRESHOLD` (try 0.1)
- Increase `RAGFLOW_TOP_K` (try 20)
- Enable `RAGFLOW_KEYWORD_MODE=true`

### Agent Not Using Tools
- Ensure `protected ?string $provider = 'azure';` is set in agent class
- Verify tools array includes the tool class

### Memory Not Working
- Use same session ID across queries
- Ensure `$includeHistory = true` in agent
- Check `$contextStrategy = 'full'`
