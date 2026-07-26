<?php

namespace App\Ai\Gate\Retrieval;

final class GateRetrievalQueryBuilder
{
    /** @var array<string, string> */
    private const ANCHOR_PATTERNS = [
        'carotid' => '/\b(carotid|cea|cas|tcar|endarterectomy|carotid\s+stenting)\b/iu',
        'stroke' => '/\b(stroke|tia|rankin|mrs|neurological)\b/iu',
        'aorta' => '/\b(aorta|aortic|aaa|aneurysm|evar|tevar|f\/?b?evar|dissection|thoracoabdominal)\b/iu',
        'venous' => '/\b(dvt|pe|vte|venous|brachial\s+vein|saphen|iliac\s+vein|ivc)\b/iu',
        'thrombus' => '/\b(thrombus|thrombosis|embol|embolism)\b/iu',
        'graft' => '/\b(graft|endograft|bypass|stump|infection|magic|patent\s+bypass)\b/iu',
        'limb' => '/\b(clti|ali|claudication|wifi|rutherford|rest\s+pain|gangrene|ulcer|amputation)\b/iu',
        'renal_mesenteric' => '/\b(renal|mesenteric|sma|coeliac|celiac|visceral)\b/iu',
        'access' => '/\b(avf|fistula|dialysis|vascular\s+access)\b/iu',
        'trauma' => '/\b(trauma|injury|penetrating|blunt|reboa)\b/iu',
        'antithrombotic' => '/\b(antithrombotic|anticoagul|antiplatelet|aspirin|clopidogrel|warfarin|doac|apixaban|rivaroxaban)\b/iu',
    ];

    /** @var array<string, array<int, string>> */
    private const ANCHOR_CITATION_PHRASES = [
        'carotid' => ['carotid stenosis revascularisation'],
        'stroke' => ['stroke prevention after TIA'],
        'aorta' => ['aortic aneurysm intervention'],
        'venous' => ['chronic venous disease treatment'],
        'thrombus' => ['venous thromboembolism treatment'],
        'graft' => ['vascular graft and bypass management'],
        'limb' => ['lower limb ischaemia treatment'],
        'renal_mesenteric' => ['renal and mesenteric artery treatment'],
        'access' => ['haemodialysis vascular access management'],
        'trauma' => ['vascular trauma injury management'],
        'antithrombotic' => ['antithrombotic therapy for vascular disease'],
    ];

    /**
     * @param  array<string, mixed>  $orient
     * @param  array<int, array<string, mixed>>  $issues
     * @return array{
     *   narrative: string,
     *   citation: string,
     *   citation_queries: array<int, string>,
     *   citation_core_queries: array<int, string>
     * }
     */
    public function build(array $orient, array $issues = [], string $rawTurnText = ''): array
    {
        $patientModel = (array) ($orient['patient_model'] ?? []);
        $coreQuestion = trim((string) ($orient['core_question'] ?? ''));
        if ($coreQuestion === '') {
            $coreQuestion = 'What ESVS-guided clinical decision is appropriate?';
        }
        $coreQuestion = $this->rewriteWithCaseContext($coreQuestion, $patientModel);
        $patientProse = $this->renderPatientModel($patientModel);
        $terms = $this->terms($orient);
        $anchors = $this->caseAnchorTerms($coreQuestion.' '.$patientProse.' '.implode(' ', $terms));
        $issueText = $this->renderIssues($issues);

        $shared = array_filter([
            'Clinical question: '.$coreQuestion,
            $patientProse === '' ? null : 'Patient context: '.$patientProse,
            $terms === [] ? null : 'Clinical concepts: '.implode(', ', $terms),
            $anchors === [] ? null : 'Anatomical anchors: '.implode(', ', $anchors),
            $issueText === '' ? null : 'Retrieval gaps to resolve: '.$issueText,
        ]);

        $legacyCitation = $this->buildCitationQuery(
            $coreQuestion,
            // Must-include terms first: under a character budget the terms the
            // planner marked mandatory must survive, not be truncated away.
            $this->terms($orient, ['must_include_terms', 'expansion_terms', 'interpretation_terms']),
        );
        $multiQuery = $this->buildCitationQueries(
            $patientModel,
            (array) ($orient['must_include_terms'] ?? []),
            $rawTurnText,
        );

        return [
            'narrative' => implode("\n", $shared),
            // Kept verbatim for the feature-flagged A/B path.
            'citation' => $legacyCitation,
            'citation_queries' => (bool) config('gate-v2.retrieval.citation_multi_query', true)
                ? $multiQuery['queries']
                : [$legacyCitation],
            'citation_core_queries' => (bool) config('gate-v2.retrieval.citation_multi_query', true)
                ? $multiQuery['core']
                : [],
        ];
    }

