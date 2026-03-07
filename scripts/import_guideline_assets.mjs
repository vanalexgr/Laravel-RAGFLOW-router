#!/usr/bin/env node
/**
 * Import figure/table crops + metadata into Laravel storage and generate/update
 * guideline asset manifest JSON used by GuidelineAssetService.
 *
 * Usage:
 *   node scripts/import_guideline_assets.mjs \
 *     --src /Volumes/macshare/guidelines/di_crops \
 *     --guideline-key clti
 *
 * Optional:
 *   --manifest resources/guideline_assets/manifest.json
 *   --disk-subdir guideline_assets
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
    src: null,
    guidelineKey: null,
    sourceGuideline: null,
    metadata: null,
    manifest: "resources/guideline_assets/manifest.json",
    diskSubdir: "guideline_assets",
    overwrite: false,
  };

  for (let i = 2; i < argv.length; i++) {
    const a = argv[i];
    if (a === "--src") out.src = argv[++i];
    else if (a === "--guideline-key") out.guidelineKey = argv[++i];
    else if (a === "--source-guideline") out.sourceGuideline = argv[++i];
    else if (a === "--metadata") out.metadata = argv[++i];
    else if (a === "--manifest") out.manifest = argv[++i];
    else if (a === "--disk-subdir") out.diskSubdir = argv[++i];
    else if (a === "--overwrite") out.overwrite = true;
    else die(`Unknown arg: ${a}`);
  }
  if (!out.src) die("Missing --src");
  if (!out.guidelineKey) die("Missing --guideline-key");
  return out;
}

// Minimal RFC4180-ish CSV line parser (handles quoted fields, escaped quotes).
function parseCsvLine(line) {
  const fields = [];
  let cur = "";
  let i = 0;
  let inQuotes = false;

  while (i < line.length) {
    const ch = line[i];
    if (inQuotes) {
      if (ch === '"') {
        if (line[i + 1] === '"') {
          cur += '"';
          i += 2;
          continue;
        }
        inQuotes = false;
        i += 1;
        continue;
      }
      cur += ch;
      i += 1;
      continue;
    }

    if (ch === '"') {
      inQuotes = true;
      i += 1;
      continue;
    }
    if (ch === ",") {
      fields.push(cur);
      cur = "";
      i += 1;
      continue;
    }
    cur += ch;
    i += 1;
  }
  fields.push(cur);
  return fields;
}

function parsePyListString(s) {
  // Example: "['CLTI', 'imaging', 'CTA']"
  if (!s) return [];
  let t = String(s).trim();
  if (t.startsWith("[") && t.endsWith("]")) t = t.slice(1, -1);
  if (!t.trim()) return [];
  // Split on comma that separates quoted items.
  // This is intentionally simple because values are short tags.
  return t
    .split(",")
    .map((x) => x.trim())
    .map((x) => x.replace(/^'+|'+$/g, "").replace(/^"+|"+$/g, ""))
    .filter(Boolean);
}

function ensureDir(p) {
  fs.mkdirSync(p, { recursive: true });
}

function safeReadJson(p) {
  if (!fs.existsSync(p)) return {};
  const raw = fs.readFileSync(p, "utf8");
  if (!raw.trim()) return {};
  const j = JSON.parse(raw);
  if (!j || typeof j !== "object") return {};
  return j;
}

function normalizeType(t) {
  const x = String(t || "").toLowerCase().trim();
  if (x === "figure") return "figure";
  if (x === "table") return "table";
  return x || "asset";
}

function labelFromElementId(type, elementId, subtype) {
  // elementId format: fig_p026_001, tbl_p006_004
  const m = String(elementId || "").match(/_(\d{3})$/);
  const n = m ? String(parseInt(m[1], 10)) : null;
  if (!n) return elementId || "Asset";
  if (type === "figure") {
    // Some flowcharts are "Algorithm" in prose; add aliases later.
    return `Figure ${n}`;
  }
  if (type === "table") return `Table ${n}`;
  return `${type} ${n}`;
}

function buildAliases(type, label, subtype) {
  const out = new Set();
  const l = String(label || "").trim();
  if (!l) return [];

  const m = l.match(/^(Figure|Table)\s+(\d+)/i);
  if (m) {
    const kind = m[1].toLowerCase();
    const n = m[2];
    if (kind === "figure") {
      out.add(`Fig. ${n}`);
      out.add(`figure ${n}`);
      // If a figure is a flowchart, it is frequently referenced as an "algorithm".
      if (String(subtype || "").toLowerCase().includes("flow")) {
        out.add(`Algorithm ${n}`);
        out.add(`algorithm ${n}`);
      }
    } else if (kind === "table") {
      out.add(`table ${n}`);
    }
  }
  return Array.from(out);
}

function main() {
  const args = parseArgs(process.argv);
  const srcDir = path.resolve(args.src);
  const metaPath = args.metadata ? path.resolve(args.metadata) : path.join(srcDir, "metadata.csv");
  if (!fs.existsSync(metaPath)) die(`metadata.csv not found at ${metaPath}`);

  const manifestPath = path.resolve(args.manifest);
  const cwd = process.cwd();

  const existing = safeReadJson(manifestPath);
  if (!existing[args.guidelineKey]) existing[args.guidelineKey] = [];

  const rows = fs.readFileSync(metaPath, "utf8").split(/\r?\n/).filter(Boolean);
  if (rows.length < 2) die("metadata.csv has no rows");

  const header = parseCsvLine(rows[0]);
  const idx = Object.fromEntries(header.map((h, i) => [h, i]));

  const required = ["type", "element_id", "llm_title", "llm_description", "llm_tags", "subtype"];
  for (const r of required) {
    if (!(r in idx)) die(`metadata.csv missing column: ${r}`);
  }

  const destRoot = path.resolve(path.join(cwd, "storage/app/public", args.diskSubdir, args.guidelineKey));
  ensureDir(destRoot);

  const existingIds = new Set(existing[args.guidelineKey].map((a) => a?.id).filter(Boolean));

  let copied = 0;
  let added = 0;
  let skipped = 0;

  for (let i = 1; i < rows.length; i++) {
    const cols = parseCsvLine(rows[i]);
    if (cols.length !== header.length) {
      // tolerate trailing commas / minor inconsistencies
      // but skip truly broken lines
      if (cols.length < 5) continue;
    }

    const sourceGuideline = idx.guideline !== undefined ? String(cols[idx.guideline] || "").trim() : "";
    if (args.sourceGuideline && sourceGuideline !== args.sourceGuideline) {
      continue;
    }

    const type = normalizeType(cols[idx.type]);
    const elementId = cols[idx.element_id];
    const rawFile = idx.file !== undefined ? cols[idx.file] : "";
    const file = (rawFile && String(rawFile).trim()) ? String(rawFile).trim() : `${elementId}.png`;
    const title = cols[idx.llm_title] || "";
    const desc = cols[idx.llm_description] || "";
    const tags = parsePyListString(cols[idx.llm_tags]);
    const subtype = cols[idx.subtype] || "";

    if (!elementId) continue;
    if (existingIds.has(elementId)) {
      skipped++;
      continue;
    }

    // Support both old metadata (explicit `file` column) and newer metadata
    // where filename can be inferred from element_id (e.g. fig_p017_005.png).
    const candidateFiles = [];
    if (rawFile && fs.existsSync(rawFile)) {
      candidateFiles.push(rawFile);
    }
    // Remap legacy absolute file paths to the provided --src root.
    // Example:
    //   /home/vga/work/guideline_crops/ALI_2020_crops/tbl_xxx.png
    // -><srcDir>/ALI_2020_crops/tbl_xxx.png
    const marker = "guideline_crops/";
    const markerPos = file.indexOf(marker);
    if (markerPos >= 0) {
      const relAfterMarker = file.slice(markerPos + marker.length);
      candidateFiles.push(path.join(srcDir, relAfterMarker));
    }
    candidateFiles.push(path.join(srcDir, file));
    candidateFiles.push(path.join(srcDir, path.basename(file)));
    if (sourceGuideline) {
      candidateFiles.push(path.join(srcDir, `${sourceGuideline}_crops`, `${elementId}.png`));
    }
    candidateFiles.push(path.join(srcDir, `${elementId}.png`));
    const srcPng = candidateFiles.find((p) => fs.existsSync(p));
    if (!srcPng) {
      skipped++;
      continue;
    }

    const kindDir = type === "table" ? "tables" : "figures";
    const destDir = path.join(destRoot, kindDir);
    ensureDir(destDir);

    const destFile = path.join(destDir, path.basename(srcPng));
    if (fs.existsSync(destFile) && !args.overwrite) {
      // file exists; still add manifest entry
    } else {
      fs.copyFileSync(srcPng, destFile);
      copied++;
    }

    const relPath = path.relative(path.resolve(path.join(cwd, "storage/app/public")), destFile).replaceAll(path.sep, "/");

    const label = labelFromElementId(type, elementId, subtype);
    const aliases = buildAliases(type, label, subtype);

    const asset = {
      id: elementId,
      kind: type,
      subtype: subtype || undefined,
      label,
      caption: title || desc || elementId,
      description: desc || undefined,
      keywords: Array.from(new Set(tags.map((t) => t.trim()).filter(Boolean))).slice(0, 24),
      path: relPath, // resolved by Storage::disk(...)->url(path)
      aliases: aliases.length ? aliases : undefined,
    };

    // Remove undefined keys for cleaner JSON
    for (const k of Object.keys(asset)) {
      if (asset[k] === undefined) delete asset[k];
    }

    existing[args.guidelineKey].push(asset);
    existingIds.add(elementId);
    added++;
  }

  ensureDir(path.dirname(manifestPath));
  fs.writeFileSync(manifestPath, JSON.stringify(existing, null, 2) + "\n");

  console.log(
    JSON.stringify(
      {
        guideline_key: args.guidelineKey,
        src: srcDir,
        manifest: manifestPath,
        dest_root: destRoot,
        copied,
        added,
        skipped,
        total_assets_for_guideline: existing[args.guidelineKey].length,
      },
      null,
      2
    )
  );
}

main();
