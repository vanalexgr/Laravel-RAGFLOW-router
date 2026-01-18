#!/bin/bash

set -e

if [ -n "$REPL_HOME" ]; then
    PROJECT_ROOT="$REPL_HOME"
elif [ -n "$HOME" ]; then
    PROJECT_ROOT="$HOME/workspace"
else
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
fi

echo "[Production] Starting ESVS Medical Guidelines API..."
echo "[Production] Project root: $PROJECT_ROOT"
echo "[Production] Current directory: $(pwd)"

if [ ! -f "$PROJECT_ROOT/artisan" ]; then
    echo "[Production] ERROR: Laravel artisan not found at $PROJECT_ROOT/artisan"
    echo "[Production] Trying alternative paths..."
    
    for try_path in "/home/runner/workspace" "/home/runner" "$(pwd)"; do
        if [ -f "$try_path/artisan" ]; then
            PROJECT_ROOT="$try_path"
            echo "[Production] Found Laravel at: $PROJECT_ROOT"
            break
        fi
    done
fi

if [ ! -f "$PROJECT_ROOT/artisan" ]; then
    echo "[Production] ERROR: Could not find Laravel installation"
    exit 1
fi

wait_for_port() {
    local port=$1
    local max_attempts=30
    local attempt=0
    
    while [ $attempt -lt $max_attempts ]; do
        if nc -z localhost $port 2>/dev/null; then
            return 0
        fi
        sleep 1
        attempt=$((attempt + 1))
    done
    return 1
}

echo "[Production] Starting RAGFlow Bridge on port 8000..."
cd "$PROJECT_ROOT/ragflow_service"
python -m uvicorn app:app --host 0.0.0.0 --port 8000 --timeout-keep-alive 120 --timeout-graceful-shutdown 30 &
RAGFLOW_PID=$!

sleep 3
if ! kill -0 $RAGFLOW_PID 2>/dev/null; then
    echo "[Production] ERROR: RAGFlow Bridge failed to start"
    exit 1
fi

if ! wait_for_port 8000; then
    echo "[Production] ERROR: RAGFlow Bridge not responding on port 8000"
    kill $RAGFLOW_PID 2>/dev/null
    exit 1
fi
echo "[Production] RAGFlow Bridge started successfully (PID: $RAGFLOW_PID)"

echo "[Production] Starting Laravel Server on port 5000..."
cd "$PROJECT_ROOT"

echo "[Production] Clearing config cache..."
php artisan config:clear 2>&1 || true

echo "[Production] Checking Laravel..."
php artisan --version 2>&1

echo "[Production] Starting serve..."
php artisan serve --host=0.0.0.0 --port=5000 2>&1 &
LARAVEL_PID=$!

sleep 3
if ! kill -0 $LARAVEL_PID 2>/dev/null; then
    echo "[Production] ERROR: Laravel Server failed to start"
    kill $RAGFLOW_PID 2>/dev/null
    exit 1
fi

if ! wait_for_port 5000; then
    echo "[Production] ERROR: Laravel Server not responding on port 5000"
    kill $RAGFLOW_PID 2>/dev/null
    kill $LARAVEL_PID 2>/dev/null
    exit 1
fi
echo "[Production] Laravel Server started successfully (PID: $LARAVEL_PID)"

echo "[Production] All services running"
echo "[Production] RAGFlow Bridge: http://localhost:8000"
echo "[Production] Laravel Server: http://localhost:5000"

cleanup() {
    echo "[Production] Shutting down services..."
    kill $RAGFLOW_PID 2>/dev/null
    kill $LARAVEL_PID 2>/dev/null
    wait
    echo "[Production] Shutdown complete"
    exit 0
}

trap cleanup SIGTERM SIGINT SIGHUP

while true; do
    if ! kill -0 $RAGFLOW_PID 2>/dev/null; then
        echo "[Production] ERROR: RAGFlow Bridge died unexpectedly"
        kill $LARAVEL_PID 2>/dev/null
        exit 1
    fi
    if ! kill -0 $LARAVEL_PID 2>/dev/null; then
        echo "[Production] ERROR: Laravel Server died unexpectedly"
        kill $RAGFLOW_PID 2>/dev/null
        exit 1
    fi
    sleep 10
done
