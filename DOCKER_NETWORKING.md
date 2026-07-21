# 🚨 CRITICAL: Docker Networking for Development/Testing

## The Problem

**Symptom**: Semantic router returns results when tested directly (`curl http://localhost:8000`), but Laravel returns empty results during testing.

**Root Cause**: Environment variable mismatch between development/testing and production.

---

## Understanding the Architecture

```
┌─────────────────────────────────────────────────────────┐
│  Host VM (Hetzner) (Host)                                        │
│                                                         │
│  ┌──────────────────┐      ┌──────────────────────┐   │
│  │  SSH Session     │      │  Docker Network      │   │
│  │  (Host context)  │      │                      │   │
│  │                  │      │  ┌────────────────┐  │   │
│  │  php /tmp/test.php│  ✗  │  │ ragflow-bridge │  │   │
│  │  php artisan ... │  ✗  │  │   :8000        │  │   │
│  │                  │      │  └────────────────┘  │   │
│  └──────────────────┘      │         ▲           │   │
│          │                 │         │           │   │
│          │ via localhost   │         │           │   │
│          ▼                 └─────────┼───────────┘   │
│  ┌──────────────────┐               │               │
│  │  localhost:8000  │ ──────────────┘               │
│  │  (port mapping)  │                               │
│  └──────────────────┘                               │
└─────────────────────────────────────────────────────┘
```

---

## The Solution

### **For Testing on Host VM (Hetzner) (SSH Session)**

SSH runs on the **host**, outside Docker. Must use `localhost`:

```bash
# .env configuration for testing
RAGFLOW_BRIDGE_URL=http://localhost:8000
```

### **For Production (Docker Environment)**

Laravel runs **inside Docker**. Must use Docker network hostname:

```bash
# .env configuration for production
RAGFLOW_BRIDGE_URL=http://ragflow-bridge:8000
```

---

## Quick Reference Commands

### **Before Testing (on Host VM (Hetzner) via SSH)**
```bash
cd /var/www/laravel-ragflow

# Switch to localhost
sed -i 's|RAGFLOW_BRIDGE_URL=http://ragflow-bridge:8000|RAGFLOW_BRIDGE_URL=http://localhost:8000|g' .env
php artisan config:clear

# Run tests
php /tmp/test_script.php
```

### **After Testing (restore production config)**
```bash
# Switch back to Docker hostname
sed -i 's|RAGFLOW_BRIDGE_URL=http://localhost:8000|RAGFLOW_BRIDGE_URL=http://ragflow-bridge:8000|g' .env
php artisan config:clear

# Verify
grep RAGFLOW_BRIDGE_URL .env
```

---

## Why This Happens

| Context | Laravel Location | Can Resolve ragflow-bridge? | Correct URL |
|---------|------------------|----------------------------|-------------|
| SSH session | Host filesystem | ❌ No | `http://localhost:8000` |
| Production web | Docker container | ✅ Yes | `http://ragflow-bridge:8000` |
| Docker exec | Docker container | ✅ Yes | `http://ragflow-bridge:8000` |

**Port mapping**: Docker maps container port 8000 to host port 8000, so `localhost:8000` on the host reaches the service.

---

## Alternative: Run Tests Inside Docker

Instead of changing `.env`, run tests inside the Docker container:

```bash
# Enter Laravel container
docker exec -it laravel-ragflow-laravel.test-1 bash

# Inside container - ragflow-bridge resolves correctly
php /var/www/html/tests/test_script.php
exit
```

This uses the production config without modification.

---

## Best Practice for Future Development

1. **Create a separate `.env.testing`** file with `localhost` URLs
2. **Use it for SSH-based tests**:
   ```bash
   cp .env.testing .env
   php artisan config:clear
   php /tmp/test.php
   ```
3. **Keep `.env` with `ragflow-bridge` for production**

---

## Troubleshooting

### "Connection refused" errors
```bash
# Check if semantic router is accessible from host
curl http://localhost:8000/route/guidelines

# If fails, check Docker container is running
docker ps | grep ragflow-bridge
```

### Empty routing results
```bash
# Check current config
grep RAGFLOW_BRIDGE_URL .env

# If shows ragflow-bridge and you're testing from SSH: WRONG!
# If shows localhost and you're in production: WRONG!
```

### After deployment, nothing works
```bash
# Ensure production config is restored
grep RAGFLOW_BRIDGE_URL .env
# Should show: http://ragflow-bridge:8000

php artisan config:clear
```

---

## 🎓 Key Takeaway

**Golden Rule**: 
- Testing from SSH → `localhost:8000`
- Production/Docker → `ragflow-bridge:8000`

**Always restore production config after testing!**
