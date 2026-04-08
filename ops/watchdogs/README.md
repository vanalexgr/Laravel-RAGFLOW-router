# Watchdogs

These templates back the production self-healing setup on the Azure VMs.

## Laravel VM (`135.237.148.105`)

- `ragflow-bridge.service`
  Runs the FastAPI bridge on `127.0.0.1:8000` under `systemd`.
- `laravel-platform-watchdog.service`
  Checks Laravel (`/up`) and the bridge (`/health`), then restarts the specific service if either one is down.
- `laravel-platform-watchdog.timer`
  Runs the Laravel watchdog every 3 minutes.

## OpenWebUI + RAGFlow VM (`48.211.217.69`)

- `ragflow-platform-watchdog.service`
  Checks `open-webui` and `docker-ragflow-cpu-1`, then restarts the affected container if it is unhealthy.
- `ragflow-platform-watchdog.timer`
  Runs the container watchdog every 3 minutes.

## Installed Paths

The scripts are installed to `/usr/local/bin/` and the units to `/etc/systemd/system/`.
