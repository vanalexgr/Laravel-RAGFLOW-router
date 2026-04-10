#!/usr/bin/env python3
"""
VT Conceptual Embedding Benchmark
Formal Hit@k / MRR / Noise / Drift scoring for OLD vs NEW datasets.

OLD: 7a8a227619b511f183b3aa811fe4315f  (standard embeddings)
NEW: 9302da4a352211f18a57896c82939c88  (pplx-embed-v1-4b conceptual)

Run on 135 VM:
  python3 /tmp/vt_benchmark.py [--full]   # default: 5 key queries
"""
import os, sys, json, time, math, httpx

BRIDGE_URL    = os.getenv("BRIDGE_URL", "http://localhost:8000")
BRIDGE_SECRET = os.getenv("RAGFLOW_BRIDGE_SECRET", "")
OLD_DS = "7a8a227619b511f183b3aa811fe4315f"
NEW_DS = "9302da4a352211f18a57896c82939c88"

# ── Gold chunk signatures (substring match in retrieved content) ───────────────
# These are substrings known to appear in the target chunks.
GOLD = {
    "dimer_not_recommended":    "use of D dimer and Wells score is not recommended",
    "wells_not_recommended":    "use of D dimer and Wells score is not recommended",
    "ivc_filter_delivery":      "temporary inferior vena cava",           # Rec 62 — specific
    "lmwh_postpartum":          "anticoagulant therapy should be started as soon as possible",
    "left_leg_anatomy":         "left iliac",                             # May-Thurner — not generic "left"
    "thrombophilia_avoid":      "thrombophilia testing is not recommended",  # exact negative rec
    "doac_pregnancy":           "direct oral anticoagulant",
    "warfarin_pregnancy":       "warfarin",
    "imaging_escalation":       "magnetic resonance venograph",           # MRI venography — specific
    "pathophysiology":          "hypercoagulable",                        # pregnancy physiology section
}

# ── Noise / drift signatures ──────────────────────────────────────────────────
# Chunk 59 (paediatric) is the dominant noise chunk — flag any appearance in top 5
NOISE_SIGS   = ["monagle", "paediatric", "pediatric", "children with deep vein",
                "expertise in paediatric thrombosis"]
DRIFT_SIGS   = ["cancer-associated", "malignancy", "cancer associated", "4.3"]

# Chunk 59 rank-1 = noise-contaminated result regardless of gold hit
CHUNK59_SIG  = "expertise in paediatric thrombosis"

# ── Query definitions ─────────────────────────────────────────────────────────
# Each query has: question, gold_keys (list), category, notes
KEY_QUERIES = [
    {
        "id": "K1",
        "q": "Can D dimer exclude DVT in pregnancy?",
        "gold_keys": ["dimer_not_recommended"],
        "category": "implicit_reasoning",
        "notes": "Correct chunk: 'use of D dimer and Wells score is not recommended' (Chunk 60)",
    },
    {
        "id": "K2",
        "q": "Pregnant patient with suspected DVT but negative ultrasound and high clinical suspicion — what next?",
        "gold_keys": ["imaging_escalation"],
        "category": "cross_paragraph",
        "notes": "Expects MRI / repeat ultrasound / escalation pathway",
    },
    {
        "id": "K3",
        "q": "Why is DVT more common in the left leg during pregnancy?",
        "gold_keys": ["left_leg_anatomy"],
        "category": "mechanism",
        "notes": "Expects iliac vein compression / May-Thurner anatomy explanation",
    },
    {
        "id": "K4",
        "q": "When should thrombophilia testing be avoided in pregnancy-associated DVT?",
        "gold_keys": ["thrombophilia_avoid"],
        "category": "negative_reasoning",
        "notes": "Expects 'not recommended' / 'acute phase' limitation text",
    },
    {
        "id": "K5",
        "q": "DVT less than two weeks before delivery — management strategy",
        "gold_keys": ["ivc_filter_delivery"],
        "category": "temporal_reasoning",
        "notes": "Expects Rec 62: temporary IVC filter consideration",
    },
]

