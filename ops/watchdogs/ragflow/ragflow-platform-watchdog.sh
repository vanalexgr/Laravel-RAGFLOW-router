#!/usr/bin/env bash

set -euo pipefail

OPENWEBUI_CONTAINER="${OPENWEBUI_CONTAINER:-open-webui}"
OPENWEBUI_URL="${OPENWEBUI_URL:-http://127.0.0.1:8080/api/version}"
RAGFLOW_CONTAINER="${RAGFLOW_CONTAINER:-docker-ragflow-cpu-1}"
RAGFLOW_URL="${RAGFLOW_URL:-http://127.0.0.1:8082/}"
HTTP_TIMEOUT="${HTTP_TIMEOUT:-10}"
WAIT_SECONDS="${WAIT_SECONDS:-60}"
LOG_TAG="${LOG_TAG:-ragflow-platform-watchdog}"

log() {
    local message="$1"
    logger -t "$LOG_TAG" "$message"
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$message"
}

check_http() {
    local url="$1"
    curl -fsS --max-time "$HTTP_TIMEOUT" "$url" >/dev/null 2>&1
}

wait_for_http() {
    local url="$1"
    local timeout="${2:-$WAIT_SECONDS}"
    local deadline=$((SECONDS + timeout))

    while (( SECONDS < deadline )); do
        if check_http "$url"; then
            return 0
        fi
        sleep 3
    done

    return 1
}

container_running() {
    local name="$1"
    [[ "$(docker inspect -f '{{.State.Running}}' "$name" 2>/dev/null || echo false)" == "true" ]]
}

container_health() {
    local name="$1"
    docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$name" 2>/dev/null || echo missing
}

restart_container() {
    local name="$1"
    docker restart "$name" >/dev/null
}

ensure_openwebui() {
    if ! container_running "$OPENWEBUI_CONTAINER"; then
        log "OpenWebUI container not running; restarting $OPENWEBUI_CONTAINER"
        restart_container "$OPENWEBUI_CONTAINER"
    else
        local health
        health="$(container_health "$OPENWEBUI_CONTAINER")"
        if [[ "$health" != "healthy" && "$health" != "none" ]]; then
            log "OpenWebUI health is $health; restarting $OPENWEBUI_CONTAINER"
            restart_container "$OPENWEBUI_CONTAINER"
        elif ! check_http "$OPENWEBUI_URL"; then
            log "OpenWebUI HTTP check failed; restarting $OPENWEBUI_CONTAINER"
            restart_container "$OPENWEBUI_CONTAINER"
        else
            return 0
        fi
    fi

    if ! wait_for_http "$OPENWEBUI_URL"; then
        log "ERROR: OpenWebUI did not recover after restart"
        return 1
    fi

    log "OpenWebUI healthy after restart"
}

ensure_ragflow() {
    if ! container_running "$RAGFLOW_CONTAINER"; then
        log "RAGFlow container not running; restarting $RAGFLOW_CONTAINER"
        restart_container "$RAGFLOW_CONTAINER"
    elif ! check_http "$RAGFLOW_URL"; then
        log "RAGFlow HTTP check failed; restarting $RAGFLOW_CONTAINER"
        restart_container "$RAGFLOW_CONTAINER"
    else
        return 0
    fi

    if ! wait_for_http "$RAGFLOW_URL"; then
        log "ERROR: RAGFlow did not recover after restart"
        return 1
    fi

    log "RAGFlow healthy after restart"
}

main() {
    local exit_code=0

    ensure_openwebui || exit_code=1
    ensure_ragflow || exit_code=1

    exit "$exit_code"
}

main "$@"
