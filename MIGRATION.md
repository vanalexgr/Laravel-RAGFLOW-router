# Migration Guide: Hybrid Router & Guardrails (Jan 2026)

This update introduces a Hybrid Router (Semantic + BM25) with a comprehensive Guardrail System for accurate clinical guideline selection.

## 🚀 Key Features
- **Hybrid Search**: Combines Dense Embeddings with BM25 for better keyword matching.
- **Guardrails**: pin/exclude/companion logic to handle edge cases (e.g., Trauma exclusion, VGEI collision).
- **Abbreviation Expansion**: Automatically expands acronyms (e.g. `sTBAD` -> `type B aortic dissection`).
- **Caching**: Routing results are cached for 1 hour to improve performance.

## 📦 Deployment Steps

### 1. Update Configuration
Ensure `config/router_abbreviations.php` is present. This file dictates the 14-guideline priority order and guardrail rules.

### 2. Clear Caches
The new routing logic uses Laravel's Cache facade.
```bash
# Clear application cache
php artisan cache:clear

# Clear abbreviation cache
php artisan guidelines:clear-abbr-cache
```

### 3. Verify Environment
Ensure the following env variables are set (defaults provided):
```ini
RAGFLOW_BRIDGE_URL=http://ragflow-bridge:8000
ABBREVIATION_EXPANSION_ENABLED=true
ROUTER_GUARDRAILS_ENABLED=true
```

### 4. Restart Services
If running in Docker/Sail:
```bash
./vendor/bin/sail restart laravel.test
```
Or for the bridge:
```bash
docker-compose restart ragflow-bridge
```

## 🧪 Verification

Run the Golden Validation Suite to verify routing accuracy (Target: 100%):
```bash
php artisan test:golden
```

## 🔍 Troubleshooting

- **422 Errors**: Usually means the bridge is unreachable or the query expands to be too long. Check `storage/logs/laravel.log`.
- **Wrong Routing**: Check `config/router_abbreviations.php` or `storage/keywords/*.json` for missing/conflicting keywords.
- **Cache Persistence**: If changes aren't reflecting, force clear cache or disable caching temporarily in `GuidelineRouterService.php`.

## 📂 File Structure Changes
- `storage/keywords/*.json`: External keyword files for each of the 14 guidelines.
- `app/Services/Routing/`: New folder containing `GuardrailDecider`, `QueryExpander`, etc.
