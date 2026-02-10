#!/bin/bash

# Load environment variables from .env file
if [ -f .env ]; then
    export $(grep -v '^#' .env | xargs)
fi

# Prefer explicit LARAVEL_PORT, then APP_PORT, default 8001.
LARAVEL_PORT="${LARAVEL_PORT:-${APP_PORT:-8001}}"

# Kill existing processes
pkill -f "php artisan serve" || true
pkill -f "uvicorn app:app" || true

sleep 2

# Start Python Bridge (Port 8000)
echo "Starting RAGFlow Bridge..."
cd ragflow_service
source venv/bin/activate
nohup uvicorn app:app --host 0.0.0.0 --port 8000 --timeout-keep-alive 120 --timeout-graceful-shutdown 30 > ../bridge.log 2>&1 &
BRIDGE_PID=$!
echo "Bridge running (PID: $BRIDGE_PID)"
cd ..

# Start Laravel (Port 8001)
echo "Starting Laravel on port ${LARAVEL_PORT}..."
php artisan config:clear > /dev/null 2>&1
nohup php artisan serve --host 0.0.0.0 --port "${LARAVEL_PORT}" > laravel.log 2>&1 &
LARAVEL_PID=$!
echo "Laravel running (PID: $LARAVEL_PID)"

echo "Services started."
echo "RAGFlow Bridge: http://localhost:8000"
echo "Laravel API: http://localhost:${LARAVEL_PORT}"
