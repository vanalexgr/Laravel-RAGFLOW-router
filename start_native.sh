#!/bin/bash

# Load environment variables from .env file
if [ -f .env ]; then
    export $(cat .env | grep -v '^#' | xargs)
fi

# Kill existing processes
pkill -f "php artisan serve"
pkill -f "uvicorn app:app"

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
echo "Starting Laravel..."
php artisan config:clear > /dev/null 2>&1
nohup php artisan serve --host 0.0.0.0 --port 8001 > laravel.log 2>&1 &
LARAVEL_PID=$!
echo "Laravel running (PID: $LARAVEL_PID)"

echo "Services started."
echo "RAGFlow Bridge: http://localhost:8000"
echo "Laravel API: http://localhost:8001"
