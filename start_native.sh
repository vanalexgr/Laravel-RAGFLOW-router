#!/bin/bash

# Kill existing processes
pkill -f "php artisan serve"
pkill -f "uvicorn app:app"

# Start Python Bridge (Port 8000)
echo "Starting RAGFlow Bridge..."
cd ragflow_service
source venv/bin/activate
nohup uvicorn app:app --host 0.0.0.0 --port 8000 > ../bridge.log 2>&1 &
BRIDGE_PID=$!
echo "Bridge running (PID: $BRIDGE_PID)"
cd ..

# Start Laravel (Port 8001)
echo "Starting Laravel..."
nohup php artisan serve --host 0.0.0.0 --port 8001 > laravel.log 2>&1 &
LARAVEL_PID=$!
echo "Laravel running (PID: $LARAVEL_PID)"

echo "Services started."
