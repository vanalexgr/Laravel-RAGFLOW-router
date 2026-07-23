#!/usr/bin/env python3
"""
Agentic Gate v2 — Ollama capability spike (GO/NO-GO gate, plan §8 step 0).

Runs on the Hetzner box where Ollama lives (no laravel/ai / PHP needed — this
tests the MODEL + decoder, which is what gates the whole design). It answers
three questions for `qwen2.5:14b-instruct`:

  1. SCHEMA FIDELITY  — with grammar-constrained decoding (Ollama `format`=JSON
                        schema), does it return schema-valid JSON every time,
                        across the real gate schemas (Orient delta-merge,
                        Pathway relevance, Critic invariants)?
  2. JUDGMENT QUALITY — on canned "is this snippet relevant to this decision?"
                        and "does this probe violate an invariant?" cases with
                        gold labels, how often does it agree?
  3. LATENCY          — per-call ms (p50/p95). The deep path budgets ~6-9 calls
                        into 60 s, so a structured call must land well under ~7 s.

Usage (on Hetzner):
  OLLAMA_URL=http://localhost:11434 MODEL=qwen2.5:14b-instruct \
    python3 ollama_capability_spike.py [--runs 5]

GO/NO-GO thresholds (tune with the clinician if borderline):
  schema fidelity >= 95%  |  judgment accuracy >= 80%  |  p95 structured call <= 7000 ms
Prints a scorecard and exits 0 (GO) / 1 (NO-GO). Delete after running (keep prod == deployed).
"""
import os
import sys
import json
import time
import argparse
import urllib.request

OLLAMA_URL = os.environ.get("OLLAMA_URL", "http://localhost:11434")
MODEL = os.environ.get("MODEL", "qwen2.5:14b-instruct")

THRESH = {"schema_fidelity": 0.95, "judgment_accuracy": 0.80, "p95_ms": 7000}

# --- Real gate schemas (subset, mirrors app/Ai/Gate/*Agent::schema) -----------
ORIENT_SCHEMA = {
    "type": "object", "additionalProperties": False,
    "required": ["mode", "same_case", "patient_model", "changed_fields", "candidate_guidelines"],
    "properties": {
        "mode": {"type": "string", "enum": [
            "knowledge", "case_new", "case_followup_substantive", "case_followup_vague",
            "gate_reply", "capabilities", "out_of_scope", "model_meta", "prompt_injection"]},
        "same_case": {"type": "boolean"},
        "patient_model": {"type": "object", "additionalProperties": False,
            "required": ["lesion", "symptom_status", "other_findings"],
            "properties": {
                "lesion": {"type": "string"},
                "symptom_status": {"type": "string"},
                "other_findings": {"type": "array", "items": {"type": "string"}}}},
        "changed_fields": {"type": "array", "items": {"type": "string"}},
        "candidate_guidelines": {"type": "array", "items": {"type": "string"}},
    },
}
RELEVANCE_SCHEMA = {
    "type": "object", "additionalProperties": False,
    "required": ["relevant", "better_query"],
    "properties": {"relevant": {"type": "boolean"}, "better_query": {"type": "string"}},
}
CRITIC_SCHEMA = {
    "type": "object", "additionalProperties": False,
    "required": ["approved", "revise_stage", "violated_invariant"],
    "properties": {
        "approved": {"type": "boolean"},
        "revise_stage": {"type": "string", "enum": ["none", "orient_route", "ground", "probe"]},
        "violated_invariant": {"type": "string", "enum": [
            "none", "state_completeness", "routing_validity", "retrieval_sufficiency",
            "grounding", "frame_integrity", "question_value", "calibration"]},
    },
}


def ollama_chat(system, user, schema):
    """One structured call. Returns (raw_text, latency_ms)."""
    body = json.dumps({
        "model": MODEL,
        "messages": [{"role": "system", "content": system}, {"role": "user", "content": user}],
        "stream": False,
        "format": schema,            # grammar-constrained decoding
        "options": {"temperature": 0},
    }).encode()
    req = urllib.request.Request(OLLAMA_URL.rstrip("/") + "/api/chat", data=body,
                                 headers={"Content-Type": "application/json"})
    t0 = time.perf_counter()
    resp = urllib.request.urlopen(req, timeout=120).read()
    ms = (time.perf_counter() - t0) * 1000
    return json.loads(resp)["message"]["content"], ms


def validates(obj, schema):
    """Minimal structural + enum check (no jsonschema dependency on the box)."""
    if schema.get("type") == "object":
        if not isinstance(obj, dict):
            return False
        for req in schema.get("required", []):
            if req not in obj:
                return False
        for k, sub in schema.get("properties", {}).items():
            if k in obj and not validates(obj[k], sub):
                return False
        return True
    if schema.get("type") == "array":
        return isinstance(obj, list) and all(validates(i, schema["items"]) for i in obj)
    if schema.get("type") == "boolean":
        return isinstance(obj, bool)
    if schema.get("type") == "string":
        return isinstance(obj, str) and (obj in schema["enum"] if "enum" in schema else True)
    return True


# --- Test cases: (system, user, schema, gold-check-or-None) -------------------
SYS_ORIENT = ("You classify a vascular turn and delta-merge patient state. Return ONLY JSON. "
              "Update earlier facts with later ones; list changed fields.")
SYS_REL = ("You judge whether retrieved ESVS snippets are relevant to the clinical decision. "
           "If not relevant, propose a better retrieval query. Return ONLY JSON.")
SYS_CRIT = ("You review a clarification plan against invariants and name the first violated one. "
            "Return ONLY JSON.")

