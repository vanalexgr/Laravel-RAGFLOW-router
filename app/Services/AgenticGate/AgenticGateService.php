<?php

namespace App\Services\AgenticGate;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PROTOTYPE — agentic clarification gate ("orient → probe → proceed").
 *
 * This is a research prototype, NOT wired into the production request path. It
 * explores replacing the current closed-whitelist gate (PreRetrievalService's
 * SOFT WARN rules) with senior-surgeon-style reasoning:
 *
 *   ORIENT  — build a structured patient model and enumerate the LIVE guideline
 *             decision pathways relevant to this case.
 *   PROBE   — find the unknowns that discriminate between those pathways, rank
 *             them by branch impact (value of information), and ask only the
 *             highest-impact 1-2 questions a consultant would actually ask.
 *   PROCEED — always able to give a best-effort answer with stated assumptions,
 *             plus a calibrated "can I answer now?" confidence.
 *
 * The intelligence lives in one reasoning LLM call. Grounding is injected via
 * $guidelineContext: when empty the model uses its own ESVS knowledge to name
 * decision nodes; when populated with retrieved recommendation snippets the
 * pathways must be grounded in those snippets (the production step).
 */
class AgenticGateService
{
    /** Confidence at/above which the gate proceeds without asking. */
    private const PROCEED_CONFIDENCE = 0.70;

    /**
     * This step is the highest-value call in the pipeline, so it gets its own
     * generous budget rather than the 10s-capped shared LlmClient — a reasoning
     * pass over a full patient model needs room in both time and tokens.
     */
    private const TIMEOUT_SECONDS = 45;
    private const MAX_TOKENS = 1400;

    public function __construct(
        /** @var array<string,string> guideline key => display name */
        private readonly array $guidelineKeys = [],
    ) {
    }

    /**
     * Run the agentic gate over a case.
     *
     * @param  string                     $question         raw case / question
     * @param  array<int,string>          $history          prior turns (strings)
     * @param  array<int,string>          $guidelineContext retrieved recommendation snippets (grounding seam)
     * @return array<string,mixed>        structured reasoning + decision
     */
    public function probe(string $question, array $history = [], array $guidelineContext = []): array
    {
        $question = trim($question);
        if ($question === '') {
            return $this->fallback('empty question');
        }

        $prompt = $this->buildPrompt($question, $history, $guidelineContext);

        try {
            $raw = $this->callAzure($prompt);
            $data = $this->parseJson($raw);

            if ($data === null) {
                Log::channel('retrieval')->warning('[AGENTIC GATE] JSON parse failed', [
                    'question_preview' => substr($question, 0, 120),
                    'raw_preview' => substr($raw, 0, 240),
                ]);

                return $this->fallback('json parse failed');
            }

            return $this->decide($this->normalize($data));
        } catch (\Throwable $e) {
            Log::channel('retrieval')->warning('[AGENTIC GATE] LLM call failed', [
                'question_preview' => substr($question, 0, 120),
                'error' => $e->getMessage(),
            ]);

            return $this->fallback('llm error: '.$e->getMessage());
        }
    }

    /**
     * Direct Azure call with a generous timeout/token budget. temperature is
     * omitted because gpt-5-chat only supports its default value.
     */
    private function callAzure(string $prompt): string
    {
        $endpoint = config('prism.providers.azure.endpoint');
        $apiKey = config('prism.providers.azure.api_key');
        $deployment = config('prism.providers.azure.deployment');
        $apiVersion = config('prism.providers.azure.api_version');

        if (empty($endpoint) || empty($apiKey) || empty($deployment)) {
            throw new \RuntimeException('Azure OpenAI is not configured.');
        }

        $url = rtrim((string) $endpoint, '/')
            ."/openai/deployments/{$deployment}/chat/completions"
            ."?api-version={$apiVersion}";

        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->withHeaders([
                'api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post($url, [
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a senior vascular surgeon. Return ONLY valid JSON — no prose, no markdown fences.',
                    ],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_completion_tokens' => self::MAX_TOKENS,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Azure request failed with status '.$response->status());
        }

