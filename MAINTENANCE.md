# Maintenance Guide: Vascular Guideline Router

This document explains the core logic and deployment requirements for the Guideline Router to assist with future updates.

## 🧠 Core Routing Logic

The system uses a tiered approach to map clinical queries to the correct ESVS guidelines:

1.  **Abbreviation Expansion**: Acronyms (e.g., `IC`, `sTBAD`) are expanded into medical terms *before* searching.
    -   **Regex First**: Uses `QueryExpander` to find matches in the local abbreviation database.
    -   **Signal Protection**: If an acronym is found by regex, the system **skips** LLM expansion to prevent "Signal Dilution" (too many synonyms weakening the core medical context).
2.  **Semantic Search**: The expanded query is sent to the Semantic Bridge (Port 8000) using FastEmbed.
3.  **Guardrails (Clinical Filtering)**:
    -   **Exclusions**: Guidelines are removed if specific "danger" keywords are found (e.g., "claudication" excludes "Acute Limb Ischaemia").
    -   **Pinning**: If an exact high-value clinical term is found, that guideline is pinned to position #1.
    -   **Score Gap**: If the gap between candidates is small (<0.08), the system keeps both.

## 🛠️ Adding New Abbreviations

To add support for a new domain (e.g., "Venous Disease"):

1.  Create a markdown file in `storage/app/guidelines/abbr/raw/[domain].md`.
2.  Format: `| ABBR | Definition | Notes |`.
3.  Run the import script: `php scripts/update_abbreviations.php` (or similar).
4.  Clear the cache: `php artisan guidelines:clear-abbr-cache`.

## 📦 Deployment & Environment

### Docker Support
The application runs in a Docker container (`laravel.test`).
-   **OPcache**: PHP-FPM inside the container caches code. After making changes to `GuidelineRouterService.php`, you **must** restart the container:
    ```bash
    sudo docker restart laravel-ragflow-laravel.test-1
    ```
-   **Volumes**: The project is mounted at `/var/www/html` inside the container, linked to the host folder.

### Permissions
The `storage/` folder must be writable by the user inside the container (usually `sail` or your host user).
```bash
sudo chown -R $USER:$USER storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

## 🔍 Troubleshooting 422 Errors

If the system returns 422 ("Unable to identify relevant guidelines"):
-   **Check Bridge**: Ensure `ragflow-bridge` container is running and reachable at `http://ragflow-bridge:8000`.
-   **Check Expansion**: Run `php artisan router:debug "your query"` and look at the `Expanded Query`. If it's too long/noisy, it will cause a 422.
-   **Check Cache**: Clear both host and container caches.
