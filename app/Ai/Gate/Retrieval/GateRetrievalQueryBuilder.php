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
    ];

    /**
     * @param  array<string, mixed>  $orient
     * @param  array<int, array<string, mixed>>  $issues
     * @return array{narrative: string, citation: string}
     */
    public function build(array $orient, array $issues = []): array
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

        return [
            'narrative' => implode("\n", $shared),
            'citation' => $this->buildCitationQuery(
                $coreQuestion,
                // Must-include terms first: under a character budget the terms the
                // planner marked mandatory must survive, not be truncated away.
                $this->terms($orient, ['must_include_terms', 'expansion_terms', 'interpretation_terms']),
            ),
        ];
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
     * document), but those two variants differed in wording as well as length, so
     * the budget is set to the largest value proven on BOTH documents and left
     * tunable rather than guessed tighter.
     *
     * @param  array<int, string>  $terms
     */
    private function buildCitationQuery(string $coreQuestion, array $terms): string
    {
        $budget = max(40, (int) config('gate-v2.retrieval.citation_query_max_chars', 100));
        $query = '';

        foreach ($terms as $term) {
            $term = trim($term);
            if ($term === '' || str_contains(mb_strtolower($query), mb_strtolower($term))) {
                continue;
            }
            $candidate = $query === '' ? $term : $query.' '.$term;
            if (mb_strlen($candidate) > $budget) {
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
        $budget = max(40, (int) config('gate-v2.retrieval.citation_query_max_chars', 100));

        return mb_substr($this->declarativeForm($text), 0, $budget);
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
