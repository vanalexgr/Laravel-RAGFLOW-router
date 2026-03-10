# Guideline Figure/Table Assets

Some guideline figures (diagnostic/treatment algorithms, flowcharts, complex tables) lose meaning when chunked to text.
This project supports attaching the original figure/table images to tool responses when relevant so end users can view them.

## How It Works

- Assets (PNG/WebP) are stored on a Laravel filesystem disk (default: `public`).
- A JSON manifest maps guideline keys (from `config/guidelines.php`) to assets and metadata.
- During retrieval, the tool response includes an `assets` array when relevant assets are detected.
  - Primary match: narrative chunks mention `Figure X`, `Table Y`, or `Algorithm Z`.
  - Fallback: local BM25-style ranking against asset metadata (scoped to the guideline(s) that contributed evidence to the answer).

Implementation:
- Config: `config/guideline_assets.php`
- Matcher: `app/Services/GuidelineAssetService.php`
- Tool output: `app/Http/Controllers/ToolController.php`

## Manifest Format

Manifest path (default): `resources/guideline_assets/manifest.json`

Shape:

```json
{
  "clti": [
    {
      "id": "fig_p033_004",
      "kind": "figure",
      "subtype": "flowchart",
      "label": "Figure 4",
      "caption": "Flowchart for Investigating Suspected CLTI",
      "description": "Optional longer description",
      "keywords": ["CLTI", "ABI", "diagnostic flowchart"],
      "path": "guideline_assets/clti/figures/fig_p033_004.png",
      "aliases": ["Fig. 4", "Algorithm 4"]
    }
  ]
}
```

Notes:
- Prefer `path` over `url`. The system calls `Storage::disk(...)->url(path)` to generate the display URL.
- If you already uploaded blobs to a public container and do not want to configure an Azure filesystem driver in Laravel, you can set `url` per asset (the service will use it as-is).
- If you need per-asset signed URLs (SAS), you can either:
  - Configure the disk `url()` to return SAS URLs, or
  - Put a full `url` on each asset and omit `path`.

## Importing Crops (CLTI Example)

If you have crops + metadata in a folder like `/Volumes/macshare/guidelines/di_crops`:

```bash
node scripts/import_guideline_assets.mjs --src /Volumes/macshare/guidelines/di_crops --guideline-key clti
```

This will:
- Copy PNGs into `storage/app/public/guideline_assets/clti/...`
- Create/update `resources/guideline_assets/manifest.json`

## Using Azure Blob Storage

Set:
- `GUIDELINE_ASSET_DISK=azure`

Then ensure:
- Your Azure disk is defined in `config/filesystems.php`.
- `Storage::disk('azure')->url($path)` returns a URL the UI can fetch (public container or SAS URL).
