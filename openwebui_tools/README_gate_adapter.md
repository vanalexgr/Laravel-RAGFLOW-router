# Clinical Gate OpenWebUI adapter

`gate_adapter.py` is a new, thin OpenWebUI Tool for
`POST /api/v1/clinical-gate`. It relays backend progress and citations and
renders the backend-provided Markdown; it contains no clinical reasoning.

## Load it

1. In OpenWebUI, open **Workspace → Tools → Create a Tool**.
2. Create a **new** tool record and paste in `gate_adapter.py`.
3. Save it, enable it for the intended model, and configure its Valves.

Do **not** paste this file over the production `vascular_mcp_adapter` tool or
reuse/overwrite that tool's database record. This adapter must be installed as
a separate new tool.

## Valves

- `CLINICAL_GATE_BASE_URL`: Laravel API origin, without a trailing API path.
- `CLINICAL_GATE_API_KEY`: API key sent as `Authorization: Bearer <key>`.
- `REQUEST_TIMEOUT_SECONDS`: main request timeout; default `120`.
- `POLL_INTERVAL_SECONDS`: progress polling interval; default `2`.
- `EMIT_STATUS`: emit real backend progress to OpenWebUI; default `true`.

The backend must expose:

- `POST /api/v1/clinical-gate`
- `GET /api/v1/gate-progress/{request_id}`

Citation chips use each backend citation's `id` as OpenWebUI's `source.id`.
Consequently, a backend marker such as `[3]` resolves to the citation whose
`id` is `3`; the adapter does not renumber or rewrite answer markers.
