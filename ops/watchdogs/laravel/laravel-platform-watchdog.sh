#!/usr/bin/env bash

set -euo pipefail

LARAVEL_URL="${LARAVEL_URL:-http://127.0.0.1:8001/up}"
BRIDGE_URL="${BRIDGE_URL:-http://127.0.0.1:8000/health}"
LARAVEL_SERVICE="${LARAVEL_SERVICE:-laravel-api.service}"
BRIDGE_SERVICE="${BRIDGE_SERVICE:-ragflow-bridge.service}"
HTTP_TIMEOUT="${HTTP_TIMEOUT:-10}"
WAIT_SECONDS="${WAIT_SECONDS:-30}"
LOG_TAG="${LOG_TAG:-laravel-platform-watchdog}"

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
        sleep 2
    done

    return 1
}

restart_service() {
    local service="$1"
    systemctl restart "$service"
}

ensure_service_health() {
    local label="$1"
    local service="$2"
    local url="$3"
    local exit_code=0

    if ! systemctl is-active --quiet "$service"; then
        log "$label inactive; restarting $service"
        restart_service "$service"
    elif ! check_http "$url"; then
        log "$label health check failed; restarting $service"
        restart_service "$service"
    else
        return 0
    fi

    if wait_for_http "$url"; then
        log "$label healthy after restart"
    else
        log "ERROR: $label still unhealthy after restarting $service"
        exit_code=1
    fi

    return "$exit_code"
}

main() {
    local exit_code=0

    ensure_service_health "RAGFlow bridge" "$BRIDGE_SERVICE" "$BRIDGE_URL" || exit_code=1
    ensure_service_health "Laravel API" "$LARAVEL_SERVICE" "$LARAVEL_URL" || exit_code=1

    exit "$exit_code"
}

main "$@"