        return (string) $response->json('choices.0.message.content', '');
    }

    /**
     * Apply the proceed/ask decision from the model's calibrated confidence and
     * the ranked unknowns. Asking is bounded to the two highest-impact questions.
     */
    private function decide(array $data): array
    {
        $confidence = (float) ($data['confidence'] ?? 0.0);

        $highImpact = array_values(array_filter(
            $data['unknowns'] ?? [],
            fn ($u) => is_array($u)
                && ($u['branch_impact'] ?? '') === 'high'
                && ($u['currently_known'] ?? false) === false
        ));

        // Ask only when a genuinely decision-changing unknown exists AND the
        // model is not already confident enough to answer.
        $shouldAsk = ! empty($highImpact) && $confidence < self::PROCEED_CONFIDENCE;

        $questions = array_slice($data['questions'] ?? [], 0, 2);

        $data['decision'] = $shouldAsk ? 'ask' : 'proceed';
        $data['questions'] = $shouldAsk ? $questions : [];
        $data['high_impact_unknown_count'] = count($highImpact);

        return $data;
    }

    private function buildPrompt(string $question, array $history, array $guidelineContext): string
    {
        $historyText = '';
        $recent = array_slice(array_values(array_filter(array_map(
            fn ($h) => is_string($h) ? trim($h) : '',
            $history
        ), fn (string $h): bool => $h !== '')), -4);
        if (! empty($recent)) {
            $historyText = "PRIOR CONVERSATION (already known — never re-ask):\n- ".implode("\n- ", $recent)."\n\n";
        }

        if (! empty($guidelineContext)) {
            $groundingBlock = "GROUNDING — you MUST derive decision pathways from these retrieved ESVS recommendations, "
                ."not from memory:\n- ".implode("\n- ", array_slice($guidelineContext, 0, 12))."\n\n";
            $groundingRule = 'Every decision_pathway.guideline_basis MUST reference the retrieved recommendations above.';
        } else {
            $groundingBlock = '';
            $groundingRule = 'Use your knowledge of current ESVS guideline decision thresholds to name the pathways.';
        }

        $guidelineList = '';
        if (! empty($this->guidelineKeys)) {
            $lines = [];
            foreach ($this->guidelineKeys as $key => $name) {
                $lines[] = "- {$key}: {$name}";
            }
            $guidelineList = "AVAILABLE GUIDELINE KEYS (route among these):\n".implode("\n", $lines)."\n\n";
        }

        return <<<PROMPT
You are a senior consultant vascular surgeon reasoning about a case before pulling ESVS guideline evidence.
Think the way a consultant does on a ward round: form a differential, work out which management pathways are live,
and ask ONLY the question(s) whose answer would actually change what you do next. Do not ask an intake-form
checklist. A junior asks everything; a consultant asks the one or two things that move the decision.

{$guidelineList}{$groundingBlock}{$historyText}CASE:
{$question}

Return ONE valid JSON object (no markdown, no prose) with EXACTLY these fields:

{
  "patient_model": {
    "demographics": "string or unknown",
    "lesion": "string or unknown",
    "symptom_status": "symptomatic | asymptomatic | unknown",
    "timing": "string or unknown",
    "fitness": "string or unknown",
    "imaging": "string or unknown",
    "comorbidities": ["..."],
    "medications": ["..."],
    "prior_interventions": ["..."],
    "measurements": {"key": "value"}
  },
  "differential": ["most likely clinical framing", "..."],
  "routed_guidelines": ["guideline_key", "..."],
  "decision_pathways": [
    {
      "pathway": "e.g. carotid endarterectomy",
      "guideline_basis": "the decision rule that makes this pathway apply",
      "discriminating_variables": ["variables whose value selects for/against this pathway"]
    }
  ],
  "unknowns": [
    {
      "variable": "short name of the missing fact",
      "why_it_changes_management": "which pathway it selects and why",
      "branch_impact": "high | medium | low",
      "currently_known": false
    }
  ],
  "questions": [
    {"question": "the exact question to ask the clinician", "targets": "variable name", "rationale": "one line"}
  ],
  "can_answer_now": true,
  "confidence": 0.0,
  "provisional_answer": "best-effort answer you could give now, stating assumptions inline",
  "assumptions": ["assumption you would make to proceed without asking"]
}

RULES:
- {$groundingRule}
- "unknowns" must list ONLY facts genuinely absent from the CASE and PRIOR CONVERSATION. Never mark a stated fact as unknown.
- "branch_impact" is HIGH only if the unknown flips the first-line decision between pathways; MEDIUM if it changes technique/timing detail; LOW if merely nice-to-know.
- "questions" must contain at most 2 items, drawn from the HIGHEST branch_impact unknowns only, phrased as a consultant would ask.
- If no HIGH-impact unknown exists, "questions" must be empty and you should be able to answer.
- "confidence" (0.0-1.0) is your calibrated probability that your provisional_answer would NOT change if the unknowns were filled.
- Always produce a usable "provisional_answer" even when you would prefer to ask.
PROMPT;
    }

    private function normalize(array $data): array
    {
        $arr = fn ($v) => is_array($v) ? array_values($v) : [];

        $pm = is_array($data['patient_model'] ?? null) ? $data['patient_model'] : [];

        return [
            'patient_model' => [
                'demographics' => (string) ($pm['demographics'] ?? 'unknown'),
                'lesion' => (string) ($pm['lesion'] ?? 'unknown'),
                'symptom_status' => (string) ($pm['symptom_status'] ?? 'unknown'),
                'timing' => (string) ($pm['timing'] ?? 'unknown'),
                'fitness' => (string) ($pm['fitness'] ?? 'unknown'),
                'imaging' => (string) ($pm['imaging'] ?? 'unknown'),
                'comorbidities' => $arr($pm['comorbidities'] ?? []),
                'medications' => $arr($pm['medications'] ?? []),
                'prior_interventions' => $arr($pm['prior_interventions'] ?? []),
                'measurements' => is_array($pm['measurements'] ?? null) ? $pm['measurements'] : [],
            ],
            'differential' => $arr($data['differential'] ?? []),
            'routed_guidelines' => $arr($data['routed_guidelines'] ?? []),
            'decision_pathways' => $arr($data['decision_pathways'] ?? []),
            'unknowns' => $arr($data['unknowns'] ?? []),
            'questions' => $arr($data['questions'] ?? []),
            'can_answer_now' => (bool) ($data['can_answer_now'] ?? false),
            'confidence' => max(0.0, min(1.0, (float) ($data['confidence'] ?? 0.0))),
            'provisional_answer' => (string) ($data['provisional_answer'] ?? ''),
            'assumptions' => $arr($data['assumptions'] ?? []),
        ];
    }

    private function fallback(string $reason): array
    {
        return [
            'decision' => 'ask',
            'error' => $reason,
            'patient_model' => [],
            'differential' => [],
            'routed_guidelines' => [],
            'decision_pathways' => [],
            'unknowns' => [],
            'questions' => [[
                'question' => 'Could you add the key clinical details (symptom status, timing, and relevant measurements) for this case?',
                'targets' => 'general',
                'rationale' => 'fallback — reasoning gate unavailable',
            ]],
            'can_answer_now' => false,
            'confidence' => 0.0,
            'provisional_answer' => '',
            'assumptions' => [],
            'high_impact_unknown_count' => 0,
        ];
    }

    private function parseJson(string $raw): ?array
    {
        $clean = trim((string) preg_replace('/```(?:json)?|```/i', '', $raw));
        if ($clean === '') {
            return null;
        }

        if (preg_match('/\{[\s\S]*\}/u', $clean, $m)) {
            $clean = $m[0];
        }

        $decoded = json_decode($clean, true);

        return is_array($decoded) ? $decoded : null;
    }
}
