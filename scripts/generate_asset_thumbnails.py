#!/usr/bin/env python3
"""
Generate and upload thumbnail PNGs for guideline assets, then update manifest.json.

Usage:
  STORAGE_ACCOUNT_NAME=pngsrag \
  STORAGE_ACCOUNT_KEY=... \
  /home/vga/LAVAREL/Laravel-RAGFLOW-router/.venv/bin/python \
    /home/vga/LAVAREL/Laravel-RAGFLOW-router/scripts/generate_asset_thumbnails.py \
    --manifest /home/vga/LAVAREL/Laravel-RAGFLOW-router/resources/guideline_assets/manifest.json \
    --container guideline-assets \
    --max-width 220
"""

from __future__ import annotations

import argparse
import io
import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any
from urllib.parse import urlparse

import requests
from PIL import Image
from azure.storage.blob import BlobServiceClient, ContentSettings


def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Generate and upload guideline asset thumbnails.")
    p.add_argument("--manifest", required=True, help="Path to guideline asset manifest JSON.")
    p.add_argument("--container", default="guideline-assets", help="Azure blob container name.")
    p.add_argument("--prefix", default="thumbnails", help="Blob prefix for thumbnails.")
    p.add_argument("--max-width", type=int, default=220, help="Thumbnail max width in pixels.")
    p.add_argument("--overwrite", action="store_true", help="Overwrite existing thumbnail blobs.")
    p.add_argument("--timeout", type=int, default=20, help="HTTP timeout for source image downloads.")
    p.add_argument("--dry-run", action="store_true", help="Do not upload or write manifest.")
    return p.parse_args()


def require_env(name: str) -> str:
    value = os.getenv(name, "").strip()
    if not value:
        raise RuntimeError(f"Missing required environment variable: {name}")
    return value


def thumbnail_bytes(image_bytes: bytes, max_width: int) -> bytes:
    with Image.open(io.BytesIO(image_bytes)) as img:
        img = img.convert("RGBA")
        width, height = img.size
        if width > max_width:
            new_height = int((max_width / float(width)) * float(height))
            img = img.resize((max_width, new_height), Image.Resampling.LANCZOS)
        out = io.BytesIO()
        img.save(out, format="PNG", optimize=True)
        return out.getvalue()


def pick_blob_name(asset: dict[str, Any], source_url: str, prefix: str) -> str:
    parsed = urlparse(source_url)
    base = Path(parsed.path).name
    if not base.lower().endswith(".png"):
        base = f"{Path(base).stem}.png"
    asset_id = str(asset.get("id", "")).strip()
    if asset_id:
        safe_id = "".join(ch if ch.isalnum() or ch in ("-", "_") else "_" for ch in asset_id)
        return f"{prefix}/{safe_id}.png"
    return f"{prefix}/{base}"


def main() -> int:
    args = parse_args()
    account = require_env("STORAGE_ACCOUNT_NAME")
    key = require_env("STORAGE_ACCOUNT_KEY")

    manifest_path = Path(args.manifest)
    if not manifest_path.is_file():
        raise RuntimeError(f"Manifest not found: {manifest_path}")

    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    if not isinstance(manifest, dict):
        raise RuntimeError("Manifest root must be a JSON object.")

    base_url = f"https://{account}.blob.core.windows.net"
    service = BlobServiceClient(account_url=base_url, credential=key)
    container = service.get_container_client(args.container)

    updated = 0
    skipped = 0
    failed = 0
    total = 0

    session = requests.Session()
    session.headers.update({"User-Agent": "guideline-asset-thumbnailer/1.0"})

    for guideline_key, assets in manifest.items():
        if not isinstance(assets, list):
            continue
        for asset in assets:
            if not isinstance(asset, dict):
                continue
            total += 1
            source_url = str(asset.get("url", "")).strip()
            if not source_url.startswith(("http://", "https://")):
                skipped += 1
                continue

            blob_name = pick_blob_name(asset, source_url, args.prefix)
            thumbnail_url = f"{base_url}/{args.container}/{blob_name}"

            if asset.get("thumbnail_url") == thumbnail_url and not args.overwrite:
                skipped += 1
                continue

            try:
                resp = session.get(source_url, timeout=args.timeout)
                resp.raise_for_status()
                data = resp.content
                thumb = thumbnail_bytes(data, args.max_width)

                if not args.dry_run:
                    blob = container.get_blob_client(blob_name)
                    if blob.exists() and not args.overwrite:
                        pass
                    else:
                        blob.upload_blob(
                            thumb,
                            overwrite=True,
                            content_settings=ContentSettings(
                                content_type="image/png",
                                cache_control="public, max-age=31536000",
                            ),
                        )

                asset["thumbnail_url"] = thumbnail_url
                asset["thumbnail_path"] = blob_name
                updated += 1
                if updated % 25 == 0:
                    print(f"progress: updated={updated} skipped={skipped} failed={failed}")
            except Exception as exc:
                failed += 1
                print(f"failed: guideline={guideline_key} id={asset.get('id')} err={exc}", file=sys.stderr)

    if not args.dry_run:
        backup = manifest_path.with_suffix(
            manifest_path.suffix + f".bak.{datetime.now(timezone.utc).strftime('%Y%m%d_%H%M%S')}"
        )
        backup.write_text(manifest_path.read_text(encoding="utf-8"), encoding="utf-8")
        manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(f"manifest backup: {backup}")
        print(f"manifest updated: {manifest_path}")

    print(f"summary: total={total} updated={updated} skipped={skipped} failed={failed}")
    return 0 if failed == 0 else 2


if __name__ == "__main__":
    raise SystemExit(main())
