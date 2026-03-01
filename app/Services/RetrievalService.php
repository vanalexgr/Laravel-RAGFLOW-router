<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use App\Services\GuidelineRouterService;
use App\Services\PHIScrubberService;
use App\Services\BridgeRerankService;
use App\Services\GapDetectionService;

class RetrievalService
{
    /**
     * Core retrieval pipeline: PHI Scrub -> Route -> Dual Retrieve.
     *
     * @param string $question
     * @param array $history
     * @param array|null $requestedKeys
     * @return array
     */
    public function retrieve(string $question, array $history = [], ?array $requestedKeys = null): array
    {
        $startTime = microtime(true);
        $log = Log::channel('retrieval');
        $correlationId = substr(uniqid(), -8);

        // 1. PHI Scrubbing
        $phiScrubber = new PHIScrubberService();
        $scrubResult = $phiScrubber->scrub($question);
        $scrubbedQuestion = $scrubResult['scrubbed_text'];
        $retrievalQuestion = $scrubbedQuestion;
        $normalizationOriginalQuestion = $scrubbedQuestion;
        $normalizationMeta = null;

        if ($this->containsNonAscii($scrubbedQuestion)) {
            try {
                $normalizer = new GuidelineRouterService();
                $normalizationMeta = $normalizer->normalizeForRetrieval($scrubbedQuestion, $requestedKeys);
                if (is_array($normalizationMeta) && !empty($normalizationMeta['normalized_query'])) {
                    $candidate = trim((string) $normalizationMeta['normalized_query']);
                    if ($candidate !== '') {
                        $retrievalQuestion = $candidate;
                    }
                    $log->info('[QUERY NORMALIZATION] Applied multilingual retrieval normalization', [
                        'correlation_id' => $correlationId,
                        'original_preview' => substr($scrubbedQuestion, 0, 80),
                        'normalized_preview' => substr($retrievalQuestion, 0, 120),
                        'language' => $normalizationMeta['language'] ?? 'unknown',
                        'changed' => (bool) ($normalizationMeta['changed'] ?? false),
                        'requested_keys' => $requestedKeys ?? [],
                    ]);
                }
            } catch (\Throwable $e) {
                $log->warning('[QUERY NORMALIZATION] Failed before retrieval; continuing with original query', [
                    'correlation_id' => $correlationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $log->info('=== RETRIEVAL SERVICE ===', [
            'correlation_id' => $correlationId,
            'question_preview' => substr($scrubbedQuestion, 0, 50),
            'retrieval_query_preview' => substr($retrievalQuestion, 0, 80),
            'has_history' => !empty($history),
        ]);

        // 2. Routing
        $guidelineScores = [];
        $routingMethod = 'manual';

        if (empty($requestedKeys)) {
            $router = new GuidelineRouterService();
            // Use context-aware routing
            $llmResult = $router->routeWithContext($retrievalQuestion, $history, 3);

            $selectedKeys = $llmResult['selected'];
            $guidelineScores = $llmResult['scores'] ?? [];
            $routingMethod = $llmResult['routing_method'] ?? 'unknown';

            // Convert keys to full guideline info
            if (!empty($selectedKeys)) {
                $registry = $this->buildGuidelineRegistry();
                $selectedGuidelines = [];
                foreach ($selectedKeys as $key) {
                    if (isset($registry[$key])) {
                        $selectedGuidelines[$key] = $registry[$key];
                    }
                }
            } else {
                // Fallback to rule-based
                $selectedGuidelines = $this->selectGuidelinesRuleBased($retrievalQuestion);
                $routingMethod = 'rule_based_fallback';
            }
        } else {
            $selectedGuidelines = $this->validateGuidelineKeys($requestedKeys);
            $routingMethod = 'explicit';
        }

        // Apply post-routing guardrails (SVT/anticoag)
        $selectedGuidelines = $this->applyGuardrails($selectedGuidelines, $retrievalQuestion);

        // 4. Fallback Keyword Scoring (if still empty)
        if (empty($selectedGuidelines)) {
            $selectedGuidelines = $this->selectGuidelinesByKeywordScore($retrievalQuestion, 4);
            $routingMethod = 'keyword_fallback';

            if (empty($selectedGuidelines)) {
                throw new \RuntimeException('Unable to identify relevant guidelines. Please try specific clinical terms.');
            }
        }

        // 4. Dual Retrieval
        $retrievalConfig = config('ragflow.retrieval', []);
        $narrativeMax = (int) ($retrievalConfig['narrative_max'] ?? 10);
        $citationMax = (int) ($retrievalConfig['citation_max'] ?? 4);
        // Prevent pathological values while still allowing larger pools for experimentation.
        $narrativeMax = max(1, min($narrativeMax, 200));
        $citationMax = max(1, min($citationMax, 200));

        // Create an expanded query for retrieval
        $router = new GuidelineRouterService();
        $expansionResult = $router->selectAndExpand($retrievalQuestion, 3, null, null);
        $expandedQuery = $expansionResult['expanded'] ?? $retrievalQuestion; // Use expanded or original
        $expandedQuery = $this->buildCitationQuery($expandedQuery, $normalizationOriginalQuestion, $normalizationMeta, array_keys($selectedGuidelines));
        $citationQuery = $this->buildCitationQuery($retrievalQuestion, $normalizationOriginalQuestion, $normalizationMeta, array_keys($selectedGuidelines));
        $expandedQuery = $this->applyRetrievalQueryBoosts($expandedQuery, array_keys($selectedGuidelines), 'narrative');
        $citationQuery = $this->applyRetrievalQueryBoosts($citationQuery, array_keys($selectedGuidelines), 'citation');

        $dualResult = $this->retrieveDualChunks($expandedQuery, $citationQuery, $selectedGuidelines, $narrativeMax, $citationMax, $guidelineScores);

        $dualResult = $this->applyFocusedRecall(
            $dualResult,
            $scrubbedQuestion,
            $expandedQuery,
            $citationQuery,
            $selectedGuidelines,
            $narrativeMax,
            $citationMax,
            $guidelineScores
        );

        $dualResult = $this->applyQualityPass(
            $dualResult,
            $scrubbedQuestion,
            $expandedQuery,
            $citationQuery,
            $selectedGuidelines,
            $narrativeMax,
            $citationMax,
            $guidelineScores
        );

        $gapReport = null;
        $gapService = new GapDetectionService();
        if ($gapService->enabled() && $gapService->maxPasses() > 0) {
            $gapReport = $gapService->detect(
                $scrubbedQuestion,
                $dualResult['narrative_chunks'] ?? [],
                $dualResult['citation_chunks'] ?? [],
                $normalizationMeta
            );

            if (!empty($gapReport['missing']) && !empty($gapReport['query_terms'])) {
                $limits = $gapService->secondPassLimits();
                $focusedNarrativeQuery = trim($expandedQuery . ' ' . implode(' ', $gapReport['query_terms']));
                $focusedCitationQuery = trim($citationQuery . ' ' . implode(' ', $gapReport['query_terms']));

                $log->info('[GAP DETECTION] Missing fields detected; running focused second pass', [
                    'missing' => $gapReport['missing'],
                    'query_terms' => $gapReport['query_terms'],
                    'narrative_max' => $limits['narrative_max'],
                    'citation_max' => $limits['citation_max'],
                ]);

                $second = $this->retrieveDualChunks(
                    $focusedNarrativeQuery,
                    $focusedCitationQuery,
                    $selectedGuidelines,
                    $limits['narrative_max'],
                    $limits['citation_max'],
                    $guidelineScores
                );

                $dualResult = [
                    'narrative_chunks' => $this->mergeChunks(
                        $dualResult['narrative_chunks'] ?? [],
                        $second['narrative_chunks'] ?? [],
                        'narrative'
                    ),
                    'citation_chunks' => $this->mergeChunks(
                        $dualResult['citation_chunks'] ?? [],
                        $second['citation_chunks'] ?? [],
                        'citation'
                    ),
                ];

                // Re-evaluate gaps after second pass.
                $gapReport = $gapService->detect(
                    $scrubbedQuestion,
                    $dualResult['narrative_chunks'] ?? [],
                    $dualResult['citation_chunks'] ?? [],
                    $normalizationMeta
                );
            }
        }

        $duration = round((microtime(true) - $startTime) * 1000);

        return [
            'success' => true,
            'question' => $scrubbedQuestion,
            'retrieval_query' => $retrievalQuestion,
            'query_normalization' => $normalizationMeta,
            'expanded_query' => $expandedQuery, // Return for debug
            'phi_scrubbed' => $scrubResult['was_modified'],
            'selected_guidelines' => $selectedGuidelines,
            'routing_method' => $routingMethod,
            'narrative_chunks' => $dualResult['narrative_chunks'],
            'citation_chunks' => $dualResult['citation_chunks'],
            'duration_ms' => $duration,
            'system_prompt' => $this->getDualSystemPrompt(),
            'gap_report' => $gapService->enabled() && config('gap_detection.include_debug', false) ? $gapReport : null,
        ];
    }

    protected function mergeChunks(array $primary, array $secondary, string $type): array
    {
        if (empty($secondary)) {
            return $primary;
        }

        $seen = [];
        $out = [];

        $fingerprint = function (array $chunk) use ($type): string {
            if ($type === 'citation') {
                $text = (string) ($chunk['text'] ?? '');
                $rec = (string) ($chunk['recommendation_id'] ?? '');
                return md5($rec . '|' . $text);
            }
            $text = (string) ($chunk['content'] ?? '');
            $source = (string) ($chunk['source_guideline'] ?? '');
            return md5($source . '|' . $text);
        };

        foreach (array_merge($primary, $secondary) as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            $key = $fingerprint($chunk);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $chunk;
        }

        return $out;
    }

    protected function containsNonAscii(string $text): bool
    {
        return preg_match('/[^\x00-\x7F]/', $text) === 1;
    }

    protected function nonANonBPattern(): string
    {
        return '/\bnon\s*[-\x{2010}-\x{2015}\x{2212}\x{00ad}\x{2011}]?\s*a\s*[,\-\/]?\s*non\s*[-\x{2010}-\x{2015}\x{2212}\x{00ad}\x{2011}]?\s*b\b/iu';
    }

    protected function containsNonANonB(string $text): bool
    {
        return preg_match($this->nonANonBPattern(), $text) === 1;
    }

    protected function appendUniqueTerms(string $query, array $terms): string
    {
        $lower = mb_strtolower($query);
        foreach ($terms as $term) {
            $termLower = mb_strtolower($term);
            if (!str_contains($lower, $termLower)) {
                $query .= ' ' . $term;
                $lower .= ' ' . $termLower;
            }
        }
        return $query;
    }

    protected function applyRetrievalQueryBoosts(string $query, array $selectedGuidelines, string $channel): string
    {
        $config = config('ragflow.retrieval.query_boosts', []);
        if (empty($config['enabled'])) {
            return $query;
        }

        $original = $query;

        if (!empty($config['non_a_non_b_enabled']) && $this->containsNonANonB($query)) {
            $query = $this->appendUniqueTerms($query, [
                'aortic arch dissection',
                'arch-involving dissection',
                'aortic arch',
            ]);
        }

        if ($query !== $original) {
            Log::channel('retrieval')->info('[QUERY BOOST] Applied retrieval phrase boosts', [
                'channel' => $channel,
                'selected_guidelines' => $selectedGuidelines,
                'original_preview' => substr($original, 0, 80),
                'boosted_preview' => substr($query, 0, 120),
            ]);
        }

        return $query;
    }

    protected function chunksContainPattern(array $chunks, string $pattern, array $fields): bool
    {
        foreach ($chunks as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            foreach ($fields as $field) {
                $text = (string) ($chunk[$field] ?? '');
                if ($text !== '' && preg_match($pattern, $text)) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function promotePatternMatches(array $chunks, string $pattern, array $fields): array
    {
        if (empty($chunks)) {
            return $chunks;
        }
        $matches = [];
        $rest = [];
        foreach ($chunks as $chunk) {
            $matched = false;
            if (is_array($chunk)) {
                foreach ($fields as $field) {
                    $text = (string) ($chunk[$field] ?? '');
                    if ($text !== '' && preg_match($pattern, $text)) {
                        $matched = true;
                        break;
                    }
                }
            }
            if ($matched) {
                $matches[] = $chunk;
            } else {
                $rest[] = $chunk;
            }
        }
        return array_merge($matches, $rest);
    }

    protected function buildNonANonBFocusTerms(): array
    {
        return [
            'non-A non-B dissection',
            'arch-involving dissection',
            'aortic arch dissection',
            'mediastinal effusion',
            'cerebral malperfusion',
            'ascending aortic haematoma',
        ];
    }

    protected function applyFocusedRecall(
        array $dualResult,
        string $question,
        string $expandedQuery,
        string $citationQuery,
        array $selectedGuidelines,
        int $narrativeMax,
        int $citationMax,
        array $guidelineScores
    ): array {
        $focusConfig = config('ragflow.retrieval.focused_recall', []);
        if (empty($focusConfig['enabled']) || empty($focusConfig['non_a_non_b_enabled'])) {
            return $dualResult;
        }

        if (!$this->containsNonANonB($question)) {
            return $dualResult;
        }

        $pattern = $this->nonANonBPattern();
        $hasNarrative = $this->chunksContainPattern($dualResult['narrative_chunks'] ?? [], $pattern, ['content']);
        $hasCitation = $this->chunksContainPattern($dualResult['citation_chunks'] ?? [], $pattern, ['text', 'content']);

        if ($hasNarrative || $hasCitation) {
            $dualResult['narrative_chunks'] = $this->promotePatternMatches(
                $dualResult['narrative_chunks'] ?? [],
                $pattern,
                ['content']
            );
            $dualResult['citation_chunks'] = $this->promotePatternMatches(
                $dualResult['citation_chunks'] ?? [],
                $pattern,
                ['text', 'content']
            );
            return $dualResult;
        }

        $focusNarrativeMax = max($narrativeMax, (int) ($focusConfig['narrative_max'] ?? $narrativeMax));
        $focusCitationMax = max($citationMax, (int) ($focusConfig['citation_max'] ?? $citationMax));
        $focusQuery = $this->appendUniqueTerms($expandedQuery, $this->buildNonANonBFocusTerms());
        $focusCitationQuery = $this->appendUniqueTerms($citationQuery, $this->buildNonANonBFocusTerms());

        $overrides = [
            'similarity_threshold' => $focusConfig['similarity_threshold'] ?? null,
            'top_k' => $focusConfig['top_k'] ?? null,
            'keyword' => $focusConfig['keyword_mode'] ?? null,
            'vector_similarity_weight' => $focusConfig['vector_similarity_weight'] ?? null,
        ];

        Log::channel('retrieval')->info('[FOCUSED RECALL] non-A non-B second pass', [
            'focus_narrative_max' => $focusNarrativeMax,
            'focus_citation_max' => $focusCitationMax,
            'overrides' => array_filter($overrides, fn($v) => $v !== null),
            'query_preview' => substr($focusQuery, 0, 140),
        ]);

        $focused = $this->retrieveDualChunks(
            $focusQuery,
            $focusCitationQuery,
            $selectedGuidelines,
            $focusNarrativeMax,
            $focusCitationMax,
            $guidelineScores,
            $overrides
        );

        $mergedNarrative = $this->mergeChunks(
            $dualResult['narrative_chunks'] ?? [],
            $focused['narrative_chunks'] ?? [],
            'narrative'
        );
        $mergedCitation = $this->mergeChunks(
            $dualResult['citation_chunks'] ?? [],
            $focused['citation_chunks'] ?? [],
            'citation'
        );

        $dualResult['narrative_chunks'] = $this->promotePatternMatches(
            $mergedNarrative,
            $pattern,
            ['content']
        );
        $dualResult['citation_chunks'] = $this->promotePatternMatches(
            $mergedCitation,
            $pattern,
            ['text', 'content']
        );

        return $dualResult;
    }

    protected function applyQualityPass(
        array $dualResult,
        string $question,
        string $expandedQuery,
        string $citationQuery,
        array $selectedGuidelines,
        int $narrativeMax,
        int $citationMax,
        array $guidelineScores
    ): array {
        $qualityConfig = config('ragflow.retrieval.quality_pass', []);
        if (empty($qualityConfig['enabled'])) {
            return $dualResult;
        }

        $minNarrative = (int) ($qualityConfig['min_narrative'] ?? 0);
        $minCitation = (int) ($qualityConfig['min_citation'] ?? 0);
        $haveNarrative = count($dualResult['narrative_chunks'] ?? []);
        $haveCitation = count($dualResult['citation_chunks'] ?? []);
        $alwaysRun = $minNarrative === 0 && $minCitation === 0;
        $needsNarrative = $minNarrative > 0 && $haveNarrative < $minNarrative;
        $needsCitation = $minCitation > 0 && $haveCitation < $minCitation;

        if (!$alwaysRun && !$needsNarrative && !$needsCitation) {
            return $dualResult;
        }

        $qualityNarrativeMax = max($narrativeMax, (int) ($qualityConfig['narrative_max'] ?? $narrativeMax));
        $qualityCitationMax = max($citationMax, (int) ($qualityConfig['citation_max'] ?? $citationMax));

        $overrides = [
            'similarity_threshold' => $qualityConfig['similarity_threshold'] ?? null,
            'top_k' => $qualityConfig['top_k'] ?? null,
            'keyword' => $qualityConfig['keyword_mode'] ?? null,
            'vector_similarity_weight' => $qualityConfig['vector_similarity_weight'] ?? null,
        ];
        if (array_key_exists('use_kg', $qualityConfig)) {
            $overrides['use_kg'] = $qualityConfig['use_kg'];
        }
        if (array_key_exists('citation_top_k', $qualityConfig)) {
            $overrides['citation_top_k'] = $qualityConfig['citation_top_k'];
        }

        Log::channel('retrieval')->info('[QUALITY PASS] Running high-recall retrieval pass', [
            'min_narrative' => $minNarrative,
            'min_citation' => $minCitation,
            'have_narrative' => $haveNarrative,
            'have_citation' => $haveCitation,
            'narrative_max' => $qualityNarrativeMax,
            'citation_max' => $qualityCitationMax,
            'overrides' => array_filter($overrides, fn($v) => $v !== null),
        ]);

        $quality = $this->retrieveDualChunks(
            $expandedQuery,
            $citationQuery,
            $selectedGuidelines,
            $qualityNarrativeMax,
            $qualityCitationMax,
            $guidelineScores,
            $overrides
        );

        $dualResult['narrative_chunks'] = $this->mergeChunks(
            $dualResult['narrative_chunks'] ?? [],
            $quality['narrative_chunks'] ?? [],
            'narrative'
        );
        $dualResult['citation_chunks'] = $this->mergeChunks(
            $dualResult['citation_chunks'] ?? [],
            $quality['citation_chunks'] ?? [],
            'citation'
        );

        if ($this->containsNonANonB($question)) {
            $pattern = $this->nonANonBPattern();
            $dualResult['narrative_chunks'] = $this->promotePatternMatches(
                $dualResult['narrative_chunks'] ?? [],
                $pattern,
                ['content']
            );
            $dualResult['citation_chunks'] = $this->promotePatternMatches(
                $dualResult['citation_chunks'] ?? [],
                $pattern,
                ['text', 'content']
            );
        }

        return $dualResult;
    }

    // -- Helper Methods Refactored from Controller --

    protected function buildGuidelineRegistry(): array
    {
        $categories = config('guidelines.categories', []);
        $registry = [];
        foreach ($categories as $category) {
            foreach ($category['guidelines'] as $key => $guideline) {
                $registry[$key] = [
                    'id' => $guideline['id'],
                    'name' => $guideline['name'],
                ];
            }
        }
        return $registry;
    }

    protected function selectGuidelinesRuleBased(string $question): array
    {
        $questionLower = strtolower($question);
        $categories = config('guidelines.categories', []);
        $registry = $this->buildGuidelineRegistry();

        // Hardcoded force rules (simplified for service)
        // Note: Ideally move these to a config file
        $forceRules = [
            'carotid' => ['triggers' => ['carotid', 'cea', 'cas', 'tcar'], 'keys' => ['carotid_vertebral']],
            'aaa' => ['triggers' => ['abdominal aortic', 'aaa', 'evar'], 'keys' => ['abdominal_aortic_aneurysm']],
            'pad' => ['triggers' => ['pad', 'claudication'], 'keys' => ['asymptomatic_pad']],
            'clti' => ['triggers' => ['clti', 'gangrene', 'tissue loss'], 'keys' => ['clti']],
            'dvt' => ['triggers' => ['dvt', 'deep vein', 'pulmonary embolism'], 'keys' => ['venous_thrombosis']],
        ];

        $selected = [];
        foreach ($forceRules as $rule) {
            foreach ($rule['triggers'] as $trigger) {
                if (str_contains($questionLower, $trigger)) {
                    foreach ($rule['keys'] as $key) {
                        if (isset($registry[$key]))
                            $selected[$key] = $registry[$key];
                    }
                }
            }
        }

        if (!empty($selected))
            return $selected;

        // Basic keyword match if no force rules
        foreach ($categories as $category) {
            foreach ($category['guidelines'] as $key => $guideline) {
                foreach ($guideline['key_concepts'] as $concept) {
                    if (str_contains($questionLower, strtolower($concept))) {
                        $selected[$key] = $registry[$key];
                        break;
                    }
                }
            }
        }

        return array_slice($selected, 0, 3, true);
    }

    protected function validateGuidelineKeys(array $keys): array
    {
        $registry = $this->buildGuidelineRegistry();
        $validated = [];
        foreach ($keys as $key) {
            if (isset($registry[$key])) {
                $validated[$key] = $registry[$key];
            }
        }
        return $validated;
    }

    protected function selectGuidelinesByKeywordScore(string $question, int $max = 4): array
    {
        // Simplified version of controller's logic
        $categories = config('guidelines.categories', []);
        $questionLower = strtolower($question);
        $scores = [];
        $data = [];

        foreach ($categories as $category) {
            foreach ($category['guidelines'] as $key => $guideline) {
                $score = 0;
                foreach ($guideline['key_concepts'] as $concept) {
                    if (str_contains($questionLower, strtolower($concept)))
                        $score += 2;
                }
                if ($score > 0) {
                    $scores[$key] = $score;
                    $data[$key] = ['id' => $guideline['id'], 'name' => $guideline['name']];
                }
            }
        }

        arsort($scores);
        $result = [];
        foreach (array_slice(array_keys($scores), 0, $max) as $key) {
            $result[$key] = $data[$key];
        }
        return $result;
    }

    /**
     * Apply post-routing guardrails to ensure critical guidelines are not missed.
     * 
     * Guardrails A-N cover all 14 ESVS guidelines with keyword detection.
     * 
     * @param array $selectedGuidelines Current selection (key => guideline info)
     * @param string $question The scrubbed user question
     * @return array Updated selection with guardrails applied
     */
    protected function applyGuardrails(array $selectedGuidelines, string $question): array
    {
        $log = Log::channel('retrieval');
        $questionLower = strtolower($question);
        $registry = $this->buildGuidelineRegistry();
        $modified = false;

        // Define all guardrails: [guideline_key => [trigger_terms]]
        $guardrails = [
            // Guardrail A: Thrombosis terms → venous_thrombosis
            'venous_thrombosis' => [
                'svt',
                'superficial vein thrombosis',
                'thrombophlebitis',
                'dvt',
                'deep vein thrombosis',
                'venous thrombosis',
                'pulmonary embolism',
                'vte',
                'venous thromboembolism',
                'ivc filter',
                'post-thrombotic',
                'catheter-directed thrombolysis'
            ],

            // Guardrail B: Anticoag terms → antithrombotic_therapy
            'antithrombotic_therapy' => [
                'anticoag',
                'anticoagulation',
                'fondaparinux',
                'heparin',
                'lmwh',
                'doac',
                'apixaban',
                'rivaroxaban',
                'warfarin',
                'dabigatran',
                'edoxaban',
                'aspirin',
                'clopidogrel',
                'dual antiplatelet',
                'dapt',
                'triple therapy'
            ],

            // Guardrail C: Aortic Arch terms → aortic_arch
            'aortic_arch' => [
                'aortic arch',
                'zone 0',
                'zone 1',
                'zone 2',
                'frozen elephant trunk',
                'fet',
                'arch aneurysm',
                'hybrid arch',
                'arch repair',
                'total arch'
            ],

            // Guardrail D: Thoracic Aorta terms → descending_thoracic_aorta
            'descending_thoracic_aorta' => [
                'type b dissection',
                'tbad',
                'tevar',
                'thoracic aneurysm',
                'intramural hematoma',
                'imh',
                'descending aorta',
                'penetrating ulcer',
                'thoracic aortic',
                'spinal cord ischemia'
            ],

            // Guardrail E: AAA terms → abdominal_aortic_aneurysm
            'abdominal_aortic_aneurysm' => [
                'aaa',
                'abdominal aortic aneurysm',
                'evar',
                'endoleak',
                'infrarenal aneurysm',
                'iliac aneurysm',
                'aortic rupture',
                'abdominal aneurysm',
                'open repair'
            ],

            // Guardrail F: Mesenteric/Renal terms → mesenteric_renal
            'mesenteric_renal' => [
                'mesenteric ischemia',
                'cmi',
                'ami',
                'bowel ischemia',
                'sma stenosis',
                'celiac stenosis',
                'renal artery stenosis',
                'ras',
                'visceral aneurysm',
                'chronic mesenteric',
                'acute mesenteric'
            ],

            // Guardrail G: Carotid terms → carotid_vertebral
            'carotid_vertebral' => [
                'stroke',
                'tia',
                'transient ischemic',
                'carotid stenosis',
                'cea',
                'cas',
                'tcar',
                'carotid endarterectomy',
                'carotid stenting',
                'vertebral artery',
                'carotid artery'
            ],

            // Guardrail H: PAD/Claudication terms → asymptomatic_pad
            'asymptomatic_pad' => [
                'claudication',
                'intermittent claudication',
                'peripheral arterial disease',
                'abi',
                'ankle brachial',
                'supervised exercise',
                'walking distance',
                'exercise therapy'
            ],

            // Guardrail I: CLTI terms → clti
            'clti' => [
                'clti',
                'cli',
                'critical limb',
                'rest pain',
                'tissue loss',
                'gangrene',
                'wifi',
                'wif-i',
                'limb salvage',
                'heel ulcer',
                'angiosome',
                'ischemic ulcer'
            ],

            // Guardrail J: ALI terms → acute_limb_ischaemia
            'acute_limb_ischaemia' => [
                'acute limb ischemia',
                'acute limb ischaemia',
                'ali',
                'embolectomy',
                '6 ps',
                'pulseless limb',
                'sudden leg pain',
                'rutherford',
                'acute arterial occlusion'
            ],

            // Guardrail K: Venous/Varicose terms → chronic_venous_disease
            'chronic_venous_disease' => [
                'varicose veins',
                'venous ulcer',
                'ceap',
                'venous reflux',
                'gsv',
                'great saphenous',
                'ssv',
                'small saphenous',
                'sclerotherapy',
                'venous ablation',
                'leg ulcer',
                'venous insufficiency'
            ],

            // Guardrail L: Trauma terms → vascular_trauma
            'vascular_trauma' => [
                'vascular trauma',
                'reboa',
                'penetrating injury',
                'blunt vascular',
                'mangled extremity',
                'mess score',
                'vascular injury',
                'hemorrhage control',
                'vascular laceration',
                'arterial injury'
            ],

            // Guardrail M: Graft Infection terms → vascular_graft_infections
            'vascular_graft_infections' => [
                'graft infection',
                'prosthetic infection',
                'aortic graft infection',
                'magic criteria',
                'infected graft',
                'endograft infection',
                'graft excision',
                'infected bypass'
            ],

            // Guardrail N: Vascular Access terms → vascular_access
            'vascular_access' => [
                'avf',
                'av fistula',
                'arteriovenous fistula',
                'dialysis access',
                'hemodialysis access',
                'steal syndrome',
                'access thrombosis',
                'fistula maturation',
                'dialysis catheter'
            ],
        ];

        // Apply each guardrail
        foreach ($guardrails as $guidelineKey => $triggerTerms) {
            if (isset($selectedGuidelines[$guidelineKey])) {
                continue; // Already selected
            }

            foreach ($triggerTerms as $term) {
                if (str_contains($questionLower, $term)) {
                    if (isset($registry[$guidelineKey])) {
                        $selectedGuidelines[$guidelineKey] = $registry[$guidelineKey];
                        $modified = true;
                        $log->info("[GUARDRAIL] Added $guidelineKey (detected: $term)");
                        break;
                    }
                }
            }
        }

        $boosts = config('ragflow.retrieval.query_boosts', []);
        if (!empty($boosts['enabled']) && !empty($boosts['non_a_non_b_enabled']) && $this->containsNonANonB($questionLower)) {
            if (!isset($selectedGuidelines['aortic_arch']) && isset($registry['aortic_arch'])) {
                $selectedGuidelines['aortic_arch'] = $registry['aortic_arch'];
                $modified = true;
                $log->info('[GUARDRAIL] Added aortic_arch (detected: non-A non-B dissection)');
            }
        }

        // Enforce max 3 guidelines
        if (count($selectedGuidelines) > 3) {
            $keys = array_keys($selectedGuidelines);
            $count = count($selectedGuidelines);
            $log->warning("[GUARDRAIL] Exceeded 3 guidelines ($count), keeping first 3", [
                'all_keys' => $keys
            ]);
            $selectedGuidelines = array_slice($selectedGuidelines, 0, 3, true);
        }

        if ($modified) {
            $log->info("[GUARDRAILS] Final guideline keys after guardrails", [
                'guideline_keys' => array_keys($selectedGuidelines)
            ]);
        }

        return $selectedGuidelines;
    }

    /**
     * Get full guideline config including recs_doc_id from config file.
     */
    protected function getGuidelineConfig(string $key): ?array
    {
        $categories = config('guidelines.categories', []);
        foreach ($categories as $category) {
            if (isset($category['guidelines'][$key])) {
                return $category['guidelines'][$key];
            }
        }
        return null;
    }


    protected function retrieveDualChunks(
        string $narrativeQuery,
        string $citationQuery,
        array $guidelines,
        int $narrativeMax,
        int $citationMax,
        array $scores = [],
        array $overrides = []
    ): array
    {
        $narrativeDatasets = [];
        $citationDocumentIds = []; // NEW: for hard scoping citations

        foreach ($guidelines as $key => $info) {
            // Build narrative datasets
            $narrativeDatasets[] = [
                'id' => $info['id'],
                'name' => $info['name'],
                'score' => $scores[$key] ?? null,
            ];

            // NEW: Collect citation document IDs
            $guidelineConfig = $this->getGuidelineConfig($key);
            if (!empty($guidelineConfig['recs_doc_id'])) {
                // Only add if it's not a placeholder
                if (!str_starts_with($guidelineConfig['recs_doc_id'], 'NEED_')) {
                    $citationDocumentIds[] = $guidelineConfig['recs_doc_id'];
                }
            }
        }

        // Log citation document IDs
        Log::info("[CITATION SCOPE] Document IDs for selected guidelines", [
            'guideline_keys' => array_keys($guidelines),
            'citation_document_ids' => $citationDocumentIds,
            'count' => count($citationDocumentIds),
            'has_placeholders' => count($citationDocumentIds) < count($guidelines)
        ]);

        $citationDatasetId = config('guidelines.recommendations_dataset');
        if (empty($citationDatasetId))
            throw new \RuntimeException('Citation dataset not configured');

        $retrievalConfig = config('ragflow.retrieval', []);

        // Treat retrieval params as server-owned defaults; clamp anything that could
        // explode cost/latency or swamp reranking with noise.
        $topK = (int) ($retrievalConfig['top_k'] ?? 256);
        $topK = max(1, min($topK, 256));

        $bridgeRerank = new BridgeRerankService();
        $params = [
            'question' => $narrativeQuery,
            'citation_query' => $citationQuery,
            'narrative_max' => $narrativeMax,
            'citation_max' => $citationMax,
            'citation_document_ids' => $citationDocumentIds, // NEW: pass to Python
            'top_k' => $topK,
            'similarity_threshold' => $retrievalConfig['similarity_threshold'] ?? 0.2,
            'keyword' => $retrievalConfig['keyword_mode'] ?? true,
            'vector_similarity_weight' => $retrievalConfig['vector_similarity_weight'] ?? 0.3,
            'use_kg' => $retrievalConfig['use_kg'] ?? false,
            'citation_top_k' => (int) ($retrievalConfig['citation_top_k'] ?? 10),
            'highlight' => (bool) ($retrievalConfig['highlight'] ?? false),
        ];
        if (!empty($overrides)) {
            foreach (['top_k', 'similarity_threshold', 'keyword', 'vector_similarity_weight', 'use_kg', 'citation_top_k'] as $key) {
                if (array_key_exists($key, $overrides) && $overrides[$key] !== null) {
                    $params[$key] = $overrides[$key];
                }
            }
        }
        // Always provide rerank_id if configured; ragflow_service will only forward it
        // upstream if it is non-empty and not "local". If bridge rerank is enabled,
        // do not forward rerank_id to RAGFlow to avoid remote rerank latency.
        if (!$bridgeRerank->enabled() && array_key_exists('rerank_id', $retrievalConfig) && !empty($retrievalConfig['rerank_id'])) {
            $params['rerank_id'] = $retrievalConfig['rerank_id'];
        }

        try {
            $response = \App\Facades\RAGFlow::datasets()->retrieveDual($narrativeDatasets, $citationDatasetId, $params);
        } catch (\Exception $e) {
            throw new \RuntimeException('RAGFlow bridge retrieval failed: ' . $e->getMessage());
        }

        if (($response['status'] ?? 0) !== 200) {
            throw new \RuntimeException('RAGFlow retrieval failed');
        }

        $narrativeChunks = $response['narrative']['chunks'] ?? [];
        $citationChunks = $response['citations']['chunks'] ?? [];

        // Hard safety filter: the citation dataset may return chunks from unrelated guidelines
        // when recs_doc_id placeholders are missing and citation_document_ids is empty.
        // Filter both citations and narratives to the explicitly/implicitly selected guidelines.
        $selectedGuidelineKeys = array_keys($guidelines);
        $selectedGuidelineNames = array_column($guidelines, 'name');
        $narrativeBefore = count($narrativeChunks);
        $citationBefore = count($citationChunks);
        $narrativeChunks = $this->filterRawChunksToSelectedGuidelines($narrativeChunks, $selectedGuidelineKeys, $selectedGuidelineNames, 'narrative');
        $citationChunks = $this->filterRawChunksToSelectedGuidelines($citationChunks, $selectedGuidelineKeys, $selectedGuidelineNames, 'citation');
        if (count($narrativeChunks) !== $narrativeBefore || count($citationChunks) !== $citationBefore) {
            Log::warning('[CHUNK FILTER] Dropped cross-guideline chunks after retrieval', [
                'selected_guideline_keys' => $selectedGuidelineKeys,
                'selected_guideline_names' => $selectedGuidelineNames,
                'narrative_before' => $narrativeBefore,
                'narrative_after' => count($narrativeChunks),
                'citation_before' => $citationBefore,
                'citation_after' => count($citationChunks),
            ]);
        }

        if ($bridgeRerank->enabled()) {
            $narrativeChunks = $bridgeRerank->rerank($narrativeQuery, $narrativeChunks, $narrativeMax, 'narrative');
            $citationChunks = $bridgeRerank->rerank($citationQuery, $citationChunks, $citationMax, 'citation');
        }

        $formattedNarrative = $this->formatChunks(
            $narrativeChunks,
            'narrative',
            array_column($narrativeDatasets, 'name'),
            $narrativeQuery
        );
        $formattedCitation = $this->formatChunks($citationChunks, 'citation');

        // Second-pass safety filter on formatted chunks. This catches cases where the
        // raw chunk metadata is sparse/inconsistent but the parsed formatted chunk
        // contains a reliable guideline label (e.g., citation text includes guideline_name).
        $formattedNarrativeBefore = count($formattedNarrative);
        $formattedCitationBefore = count($formattedCitation);
        $formattedNarrative = $this->filterFormattedChunksToSelectedGuidelines(
            $formattedNarrative,
            $selectedGuidelineKeys,
            $selectedGuidelineNames,
            'narrative'
        );
        // Do not apply formatted-stage citation filtering. Raw-stage filtering has access to more
        // reliable rec metadata patterns (guideline_name/category_name/rec_id prefixes). The
        // formatted citation "guideline" field is often inconsistent for venous thrombosis and can
        // incorrectly drop valid recommendations.
        if (count($formattedNarrative) !== $formattedNarrativeBefore || count($formattedCitation) !== $formattedCitationBefore) {
            Log::warning('[FORMATTED CHUNK FILTER] Dropped cross-guideline chunks after formatting', [
                'selected_guideline_keys' => $selectedGuidelineKeys,
                'selected_guideline_names' => $selectedGuidelineNames,
                'narrative_before' => $formattedNarrativeBefore,
                'narrative_after' => count($formattedNarrative),
                'citation_before' => $formattedCitationBefore,
                'citation_after' => count($formattedCitation),
            ]);
        }

        return [
            'narrative_chunks' => $formattedNarrative,
            'citation_chunks' => $formattedCitation,
        ];
    }

    protected function buildCitationQuery(string $retrievalQuery, string $originalQuestion, ?array $normalizationMeta, array $selectedGuidelineKeys): string
    {
        $q = trim($retrievalQuery);
        if ($q === '') {
            return $retrievalQuery;
        }

        $selected = array_map('strval', $selectedGuidelineKeys);
        $normalizedChanged = (bool) (($normalizationMeta['changed'] ?? false));
        $originalLower = mb_strtolower($originalQuestion);
        $retrievalLower = mb_strtolower($retrievalQuery);

        // Targeted multilingual recall boost for venous thrombosis SVT/saphenous queries.
        // This improves citation retrieval from the shared recommendations dataset when the
        // venous thrombosis recs_doc_id is not yet configured.
        if (
            in_array('venous_thrombosis', $selected, true)
            && $normalizedChanged
            && (
                str_contains($retrievalLower, 'saphenous')
                || str_contains($retrievalLower, 'superficial venous thrombosis')
                || str_contains($retrievalLower, 'svt')
                || str_contains($originalLower, 'σαφην')
            )
        ) {
            $extras = [
                'SVT',
                'superficial vein thrombosis',
                'great saphenous vein',
                'fondaparinux',
                'LMWH',
                'ultrasound',
            ];
            foreach ($extras as $term) {
                if (!str_contains(mb_strtolower($q), mb_strtolower($term))) {
                    $q .= ' ' . $term;
                }
            }
        }

        return $q;
    }

    protected function formatChunks(array $rawChunks, string $type, array $guidelineNames = [], ?string $query = null): array
    {
        $formatted = [];
        foreach ($rawChunks as $chunk) {
            $content = $chunk['content'] ?? $chunk['content_with_weight'] ?? '';
            if (empty($content))
                continue;

            $meta = [];

            if ($type === 'citation') {
                $text = $content;
                // Parse Citation Metadata - Handle both formats
                // Format 1: RECOMMENDATION_ID: Rec 12
                if (preg_match('/RECOMMENDATION_ID:\s*(Rec\s*[\d\w]+)/i', $content, $m))
                    $meta['recommendation_id'] = $m[1];
                // Format 2: rec_id:vascular_trauma_R002
                elseif (preg_match('/rec_id:\s*([^\s;]+)/i', $content, $m))
                    $meta['recommendation_id'] = $m[1];

                if (preg_match('/CLASS:\s*(Class\s*\S+)/i', $content, $m))
                    $meta['class'] = $m[1];
                elseif (preg_match('/class:\s*([^\s;]+)/i', $content, $m))
                    $meta['class'] = $m[1];

                if (preg_match('/LEVEL:\s*(Level\s*\S+)/i', $content, $m))
                    $meta['level'] = $m[1];
                elseif (preg_match('/level:\s*([^\s;]+)/i', $content, $m))
                    $meta['level'] = $m[1];

                // Extract guideline name if present
                if (preg_match('/guideline_name:\s*([^;]+)/i', $content, $m))
                    $meta['guideline'] = trim($m[1]);

                // Extract text content (remove metadata headers if possible)
                if (preg_match('/RECOMMENDATION_TEXT:\s*(.+?)(?=TRIPLES:|CLASS:|LEVEL:|$)/is', $content, $m))
                    $text = trim($m[1]);
                elseif (preg_match('/recommendation_text:\s*(.+?)(?=;|$)/is', $content, $m))
                    $text = trim($m[1]);
                // If the content is just metadata-heavy, we might want to clean it, but usually the text is in there.
                // For the new format, if no explicit text field, use the whole thing?
                // The chunk preview showed "rec_id:...". Let's assume the text follows or is the whole thing?
                // Actually, let's keep the full content as fallback.

                $meta['text'] = strlen($text) > 800 ? substr($text, 0, 800) . '...' : $text;
            } else {
                // Narrative
                $maxChars = (int) (config('ragflow.retrieval.narrative_excerpt_max_chars', 1000));
                if ($maxChars <= 0) {
                    $meta['content'] = $content;
                } else {
                    $meta['content'] = $this->extractNarrativeSnippet($content, $query, $maxChars);
                }
                $meta['source_guideline'] = $chunk['_source_guideline'] ?? ($guidelineNames[0] ?? 'ESVS Guidelines');
            }

            $formatted[] = array_merge([
                'type' => $type,
                'similarity' => round(($chunk['similarity'] ?? 0) * 100, 1),
            ], $meta);
        }
        return $formatted;
    }

    protected function extractNarrativeSnippet(string $content, ?string $query, int $maxChars): string
    {
        $content = (string) $content;
        if ($maxChars <= 0 || mb_strlen($content) <= $maxChars) {
            return $content;
        }

        $query = trim((string) $query);
        $matchPos = null;
        $matchLen = 0;

        if ($query !== '' && $this->containsNonANonB($query)) {
            if (preg_match($this->nonANonBPattern(), $content, $m, PREG_OFFSET_CAPTURE)) {
                $matchPos = $m[0][1];
                $matchLen = mb_strlen($m[0][0]);
            }
        }

        if ($matchPos === null && $query !== '') {
            $terms = $this->extractQueryTerms($query);
            foreach ($terms as $term) {
                $pos = mb_stripos($content, $term);
                if ($pos !== false) {
                    $matchPos = $pos;
                    $matchLen = mb_strlen($term);
                    break;
                }
            }
        }

        if ($matchPos === null) {
            return mb_substr($content, 0, $maxChars) . '...';
        }

        $window = $maxChars;
        $contentLen = mb_strlen($content);
        $start = max(0, $matchPos - (int) ($window * 0.25));
        if ($start + $window > $contentLen) {
            $start = max(0, $contentLen - $window);
        }

        $snippet = mb_substr($content, $start, $window);
        $prefix = $start > 0 ? '...' : '';
        $suffix = ($start + $window) < $contentLen ? '...' : '';
        return $prefix . $snippet . $suffix;
    }

    protected function extractQueryTerms(string $query): array
    {
        $q = mb_strtolower($query);
        $q = preg_replace('/[^\\p{L}\\p{N}\\s]+/u', ' ', $q) ?? $q;
        $parts = preg_split('/\\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (empty($parts)) {
            return [];
        }

        $stop = [
            'the', 'and', 'for', 'with', 'without', 'from', 'into', 'over', 'under',
            'this', 'that', 'these', 'those', 'what', 'when', 'where', 'which', 'while',
            'about', 'after', 'before', 'since', 'during', 'into', 'onto',
            'management', 'treatment', 'therapy', 'patient', 'patients', 'disease',
            'guideline', 'recommendation', 'recommendations', 'clinical', 'evidence',
            'acute', 'chronic', 'type', 'case', 'cases',
        ];

        $terms = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) < 4) {
                continue;
            }
            if (in_array($part, $stop, true)) {
                continue;
            }
            if (!isset($terms[$part])) {
                $terms[$part] = true;
            }
        }

        $unique = array_keys($terms);
        usort($unique, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        return $unique;
    }

    protected function filterRawChunksToSelectedGuidelines(array $rawChunks, array $selectedKeys, array $selectedNames, string $type): array
    {
        if (empty($rawChunks)) {
            return [];
        }

        $allowedLabels = [];
        foreach ($selectedKeys as $k) {
            if (is_string($k) && $k !== '') {
                $allowedLabels[$this->normalizeGuidelineLabel($k)] = true;
                foreach ($this->recommendationGuidelineAliasesForKey($k) as $alias) {
                    $allowedLabels[$this->normalizeGuidelineLabel($alias)] = true;
                }
            }
        }
        foreach ($selectedNames as $n) {
            if (is_string($n) && $n !== '') {
                $allowedLabels[$this->normalizeGuidelineLabel($n)] = true;
            }
        }
        if (empty($allowedLabels)) {
            return $rawChunks;
        }

        $out = [];
        foreach ($rawChunks as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }

            $content = (string) ($chunk['content'] ?? $chunk['content_with_weight'] ?? '');
            $sourceGuideline = (string) ($chunk['_source_guideline'] ?? '');

            // Narratives are already dataset-scoped; if metadata exists and mismatches, drop.
            if ($type === 'narrative' && $sourceGuideline !== '') {
                if ($this->guidelineLabelAllowed($sourceGuideline, $allowedLabels)) {
                    $out[] = $chunk;
                }
                continue;
            }

            // Citations: try multiple metadata patterns used in the recs dataset.
            $candidates = [];
            $explicitGuidelineName = null;
            if ($content !== '') {
                if (preg_match('/guideline_name:\s*([^;\\n]+)/i', $content, $m)) {
                    $explicitGuidelineName = trim($m[1]);
                    $candidates[] = $explicitGuidelineName;
                }
                if (preg_match('/guideline_key:\s*([^;\\n]+)/i', $content, $m)) {
                    $candidates[] = trim($m[1]);
                }
                if (preg_match('/rec_id:\s*([a-z0-9_]+)/i', $content, $m)) {
                    $recId = strtolower(trim($m[1]));
                    foreach ($selectedKeys as $k) {
                        if (is_string($k) && $k !== '' && str_starts_with($recId, strtolower($k) . '_')) {
                            $candidates[] = $k;
                        }
                    }
                }
                if (preg_match('/category_name:\s*([^;\\n]+)/i', $content, $m)) {
                    $candidates[] = trim($m[1]);
                }
            }

            // If the row explicitly declares a guideline_name and it is not one of the selected
            // guidelines, treat that as authoritative and drop the chunk, even if other metadata
            // fields (e.g., category_name / rec_id prefix) look compatible.
            if ($explicitGuidelineName !== null && !$this->guidelineLabelAllowed($explicitGuidelineName, $allowedLabels)) {
                continue;
            }

            // If we can identify a guideline and it matches, keep it. If we cannot identify at all,
            // keep it (to avoid accidentally dropping valid chunks with sparse metadata).
            if (empty($candidates)) {
                $out[] = $chunk;
                continue;
            }

            $keep = false;
            foreach ($candidates as $label) {
                if ($this->guidelineLabelAllowed($label, $allowedLabels)) {
                    $keep = true;
                    break;
                }
            }
            if ($keep) {
                $out[] = $chunk;
            }
        }

        return $out;
    }

    protected function filterFormattedChunksToSelectedGuidelines(array $chunks, array $selectedKeys, array $selectedNames, string $type): array
    {
        if (empty($chunks)) {
            return [];
        }

        $allowedLabels = [];
        foreach ($selectedKeys as $k) {
            if (is_string($k) && $k !== '') {
                $allowedLabels[$this->normalizeGuidelineLabel($k)] = true;
                foreach ($this->recommendationGuidelineAliasesForKey($k) as $alias) {
                    $allowedLabels[$this->normalizeGuidelineLabel($alias)] = true;
                }
            }
        }
        foreach ($selectedNames as $n) {
            if (is_string($n) && $n !== '') {
                $allowedLabels[$this->normalizeGuidelineLabel($n)] = true;
            }
        }
        if (empty($allowedLabels)) {
            return $chunks;
        }

        $out = [];
        foreach ($chunks as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }

            $label = '';
            if ($type === 'citation') {
                $label = (string) ($chunk['guideline'] ?? '');
            } else {
                $label = (string) ($chunk['source_guideline'] ?? '');
            }

            // If no label is available at formatted stage, keep the chunk to avoid
            // over-pruning valid evidence; raw filtering already ran earlier.
            if ($label === '' || $this->guidelineLabelAllowed($label, $allowedLabels)) {
                $out[] = $chunk;
            }
        }

        return $out;
    }

    protected function guidelineLabelAllowed(string $label, array $allowedLabels): bool
    {
        $norm = $this->normalizeGuidelineLabel($label);
        if ($norm === '') {
            return false;
        }
        if (isset($allowedLabels[$norm])) {
            return true;
        }

        // Fuzzy containment for labels like "Venous Thrombosis (DVT/PE)" vs "venous_thrombosis"
        foreach (array_keys($allowedLabels) as $allowed) {
            if ($allowed === '') {
                continue;
            }
            if (str_contains($norm, $allowed) || str_contains($allowed, $norm)) {
                return true;
            }
        }
        return false;
    }

    protected function normalizeGuidelineLabel(string $s): string
    {
        $s = mb_strtolower(trim($s));
        if ($s === '') {
            return '';
        }
        // Canonicalize separators and remove punctuation/noise while preserving alnum.
        $s = str_replace(['&', '/'], ' ', $s);
        $s = preg_replace('/[^\\p{L}\\p{N}]+/u', ' ', $s) ?? $s;
        $s = trim(preg_replace('/\\s+/u', ' ', $s) ?? $s);
        return str_replace(' ', '_', $s);
    }

    protected function recommendationGuidelineAliasesForKey(string $guidelineKey): array
    {
        $map = [
            'aortic_arch' => [
                'Treatment of Thoracic Aortic Pathologies Involving the Aortic Arch',
            ],
            'descending_thoracic_aorta' => [
                'Management of Descending Thoracic and Thoraco-Abdominal Aortic Diseases',
                'Management of Descending Thoracic and Thoracoabdominal Aortic Diseases',
            ],
            'abdominal_aortic_aneurysm' => [
                'Management of Abdominal Aorto-Iliac Artery Aneurysms',
                'AAA',
            ],
            'mesenteric_renal' => [
                'Management of Diseases of the Mesenteric and Renal Arteries and Veins',
                'Mesenteric and Renal Arteries',
            ],
            'asymptomatic_pad' => [
                'Management of Asymptomatic Lower Limb Peripheral Arterial Disease and Intermittent Claudication',
                'Asymptomatic Lower Limb Peripheral Arterial Disease and Intermittent Claudication',
            ],
            'clti' => [
                'Global Vascular Guidelines on CLTI Management',
                'Chronic Limb-Threatening Ischemia',
            ],
            'acute_limb_ischaemia' => [
                'Management of Acute Limb Ischaemia',
                'Acute Limb Ischemia',
            ],
            'carotid_vertebral' => [
                'Management of Atherosclerotic Carotid and Vertebral Artery Disease',
            ],
            'antithrombotic_therapy' => [
                'Antithrombotic Therapy for Vascular Diseases',
            ],
            'venous_thrombosis' => [
                'Venous Thrombosis (DVT/PE)',
                'Management of Venous Thrombosis',
            ],
            'chronic_venous_disease' => [
                'Chronic Venous Disease of the Lower Limbs',
            ],
            'vascular_trauma' => [
                'Management of Vascular Trauma',
            ],
            'vascular_graft_infections' => [
                'Management of Vascular Graft and Endograft Infection',
                'Management of Vascular Graft and Endograft Infections',
            ],
            'vascular_access' => [
                'Vascular Access',
            ],
        ];

        return $map[$guidelineKey] ?? [];
    }

    public function getDualSystemPrompt(): string
    {
        return <<<'PROMPT'
You are an ESVS (European Society for Vascular Surgery) clinical guideline assistant.

## YOUR TASK
Answer vascular surgery questions using the provided evidence. You receive TWO types of chunks:

### NARRATIVE_CHUNKS (use_kg configurable)
- Rich clinical context from full guideline text
- Use these for understanding and synthesizing your clinical answer

### CITATION_CHUNKS (use_kg=false)
- Exact recommendations with metatags: recommendation_id, class, level, guideline
- Use these for VERBATIM citations only

## RESPONSE FORMAT
🩺 **Clinical Synthesis**
- 3-6 bullet points answering the clinical question
- Reference recommendation numbers (e.g., "per Rec 12")

📑 **Recommendations used in this answer**
- ONLY use recommendations from CITATION_CHUNKS
- Format: **Rec [ID]** (Class [X], Level [Y]) — [Guideline]
  > "[EXACT verbatim text from citation_chunks]"

## RULES
1. Never invent recommendations
2. If CITATION_CHUNKS don't support your synthesis, note: "Direct recommendation not retrieved"
PROMPT;
    }
}
