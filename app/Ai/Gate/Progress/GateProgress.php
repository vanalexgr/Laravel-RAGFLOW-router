<?php

namespace App\Ai\Gate\Progress;

/**
 * Progress channel for the gate. The workflow calls emit() at every stage
 * boundary so the caller can show the user what is happening — especially when
 * the agent leaves the fast path and goes into deep reasoning or a re-check, so
 * the extra latency is always explained rather than felt as a hang.
 *
 * Implementations:
 *  - NullGateProgress  — discards (batch/eval runs, tests).
 *  - LogGateProgress   — writes to the retrieval log (Hetzner debugging).
 *  - the OpenWebUI adapter maps these events to __event_emitter__ "status"
 *    messages so the user sees a live line like "🔍 Retrieving carotid guideline…".
 *
 * Events are intentionally coarse and user-facing, not a debug trace. Stage keys
 * are stable so the adapter can localise / icon them:
 *   triage, knowledge_fast, orient, retrieve, probe, evaluate, revise, decide, done
 */
interface GateProgress
{
    /**
     * @param  string  $stage    stable stage key (see list above)
     * @param  string  $message  short human-facing status line
     * @param  array<string,mixed>  $context  optional structured detail
     *                            (e.g. ['guideline' => 'carotid', 'iteration' => 2])
     */
    public function emit(string $stage, string $message, array $context = []): void;
}
