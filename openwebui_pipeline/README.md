# ESVS Vascular Guidelines RAG Filter Pipeline for OpenWebUI

This filter pipeline enables fast (<5 second) retrieval of ESVS vascular surgery guidelines, letting OpenWebUI handle answer synthesis with native streaming.

## Architecture

```
User Question
     ↓
[OpenWebUI Filter Pipeline]
     ↓
[Laravel /api/v1/retrieve] ← Guideline selection + RAGFlow retrieval
     ↓
[Chunks injected as context]
     ↓
[OpenWebUI's LLM] ← Native streaming to user
     ↓
Answer with citations
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
| `TOP_K` | `12` (default, adjust 1-50) |
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
2. Call the Laravel API to retrieve relevant guideline chunks
3. Inject the chunks as context into your prompt
4. Pass to OpenWebUI's LLM for synthesis with streaming

## API Response Format

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
  "chunks": [
    {
      "recommendation_id": "Rec 12",
      "class": "Class I",
      "level": "Level A",
      "content": "In symptomatic patients with carotid stenosis...",
      "source_guideline": "ESVS Carotid Guidelines 2023",
      "similarity": 89.5
    }
  ],
  "chunk_count": 12,
  "duration_ms": 1850,
  "system_prompt": "You are a vascular surgery expert..."
}
```

## Troubleshooting

### Pipeline not working
- Check the OpenWebUI console logs for `[ESVS Vascular Guidelines RAG]` messages
- Verify the API URL is correct and accessible
- Ensure the API key matches your Laravel `API_SECRET_KEY`

### Slow responses
- Reduce `TOP_K` to retrieve fewer chunks
- Check RAGFlow bridge is running

### No chunks returned
- The question may not match any guideline topics
- Check Laravel logs: `storage/logs/laravel-YYYY-MM-DD.log`

## Comparison: Filter Pipeline vs Agent

| Feature | Filter Pipeline (Fast) | Agent (Full) |
|---------|----------------------|--------------|
| Response time | 3-5 seconds | 25-30 seconds |
| Streaming | Native OpenWebUI | Simulated |
| V7.7 prompt enforcement | No | Yes |
| Multi-turn memory | OpenWebUI handles | Laravel handles |
| Citation formatting | LLM-dependent | Enforced |
| Best for | General queries | Compliance-critical |