    /**
     * Build an immutable deterministic core from both structured case fields and
     * the accumulated raw turns, then append (never concatenate into the core) at
     * most two Orient terms. The raw-turn source is guarded against negation and
     * family attribution before it is unioned with the structured source.
     *
     * The bridge currently has no citation-query batch input. The sequential
     * fallback is therefore capped at two queries; see RetrieveEsvsSnippetsTool
     * for the 1.5x timeout budget. If batching is added, the configurable cap can
     * safely be raised to four without changing this separation.
     *
     * @param  array<string, mixed>  $patientModel
     * @param  array<int, mixed>  $mustIncludeTerms
     * @return array{queries: array<int, string>, core: array<int, string>}
     */
    public function buildCitationQueries(
        array $patientModel,
        array $mustIncludeTerms = [],
        string $rawTurnText = '',
    ): array
    {
        $maxQueries = max(2, min(4, (int) config('gate-v2.retrieval.citation_multi_query_max', 2)));
        $fieldText = $this->structuredCitationText($patientModel);
        $lower = mb_strtolower($fieldText);
        $anchors = array_values(array_unique(array_merge(
            $this->caseAnchorTerms($fieldText),
            $this->guardedCaseAnchorTerms($rawTurnText),
        )));
        $core = [];

        $veinBypassPattern = '/\bvein\b.{0,24}\bbypass\b|\bbypass\b.{0,24}\bvein\b|\bvein\s+(?:bk|below[- ]knee)\s+bypass\b/iu';
        $hasVeinBypass = preg_match($veinBypassPattern, $fieldText) === 1
            || $this->hasGuardedTranscriptMatch($veinBypassPattern, $rawTurnText);
        $hasBypass = preg_match('/\bbypass\b/iu', $fieldText) === 1
            || $this->hasGuardedTranscriptMatch('/\bbypass\b/iu', $rawTurnText);
        if ($hasVeinBypass) {
            $core[] = 'antithrombotic therapy after vein bypass';
        } elseif ($hasBypass) {
            $core[] = 'antithrombotic therapy after bypass';
        }

        $hasLimbIschaemia = in_array('limb', $anchors, true)
            || preg_match('/\b(?:lower\s+limb|peripheral arterial disease|ischemi|ischaemi)\b/iu', $fieldText) === 1
            || $this->hasGuardedTranscriptMatch(
                '/\b(?:lower\s+limb|peripheral arterial disease|ischemi|ischaemi)\b/iu',
                $rawTurnText,
            );
        $hasRevascularisation = str_contains($lower, 'revascular')
            || $this->hasGuardedTranscriptMatch('/\brevascular/iu', $rawTurnText);
        if ($hasLimbIschaemia && ($hasVeinBypass || $hasRevascularisation)) {
            $core[] = 'critical limb-threatening ischaemia revascularisation';
        }

        // Guideline-specific phrases live with the guideline registry, so adding
        // coverage for a new document is a configuration change. Anchor phrases
        // provide a specific fallback when the case does not identify one exact
        // guideline strongly enough.
        $core = array_merge($core, $this->configuredCitationPhrases($fieldText));
        foreach ($anchors as $anchor) {
            $core = array_merge($core, self::ANCHOR_CITATION_PHRASES[$anchor] ?? []);
        }

        if ($core === []) {
            $fallback = $this->firstStructuredConcept($patientModel);
            if ($fallback !== '') {
                $core[] = $fallback;
            }
        }

        $core = $this->uniqueCappedQueries($core, $maxQueries);
        $queries = $core;
        foreach (array_slice($mustIncludeTerms, 0, 2) as $term) {
            if (count($queries) >= $maxQueries) {
                break;
            }
            $candidate = $this->shapeMultiCitationQuery((string) $term);
            if ($candidate !== '') {
                $queries[] = $candidate;
                $queries = $this->uniqueCappedQueries($queries, $maxQueries);
            }
        }

        return ['queries' => $queries, 'core' => $core];
    }

