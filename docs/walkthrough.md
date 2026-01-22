# RAGFlow Optimization & Interactive Experience Walkthrough

## 🚀 Goal
Optimize the system's performance and enhance the user experience by implementing interactive guideline selection.

## ⚡ Performance Tuning
We identified latency bottlenecks in the retrieval process and effectively addressed them:

1.  **Reduced Retrieval Overhead**:
    *   Lowered `RAGFLOW_TOP_K` from default (256/1024) to **50**. This reduces the number of chunks sent to the reranker, significantly speeding up processing.
2.  **Disabled Query Expansion**:
    *   Set `RAGFLOW_QUERY_EXPANSION=false`. This saves approximately 1-2 seconds per request by skipping an additional LLM round-trip, relying instead on the robust semantic search capabilities.

## 🎮 Interactive User Experience
We transformed the static retrieval experience into an app-like interactive session using OpenWebUI features.

### New Pipeline: `esvs_interactive_pipeline.py`
We created a new pipeline (v3.1) that intercepts specific user commands to manage "Scopes" (locking retrieval to specific guidelines) without always requiring full LLM processing for control logic.

**Features:**
*   **State Management**: Tracks selected guidelines for each conversation.
*   **Command Interception**: Process commands locally in the pipeline for instant feedback.
*   **System Prompt Injection**: Automatically teaching the LLM about its new capabilities.

### 🕹️ Commands
| Command | Description |
| :--- | :--- |
| `/scope [key]` | Locks retrieval to specific guideline(s). <br>Example: `/scope carotid_vertebral` |
| `/auto` | Resets the system to automatic semantic routing. |
| `/menu` | Displays the **Clickable Menu** of all available guidelines. |

### 📸 Demo Verified
User confirmed the experience with the `/menu` command:
> 📚 Available VS Guidelines
> Click an option to lock the assistant's scope:
> [🧠 **Carotid & Vertebral**](message:/scope carotid_vertebral)
> ...

## 🛠️ Files Modified
*   `Laravel-RAGFLOW-router/.env.example`: Added performance tuning variables.
*   `config/ragflow.php`: Mapped env variables to config.
*   `openwebui_pipeline/esvs_interactive_pipeline.py`: **[NEW]** The core logic for interactions.

## 🐛 Incident Log: Server 500 Errors
During the deployment of the new "Scope Persistence" feature, you experienced **HTTP 500 Errors** (Server Crashes).

**Cause 1: Missing Import**
*   **Issue**: I added code to save scopes to the database (`Cache::put`), but I forgot to tell PHP where the `Cache` tool was located (`use Illuminate\Support\Facades\Cache;`).
*   **Result**: The code crashed with "Class 'Cache' not found" whenever a request was made.

**Cause 2: Syntax Error**
*   **Issue**: While fixing the first error, a copy-paste mistake left a stray line of code (`return $truncated;`) floating outside of any function.
*   **Result**: PHP refused to run the file because it was invalid code.

**Resolution:**
Both issues were fixed in the codebase on GitHub. The server was restarted to load the corrected code.