FULL_QUERIES = KEY_QUERIES + [
    # Implicit reasoning
    {
        "id": "I1",
        "q": "Why are common clinical prediction scores unreliable in pregnancy-related DVT?",
        "gold_keys": ["wells_not_recommended"],
        "category": "implicit_reasoning",
        "notes": "Wells score not validated in pregnancy",
    },
    {
        "id": "I2",
        "q": "A clinician wants to avoid imaging in pregnancy using biomarkers — what is the problem with that approach?",
        "gold_keys": ["dimer_not_recommended"],
        "category": "implicit_reasoning",
        "notes": "D-dimer unreliable / Wells unreliable in pregnancy",
    },
    {
        "id": "I3",
        "q": "Why might DVT present with abdominal or back pain in pregnancy rather than calf symptoms?",
        "gold_keys": ["left_leg_anatomy"],
        "category": "implicit_reasoning",
        "notes": "Iliofemoral DVT / pelvic vein involvement",
    },
    {
        "id": "I4",
        "q": "Why is clinical diagnosis of DVT less reliable during pregnancy?",
        "gold_keys": ["dimer_not_recommended"],
        "category": "implicit_reasoning",
        "notes": "Physiological leg swelling confounds clinical signs",
    },
    # Cross-paragraph
    {
        "id": "C1",
        "q": "If imaging is delayed in suspected pregnancy DVT, what is the recommended immediate management?",
        "gold_keys": ["lmwh_postpartum"],
        "category": "cross_paragraph",
        "notes": "Start anticoagulation before imaging if delay expected",
    },
    {
        "id": "C2",
        "q": "How should iliac vein thrombosis be investigated if standard ultrasound is insufficient?",
        "gold_keys": ["imaging_escalation"],
        "category": "cross_paragraph",
        "notes": "MRI venography / CT venography escalation",
    },
    # Temporal
    {
        "id": "T1",
        "q": "How does anticoagulation duration differ between pregnancy and postpartum?",
        "gold_keys": ["lmwh_postpartum"],
        "category": "temporal_reasoning",
        "notes": "LMWH during pregnancy, continued ≥6 weeks postpartum",
    },
    {
        "id": "T2",
        "q": "Why is anticoagulation continued after delivery even if DVT symptoms resolve?",
        "gold_keys": ["lmwh_postpartum"],
        "category": "temporal_reasoning",
        "notes": "Postpartum hypercoagulable state persists",
    },
    # Negative reasoning
    {
        "id": "N1",
        "q": "Why should DOACs not be used in pregnancy?",
        "gold_keys": ["doac_pregnancy"],
        "category": "negative_reasoning",
        "notes": "Teratogenicity / placental transfer",
    },
    {
        "id": "N2",
        "q": "When should warfarin be avoided in pregnant patients with DVT?",
        "gold_keys": ["warfarin_pregnancy"],
        "category": "negative_reasoning",
        "notes": "Warfarin embryopathy / first trimester",
    },
    # Mechanism
    {
        "id": "M1",
        "q": "What physiological changes in pregnancy increase thrombosis risk?",
        "gold_keys": ["pathophysiology"],
        "category": "mechanism",
        "notes": "Virchow's triad in pregnancy: stasis, hypercoagulability, endothelial",
    },
    {
        "id": "M2",
        "q": "Why is iliofemoral DVT more common in pregnancy than in non-pregnant patients?",
        "gold_keys": ["left_leg_anatomy"],
        "category": "mechanism",
        "notes": "Uterine compression + left iliac anatomy",
    },
    # Clinical scenarios
    {
        "id": "S1",
        "q": "Pregnant patient with confirmed DVT close to delivery — how should delivery be managed?",
        "gold_keys": ["ivc_filter_delivery"],
        "category": "clinical_scenario",
        "notes": "Bridging / epidural safety / IVC filter timing",
    },
    {
        "id": "S2",
        "q": "Patient becomes pregnant while on anticoagulation for prior DVT — what should be done?",
        "gold_keys": ["warfarin_pregnancy"],
        "category": "clinical_scenario",
        "notes": "Switch from warfarin/DOAC to LMWH",
    },
    {
        "id": "S3",
        "q": "Can regional anesthesia be used in a pregnant patient on LMWH?",
        "gold_keys": ["lmwh_postpartum"],
        "category": "clinical_scenario",
        "notes": "Neuraxial timing relative to LMWH dose",
    },
    # Hard distractors
    {
        "id": "D1",
        "q": "Management of cancer-associated DVT in pregnancy",
        "gold_keys": ["lmwh_postpartum"],
        "category": "distractor",
        "notes": "Should stay in pregnancy section, NOT drift to cancer section (4.3)",
    },
    {
        "id": "D2",
        "q": "Role of DOACs in venous thrombosis during pregnancy",
        "gold_keys": ["doac_pregnancy"],
        "category": "distractor",
        "notes": "Should retrieve 'not recommended' — NOT general DOAC dosing",
    },
    {
        "id": "D3",
        "q": "Use of D-dimer in diagnosing PE in pregnancy",
        "gold_keys": ["dimer_not_recommended"],
        "category": "distractor",
        "notes": "Should link to pregnancy diagnostic limitations, NOT general PE workup",
    },
    {
        "id": "D4",
        "q": "Thrombophilia testing to guide acute management of pregnancy DVT",
        "gold_keys": ["thrombophilia_avoid"],
        "category": "distractor",
        "notes": "Should retrieve 'not useful in acute phase' — NOT general thrombophilia recs",
    },
]

