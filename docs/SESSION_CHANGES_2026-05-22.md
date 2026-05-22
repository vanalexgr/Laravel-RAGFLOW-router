# Session Changes - 2026-05-22

This document summarizes the infrastructure migration and production fixes completed during the May 22, 2026 session.

## Scope

Primary goals covered:

- migrate the production stack from Azure VMs to Hetzner
- restore RAGFlow data on the new host
- switch Hetzner RAGFlow from `DOC_ENGINE=infinity` to `DOC_ENGINE=elasticsearch`
- verify Laravel, RAGFlow, OpenWebUI, the bridge service, and the vascular MCP service on Hetzner
- confirm that the legacy `pipelines` container was no longer in active use
- remove the remaining Azure Blob dependency for guideline images

## Final Production State

### Hetzner host

- Host: `178.105.193.206`
- Laravel app: `/opt/cg/laravel/app`
- RAGFlow: `/opt/cg/ragflow/ragflow`
- OpenWebUI: `/opt/cg/openwebui`
- Vascular MCP: `/opt/cg/vascular-mcp`

### Public routing

- `chat.clinicalguidelines.io` points to Hetzner and serves OpenWebUI
- `ragflow.clinicalguidelines.io` points to Hetzner and serves RAGFlow
- `clinicalguidelines.io` remained outside this cutover at session end and still resolved elsewhere

### Laravel runtime

- Caddy fronts the Laravel app
- PHP-FPM replaced the old `php artisan serve` production runtime
- the Hetzner deployment uses the real public root under `public/`

### RAGFlow runtime

- `DOC_ENGINE=elasticsearch`
- `COMPOSE_PROFILES=elasticsearch,cpu`
- restored Elasticsearch chunk count: `72,292`
- retrieval verified successfully against the restored knowledge base

### OpenWebUI and side services

- OpenWebUI restored and healthy
- OIDC environment restored
- `ragflow-bridge.service` healthy
- `vascular-mcp.service` healthy
- `pipelines` intentionally not redeployed on Hetzner

## Repository Changes

### Files updated

- `app/Services/GuidelineAssetService.php`
- `config/guideline_assets.php`
- `resources/guideline_assets/manifest.json`
- `docs/GUIDELINE_ASSETS.md`

### Files added

- `docs/SESSION_CHANGES_2026-05-22.md`

## Guideline Asset Migration

The guideline asset manifest previously carried Azure Blob URLs for every original image and thumbnail. That kept production dependent on the old Azure Blob container even after the application had moved to Hetzner.

Final state:

- all original images and thumbnails were copied onto Hetzner local storage
- the manifest was normalized to path-based records only
- asset URLs are now generated at runtime
- thumbnail URLs are generated the same way as original asset URLs
- the app now supports `GUIDELINE_ASSET_BASE_URL` and `GUIDELINE_ASSET_URL_PREFIX` so assets can be served from a host different from `APP_URL`

Production serving path:

- originals: `/storage/guideline_assets/...`
- thumbnails: `/storage/thumbnails/...`
- current public asset host: `https://chat.clinicalguidelines.io`

## Production-Only Changes Outside The Repo

These changes were applied on the live Hetzner server and are not fully represented in the repository:

- Caddy was configured to serve `/storage/*` from the Laravel public root on `chat.clinicalguidelines.io`
- Laravel `.env` was updated with:
  - `GUIDELINE_ASSET_DISK=public`
  - `GUIDELINE_ASSET_BASE_URL=https://chat.clinicalguidelines.io`
  - `GUIDELINE_ASSET_URL_PREFIX=/storage`
- the guideline asset corpus was copied into `storage/app/public/`
- the RAGFlow Docker stack was switched from Infinity to Elasticsearch
- the temporary Elasticsearch migration script and log were removed from the RAGFlow container
- the temporary Azure Elasticsearch SSH tunnel on port `19200` was removed

## Verification Summary

- Hetzner RAGFlow Elasticsearch document count matched the Azure source: `72,292`
- Laravel health endpoint responded successfully on Hetzner
- OpenWebUI and RAGFlow both responded successfully on Hetzner
- guideline asset payloads now emit `chat.clinicalguidelines.io/storage/...` URLs
- sample original and thumbnail asset URLs returned `200`
- no live application/config/resource references to `pngsrag.blob.core.windows.net` remained after the manifest cleanup

## Follow-Up Fix: Historical OpenWebUI Messages

After the asset migration, old OpenWebUI chats still rendered Azure Blob URLs because those URLs were already persisted inside historical assistant messages and chat JSON in the OpenWebUI SQLite database.

Fix applied on the live Hetzner host:

- generated an exact old-to-new asset URL map from the previous manifest revision
- rewrote persisted OpenWebUI chat records to replace Azure Blob URLs with the new `chat.clinicalguidelines.io/storage/...` URLs
- restarted OpenWebUI after the SQLite rewrite
- verified that the database no longer contained `pngsrag.blob.core.windows.net` references

## Operational Notes

- the local repository already contained unrelated in-progress changes during this session
- only the migration and asset-hosting changes from this session should be bundled together for publication
- the apex DNS cutover for `clinicalguidelines.io` should still be handled separately if the main site is intended to move off its current host
