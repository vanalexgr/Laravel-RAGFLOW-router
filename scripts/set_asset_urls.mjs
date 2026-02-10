#!/usr/bin/env node
/**
 * Populate/override asset `url` fields in resources/guideline_assets/manifest.json
 * using a base URL + `${id}.png` naming convention (works for fig_* and tbl_* crops).
 *
 * Usage:
 *   node scripts/set_asset_urls.mjs \
 *     --guideline-key clti \
 *     --base-url https://<account>.blob.core.windows.net/<container>/
 *
 * Optional:
 *   --manifest resources/guideline_assets/manifest.json
 *   --overwrite
 */

import fs from "node:fs";
import path from "node:path";

function die(msg) {
  console.error(msg);
  process.exit(1);
}

function parseArgs(argv) {
  const out = {
    guidelineKey: null,
    baseUrl: null,
    manifest: "resources/guideline_assets/manifest.json",
    overwrite: false,
  };

  for (let i = 2; i < argv.length; i++) {
    const a = argv[i];
    if (a === "--guideline-key") out.guidelineKey = argv[++i];
    else if (a === "--base-url") out.baseUrl = argv[++i];
    else if (a === "--manifest") out.manifest = argv[++i];
    else if (a === "--overwrite") out.overwrite = true;
    else die(`Unknown arg: ${a}`);
  }
  if (!out.guidelineKey) die("Missing --guideline-key");
  if (!out.baseUrl) die("Missing --base-url");
  return out;
}

function main() {
  const args = parseArgs(process.argv);

  const manifestPath = path.resolve(args.manifest);
  if (!fs.existsSync(manifestPath)) die(`Manifest not found: ${manifestPath}`);

  const raw = fs.readFileSync(manifestPath, "utf8");
  const j = JSON.parse(raw);

  const list = j[args.guidelineKey];
  if (!Array.isArray(list)) die(`No assets for guideline key: ${args.guidelineKey}`);

  const base = args.baseUrl.endsWith("/") ? args.baseUrl : `${args.baseUrl}/`;

  let updated = 0;
  for (const a of list) {
    if (!a || typeof a !== "object") continue;
    const id = a.id;
    if (!id) continue;

    if (!args.overwrite && a.url) continue;

    // Most crops are `${element_id}.png` in your container.
    a.url = `${base}${encodeURIComponent(id)}.png`;
    updated++;
  }

  fs.writeFileSync(manifestPath, JSON.stringify(j, null, 2) + "\n");
  console.log(JSON.stringify({ manifest: manifestPath, guideline_key: args.guidelineKey, updated }, null, 2));
}

main();

