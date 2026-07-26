# Clinical State Ledger: Architecture Design

This document outlines the architectural design for the "gate v2" ESVS vascular surgery guidelines decision-support system, specifically addressing the failure modes associated with memoryless LLM state extraction.

## 1. Shape: Event Sourcing over Mutable Records

Given the absolute necessity for provenance, temporal progression, and explicit handling of assumptions/refusals, an **append-only event log with a derived projection (Event Sourcing)** is the correct architectural shape.

*   **Why not a mutable record with an audit trail?** An audit trail tells you *what* changed, but an event log tells you *why* it changed. In clinical decision support, the distinction between "The clinician corrected an error" and "The patient's condition evolved" is critical. A mutable record loses the semantic intent behind the change.
*   **The Pattern**: The LLM analyzes the turn and emits *Commands* (intent). A deterministic State Manager validates these Commands against the current state. Valid Commands become *Events* (facts appended to the log). The `patient_model` is simply a stateless, deterministic projection (a left-fold) over this event stream.

## 2. Who May Write: Additive Proposals + Typed Corrections

The LLM cannot be permitted to write the final state directly, nor should it output the entire `patient_model` on every turn (which is the root cause of the "silent field loss" in the 3-turn AAA case).

*   **The LLM as an Intent Parser**: Its only job regarding state is to output an array of `StateChangeProposals`.
*   **Structural Guardrails**: Deletions or overwrites are structurally impossible for the LLM. It may only emit specific intents:
    *   `AddFact` (e.g., introducing aneurysm diameter)
    *   `CorrectFact` (e.g., fixing 70% to 50-69%)
    *   `RecordAssumption` (e.g., assuming smoking status)
    *   `RecordRefusal` (e.g., patient declined to answer)
*   **Validation boundary**: A strict, code-based Domain Service takes the LLM's proposals and attempts to apply them to the current projection. Only validated proposals are appended to the event log. The silent loss of AAA fields is solved because the LLM no longer has the power to "forget" a field; it only has the power to propose additions.

## 3. The Contradiction Guard

To prevent a patient spontaneously morphing from asymptomatic to symptomatic based on general-knowledge interleaving, we implement a **Deterministic Contradiction Guard** in the Domain Service, entirely independent of the LLM.

*   **Schema Definition**: The `patient_model` fields must be strongly typed and annotated with conflict-resolution rules. (e.g., `symptoms`: `MutuallyExclusive`).
*   **The Mechanism**: If the LLM proposes `AddFact({field: "symptoms", value: "symptomatic"})`, the Domain Service checks the current projection. If the projection currently says `asymptomatic`, the Service strictly **rejects** the command with a `ContradictionError`.
*   **Resolving the Error**: The LLM is only permitted to change a mutually exclusive field if it explicitly uses the `CorrectFact` command, which requires a `citation` field pointing to the exact clinician quote that justifies the change. If the topic shift was due to a general knowledge question (as in the failure data), no such quote exists, the LLM cannot formulate a valid `CorrectFact`, and the clinical state remains safely uncorrupted.

## 4. Idempotency

Duplicate message processing ("dup-001") corrupts state logs and triggers redundant, expensive pipeline executions.

*   **The Dedupe Key**: You must push back on the client to provide an `idempotency_key` (UUID v4) on every request. This is the industry standard for safe state mutation.
*   **Fallback Deduplication**: If the client context absolutely cannot provide one, the gateway must synthetically generate a deterministic hash before routing to the LLM: `hash(session_id + normalized(message_text) + turn_timestamp_rounded_to_minute)`.
*   **Handling**: At the very start of the pipeline, query the Event Log for a `TurnStarted { idempotency_key: "..." }` event. If it exists, halt processing and return the previously stored response for that turn.

## 5. Scope Boundary

Your inclination is entirely correct. The ledger should **strictly contain clinical facts and provenance**.

*   **Keep OUT**: Retrieval terms, routed guidelines, evidence coverage, and the final clinical answer.
*   **Why?**: Mixing ephemeral execution data with persistent clinical state breaks the projection logic and heavily pollutes the context window. The `patient_model` projection should represent the "Current Clinical Truth" and nothing else. Derived execution artefacts belong in a standard request trace or a separate stateless log.

## 6. Migration Risk: The Shadow Mode Path

Given the system relies on `patient_model` in at least four different places (retrieval, routing, prompt, Critic), replacing it outright is highly risky.

*   **Phase 1: Parallel Extraction (Shadow Mode)**
    Run the new Event Sourcing pipeline in parallel with the existing memoryless LLM. Continue using the memoryless LLM's output for downstream pipeline execution. Silently log the output of the new Event-Sourced projection.
*   **Phase 2: Offline Diffing & Tuning**
    Build an offline script that diffs the memoryless `patient_model` against the Event-Sourced `patient_model` across historic transcripts (using your 32-turn eval dataset). You will immediately see the event-sourced model retaining the lost AAA fields. Tune the Contradiction Guard until the diffs mathematically prove the new model is strictly superior.
*   **Phase 3: The Swap**
    Point the retrieval, routing, and Critic to read from the Event-Sourced projection. Deprecate and remove the memoryless extraction logic.

## 7. What NOT to Build (Avoiding Over-engineering)

Given a 90-second wall-clock budget and a prototype scope, explicitly avoid building the following:

*   **Full Ontology/Knowledge Graph Tracking**: Do not build a massive graph database of medical concepts. Keep the `patient_model` as a flat or shallowly nested JSON schema. Mapping every term to SNOMED CT is overkill for this stage.
*   **Branching / Time-Travel Event Sourcing**: You only need to project the state forward linearly to understand the *current* turn. You do not need the complex infrastructure required to "branch" the conversation or rollback to arbitrary turns dynamically in the UI.
*   **Probabilistic Belief States**: Avoid fields like `{ confidence: 0.85 }`. The system should deal in deterministic facts, explicitly recorded assumptions, or nulls.
*   **LLM-based State Reducers**: The projection (Event Log -> State) must be a 100% deterministic code-based reducer (e.g., in PHP/Laravel). **Never** use an LLM to "summarize the events into a state" as that reintroduces the exact memoryless variance you are trying to escape.

---

## Concrete Design Recommendation

### Event Schema (Types)

```typescript
type Event =
  | { type: "FactAdded", field: string, value: any, turn: number, quote: string }
  | { type: "FactCorrected", field: string, newValue: any, priorValue: any, turn: number, quote: string, reason: string }
  | { type: "AssumptionRecorded", field: string, assumedValue: any, turn: number, rationale: string }
  | { type: "QuestionDeclined", field: string, turn: number, quote: string }
  | { type: "TurnStarted", idempotencyKey: string, timestamp: string };
```

### Ranked Implementation Phases & Measurement

1.  **Phase 1: Event Log Schema & Projection Engine (Code Only)**
    *   *What to measure*: Unit test coverage of the State Reducer. Prove 100% deterministic folding of synthetic events into a `patient_model` without any LLM involvement.
2.  **Phase 2: LLM Intent Parsing (Shadow Mode)**
    *   *What to measure*: Precision and Recall of the Orient LLM emitting the correct Commands (`AddFact`, `CorrectFact`) against a golden dataset of historic turns (specifically checking the 3-turn AAA case).
3.  **Phase 3: Contradiction Guard**
    *   *What to measure*: Number of `ContradictionErrors` thrown during the general-knowledge test case. It must reliably block the asymptomatic->symptomatic transition 100% of the time.
4.  **Phase 4: Swap and E2E Integration**
    *   *What to measure*: Re-run the external model judge on the 32-turn eval. The target is **0 failures** related to conversational state loss or silent mutation.
