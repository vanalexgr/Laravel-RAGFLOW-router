#!/usr/bin/env python3
"""Print the Phase-0 turn-classification accuracy and local latency baseline."""

from collections import Counter, defaultdict
from pathlib import Path
import statistics
import sys
import time

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from openwebui_tools.turn_classification_support import (
    classify_corpus_row,
    load_turn_corpus,
)
from openwebui_tools.vascular_mcp_adapter import Tools


LABELS = [
    "NEW_CASE",
    "EXPLICIT_NEW_CASE",
    "GATE_REPLY",
    "FOLLOWUP_VAGUE",
    "FOLLOWUP_SUBSTANTIVE",
    "KNOWLEDGE",
    "GUARDRAIL",
]
LATENCY_REPEATS = 100


def percentile(values, fraction):
    ordered = sorted(values)
    index = min(len(ordered) - 1, int(round((len(ordered) - 1) * fraction)))
    return ordered[index]


def main():
    corpus = load_turn_corpus()
    tools = Tools()
    matrix = defaultdict(Counter)
    timings_us = []

    for row in corpus:
        decision = classify_corpus_row(row, tools=tools)
        matrix[row["expected"]][decision.turn_class] += 1
        for _ in range(LATENCY_REPEATS):
            started = time.perf_counter_ns()
            classify_corpus_row(row, tools=tools)
            timings_us.append((time.perf_counter_ns() - started) / 1_000)

    width = max(len(label) for label in LABELS)
    print("Confusion matrix (rows=expected, columns=predicted)")
    print(" " * (width + 2) + " ".join(f"{label:>22}" for label in LABELS))
    for expected in LABELS:
        values = " ".join(f"{matrix[expected][predicted]:>22}" for predicted in LABELS)
        print(f"{expected:<{width}}  {values}")

    print("\nPer-class accuracy")
    correct = 0
    total = 0
    for label in LABELS:
        class_total = sum(matrix[label].values())
        class_correct = matrix[label][label]
        correct += class_correct
        total += class_total
        accuracy = 100 * class_correct / class_total if class_total else 0
        print(f"{label}: {class_correct}/{class_total} ({accuracy:.2f}%)")

    print(f"\nOverall accuracy: {correct}/{total} ({100 * correct / total:.2f}%)")
    print(
        "Classifier latency (local, no I/O): "
        f"median={statistics.median(timings_us):.2f}us "
        f"p95={percentile(timings_us, 0.95):.2f}us "
        f"max={max(timings_us):.2f}us"
    )


if __name__ == "__main__":
    main()
