#!/usr/bin/env python3
"""
Conceptual retrieval quality test: paraphrased clinical queries
OLD: 7a8a227619b511f183b3aa811fe4315f  (standard embeddings)
NEW: 9302da4a352211f18a57896c82939c88  (pplx-embed-v1-4b conceptual)

Shows full top-3 snippets so we can judge semantic retrieval quality.
Run on 135 VM: python3 /tmp/vt_conceptual_test.py
"""
import os, json, time, httpx

BRIDGE_URL    = os.getenv("BRIDGE_URL", "http://localhost:8000")
BRIDGE_SECRET = os.getenv("RAGFLOW_BRIDGE_SECRET", "")
OLD_DS = "7a8a227619b511f183b3aa811fe4315f"
NEW_DS = "9302da4a352211f18a57896c82939c88"

QUESTIONS = [
    "Can D dimer exclude DVT in a pregnant patient?",
    "Should I use Wells score for suspected pregnancy DVT?",
    "How long should a pregnant woman with DVT stay on anticoagulation after delivery?",
    "Woman with DVT 10 days before expected delivery, should an IVC filter be considered?",
    "Pregnant patient on warfarin with prior DVT becomes pregnant, what anticoagulant should she switch to?",
]

SNIPPET_CHARS = 250  # longer snippets for qualitative review

def retrieve(question, dataset_id, timeout=60):
    headers = {"Content-Type": "application/json"}
    if BRIDGE_SECRET:
        headers["X-Bridge-Secret"] = BRIDGE_SECRET
    payload = {
        "question": question,
        "dataset_ids": [dataset_id],
        "top_k": 40,
        "size": 10,
        "similarity_threshold": 0.15,   # lower threshold to surface more conceptual matches
        "vector_similarity_weight": 0.5, # balanced hybrid for fair comparison
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
        scores = [min(float(c.get("similarity", c.get("score", 0))), 1.0) for c in chunks]
        return {
            "count": len(chunks),
            "top_score": round(max(scores), 4) if scores else 0,
            "avg_score": round(sum(scores) / len(scores), 4) if scores else 0,
            "top5_scores": [round(s, 3) for s in scores[:5]],
            "snippets": [c.get("content", c.get("text", ""))[:SNIPPET_CHARS] for c in chunks[:3]],
            "ms": elapsed,
        }
    except Exception as e:
        return {"error": str(e)[:80], "ms": round((time.time() - t0)*1000), "chunks": []}

SEP = "=" * 72

print("\n" + SEP)
print("  CONCEPTUAL vs STANDARD — Paraphrased Pregnancy DVT Queries")
print("  vector_similarity_weight=0.5  similarity_threshold=0.15")
print(SEP + "\n")

all_results = []
old_wins = new_wins = ties = errors = 0

for i, q in enumerate(QUESTIONS, 1):
    print(SEP)
    print("Q%d: %s" % (i, q))
    print(SEP)

    old = retrieve(q, OLD_DS)
    new = retrieve(q, NEW_DS)

    old_err = "error" in old
    new_err = "error" in new

    if old_err or new_err:
        errors += 1
        winner = "-"
        print("  OLD: %s" % (old.get("error","ERR") if old_err else "n=%d top=%.3f" % (old["count"], old["top_score"])))
        print("  NEW: %s" % (new.get("error","ERR") if new_err else "n=%d top=%.3f" % (new["count"], new["top_score"])))
    else:
        # Composite: top_score weighted 70% (quality matters more here), count 30%
        o_comp = old["top_score"] * 0.7 + (old["count"] / 40) * 0.3
        n_comp = new["top_score"] * 0.7 + (new["count"] / 40) * 0.3
        delta = n_comp - o_comp
        winner = "NEW" if delta > 0.02 else ("OLD" if delta < -0.02 else "TIE")
        if winner == "NEW":   new_wins += 1
        elif winner == "OLD": old_wins += 1
        else:                 ties += 1

        print("  OLD | n=%2d  top=%.3f  avg=%.3f  top5=%s  %dms" % (
            old["count"], old["top_score"], old["avg_score"],
            str(old["top5_scores"]), old["ms"]))
        print("  NEW | n=%2d  top=%.3f  avg=%.3f  top5=%s  %dms" % (
            new["count"], new["top_score"], new["avg_score"],
            str(new["top5_scores"]), new["ms"]))
        print("  → Winner: %s  (Δ=%.3f)\n" % (winner, delta))

        print("  ── OLD top-3 snippets ──────────────────────────────────────")
        for j, s in enumerate(old["snippets"], 1):
            print("  [%d] %s" % (j, s.replace("\n", " ").strip()))
        print()
        print("  ── NEW top-3 snippets ──────────────────────────────────────")
        for j, s in enumerate(new["snippets"], 1):
            print("  [%d] %s" % (j, s.replace("\n", " ").strip()))

    all_results.append({"q": q, "old": old, "new": new, "winner": winner})
    print()

print(SEP)
print("  SUMMARY: OLD=%d  NEW=%d  TIE=%d  ERR=%d" % (old_wins, new_wins, ties, errors))
print(SEP + "\n")

out = "/tmp/vt_conceptual_results.json"
with open(out, "w") as f:
    json.dump(all_results, f, indent=2)
print("  Saved → %s\n" % out)
