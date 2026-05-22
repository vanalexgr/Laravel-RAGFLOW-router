#!/usr/bin/env bash

# Watchdog for the RAGFlow/OpenWebUI Docker Compose stack on Hetzner.
# Runs every 3 minutes via ragflow-platform-watchdog.timer.
# Recovery strategy: if any compose service is down/unhealthy, run
# "docker compose up -d" which restores all services in dependency order.

COMPOSE_DIR="${COMPOSE_DIR:-/opt/cg/ragflow/ragflow/docker}"
OPENWEBUI_CONTAINER="${OPENWEBUI_CONTAINER:-open-webui}"
OPENWEBUI_URL="${OPENWEBUI_URL:-http://127.0.0.1:8080/api/version}"
RAGFLOW_CONTAINER="${RAGFLOW_CONTAINER:-docker-ragflow-cpu-1}"
RAGFLOW_PORT="${RAGFLOW_PORT:-9380}"
HTTP_TIMEOUT="${HTTP_TIMEOUT:-10}"
WAIT_SECONDS="${WAIT_SECONDS:-120}"
LOG_TAG="${LOG_TAG:-ragflow-platform-watchdog}"

# Compose-managed containers in start order (dependencies first)
COMPOSE_CONTAINERS=(
    docker-mysql-1
    docker-redis-1
    docker-minio-1
    docker-es01-1
    docker-ragflow-cpu-1
)

log() {
    logger -t "$LOG_TAG" "$1"
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1"
}

container_ok() {
    local name="$1"
    local running health
    running="$(docker inspect -f '{{.State.Running}}' "$name" 2>/dev/null || echo false)"
    [[ "$running" != "true" ]] && return 1
    health="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$name" 2>/dev/null || echo missing)"
    [[ "$health" == "unhealthy" ]] && return 1
    return 0
}

http_responds() {
    local url="$1"
    local code
    code="$(curl -s --max-time "$HTTP_TIMEOUT" -o /dev/null -w '%{http_code}' "$url" 2>/dev/null)"
    [[ "$code" != "000" ]]
}

wait_for_http() {
    local url="$1"
    local deadline=$((SECONDS + WAIT_SECONDS))
    while (( SECONDS < deadline )); do
        http_responds "$url" && return 0
        sleep 5
    done
    return 1
}

ensure_compose_stack() {
    local needs_recovery=0

    for name in "${COMPOSE_CONTAINERS[@]}"; do
        if ! container_ok "$name"; then
            log "Container $name is not healthy; triggering compose recovery"
            needs_recovery=1
            break
        fi
    done

    [[ "$needs_recovery" -eq 0 ]] && return 0

    log "Running docker compose up -d in $COMPOSE_DIR"
    docker compose -f "$COMPOSE_DIR/docker-compose.yml" up -d 2>&1 | while read -r line; do
        log "  compose: $line"
    done

    # Wait for RAGFlow to accept connections
    if ! wait_for_http "http://127.0.0.1:${RAGFLOW_PORT}/"; then
        log "ERROR: RAGFlow at :${RAGFLOW_PORT} did not recover within ${WAIT_SECONDS}s"
        return 1
    fi

    log "Compose stack recovered successfully"
    return 0
}

ensure_openwebui() {
    if container_ok "$OPENWEBUI_CONTAINER" && http_responds "$OPENWEBUI_URL"; then
        return 0
    fi

    log "OpenWebUI unhealthy; restarting $OPENWEBUI_CONTAINER"
    docker restart "$OPENWEBUI_CONTAINER" >/dev/null 2>&1

    if ! wait_for_http "$OPENWEBUI_URL"; then
        log "ERROR: OpenWebUI did not recover after restart"
        return 1
    fi

    log "OpenWebUI healthy after restart"
}

main() {
    local exit_code=0

    ensure_compose_stack  || exit_code=1
    ensure_openwebui      || exit_code=1

    exit "$exit_code"
}

main "$@"
