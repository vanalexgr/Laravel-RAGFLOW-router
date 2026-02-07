#!/bin/bash
# Keep-alive script to prevent Replit cold starts
# Pings Laravel and RAGFlow Bridge every 3 minutes

LARAVEL_URL="http://localhost:5000/up"
RAGFLOW_URL="http://localhost:8000/health"
INTERVAL=180  # 3 minutes

echo "[KeepAlive] Starting keep-alive service (interval: ${INTERVAL}s)"
echo "[KeepAlive] Laravel: $LARAVEL_URL"
echo "[KeepAlive] RAGFlow: $RAGFLOW_URL"

while true; do
    TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
    
    # Ping Laravel health endpoint
    LARAVEL_STATUS=$(curl -s -o /dev/null -w "%{http_code}" --max-time 30 \
        "$LARAVEL_URL" 2>/dev/null)
    
    # Ping RAGFlow directly as backup
    RAGFLOW_STATUS=$(curl -s -o /dev/null -w "%{http_code}" --max-time 30 "$RAGFLOW_URL" 2>/dev/null)
    
    if [ "$LARAVEL_STATUS" = "200" ] && [ "$RAGFLOW_STATUS" = "200" ]; then
        echo "[$TIMESTAMP] OK - Laravel: $LARAVEL_STATUS, RAGFlow: $RAGFLOW_STATUS"
    else
        echo "[$TIMESTAMP] WARN - Laravel: $LARAVEL_STATUS, RAGFlow: $RAGFLOW_STATUS (services may be waking up)"
    fi
    
    sleep $INTERVAL
done
