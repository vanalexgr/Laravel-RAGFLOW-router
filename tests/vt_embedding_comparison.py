#!/usr/bin/env python3
"""
VT Dataset Embedding Comparison
Compares retrieval quality between:
  OLD: 7a8a227619b511f183b3aa811fe4315f  (existing Venous Thrombosis dataset)
  NEW: 9302da4a352211f18a57896c82939c88  (pplx-embed-v1-4b conceptual embeddings)

Run on the 135 VM where the bridge is at localhost:8000:
  python3 tests/vt_embedding_comparison.py

Or with a custom bridge URL:
  BRIDGE_URL=http://localhost:8000 python3 tests/vt_embedding_comparison.py
"""

import os
import sys
import json
import time
import httpx

BRIDGE_URL   = os.getenv("BRIDGE_URL", "http://localhost:8000")
BRIDGE_SECRET = os.getenv("RAGFLOW_BRIDGE_SECRET", "")

OLD_DS = "7a8a227619b511f183b3aa811fe4315f"
NEW_DS = "9302da4a352211f18a57896c82939c88"

# ── VT test questions ─────────────────────────────────────────────────────────
QUESTIONS = [
    # Core management
    "anticoagulation duration for first unprovoked proximal DVT",
    "treatment of provoked deep vein thrombosis after surgery",
    "management of extensive proximal DVT with phlegmasia",
    # Distal DVT
    "isolated distal DVT surveillance vs anticoagulation in low-risk patient",
    "calf DVT treatment asymptomatic patient",
    # PE
    "haemodynamically unstable pulmonary embolism thrombolysis indication",
    "submassive PE intermediate risk management",
    # IVC / filters
    "IVC filter indications venous thromboembolism",
    "retrievable IVC filter removal timing",
    # PTS / CDT
    "prevention of post-thrombotic syndrome catheter-directed thrombolysis",
    "pharmacomechanical thrombolysis iliofemoral DVT",
    # Cancer
    "cancer-associated thrombosis anticoagulation DOAC LMWH",
    # Recurrent / special
    "recurrent VTE on anticoagulation management",
    "anticoagulation in pregnancy venous thromboembolism",
    # SVT
    "superficial venous thrombosis saphenous vein 3cm from SFJ treatment",
]

TOP_K = 40
SIZE  = 10
SIMILARITY_THRESHOLD = 0.2
VECTOR_WEIGHT = 0.3

# ── helpers ───────────────────────────────────────────────────────────────────
def retrieve(question: str, dataset_id: str) -> dict:
    headers = {"Content-Type": "application/json"}
    if BRIDGE_SECRET:
        headers["X-Bridge-Secret"] = BRIDGE_SECRET

    payload = {
        "question": question,
        "dataset_ids": [dataset_id],
        "top_k": TOP_K,
        "size": SIZE,
        "similarity_threshold": SIMILARITY_THRESHOLD,
        "vector_similarity_weight": VECTOR_WEIGHT,
        "keyword": True,
        "highlight": False,
    }

    t0 = time.time()
    try:
        r = httpx.post(f"{BRIDGE_URL}/retrieve", json=payload, headers=headers, timeout=30)
        elapsed = round((time.time() - t0) * 1000)
        if r.status_code != 200:
            return {"error": f"HTTP {r.status_code}: {r.text[:200]}", "ms": elapsed}
        data = r.json()
        chunks = data.get("data", {}).get("chunks", [])
        scores = [c.get("similarity", c.get("score", 0)) for c in chunks]
        return {
            "count": len(chunks),
            "top_score": round(max(scores), 4) if scores else 0,
            "avg_score": round(sum(scores) / len(scores), 4) if scores else 0,
            "snippets": [c.get("content", c.get("text", ""))[:120] for c in chunks[:3]],
            "ms": elapsed,
        }
    except Exception as e:
        return {"error": str(e), "ms": round((time.time() - t0) * 1000)}


def score_label(count: int, top: float) -> str:
    if count == 0:
        return "EMPTY"
    if top >= 0.6 and count >= 5:
        return "GOOD"
    if top >= 0.4 or count >= 3:
        return "OK"
    return "WEAK"