    /** @return array<int, string> */
    private function guardedCaseAnchorTerms(string $rawTurnText): array
    {
        $matched = [];
        foreach (self::ANCHOR_PATTERNS as $label => $pattern) {
            if ($this->hasGuardedTranscriptMatch($pattern, $rawTurnText)) {
                $matched[] = $label;
            }
        }

        return $matched;
    }

    /**
     * A raw-turn guard applies only inside the clause containing the match.
     * Sentence punctuation, semicolons, commas, and contrast/presentation
     * transitions terminate the scope of an earlier negation or family cue.
     */
    private function hasGuardedTranscriptMatch(string $pattern, string $rawTurnText): bool
    {
        if ($rawTurnText === '') {
            return false;
        }

        preg_match_all($pattern, $rawTurnText, $matches, PREG_OFFSET_CAPTURE);
        foreach ((array) ($matches[0] ?? []) as $match) {
            $byteOffset = (int) ($match[1] ?? 0);
            $prefix = substr($rawTurnText, 0, $byteOffset);
            $clauses = preg_split(
                '/(?:[.;!?\r\n]+|,\s*|\b(?:but|however|presenting\s+with)\b)/iu',
                $prefix,
            ) ?: [];
            $guardClause = (string) end($clauses);
            $isSuppressed = preg_match(
                '/\b(?:no|not|without|denies|negative\s+for|ruled\s+out|family\s+history\s+of|father|mother|sibling)\b.*$/isu',
                $guardClause,
            ) === 1;
            if (! $isSuppressed) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function configuredCitationPhrases(string $fieldText): array
    {
        $matched = [];
        $lower = mb_strtolower(str_replace('_', ' ', $fieldText));
        foreach ((array) config('guidelines.categories', []) as $category) {
            foreach ((array) ($category['guidelines'] ?? []) as $key => $guideline) {
                $signals = array_merge(
                    [str_replace('_', ' ', (string) $key), (string) ($guideline['name'] ?? '')],
                    (array) ($guideline['key_concepts'] ?? []),
                );
                $hasSignal = false;
                foreach ($signals as $signal) {
                    $signal = mb_strtolower(trim((string) $signal));
                    if ($signal !== '' && mb_strlen($signal) >= 3 && str_contains($lower, $signal)) {
                        $hasSignal = true;
                        break;
                    }
                }
                if ($hasSignal) {
                    $matched = array_merge($matched, (array) ($guideline['citation_phrases'] ?? []));
                }
            }
        }

        return $matched;
    }

    /**
     * The recommendations dataset holds short verbatim recommendation rows — text,
     * number, guideline, class, level. It therefore needs a terse, terminology-dense
     * query, NOT the narrative prose blob.
     *
     * Run 8 sent both datasets the same ~800-character "Clinical question / Patient
     * context" text and 10 of 16 guideline branches came back with **zero**
     * recommendations: an 800-character patient narrative is too dissimilar from a
     * two-sentence recommendation to clear the similarity floor, so the answering
     * recommendation was never in the evidence the Probe saw.
     *
     * Deliberately excluded, because none of it appears in a recommendation row and
     * all of it dilutes the embedding:
     *   - the patient-context prose,
     *   - the internal anchor labels (`carotid`, `limb` — router vocabulary, not
     *     guideline vocabulary),
     *   - the old "Return the directly applicable recommendation…" instruction,
     *     which is an instruction to a model, meaningless to a similarity search.
     *
     * Measured on both recommendations documents (CLTI and antithrombotic):
     *
     *   | variant                                  | chars | CLTI | antithrombotic |
     *   | question + terms                         |   292 |    0 |              - |
     *   | question only                            |   156 |    0 |              0 |
     *   | question, patient modifiers stripped     |   116 |    0 |              - |
     *   | terms only                               |    98 |    2 |              6 |
     *   | short concept phrase                     |    54 |    5 |              6 |
     *
     * Question FORM is what fails, not length: 156- and 116-character question
     * forms both returned zero, while 98- and 54-character term phrases returned
     * 2-6. Recommendation rows are declarative statements, so an interrogative
     * sentence is too dissimilar to clear the similarity floor no matter how short
     * it is. The question is therefore dropped entirely and only terms are sent.
     *
     * Shorter still helps within terms-only (54 chars beat 98 on the stricter CLTI
     * document). Multi-query retrieval therefore caps each independent concept at
     * 60 by default instead of spending a shared 100-character concatenation
     * budget.
     *
     * @param  array<int, string>  $terms
     */
    private function buildCitationQuery(string $coreQuestion, array $terms): string
    {
        $budget = max(0, (int) config('gate-v2.retrieval.citation_query_max_chars', 100));
        $query = '';

        foreach ($terms as $term) {
            $term = trim($term);
            if ($term === '' || str_contains(mb_strtolower($query), mb_strtolower($term))) {
                continue;
            }
            $candidate = $query === '' ? $term : $query.' '.$term;
            if (mb_strlen($candidate) > $budget) {
                // Must-include terms are ordered first by build(). If the first
                // usable term alone exceeds the hard cap, keep its bounded prefix
                // instead of silently dropping it in favour of a later optional
                // term.
                if ($query === '') {
                    $query = mb_substr($term, 0, $budget);
                    break;
                }
                continue;
            }
            $query = $candidate;
        }

        // Only when the planner produced no usable terms at all. Still strips the
        // interrogative framing, since question form is the thing that fails.
        return $query !== ''
            ? $query
            : mb_substr($this->declarativeForm($coreQuestion), 0, $budget);
    }

    /**
     * Shape an arbitrary string into a citation-dataset query: declarative form,
     * within the character budget. Used by the retry path, whose `better_query`
     * arrives as a question and would otherwise fail for exactly the reason
     * documented on buildCitationQuery().
     */
    public function shapeCitationQuery(string $text): string
    {
        $budget = max(0, (int) config('gate-v2.retrieval.citation_query_max_chars', 100));

        return mb_substr($this->declarativeForm($text), 0, $budget);
    }

    private function shapeMultiCitationQuery(string $text): string
    {
        $budget = max(20, (int) config('gate-v2.retrieval.citation_multi_query_max_chars', 60));

        return mb_substr($this->declarativeForm($text), 0, $budget);
    }

    /** @param array<string, mixed> $patientModel */
    private function structuredCitationText(array $patientModel): string
    {
        $parts = [];
        foreach (['lesion', 'intervention', 'planned_intervention', 'prior_interventions'] as $field) {
            $value = $patientModel[$field] ?? null;
            foreach (is_array($value) ? $value : [$value] as $part) {
                $part = trim((string) $part);
                if ($part !== '' && ! in_array(mb_strtolower($part), ['unknown', 'none', 'n/a'], true)) {
                    $parts[] = $part;
                }
            }
        }

        return implode(' ', $parts);
    }

    /** @param array<string, mixed> $patientModel */
    private function firstStructuredConcept(array $patientModel): string
    {
        foreach (['intervention', 'planned_intervention', 'prior_interventions', 'lesion'] as $field) {
            $value = $patientModel[$field] ?? null;
            $value = is_array($value) ? reset($value) : $value;
            $candidate = $this->shapeMultiCitationQuery((string) $value);
            if ($candidate !== '') {
                return count(preg_split('/\s+/u', $candidate, -1, PREG_SPLIT_NO_EMPTY) ?: []) > 1
                    ? $candidate
                    : $candidate.' clinical management';
            }
        }

        return '';
    }

    /**
     * @param  array<int, string>  $queries
     * @return array<int, string>
     */
    private function uniqueCappedQueries(array $queries, int $limit): array
    {
        $unique = [];
        foreach ($queries as $query) {
            $query = $this->shapeMultiCitationQuery($query);
            $key = mb_strtolower($query);
            if ($query !== '' && ! isset($unique[$key])) {
                $unique[$key] = $query;
            }
        }

        return array_slice(array_values($unique), 0, $limit);
    }

    /**
     * Reduce a clinical question to its declarative subject, e.g.
     * "What is the recommended antithrombotic therapy after vein bypass?"
     * becomes "antithrombotic therapy after vein bypass".
     */
    private function declarativeForm(string $question): string
    {
        $leadingNoise = [
            'what', 'which', 'when', 'how', 'should', 'is', 'are', 'do', 'does',
            'the', 'a', 'an', 'recommended', 'indicated', 'preferred', 'best', 'optimal',
        ];

        $words = preg_split('/\s+/u', trim(rtrim(trim($question), '?')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // Strip one leading filler word at a time rather than matching a single
        // fixed pattern: "What is the recommended X" and "How should X" have
        // different prefixes, and a single regex left "the recommended" behind.
        while ($words !== [] && in_array(mb_strtolower(trim($words[0], ",.:;")), $leadingNoise, true)) {
            array_shift($words);
        }

        return $words === [] ? trim(rtrim(trim($question), '?')) : implode(' ', $words);
    }

    /** @param array<string, mixed> $patientModel */
    public function renderPatientModel(array $patientModel): string
    {
        $labels = [
            'demographics' => 'Patient',
            'lesion' => 'Lesion',
            'other_findings' => 'Other findings',
            'symptom_status' => 'Symptoms',
            'timing' => 'Timing',
            'fitness' => 'Fitness',
            'imaging' => 'Imaging',
            'comorbidities' => 'Comorbidities',
            'medications' => 'Medications',
            'prior_interventions' => 'Prior interventions',
        ];
        $sentences = [];
        foreach ($labels as $field => $label) {
            $value = $patientModel[$field] ?? null;
            $text = is_array($value)
                ? implode(', ', array_values(array_filter(array_map('strval', $value))))
                : trim((string) $value);
            if ($text === '' || in_array(mb_strtolower($text), ['unknown', 'none', 'n/a'], true)) {
                continue;
            }
            $sentences[] = "{$label}: {$text}.";
        }

        return implode(' ', $sentences);
    }

    /** @return array<int, string> */
    public function caseAnchorTerms(string $text): array
    {
        $matched = [];
        foreach (self::ANCHOR_PATTERNS as $label => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $matched[] = $label;
            }
        }

        return $matched;
    }

    /** @param array<string, mixed> $patientModel */
    private function rewriteWithCaseContext(string $question, array $patientModel): string
    {
        if (
            mb_strlen($question) > 150
            || preg_match(
                '/^(?:so,?\s*)?(?:what (?:now|next)|what should (?:i|we) do|what is the plan|which option|how should (?:i|we) proceed)\??$/iu',
                $question,
            ) !== 1
        ) {
            return $question;
        }
        $anchor = trim((string) ($patientModel['lesion'] ?? ''));

        return $anchor === '' || mb_strtolower($anchor) === 'unknown'
            ? $question
            : "{$anchor} — {$question}";
    }

    /** @param array<string, mixed> $orient
     *  @param array<int, string> $fieldOrder
     *  @return array<int, string>
     */
    private function terms(array $orient, array $fieldOrder = ['expansion_terms', 'interpretation_terms', 'must_include_terms']): array
    {
        $terms = [];
        foreach ($fieldOrder as $field) {
            foreach ((array) ($orient[$field] ?? []) as $term) {
                $term = trim((string) $term);
                if ($term !== '' && ! in_array(mb_strtolower($term), array_map('mb_strtolower', $terms), true)) {
                    $terms[] = $term;
                }
            }
        }

        return $terms;
    }

    /** @param array<int, array<string, mixed>> $issues */
    private function renderIssues(array $issues): string
    {
        $parts = [];
        array_walk_recursive($issues, static function (mixed $value) use (&$parts): void {
            if (is_scalar($value)) {
                $text = trim((string) $value);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        });

        return implode('; ', array_values(array_unique($parts)));
    }
}
