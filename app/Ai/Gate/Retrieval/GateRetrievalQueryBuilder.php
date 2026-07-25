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

        $narrative = implode("\n", $shared);
        $citation = implode("\n", array_filter([
            'ESVS recommendation sought: '.$coreQuestion,
            $patientProse === '' ? null : 'Applicable patient and procedure: '.$patientProse,
            $terms === [] ? null : 'Recommendation terms: '.implode(', ', $terms),
            $anchors === [] ? null : 'Scope anchors: '.implode(', ', $anchors),
            'Return the directly applicable recommendation, threshold, indication, class, and evidence level.',
            $issueText === '' ? null : 'Missing recommendation detail: '.$issueText,
        ]));

        return ['narrative' => $narrative, 'citation' => $citation];
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
     *  @return array<int, string>
     */
    private function terms(array $orient): array
    {
        $terms = [];
        foreach (['expansion_terms', 'interpretation_terms', 'must_include_terms'] as $field) {
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
