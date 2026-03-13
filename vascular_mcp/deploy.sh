#!/bin/bash
# Deploy vascular_mcp from GitHub to /home/azureuser/vascular-mcp/
# Usage: bash deploy.sh
set -e
REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DEPLOY_DIR=/home/azureuser/vascular-mcp

echo '==> Pulling from GitHub...'
cd "$REPO_DIR" && git pull origin main

echo '==> Syncing files to $DEPLOY_DIR...'
rsync -av --exclude='.env' --exclude='.venv' --exclude='__pycache__' \
  "$REPO_DIR/vascular_mcp/" "$DEPLOY_DIR/"

echo '==> Installing dependencies...'
"$DEPLOY_DIR/.venv/bin/pip" install -r "$DEPLOY_DIR/requirements.txt" -q

echo '==> Restarting service...'
sudo systemctl restart vascular-mcp.service
sleep 2
sudo systemctl is-active vascular-mcp.service

echo '==> Done. SHA:' && git -C "$REPO_DIR" log --oneline -1