# ── Retrieval ─────────────────────────────────────────────────────────────────
def retrieve(question: str, dataset_id: str, timeout: int = 60) -> dict:
    headers = {"Content-Type": "application/json"}
    if BRIDGE_SECRET:
        headers["X-Bridge-Secret"] = BRIDGE_SECRET
    payload = {
        "question": question,
        "dataset_ids": [dataset_id],
        "top_k": 40,
        "size": 10,
        "similarity_threshold": 0.15,
        "vector_similarity_weight": 0.5,
        "keyword": True,
        "highlight": False,
    }
    t0 = time.time()
    try:
        r = httpx.post(f"{BRIDGE_URL}/retrieve", json=payload, headers=headers, timeout=timeout)
        elapsed = round((time.time() - t0) * 1000)
        if r.status_code != 200:
            return {"error": "HTTP %d" % r.status_code, "ms": elapsed, "chunks": []}
        data = r.json()
        chunks = data.get("data", {}).get("chunks", [])
        texts  = [c.get("content", c.get("text", "")) for c in chunks]
        scores = [min(float(c.get("similarity", c.get("score", 0))), 1.0) for c in chunks]
        return {"count": len(chunks), "scores": scores, "texts": texts, "ms": elapsed}
    except Exception as e:
        return {"error": str(e)[:80], "ms": round((time.time()-t0)*1000), "chunks": []}

# ── Scoring ───────────────────────────────────────────────────────────────────
def score_result(result: dict, gold_keys: list) -> dict:
    if "error" in result:
        return {"hit1": 0, "hit3": 0, "hit5": 0, "rr": 0.0, "noise": 0, "drift": 0,
                "best_rank": None, "error": result["error"]}

    texts = [t.lower() for t in result["texts"]]

    # Gold: find best rank where ANY gold key matches
    best_rank = None
    for i, text in enumerate(texts):
        for gk in gold_keys:
            sig = GOLD.get(gk, "").lower()
            if sig and sig in text:
                best_rank = i + 1  # 1-indexed
                break
        if best_rank:
            break

    hit1  = 1 if best_rank and best_rank == 1 else 0
    hit3  = 1 if best_rank and best_rank <= 3 else 0
    hit5  = 1 if best_rank and best_rank <= 5 else 0
    rr    = (1.0 / best_rank) if best_rank else 0.0

    # Noise: paediatric chunk in top 5
    noise = sum(1 for text in texts[:5] if any(ns in text for ns in NOISE_SIGS))
    # Drift: cancer section in top 5
    drift = sum(1 for text in texts[:5] if any(ds in text for ds in DRIFT_SIGS))
    # Chunk 59 at rank1 = result is noise-contaminated (gold hit is invalid)
    c59_rank1 = CHUNK59_SIG in texts[0] if texts else False
    if c59_rank1 and best_rank == 1:
        # Don't credit the hit — rank1 is paediatric noise, not the target
        best_rank = None
        hit1 = hit3 = hit5 = 0
        rr = 0.0

    return {"hit1": hit1, "hit3": hit3, "hit5": hit5, "rr": round(rr, 3),
            "noise": noise, "drift": drift, "best_rank": best_rank,
            "c59_rank1": c59_rank1, "error": None}

# ── Formatting ────────────────────────────────────────────────────────────────
def rank_icon(rank):
    if rank is None:   return "✗ miss"
    if rank == 1:      return "★ rank1"
    if rank <= 3:      return "✓ rank%d" % rank
    return "~ rank%d" % rank

def warn(n, label):
    return (" [%s×%s]" % (n, label)) if n > 0 else ""

def c59_warn(sc):
    return " [C59@1=CONTAMINATED]" if sc.get("c59_rank1") else ""

SEP  = "=" * 72
SEP2 = "-" * 72

