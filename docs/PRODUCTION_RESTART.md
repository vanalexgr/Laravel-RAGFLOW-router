# Production Server Restart Guide

## Quick Restart (Recommended)

On the production VM, run:

```bash
cd ~/laravel-ragflow
./start_native.sh
```

This script automatically:
- ✅ Loads environment variables from `.env` (including `RAGFLOW_API_KEY`)
- ✅ Stops any existing services
- ✅ Starts RAGFlow Bridge on port 8000
- ✅ Clears Laravel config cache
- ✅ Starts Laravel API on port 8001

## Port Model (Important)

- Nginx listens on `80/443`.
- Laravel app server should run on `8001`.
- RAGFlow Bridge should run on `8000`.
- Nginx `/api` upstream must point to the same Laravel port (recommended `127.0.0.1:8001`).

If Nginx points to `8080` while Laravel runs on `8001`, you will get `502 Bad Gateway`.

## Host-Mode `.env` Baseline

Use these values on host-based production runs (non-Sail):

```bash
APP_PORT=8001
RAGFLOW_BRIDGE_URL=http://127.0.0.1:8000
REDIS_HOST=127.0.0.1
```

## Manual Restart (If Script Fails)

### 1. Stop Existing Services

```bash
sudo pkill -f "php artisan serve"
sudo pkill -f "uvicorn app:app"
```

### 2. Start RAGFlow Bridge

```bash
cd ~/laravel-ragflow/ragflow_service

# Load API key from .env
export RAGFLOW_API_KEY=$(grep RAGFLOW_API_KEY ~/laravel-ragflow/.env | cut -d '=' -f2-)

# Activate virtual environment
source venv/bin/activate

# Start bridge
nohup python3 -m uvicorn app:app \
  --host 0.0.0.0 \
  --port 8000 \
  --timeout-keep-alive 120 \
  --timeout-graceful-shutdown 30 \
  > ../bridge.log 2>&1 &

echo "Bridge started (PID: $!)"
cd ..
```

### 3. Start Laravel

```bash
cd ~/laravel-ragflow

# Clear config cache to pick up any changes
php artisan config:clear

# Start Laravel
nohup php artisan serve \
  --host 0.0.0.0 \
  --port 8001 \
  > laravel.log 2>&1 &

echo "Laravel started (PID: $!)"
```

## Verification

### Check Services Are Running

```bash
ps aux | grep -E "(uvicorn|artisan serve)" | grep -v grep
```

You should see:
- One `python3 ... uvicorn app:app` process (port 8000)
- One `php artisan serve` process (port 8001)

### Check Ports Are Listening

```bash
ss -tlnp | grep -E "(8000|8001)"
```

### Check Logs

```bash
# Bridge logs
tail -f ~/laravel-ragflow/bridge.log

# Laravel logs
tail -f ~/laravel-ragflow/laravel.log
tail -f ~/laravel-ragflow/storage/logs/laravel.log
```

## After Pulling Updates

When you pull new code from the repository:

```bash
cd ~/laravel-ragflow
git pull
./start_native.sh
```

The script will automatically clear the config cache to pick up changes to:
- `config/guidelines.php` (dataset IDs, routing configs)
- `config/ragflow.php` (RAGFlow settings)
- Any other config files

## Troubleshooting

### Error: `RAGFLOW_API_KEY not configured`

**Cause:** Bridge started without the environment variable.

**Fix:**
```bash
# Verify .env has the key
grep RAGFLOW_API_KEY ~/laravel-ragflow/.env

# Restart with the script (it loads .env automatically)
./start_native.sh
```

### Error: `python: command not found`

**Cause:** System uses `python3` instead of `python`.

**Fix:** Use `start_native.sh` which calls `python3` properly, or create a symlink:
```bash
sudo ln -s /usr/bin/python3 /usr/bin/python
```

### Port Already in Use

**Cause:** Old processes still running.

**Fix:**
```bash
# Force kill all services
sudo pkill -9 -f "uvicorn"
sudo pkill -9 -f "artisan serve"

# Wait a moment
sleep 2

# Restart
./start_native.sh
```

### 502 Bad Gateway on `/api`

**Cause:** Nginx upstream port mismatch (common after port changes).

**Check:**
```bash
sudo nginx -T | grep -n "location /api\\|proxy_pass"
ss -tlnp | grep -E "(8000|8001|8080)"
```

**Fix:** Ensure `location /api` proxies to `http://127.0.0.1:8001` and reload:
```bash
sudo nginx -t
sudo systemctl reload nginx
```

### Services Keep Dying

**Check the logs:**
```bash
tail -100 ~/laravel-ragflow/bridge.log
tail -100 ~/laravel-ragflow/storage/logs/laravel.log
```

Common issues:
- Missing dependencies: `pip install -r ragflow_service/requirements.txt`
- Database connection issues: Check `.env` database settings
- RAGFlow API unreachable: Verify `RAGFLOW_ENDPOINT` in `.env`

## Production vs Development

### Production (VM on port 80)
There are separate Laravel instances running on port 80 (production). These are managed differently. The `start_native.sh` script manages the **development/testing instance** on port 8001.

### Development/Testing (ports 8000 & 8001)
- RAGFlow Bridge: `http://localhost:8000`
- Laravel API: `http://localhost:8001`

Use these for testing changes before deploying to production.
