# Watchdogs

These templates back the production self-healing setup.

## Hetzner all-in-one VM (`178.105.193.206`) — current production

### RAGFlow / OpenWebUI stack (`ragflow/`)

- `ragflow-platform-watchdog.sh`
  Checks all five Docker Compose services (`mysql`, `redis`, `minio`, `es01`,
  `ragflow-cpu`) and `open-webui`. If any compose service is stopped or
  unhealthy, runs `docker compose up -d` from the compose directory so all
  services are restored in dependency order. OpenWebUI is outside the compose
  stack and gets an individual `docker restart` if its HTTP check fails.
- `ragflow-platform-watchdog.service` — oneshot systemd unit.
- `ragflow-platform-watchdog.timer` — fires every 3 minutes, starts 2 min after boot.

**Recovery strategy**: `docker compose up -d` rather than individual
`docker restart` calls, so dependency ordering (ES before RAGFlow, etc.) is
always respected and stopped containers come back without manual intervention.

### Installation (Hetzner)

```bash
cp ops/watchdogs/ragflow/ragflow-platform-watchdog.sh /usr/local/bin/
chmod +x /usr/local/bin/ragflow-platform-watchdog.sh
cp ops/watchdogs/ragflow/ragflow-platform-watchdog.service /etc/systemd/system/
cp ops/watchdogs/ragflow/ragflow-platform-watchdog.timer   /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now ragflow-platform-watchdog.timer
# Verify
systemctl status ragflow-platform-watchdog.timer
```

---

## Legacy Azure VMs (decommissioned)

### Laravel VM (`135.237.148.105`) — `laravel/`

- `ragflow-bridge.service` — FastAPI bridge on `127.0.0.1:8000`.
- `laravel-platform-watchdog.sh` — checks Laravel (`/up`) and bridge (`/health`).
- `laravel-platform-watchdog.timer` — runs every 3 minutes.

### OpenWebUI + RAGFlow VM (`48.211.217.69`) — `ragflow/` (old version)

The old script monitored only `open-webui` and `docker-ragflow-cpu-1` via
`docker restart`. Superseded by the Hetzner version above.

---

## Installed Paths

Scripts → `/usr/local/bin/`  
Units  → `/etc/systemd/system/`
