<?php

namespace App\Http\Controllers;

use App\Services\ChangeDetectionService;
use App\Services\RetrievalService;
use App\Services\GuidelineAssetService;
use App\Services\GapDetectionService;
use App\Services\GuidelineRouterService;
use App\Services\ClinicalGateService;
use App\Services\PreRetrievalService;
use App\ValueObjects\PreRetrievalResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ToolController extends Controller
{
    public function __construct(
        protected RetrievalService $retrievalService,
        protected GuidelineAssetService $guidelineAssetService,
        protected GuidelineRouterService $guidelineRouter,
        protected PreRetrievalService $preRetrievalService,
        protected ChangeDetectionService $changeDetectionService,
    ) {
    }

    public function consult(Request $request)
    {
        // validate input
        $request->validate([
            'question' => 'required|string|max:2000',
            'history' => 'nullable|array|max:20',
            'history.*' => 'string|max:2000',
            'guidelines' => 'nullable|array|max:3', // Encouraged but not required
            'guidelines.*' => 'string',
            'pre_retrieval_mode' => 'nullable|boolean',
            'confirmation_mode' => 'nullable|boolean',
            'pre_retrieval_result' => 'nullable|array',
        ]);

        $question = $request->input('question');
        $history = $request->input('history', []);
        $guidelinesInput = $request->input('guidelines', null);
        $preRetrievalMode = (bool) $request->boolean('pre_retrieval_mode', false);
        $confirmationMode = (bool) $request->boolean('confirmation_mode', false);

        [$requestedKeys, $invalidGuidelines] = $this->resolveRequestedGuidelineKeys($guidelinesInput);
        if (!empty($invalidGuidelines)) {
            return $this->jsonApiResponse([
                'error' => "Invalid guideline(s): " . implode(', ', $invalidGuidelines) . ". Check tool documentation for valid options."
            ], 400);
        }

        // Debug logging
        Log::info('Tool API Request', [
            'question' => $question,
            'history_count' => count($history),
            'guidelines_selected' => $requestedKeys ?? 'auto',
            'selection_mode' => $requestedKeys ? 'explicit' : 'auto-routing',
            'pre_retrieval_mode' => $preRetrievalMode,
            'confirmation_mode' => $confirmationMode,
        ]);

        if ($confirmationMode) {
            $preRetrievalData = $request->input('pre_retrieval_result');
            if (!is_array($preRetrievalData)) {
                return $this->jsonApiResponse([
                    'error' => 'confirmation_mode requires pre_retrieval_result.',
                ], 422);
            }

            $original = PreRetrievalResult::fromArray($preRetrievalData);
            $changeResult = $this->changeDetectionService->detect($question, $original);

            if ($changeResult->decision === 'reuse') {
                return $this->jsonApiResponse([
                    'phase' => 'complete',
                    'reused' => true,
                    'decision_reason' => $changeResult->reason,
                ]);
            }

            $requery = $changeResult->enrichedQuery ?: $original->retrievalQuery;
            $effectiveKeys = !empty($original->guidelines) ? $original->guidelines : $requestedKeys;
            $retrieval = $this->executeRetrieval($requery, $history, $effectiveKeys);

            if (isset($retrieval['guardrail'])) {
                return $this->guardrailResponse($retrieval['guardrail']['message'], $retrieval['guardrail']['type']);
            }

            return $this->jsonApiResponse([
                'phase' => 'complete',
                'reused' => false,
                'decision_reason' => $changeResult->reason,
                'retrieval_payload' => $this->buildConsultPayload(
                    $retrieval['result'],
                    $retrieval['assets']
                ),
            ]);
        }

        $guardrailType = $this->preRetrievalGuardrailType($question);
        if ($guardrailType !== null) {
            Log::warning('[GUARDRAIL] Out-of-scope or onboarding prompt routed to consult endpoint; short-circuiting retrieval', [
                'question' => $question,
                'guidelines_selected' => $requestedKeys ?? 'auto',
                'guardrail_type' => $guardrailType,
            ]);

            $message = $this->buildOutOfScopeGuidance($question, $guardrailType);

            return $this->guardrailResponse($message, $guardrailType);
        }

        if ($preRetrievalMode) {
            $preResult = $this->preRetrievalService->analyse($question, $history);
            $preResult = $this->preRetrievalService->applyRequestedGuidelines($preResult, $requestedKeys);
            $effectiveKeys = !empty($preResult->guidelines) ? $preResult->guidelines : $requestedKeys;
            $retrieval = $this->executeRetrieval($preResult->retrievalQuery ?: $question, $history, $effectiveKeys);

            if (isset($retrieval['guardrail'])) {
                return $this->guardrailResponse($retrieval['guardrail']['message'], $retrieval['guardrail']['type']);
            }

            $retrieval['result']['question'] = $question;

            return $this->jsonApiResponse([
                'phase' => 'awaiting_confirmation',
                'confirmation_message' => $preResult->confirmationMessage,
                'soft_warn' => $preResult->softWarn,
                'clarification_questions' => $preResult->clarificationQuestions,
                'provisional_diagnosis' => $preResult->provisionalDiagnosis,
                'pre_retrieval_result' => $preResult->toArray(),
                'retrieval_payload' => $this->buildConsultPayload(
                    $retrieval['result'],
                    $retrieval['assets']
                ),
            ]);
        }

        $retrieval = $this->executeRetrieval($question, $history, $requestedKeys);

        if (isset($retrieval['guardrail'])) {
            return $this->guardrailResponse($retrieval['guardrail']['message'], $retrieval['guardrail']['type']);
        }

        return $this->jsonApiResponse(
            $this->buildConsultPayload($retrieval['result'], $retrieval['assets'])
        );
    }

    public function preRetrieve(Request $request)
    {
        $request->validate([
            'question' => 'required|string|max:2000',
            'history' => 'nullable|array|max:20',
            'history.*' => 'string|max:2000',
            'guidelines' => 'nullable|array|max:3',
            'guidelines.*' => 'string',
        ]);

        $question = $request->input('question');
        $history = $request->input('history', []);
        $guidelinesInput = $request->input('guidelines', null);

        [$requestedKeys, $invalidGuidelines] = $this->resolveRequestedGuidelineKeys($guidelinesInput);
        if (!empty($invalidGuidelines)) {
            return $this->jsonApiResponse([
                'error' => "Invalid guideline(s): " . implode(', ', $invalidGuidelines) . ". Check tool documentation for valid options."
            ], 400);
        }

        $guardrailType = $this->preRetrievalGuardrailType($question);
        if ($guardrailType !== null) {
            $message = $this->buildOutOfScopeGuidance($question, $guardrailType);
            return $this->guardrailResponse($message, $guardrailType);
        }

        $preResult = $this->preRetrievalService->analyse($question, $history);
        $preResult = $this->preRetrievalService->applyRequestedGuidelines($preResult, $requestedKeys);

        return $this->jsonApiResponse([
            'phase' => 'pre_retrieval',
            'confirmation_message' => $preResult->confirmationMessage,
            'soft_warn' => $preResult->softWarn,
            'clarification_questions' => $preResult->clarificationQuestions,
            'provisional_diagnosis' => $preResult->provisionalDiagnosis,
            'pre_retrieval_result' => $preResult->toArray(),
        ]);
    }

    protected function resolveRequestedGuidelineKeys($guidelinesInput): array
    {
        $requestedKeys = null;
        $invalidGuidelines = [];

        if (!empty($guidelinesInput) && is_array($guidelinesInput)) {
            $validKeys = [];

            foreach ($guidelinesInput as $guideline) {
                $guidelineKey = $this->validateGuideline($guideline);
                if ($guidelineKey) {
                    $validKeys[] = $guidelineKey;
                } else {
                    $invalidGuidelines[] = $guideline;
                }
            }

            if (!empty($validKeys)) {
                $requestedKeys = $validKeys;
            }
        }

        return [$requestedKeys, $invalidGuidelines];
    }

    protected function executeRetrieval(string $question, array $history, ?array $requestedKeys): array
    {
        $result = $this->retrievalService->retrieve($question, $history, $requestedKeys);

        $narrativeCount = count($result['narrative_chunks'] ?? []);
        $citationCount = count($result['citation_chunks'] ?? []);
        if ($narrativeCount === 0 && $citationCount === 0 && $this->containsNonAscii($question)) {
            $fallbackQuery = $this->buildMultilingualRetrievalFallbackQuery($question, $requestedKeys, $result['selected_guidelines'] ?? []);
            if ($fallbackQuery !== null) {
                Log::info('[MULTILINGUAL RETRY] No evidence for non-English query; retrying retrieval with guideline-aware English hints', [
                    'question' => $question,
                    'fallback_query' => $fallbackQuery,
                    'guidelines_selected' => $requestedKeys ?? 'auto',
                ]);

                $retryResult = $this->retrievalService->retrieve($fallbackQuery, $history, $requestedKeys);
                $retryNarrativeCount = count($retryResult['narrative_chunks'] ?? []);
                $retryCitationCount = count($retryResult['citation_chunks'] ?? []);
                if ($retryNarrativeCount > 0 || $retryCitationCount > 0) {
                    Log::info('[MULTILINGUAL RETRY] Recovery succeeded', [
                        'question' => $question,
                        'retry_question' => $fallbackQuery,
                        'narrative_count' => $retryNarrativeCount,
                        'citation_count' => $retryCitationCount,
                    ]);
                    $result = $retryResult;
                    $result['question'] = $question;
                    $narrativeCount = $retryNarrativeCount;
                    $citationCount = $retryCitationCount;
                }
            }
        }

        $assets = $this->guidelineAssetService->findRelevantAssets(
            $result['question'] ?? $question,
            $result['narrative_chunks'] ?? [],
            $result['citation_chunks'] ?? [],
            $result['selected_guidelines'] ?? [],
            $requestedKeys ?? []
        );

        if ($narrativeCount === 0 && $citationCount === 0) {
            Log::warning('[GUARDRAIL] Retrieval returned no evidence; returning predefined out-of-scope/help guidance', [
                'question' => $question,
                'guidelines_selected' => $requestedKeys ?? 'auto',
            ]);

            $guardrailType = $this->containsNonAscii($question)
                ? 'no_relevant_esvs_context_non_english'
                : 'no_relevant_esvs_context';

            return [
                'guardrail' => [
                    'type' => $guardrailType,
                    'message' => $this->buildOutOfScopeGuidance($question, $guardrailType),
                ],
            ];
        }

        return [
            'result' => $result,
            'assets' => $assets,
        ];
    }

    protected function buildConsultPayload(array $result, array $assets): array
    {
        $output = $this->buildConsultOutput($result, $assets);

        Log::info('Tool API Response', [
            'question' => $result['question'] ?? '',
            'text_length' => strlen($output),
            'has_narrative_chunks' => !empty($result['narrative_chunks']),
            'narrative_count' => count($result['narrative_chunks'] ?? []),
            'citation_count' => count($result['citation_chunks'] ?? []),
            'asset_count' => count($assets ?? []),
        ]);

        $output = mb_convert_encoding($output, 'UTF-8', 'UTF-8');
        $narrativeChunks = json_decode(json_encode($result['narrative_chunks'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE), true) ?? [];
        $citationChunks = json_decode(json_encode($result['citation_chunks'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE), true) ?? [];
        $selectedGuidelines = json_decode(json_encode($result['selected_guidelines'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE), true) ?? [];
        $safeAssets = json_decode(json_encode($assets, JSON_INVALID_UTF8_SUBSTITUTE), true) ?? [];
        $queryNormalization = json_decode(json_encode($result['query_normalization'] ?? null, JSON_INVALID_UTF8_SUBSTITUTE), true);
        $llmCitationChunks = json_decode(json_encode($result['llm_citation_chunks'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE), true) ?? [];
        $llmNarrativeChunks = json_decode(json_encode($result['llm_narrative_chunks'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE), true) ?? [];
        $uiCitationChunks = json_decode(json_encode($result['ui_citation_chunks'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE), true) ?? [];
        $uiNarrativeChunks = json_decode(json_encode($result['ui_narrative_chunks'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE), true) ?? [];

        return [
            'result' => $output,
            'narrative_chunks' => $narrativeChunks,
            'citation_chunks' => $citationChunks,
            'llm_citation_chunks' => $llmCitationChunks,
            'llm_narrative_chunks' => $llmNarrativeChunks,
            'ui_citation_chunks' => $uiCitationChunks,
            'ui_narrative_chunks' => $uiNarrativeChunks,
            'must_include_chunk' => $result['must_include_chunk'] ?? null,
            'intent_profile' => $result['intent_profile'] ?? null,
            'selected_guidelines' => $selectedGuidelines,
            'assets' => $safeAssets,
            'query_normalization' => $queryNormalization,
            'query_type' => $result['query_type'] ?? 'complex_case',
        ];
    }

    protected function buildConsultOutput(array $result, array $assets): string
    {
        $question = $result['question'] ?? '';
        $output = "RETRIEVED GUIDELINES for: \"{$question}\"\n";
        $output .= "Guidelines Used: " . implode(', ', array_column($result['selected_guidelines'] ?? [], 'name')) . "\n\n";

        $output .= "=== NARRATIVE EVIDENCE (Use for context) ===\n";
        foreach (($result['narrative_chunks'] ?? []) as $chunk) {
            $output .= "- " . ($chunk['content'] ?? '') . "\n";
        }
        $output .= "\n";

        $output .= "=== CITATIONS (Use for verbatim quoting) ===\n";
        foreach (($result['citation_chunks'] ?? []) as $chunk) {
            $meta = [];
            if (!empty($chunk['recommendation_id'])) {
                $meta[] = $chunk['recommendation_id'];
            }
            if (!empty($chunk['class'])) {
                $meta[] = $chunk['class'];
            }
            if (!empty($chunk['level'])) {
                $meta[] = $chunk['level'];
            }
            $metaStr = !empty($meta) ? " [" . implode(', ', $meta) . "]" : '';

            $output .= "> \"" . ($chunk['text'] ?? '') . "\"{$metaStr}\n";
        }

        if (!empty($assets)) {
            $output .= "\n\n=== FIGURES / TABLES (For user display) ===\n";
            $output .= "If relevant, you may include these in your response as Markdown images.\n";
            foreach ($assets as $asset) {
                $label = $asset['label'] ?? ($asset['id'] ?? 'Asset');
                $caption = $asset['caption'] ?? '';
                $url = $asset['url'] ?? '';
                $guidelineKey = $asset['guideline_key'] ?? '';
                if (empty($url)) {
                    continue;
                }

                $output .= "- {$label}" . (!empty($guidelineKey) ? " ({$guidelineKey})" : '') . ": {$caption}\n";
                $output .= "  {$url}\n";
                if (!empty($caption)) {
                    $output .= "  ![{$caption}]({$url})\n";
                }
            }
        }

        $output .= "\n\n=== IMPORTANT ===\n";
        $output .= "Present this using the mandatory response format:\n";
        $output .= "1. 🩺 Clinical Synthesis (3-6 bullets with inline citations)\n";
        $output .= "2. 📑 Recommendations used in this answer (verbatim quotes)\n";
        $output .= "3. 🧠 Clinical Decision Summary (required for management/treatment/clinical strategy questions)\n";
        $output .= "   Use the retrieved guideline evidence to:\n";
        $output .= "   (1) determine whether treatment thresholds are met,\n";
        $output .= "   (2) interpret the anatomical features provided,\n";
        $output .= "   (3) compare available treatment strategies,\n";
        $output .= "   (4) state the default/preferred guideline-consistent strategy when inferable, and\n";
        $output .= "   (5) identify the main alternative strategy and when it may be chosen instead.\n";
        $output .= "   Do not stop at \"both options may be considered\"; provide a reasoned decision.\n";
        $output .= "   If anatomical measurements are provided (neck length, angulation, landing zones), interpret compatibility with standard EVAR, fenestrated/branched endovascular repair, and open surgical repair, and explain how anatomy drives modality choice.\n";
        $output .= "4. ⚠️ Perioperative Risk Mitigation (Guideline-Based)\n";
        $output .= "   For operative management, summarize key risk-reduction strategies when relevant: spinal cord ischemia prevention, renal protection, cardiac risk optimisation, staged repair strategies, and preservation of critical branch vessels.\n";
        $output .= "5. 📌 Guideline supporting statements\n";
        if (!empty($assets)) {
            $output .= "6. 🖼️ Figures / Tables (optional; show images if they help)\n";
        }

        $gapService = new GapDetectionService();
        if ($gapService->allowPartialAnswers()) {
            $output .= "\n=== PARTIAL MATCH GUIDANCE ===\n";
            $output .= "If the retrieved evidence is relevant but does not exactly match the scenario, provide a best-fit answer based on the closest evidence.\n";
            $output .= "Clearly state which parts are directly supported vs extrapolated or missing.\n";
            $output .= "Do not return a blanket \"not explicitly addressed\" response unless there is zero relevant evidence.\n";
            $output .= "Invite the user to decide what is applicable to their case.\n";
            if ($gapService->strictTemplateEnabled()) {
                $output .= "Place the fit/limitations note within Assessment or Evidence used to preserve the required structure.\n";
            }
        }

        if ($gapService->strictTemplateEnabled()) {
            $output .= "\n=== REQUIRED STRUCTURE (STRICT) ===\n";
            $output .= "Assessment:\n";
            $output .= "Imaging:\n";
            $output .= "Indication for intervention:\n";
            $output .= "Treatment options:\n";
            $output .= "Clinical Decision Summary:\n";
            $output .= "Perioperative Risk Mitigation:\n";
            $output .= "Follow-up:\n";
            $output .= "Evidence used (Rec #, Class, Level):\n";
        }

        return $output;
    }

    protected function guardrailResponse(string $message, string $type)
    {
        return $this->jsonApiResponse([
            'result' => $message,
            'narrative_chunks' => [],
            'citation_chunks' => [],
            'assets' => [],
            'guardrail' => [
                'type' => $type,
                'short_circuited' => true,
            ],
        ]);
    }

    protected function jsonApiResponse(array $payload, int $status = 200)
    {
        return response()->json($payload, $status, [], JSON_INVALID_UTF8_SUBSTITUTE)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    }

    public function clinicalGate(Request $request)
    {
        $request->validate([
            'question' => 'required|string|max:2000',
            'history'  => 'nullable|array|max:20',
            'history.*' => 'string|max:2000',
        ]);

        $question = $request->input('question');
        $history  = $request->input('history', []);

        $service = new ClinicalGateService();
        $result  = $service->interpret($question, $history);

        return response()->json($result, 200, [], JSON_INVALID_UTF8_SUBSTITUTE)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    }

    public function normalize(Request $request)
    {
        $request->validate(['question' => 'required|string|max:2000']);
        $question = $request->input('question');

        $result = $this->guidelineRouter->normalizeForRetrieval($question) ?? [
            'normalized_query' => $question,
            'language' => 'en',
            'changed' => false,
        ];

        return response()->json($result, 200, [], JSON_INVALID_UTF8_SUBSTITUTE)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    }

    protected function validateGuideline(string $guideline): ?string
    {
        $categories = config('guidelines.categories', []);
        $nameMap = [];
        $keywordMap = [];
        $allKeys = [];

        foreach ($categories as $category) {
            foreach ($category['guidelines'] as $key => $info) {
                $allKeys[] = $key;

                // Accept exact key
                $nameMap[strtolower($key)] = $key;
                // Accept exact display name
                $nameMap[strtolower($info['name'])] = $key;

                // Extract keywords from key name
                $keyWords = explode('_', strtolower($key));
                foreach ($keyWords as $word) {
                    if (strlen($word) > 2) {
                        $keywordMap[$word][] = $key;
                    }
                }

                // Also add key_concepts as exact matches and keywords
                foreach ($info['key_concepts'] ?? [] as $concept) {
                    $conceptLower = strtolower($concept);
                    $nameMap[$conceptLower] = $key;

                    // Also extract words from concepts
                    $conceptWords = preg_split('/[\s\-_]+/', $conceptLower);
                    foreach ($conceptWords as $word) {
                        if (strlen($word) > 2) {
                            $keywordMap[$word][] = $key;
                        }
                    }
                }
            }
        }

        $guidelineLower = strtolower(trim($guideline));

        // 1. Try exact match
        if (isset($nameMap[$guidelineLower])) {
            Log::info("[GUIDELINE MATCH] Exact: '{$guideline}' -> '{$nameMap[$guidelineLower]}'");
            return $nameMap[$guidelineLower];
        }

        // 2. Try keyword scoring
        $scores = [];
        foreach ($keywordMap as $keyword => $keys) {
            if (str_contains($guidelineLower, $keyword)) {
                foreach ($keys as $key) {
                    $scores[$key] = ($scores[$key] ?? 0) + 1;
                }
            }
        }

        if (!empty($scores)) {
            arsort($scores);
            $bestKey = array_key_first($scores);
            $bestScore = $scores[$bestKey];
            if ($bestScore >= 1) { // Accept even 1 keyword match
                Log::info("[GUIDELINE MATCH] Keyword: '{$guideline}' -> '{$bestKey}' (score: {$bestScore})");
                return $bestKey;
            }
        }

        // 3. Failed
        Log::warning("[GUIDELINE VALIDATION FAILED]", [
            'received' => $guideline,
            'available_keys' => $allKeys
        ]);

        return null;
    }

    protected function preRetrievalGuardrailType(string $question): ?string
    {
        if ($this->isGenericCapabilityPrompt($question)) {
            return 'capabilities_onboarding';
        }
        if ($this->isLikelyOutOfScopePrompt($question)) {
            return 'out_of_scope';
        }
        return null;
    }

    protected function isGenericCapabilityPrompt(string $question): bool
    {
        $q = mb_strtolower(trim($question));
        if ($q === '') {
            return false;
        }

        $capabilityIntent = preg_match(
            '/\b(how can (you|this app) help|can this app help|what can (you|this app) do|what does this app do|how should i use|how do i use|who is this (for|app for)|what is this app)\b/u',
            $q
        ) === 1;

        if (!$capabilityIntent) {
            return false;
        }

        return !$this->hasConcreteVascularTarget($q);
    }

    protected function isLikelyOutOfScopePrompt(string $question): bool
    {
        $q = mb_strtolower(trim($question));
        if ($q === '') {
            return false;
        }

        if ($this->hasConcreteVascularTarget($q)) {
            return false;
        }

        $nonClinicalTechOrGeneral = preg_match(
            '/\b(openfortivpn|linux|ubuntu|debian|nginx|docker|ssh|git|python|php|javascript|sql|excel|spreadsheet|powerpoint|email|auth0|cloudflare|dns|ssl|tls|certificate|vm|azure|aws|gcp|kubernetes|devops|api key|json|yaml|regex|code|programming)\b/u',
            $q
        ) === 1;

        $broadNonVascularMedical = preg_match(
            '/\b(internal medicine|pediatrics|psychiatry|dermatology|orthopedic|ophthalmology|obgyn|gynaecology|gynecology|oncology|neurology|endocrinology|gastroenterology|pulmonology|nephrology|infectious disease)\b/u',
            $q
        ) === 1;

        $generalAskWithoutESVSContext = preg_match(
            '/\b(can you help|help me|what should i do|what do you think|explain this|summarize this|translate|write|draft)\b/u',
            $q
        ) === 1 && !preg_match('/\b(esvs|guideline|vascular)\b/u', $q);

        return $nonClinicalTechOrGeneral || $broadNonVascularMedical || $generalAskWithoutESVSContext;
    }

    protected function hasConcreteVascularTarget(string $q): bool
    {
        return preg_match(
            '/\b(aaa|aneurysm|clti|critical limb|acute limb|ischaemi|ischemi|carotid|vertebral|mesenteric|renal artery|dvt|pe\b|vte|venous thrombosis|saphenous|varicose|venous ulcer|antithrombotic|aspirin|doac|dapt|vascular trauma|graft infection|endograft|vascular access|avf|fistula|tevar|evar|endarterectomy|stenting|stroke|tia|peripheral arterial disease|pad)\b/u',
            $q
        ) === 1;
    }

    protected function buildOutOfScopeGuidance(string $question, string $type = 'out_of_scope'): string
    {
        $q = trim($question);
        $lines = [];
        if ($q !== '') {
            $lines[] = "This question is outside the supported ESVS retrieval scope (or not specific enough for guideline retrieval): {$q}";
            $lines[] = '';
        }

        $lines[] = 'What this app is for';
        $lines[] = '- ESVS vascular guideline retrieval and evidence support for specific vascular clinical questions.';
        $lines[] = '- Case-to-guideline comparison using retrieved ESVS recommendations and supporting statements.';
        $lines[] = '- Figures/tables display when relevant assets exist.';
        $lines[] = '';
        $lines[] = 'What to expect';
        $lines[] = '- For in-scope queries, the app retrieves ESVS evidence chunks and returns a citation-based answer.';
        $lines[] = '- For out-of-scope or vague queries, the app returns this usage guidance instead of guessing a guideline.';
        if ($type === 'no_relevant_esvs_context_non_english') {
            $lines[] = '- For non-English queries, retrieval may miss relevant evidence even when the topic is in scope.';
        }
        $lines[] = '';
        if ($type === 'no_relevant_esvs_context_non_english') {
            $lines[] = 'Language note (important)';
            $lines[] = '- Your question appears to be non-English. Guideline retrieval currently works best with English phrasing.';
            $lines[] = '- Please rephrase in English or include key English medical terms (e.g., "saphenous vein thrombosis", "superficial venous thrombosis", "CLTI", "AAA", "carotid stenosis").';
            $lines[] = '- You can keep the rest of the question in your language, but include the main diagnosis/condition in English for best results.';
            $lines[] = '';
        }
        $lines[] = 'How to use it properly (best results)';
        $lines[] = '- Include the condition/problem (e.g., AAA, CLTI, carotid stenosis, venous thrombosis).';
        $lines[] = '- Include anatomy/territory, acuity, and treatment decision you need help with.';
        $lines[] = '- For case review, include key patient details and ask what ESVS recommends or whether management aligns.';
        $lines[] = '- Ask one main clinical question per message when possible.';
        $lines[] = '';
        $lines[] = 'Good examples';
        $lines[] = '- "What does ESVS recommend for superficial/saphenous venous thrombosis?"';
        $lines[] = '- "Does this CLTI revascularization plan align with ESVS guidance?"';
        $lines[] = '- "For carotid stenosis after TIA, what are ESVS recommendations?"';
        $lines[] = '';
        $lines[] = 'Out of scope examples';
        $lines[] = '- General app onboarding without a clinical question (e.g., "Can this app help me?").';
        $lines[] = '- Non-vascular or broad internal medicine questions without an ESVS vascular guideline target.';
        $lines[] = '- Technical/IT support questions (Linux, VPN, coding, server issues).';
        $lines[] = '';
        $lines[] = 'Scope note';
        $lines[] = '- This app is focused on ESVS vascular guidelines, not general internal medicine or non-medical support.';

        return implode("\n", $lines);
    }

    protected function containsNonAscii(string $text): bool
    {
        return preg_match('/[^\x00-\x7F]/', $text) === 1;
    }

    protected function buildMultilingualRetrievalFallbackQuery(string $question, ?array $requestedKeys, array $selectedGuidelines = []): ?string
    {
        $keys = [];
        if (is_array($requestedKeys)) {
            $keys = $requestedKeys;
        }

        if (empty($keys) && !empty($selectedGuidelines)) {
            // When selectedGuidelines is keyed by guideline key, prefer those keys directly.
            if (!array_is_list($selectedGuidelines)) {
                $keys = array_keys($selectedGuidelines);
            } else {
                foreach ($selectedGuidelines as $g) {
                    if (!is_array($g)) {
                        continue;
                    }
                    foreach (['key', 'guideline_key', 'slug'] as $candidate) {
                        if (!empty($g[$candidate]) && is_string($g[$candidate])) {
                            $keys[] = $g[$candidate];
                            break;
                        }
                    }
                }
            }
        }

        $keys = array_values(array_unique(array_filter($keys, fn ($v) => is_string($v) && $v !== '')));
        if (empty($keys)) {
            return null;
        }

        $hintMap = [
            'venous_thrombosis' => 'venous thrombosis dvt pe vte superficial venous thrombosis saphenous vein thrombosis management esvs',
            'chronic_venous_disease' => 'chronic venous disease varicose veins venous ulcer superficial venous disease esvs',
            'asymptomatic_pad' => 'peripheral arterial disease pad claudication exercise therapy asymptomatic pad esvs',
            'clti' => 'chronic limb threatening ischaemia ischemia clti revascularization tissue loss rest pain esvs',
            'acute_limb_ischaemia' => 'acute limb ischaemia ischemia ali embolism thrombosis 6Ps esvs',
            'abdominal_aortic_aneurysm' => 'abdominal aortic aneurysm aaa evar open repair iliac aneurysm esvs',
            'carotid_vertebral' => 'carotid stenosis vertebral artery tia stroke cea cas esvs',
            'antithrombotic_therapy' => 'antithrombotic therapy aspirin doac dapt anticoagulation vascular disease esvs',
            'mesenteric_renal' => 'mesenteric ischemia renal artery stenosis cmi ami esvs',
            'descending_thoracic_aorta' => 'type b dissection thoracic aneurysm tevar descending thoracic aorta esvs',
            'aortic_arch' => 'aortic arch aneurysm hybrid arch frozen elephant trunk fet esvs',
            'vascular_trauma' => 'vascular trauma penetrating blunt injury reboa limb vascular injury esvs',
            'vascular_graft_infections' => 'vascular graft infection endograft infection graft infection esvs',
            'vascular_access' => 'vascular access dialysis fistula avf graft thrombosis esvs',
        ];

        $hintParts = [];
        foreach ($keys as $k) {
            if (isset($hintMap[$k])) {
                $hintParts[] = $hintMap[$k];
            }
        }
        $hintParts = array_values(array_unique($hintParts));
        if (empty($hintParts)) {
            return null;
        }

        return trim(implode(' ', $hintParts));
    }
}
