# ESVS Vascular Guidelines RAG Filter Pipeline for OpenWebUI

This filter pipeline enables fast (<5 second) retrieval of ESVS vascular surgery guidelines with **dual-source retrieval**:
- **Narrative chunks** (KG-enabled) for clinical synthesis
- **Citation chunks** (metatag-rich) for verbatim recommendations

## Architecture (v2.0)

```
User Question
     ↓
[OpenWebUI Filter Pipeline]
     ↓
[Laravel /api/v1/retrieve]
     ↓
[RAGFlow Bridge /retrieve_dual]
     ├── Narrative: Full guideline datasets (use_kg=true)
     └── Citations: Recommendations dataset (use_kg=false, metatags)
     ↓
[Dual chunks injected as context]
     ↓
[OpenWebUI's LLM] ← Native streaming to user
     ↓
Answer with synthesis + verbatim citations
```

## Installation

### Step 1: Get Your API URL and Key

1. Your Laravel app URL: `https://your-app.replit.app`
2. Your API key: The value of `API_SECRET_KEY` in your environment

### Step 2: Install the Pipeline in OpenWebUI

1. Go to **Admin Panel → Settings → Pipelines**
2. Click **"Add Pipeline"** or **"Upload"**
3. Upload the `esvs_rag_filter.py` file
4. The pipeline will appear in the list

### Step 3: Configure the Pipeline

Click on the pipeline to configure these settings ("Valves"):

| Setting | Value |
|---------|-------|
| `RETRIEVE_API_URL` | `https://your-app.replit.app/api/v1/retrieve` |
| `API_KEY` | Your `API_SECRET_KEY` value |
| `TOP_K` | `12` (default, 8 narrative + 4 citations) |
| `ENABLE_RAG` | `true` |
| `TIMEOUT_SECONDS` | `30` |
| `INJECT_SYSTEM_PROMPT` | `true` |

### Step 4: Enable for Models

1. Go to **Admin Panel → Settings → Models**
2. Select the model you want to use (e.g., GPT-4, Claude)
3. Enable the "ESVS Vascular Guidelines RAG" filter for that model

## Usage

Once configured, simply chat with your model. The pipeline will:

1. Intercept your question
2. Call the Laravel API for dual-source retrieval
3. Inject **narrative chunks** (for synthesis) and **citation chunks** (for verbatim quotes)
4. Pass to OpenWebUI's LLM for synthesis with streaming

## API Response Format (v2.0)

The Laravel `/api/v1/retrieve` endpoint returns:

```json
{
  "success": true,
  "question": "carotid stenosis management",
  "selected_guidelines": {
    "carotid_vertebral": {
      "id": "...",
      "name": "ESVS Carotid Guidelines 2023"
    }
  },
  "narrative_chunks": [
    {
      "type": "narrative",
      "content": "Full guideline text with KG expansion...",
      "source_guideline": "ESVS Carotid Guidelines 2023",
      "similarity": 92.5
    }
  ],
  "citation_chunks": [
    {
      "type": "citation",
      "recommendation_id": "Rec 12",
      "class": "Class I",
      "level": "Level A",
      "guideline": "Carotid",
      "text": "In symptomatic patients with carotid stenosis...",
      "similarity": 89.5
    }
  ],
  "narrative_count": 8,
  "citation_count": 4,
  "duration_ms": 3500,
  "system_prompt": "You are an ESVS clinical guideline assistant..."
}
```

## Dual-Source Retrieval

| Source | Purpose | KG | Metatags |
|--------|---------|-----|----------|
| Narrative chunks | Clinical synthesis | Enabled | Limited |
| Citation chunks | Verbatim quotes | Disabled | Full (rec_id, class, level) |

**Why dual sources?**
- Narrative chunks provide rich context from knowledge graph expansion
- Citation chunks preserve exact recommendation text with metadata for proper citation

## Troubleshooting

### Pipeline not working
- Check the OpenWebUI console logs for `[ESVS Vascular Guidelines RAG]` messages
- Verify the API URL is correct and accessible
- Ensure the API key matches your Laravel `API_SECRET_KEY`

### Slow responses
- Normal response time is 3-5 seconds
- Check RAGFlow bridge is running: `RAGFlow Bridge` workflow
- Reduce TOP_K if still slow

### No chunks returned
- The question may not match any guideline topics
- Check retrieval logs: `storage/logs/retrieval-YYYY-MM-DD.log`

## Comparison: Filter Pipeline vs Agent

| Feature | Filter Pipeline (Fast) | Agent (Full) |
|---------|----------------------|--------------|
| Response time | 3-5 seconds | 25-30 seconds |
| Streaming | Native OpenWebUI | Simulated |
| Dual retrieval | Yes (v2.0) | Yes |
| Multi-turn memory | OpenWebUI handles | Laravel handles |
| Citation formatting | LLM-guided | V7.7 enforced |
| Best for | General queries | Compliance-critical |