# ── Main ──────────────────────────────────────────────────────────────────────
def run(queries):
    old_scores = {"hit1":[], "hit3":[], "hit5":[], "rr":[], "noise":0, "drift":0, "c59":0, "err":0}
    new_scores = {"hit1":[], "hit3":[], "hit5":[], "rr":[], "noise":0, "drift":0, "c59":0, "err":0}
    per_query  = []

    for q_def in queries:
        qid   = q_def["id"]
        q     = q_def["q"]
        gold  = q_def["gold_keys"]
        cat   = q_def["category"]
        notes = q_def["notes"]

        print(SEP)
        print("[%s] (%s)" % (qid, cat))
        print("Q: %s" % q)
        print("   Expected: %s" % notes)
        print(SEP2)

        old_r = retrieve(q, OLD_DS)
        new_r = retrieve(q, NEW_DS)
        old_s = score_result(old_r, gold)
        new_s = score_result(new_r, gold)

        for label, res, sc, acc in [("OLD", old_r, old_s, old_scores),
                                     ("NEW", new_r, new_s, new_scores)]:
            if sc["error"]:
                acc["err"] += 1
                print("  %s | ERROR: %s" % (label, sc["error"]))
            else:
                acc["hit1"].append(sc["hit1"])
                acc["hit3"].append(sc["hit3"])
                acc["hit5"].append(sc["hit5"])
                acc["rr"].append(sc["rr"])
                acc["noise"] += sc["noise"]
                acc["drift"] += sc["drift"]
                acc["c59"]  += 1 if sc.get("c59_rank1") else 0

                top5_scores = [round(s, 3) for s in res["scores"][:5]]
                print("  %s | %s  RR=%.2f  n=%d  top5=%s  %dms%s%s%s" % (
                    label,
                    rank_icon(sc["best_rank"]),
                    sc["rr"],
                    res["count"],
                    str(top5_scores),
                    res["ms"],
                    warn(sc["noise"], "NOISE"),
                    warn(sc["drift"], "DRIFT"),
                    c59_warn(sc),
                ))

                # Show top-3 snippets (250 chars)
                for i, txt in enumerate(res["texts"][:3], 1):
                    snippet = txt.replace("\n", " ").strip()[:250]
                    marker  = " ← GOLD" if sc["best_rank"] == i else ""
                    print("    [%d]%s %s" % (i, marker, snippet))

        # Per-query winner
        if not old_s["error"] and not new_s["error"]:
            if new_s["rr"] > old_s["rr"] + 0.01:      pw = "NEW"
            elif old_s["rr"] > new_s["rr"] + 0.01:    pw = "OLD"
            else:                                       pw = "TIE"
            print("  → Winner: %s  (OLD RR=%.2f, NEW RR=%.2f)" % (pw, old_s["rr"], new_s["rr"]))
        print()

        per_query.append({"id": qid, "q": q, "old": old_s, "new": new_s})

    # ── Aggregate ─────────────────────────────────────────────────────────────
    print(SEP)
    print("  AGGREGATE RESULTS (%d queries)" % len(queries))
    print(SEP)

    def agg(acc, label):
        n = len(acc["hit1"])
        if n == 0:
            print("  %s | no valid results" % label)
            return
        h1  = round(100 * sum(acc["hit1"]) / n, 1)
        h3  = round(100 * sum(acc["hit3"]) / n, 1)
        h5  = round(100 * sum(acc["hit5"]) / n, 1)
        mrr = round(sum(acc["rr"]) / n, 3)
        print("  %s | Hit@1=%5.1f%%  Hit@3=%5.1f%%  Hit@5=%5.1f%%  MRR=%.3f  "
              "Noise=%d  Drift=%d  C59@1=%d  Errors=%d" % (
              label, h1, h3, h5, mrr,
              acc["noise"], acc["drift"], acc["c59"], acc["err"]))

    agg(old_scores, "OLD")
    agg(new_scores, "NEW")
    print(SEP + "\n")

    out = "/tmp/vt_benchmark_results.json"
    with open(out, "w") as f:
        json.dump(per_query, f, indent=2)
    print("  Full results → %s\n" % out)

if __name__ == "__main__":
    full_mode = "--full" in sys.argv
    queries   = FULL_QUERIES if full_mode else KEY_QUERIES
    mode_str  = "FULL (%d queries)" % len(queries) if full_mode else "KEY 5 queries"
    print("\n" + SEP)
    print("  VT CONCEPTUAL BENCHMARK — %s" % mode_str)
    print("  Metrics: Hit@1/3/5, MRR, Noise (paediatric), Drift (cancer)")
    print(SEP + "\n")
    run(queries)
