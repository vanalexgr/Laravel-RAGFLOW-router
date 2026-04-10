#!/usr/bin/env python3
"""
Re-run the 6 timed-out VT comparison queries with a 60s timeout.
Run on 135 VM:  python3 /tmp/vt_comparison_retry.py
"""
import os, json, time, httpx

BRIDGE_URL    = os.getenv("BRIDGE_URL", "http://localhost:8000")
BRIDGE_SECRET = os.getenv("RAGFLOW_BRIDGE_SECRET", "")
OLD_DS = "7a8a227619b511f183b3aa811fe4315f"
NEW_DS = "9302da4a352211f18a57896c82939c88"

RETRY_QUESTIONS = [
    "anticoagulation duration for first unprovoked proximal DVT",
    "isolated distal DVT surveillance vs anticoagulation in low-risk patient",
    "submassive PE intermediate risk management",
    "anticoagulation in pregnancy venous thromboembolism",
    # Also re-run Q02/Q03 where one side timed out
    "treatment of provoked deep vein thrombosis after surgery",
    "management of extensive proximal DVT with phlegmasia",
]

TOP_K = 40
SIZE  = 10

def retrieve(question, dataset_id, timeout=60):
    headers = {"Content-Type": "application/json"}
    if BRIDGE_SECRET:
        headers["X-Bridge-Secret"] = BRIDGE_SECRET
    payload = {
        "question": question,
        "dataset_ids": [dataset_id],
        "top_k": TOP_K,
        "size": SIZE,
        "similarity_threshold": 0.2,
        "vector_similarity_weight": 0.3,
        "keyword": True,
        "highlight": False,
    }
    t0 = time.time()
    try:
        r = httpx.post(f"{BRIDGE_URL}/retrieve", json=payload, headers=headers, timeout=timeout)
        elapsed = round((time.time() - t0) * 1000)
        if r.status_code != 200:
            return {"error": "HTTP %d: %s" % (r.status_code, r.text[:100]), "ms": elapsed}
        data = r.json()
        chunks = data.get("data", {}).get("chunks", [])
        scores = [float(c.get("similarity", c.get("score", 0))) for c in chunks]
        # Clamp scores to [0,1] to avoid BM25 leakage in display
        scores = [min(s, 1.0) for s in scores]
        return {
            "count": len(chunks),
            "top_score": round(max(scores), 4) if scores else 0,
            "avg_score": round(sum(scores) / len(scores), 4) if scores else 0,
            "snippets": [c.get("content", c.get("text", ""))[:120] for c in chunks[:3]],
            "ms": elapsed,
        }
    except Exception as e:
        return {"error": str(e), "ms": round((time.time() - t0) * 1000)}

def label(r):
    if "error" in r:
        return "TIMEOUT/ERR"
    if r["count"] == 0:
        return "EMPTY"
    if r["top_score"] >= 0.6 and r["count"] >= 5:
        return "GOOD"
    if r["top_score"] >= 0.4 or r["count"] >= 3:
        return "OK"
    return "WEAK"

print("\n" + "="*72)
print("  VT Retry (60s timeout) — %d queries" % len(RETRY_QUESTIONS))
print("="*72 + "\n")

all_results = []
old_wins = new_wins = ties = errors = 0

for i, q in enumerate(RETRY_QUESTIONS, 1):
    print("[%02d/%02d] %s" % (i, len(RETRY_QUESTIONS), q[:65]))
    old = retrieve(q, OLD_DS, timeout=60)
    new = retrieve(q, NEW_DS, timeout=60)

    old_err = "error" in old
    new_err = "error" in new

    if old_err or new_err:
        errors += 1
        winner = "-"
        o_info = ("ERR: " + old["error"][:50]) if old_err else ("n=%d top=%.3f" % (old["count"], old["top_score"]))
        n_info = ("ERR: " + new["error"][:50]) if new_err else ("n=%d top=%.3f" % (new["count"], new["top_score"]))
        print("         OLD: %s" % o_info)
        print("         NEW: %s" % n_info)
    else:
        o_comp = old["top_score"] * 0.6 + (old["count"] / TOP_K) * 0.4
        n_comp = new["top_score"] * 0.6 + (new["count"] / TOP_K) * 0.4
        delta = n_comp - o_comp
        winner = "NEW" if delta > 0.02 else ("OLD" if delta < -0.02 else "TIE")
        if winner == "NEW":   new_wins += 1
        elif winner == "OLD": old_wins += 1
        else:                 ties += 1
        print("         OLD [%s] n=%d  top=%.3f  avg=%.3f  %dms" % (label(old), old["count"], old["top_score"], old["avg_score"], old["ms"]))
        print("         NEW [%s] n=%d  top=%.3f  avg=%.3f  %dms" % (label(new), new["count"], new["top_score"], new["avg_score"], new["ms"]))
        print("         Winner: %s" % winner)

    all_results.append({"q": q, "old": old, "new": new, "winner": winner})
    print()

print("="*72)
print("  RETRY SUMMARY")
print("  OLD wins: %d  NEW wins: %d  Ties: %d  Errors: %d" % (old_wins, new_wins, ties, errors))
print("="*72 + "\n")

out = "/tmp/vt_retry_results.json"
with open(out, "w") as f:
    json.dump(all_results, f, indent=2)
print("  Saved → %s\n" % out)