# ── main ──────────────────────────────────────────────────────────────────────
def main():
    print(f"\n{'='*72}")
    print(f"  VT Embedding Comparison   OLD vs NEW (pplx-embed-v1-4b)")
    print(f"  Bridge: {BRIDGE_URL}")
    print(f"{'='*72}\n")

    results = []
    old_wins = new_wins = ties = errors = 0

    for i, q in enumerate(QUESTIONS, 1):
        print(f"[{i:02d}/{len(QUESTIONS)}] {q[:65]}")
        old = retrieve(q, OLD_DS)
        new = retrieve(q, NEW_DS)

        old_err = "error" in old
        new_err = "error" in new

        if old_err or new_err:
            errors += 1
            status = "ERROR"
            winner = "-"
        else:
            old_composite = old["top_score"] * 0.6 + (old["count"] / TOP_K) * 0.4
            new_composite = new["top_score"] * 0.6 + (new["count"] / TOP_K) * 0.4
            delta = new_composite - old_composite
            if delta > 0.02:
                winner = "NEW"
                new_wins += 1
            elif delta < -0.02:
                winner = "OLD"
                old_wins += 1
            else:
                winner = "TIE"
                ties += 1
            status = "OK"

        row = {
            "q": q,
            "old": old,
            "new": new,
            "winner": winner,
            "status": status,
        }
        results.append(row)

        # Per-query summary line
        if status == "ERROR":
            old_info = ("ERR: " + old.get("error", "?")[:40]) if old_err else ("n=%d top=%.3f" % (old["count"], old["top_score"]))
            new_info = ("ERR: " + new.get("error", "?")[:40]) if new_err else ("n=%d top=%.3f" % (new["count"], new["top_score"]))
            print(f"         OLD: {old_info}")
            print(f"         NEW: {new_info}")
        else:
            o_lbl = score_label(old["count"], old["top_score"])
            n_lbl = score_label(new["count"], new["top_score"])
            print(f"         OLD [{o_lbl:4s}] n={old['count']:2d}  top={old['top_score']:.3f}  avg={old['avg_score']:.3f}  {old['ms']}ms")
            print(f"         NEW [{n_lbl:4s}] n={new['count']:2d}  top={new['top_score']:.3f}  avg={new['avg_score']:.3f}  {new['ms']}ms")
            print(f"         Winner: {winner}")
        print()

    # ── Summary ───────────────────────────────────────────────────────────────
    print(f"{'='*72}")
    print(f"  SUMMARY  ({len(QUESTIONS)} questions)")
    print(f"  OLD wins : {old_wins}")
    print(f"  NEW wins : {new_wins}")
    print(f"  Ties     : {ties}")
    print(f"  Errors   : {errors}")
    print(f"{'='*72}\n")

    # ── Detailed breakdown ────────────────────────────────────────────────────
    print("  WEAK / EMPTY results (need attention):\n")
    for r in results:
        if r["status"] == "ERROR":
            continue
        o = r["old"]
        n = r["new"]
        o_lbl = score_label(o["count"], o["top_score"])
        n_lbl = score_label(n["count"], n["top_score"])
        if "WEAK" in (o_lbl, n_lbl) or "EMPTY" in (o_lbl, n_lbl):
            print(f"  Q: {r['q'][:65]}")
            print(f"     OLD [{o_lbl}]: n={o['count']}  top={o['top_score']}")
            print(f"     NEW [{n_lbl}]: n={n['count']}  top={n['top_score']}")
            print()

    # ── Snippet comparison for top-3 NEW wins ─────────────────────────────────
    new_win_rows = [r for r in results if r["winner"] == "NEW" and r["status"] == "OK"]
    if new_win_rows:
        print(f"\n  TOP SNIPPETS — best NEW wins (up to 3):\n")
        for r in new_win_rows[:3]:
            print(f"  Q: {r['q']}")
            print(f"  NEW top-3 snippets:")
            for s in r["new"]["snippets"]:
                print(f"    · {s}")
            print()

    # ── Save full results JSON ────────────────────────────────────────────────
    out_path = os.path.join(os.path.dirname(__file__), "vt_comparison_results.json")
    with open(out_path, "w") as f:
        json.dump(results, f, indent=2)
    print(f"  Full results saved → {out_path}\n")


if __name__ == "__main__":
    main()