SCHEMA_CASES = [
    (SYS_ORIENT, "Prior: infrarenal AAA 5.8cm. New turn: 'CTA now shows juxtarenal with inadequate "
     "infrarenal neck; eGFR 28.' Guidelines: [abdominal_aortic_aneurysm, mesenteric_renal].", ORIENT_SCHEMA),
    (SYS_REL, "Decision: is CEA or CAS preferred for symptomatic 70% stenosis? Snippets: "
     "'AAA >5.5cm in men warrants repair...'.", RELEVANCE_SCHEMA),
    (SYS_CRIT, "Plan asks 'what is the stenosis degree?' but the case already states '70% stenosis'.", CRITIC_SCHEMA),
]

# Judgment cases with gold labels: (system, user, schema, field, expected)
JUDGMENT_CASES = [
    (SYS_REL, "Decision: CEA vs CAS for symptomatic carotid stenosis. Snippet: 'For symptomatic "
     "50-99% carotid stenosis, CEA is recommended...'.", RELEVANCE_SCHEMA, "relevant", True),
    (SYS_REL, "Decision: CEA vs CAS for symptomatic carotid stenosis. Snippet: 'AAA surveillance "
     "intervals for 4.0-4.9cm aneurysms...'.", RELEVANCE_SCHEMA, "relevant", False),
    (SYS_CRIT, "Case states 'eGFR 28'. Plan asks 'what is the renal function?'.", CRITIC_SCHEMA, "approved", False),
    (SYS_CRIT, "Case: symptomatic 80% stenosis, fit patient. Plan asks nothing, recommends CEA, cites "
     "the CEA recommendation. No unknowns.", CRITIC_SCHEMA, "approved", True),
    (SYS_CRIT, "Pathways grounded in the AAA guideline; case is a Type B aortic dissection.", CRITIC_SCHEMA,
     "violated_invariant", "routing_validity"),
    (SYS_REL, "Decision: management of acute limb ischaemia Rutherford IIb. Snippet: 'In ALI, immediate "
     "anticoagulation with heparin is recommended...'.", RELEVANCE_SCHEMA, "relevant", True),
    (SYS_CRIT, "Case: isolated distal DVT, low risk. Plan recommends surveillance, cites the distal-DVT "
     "recommendation, no unknowns, no questions.", CRITIC_SCHEMA, "approved", True),
    (SYS_REL, "Decision: AAA repair threshold in women. Snippet: 'Consider repair in women at 5.0cm...'.",
     RELEVANCE_SCHEMA, "relevant", True),
    (SYS_CRIT, "Case gives no symptom status for a carotid stenosis. Plan asks 'is the stenosis "
     "symptomatic?' (decision-changing).", CRITIC_SCHEMA, "approved", True),
    (SYS_CRIT, "Plan's interpretive frame introduces 'give aspirin 75mg daily' — not in any snippet or "
     "the question.", CRITIC_SCHEMA, "violated_invariant", "frame_integrity"),
]


def pctl(xs, p):
    xs = sorted(xs)
    return xs[min(len(xs) - 1, int(len(xs) * p))]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--runs", type=int, default=5, help="repeats per schema case for fidelity")
    args = ap.parse_args()

    print(f"# Ollama capability spike — model={MODEL} url={OLLAMA_URL} runs={args.runs}\n")
    lat = []

    # 1. Schema fidelity
    valid = total = 0
    for sys_p, user_p, schema in SCHEMA_CASES:
        for _ in range(args.runs):
            try:
                raw, ms = ollama_chat(sys_p, user_p, schema)
                lat.append(ms)
                ok = validates(json.loads(raw), schema)
            except Exception as e:
                ok = False
                print(f"  [err] {e}")
            valid += ok
            total += 1
    fidelity = valid / total if total else 0
    print(f"1. SCHEMA FIDELITY : {valid}/{total} = {fidelity:.0%}")

    # 2. Judgment accuracy
    agree = jt = 0
    for sys_p, user_p, schema, field, expected in JUDGMENT_CASES:
        try:
            raw, ms = ollama_chat(sys_p, user_p, schema)
            lat.append(ms)
            got = json.loads(raw).get(field)
            ok = (got == expected)
        except Exception as e:
            ok = False
            print(f"  [err] {e}")
        agree += ok
        jt += 1
        print(f"   - {field}={expected!r:>16}  got={got!r:>16}  {'OK' if ok else 'MISS'}")
    accuracy = agree / jt if jt else 0
    print(f"2. JUDGMENT ACCURACY: {agree}/{jt} = {accuracy:.0%}")

    # 3. Latency
    p50, p95 = (pctl(lat, 0.5), pctl(lat, 0.95)) if lat else (0, 0)
    print(f"3. LATENCY         : p50={p50:.0f}ms  p95={p95:.0f}ms  (n={len(lat)})")

    # Verdict
    go = (fidelity >= THRESH["schema_fidelity"] and accuracy >= THRESH["judgment_accuracy"]
          and p95 <= THRESH["p95_ms"])
    print("\n=== VERDICT:", "GO ✅" if go else "NO-GO ❌", "===")
    print(f"    fidelity {fidelity:.0%} (>= {THRESH['schema_fidelity']:.0%}) | "
          f"accuracy {accuracy:.0%} (>= {THRESH['judgment_accuracy']:.0%}) | "
          f"p95 {p95:.0f}ms (<= {THRESH['p95_ms']}ms)")
    sys.exit(0 if go else 1)


if __name__ == "__main__":
    main()
