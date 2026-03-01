<?php

namespace App\Services;

class GapDetectionService
{
    public function enabled(): bool
    {
        return (bool) config('gap_detection.enabled', false);
    }

    public function strictTemplateEnabled(): bool
    {
        return (bool) config('gap_detection.strict_template', false);
    }

    public function maxPasses(): int
    {
        $max = (int) config('gap_detection.max_passes', 0);
        return max(0, min($max, 2));
    }

    public function secondPassLimits(): array
    {
        $defaults = ['narrative_max' => 4, 'citation_max' => 3];
        $cfg = config('gap_detection.second_pass', []);
        return [
            'narrative_max' => max(0, (int) ($cfg['narrative_max'] ?? $defaults['narrative_max'])),
            'citation_max' => max(0, (int) ($cfg['citation_max'] ?? $defaults['citation_max'])),
        ];
    }

    /**
     * Detect missing clinical fields based on intent and evidence text.
     *
     * @param string $question
     * @param array $narrativeChunks formatted narrative chunks
     * @param array $citationChunks formatted citation chunks
     * @param array|null $intentProfile from query normalization (intent/question_type/key_terms)
     * @return array ['missing' => array, 'required' => array, 'query_terms' => array]
     */
    public function detect(
        string $question,
        array $narrativeChunks,
        array $citationChunks,
        ?array $intentProfile = null
    ): array {
        $intent = $this->normalizeIntent($intentProfile['intent'] ?? null);
        $required = $this->requiredFieldsForIntent($intent);

        $text = $this->buildEvidenceText($question, $narrativeChunks, $citationChunks);
        $missing = [];

        foreach ($required as $field) {
            if (!$this->fieldCovered($field, $text)) {
                $missing[] = $field;
            }
        }

        $queryTerms = $this->termsForMissingFields($missing);

        $missingConcepts = [];
        $graphTerms = $intentProfile['graph_terms'] ?? [];
        if (is_array($graphTerms) && !empty($graphTerms) && (bool) config('graphrag.concept_gap_check', true)) {
            $missingConcepts = $this->missingGraphConcepts($graphTerms, $text);
            if (!empty($missingConcepts)) {
                $queryTerms = array_values(array_unique(array_merge($queryTerms, $missingConcepts)));
            }
        }

        return [
            'intent' => $intent,
            'required' => $required,
            'missing' => $missing,
            'missing_concepts' => $missingConcepts,
            'query_terms' => $queryTerms,
        ];
    }

    protected function normalizeIntent(?string $intent): string
    {
        $intent = strtolower(trim((string) $intent));
        if ($intent === '') {
            return 'general';
        }
        return $intent;
    }

    protected function requiredFieldsForIntent(string $intent): array
    {
        $map = config('gap_detection.intent_requirements', []);
        $default = config('gap_detection.default_requirements', []);

        $required = $map[$intent] ?? $default;
        if (!is_array($required) || empty($required)) {
            return is_array($default) ? $default : [];
        }
        return array_values(array_unique($required));
    }

    protected function buildEvidenceText(string $question, array $narrativeChunks, array $citationChunks): string
    {
        $parts = [mb_strtolower($question)];

        foreach ($citationChunks as $chunk) {
            $text = (string) ($chunk['text'] ?? '');
            if ($text !== '') {
                $parts[] = mb_strtolower($text);
            }
        }

        foreach ($narrativeChunks as $chunk) {
            $text = (string) ($chunk['content'] ?? '');
            if ($text !== '') {
                $parts[] = mb_strtolower($text);
            }
        }

        return implode("\n", $parts);
    }

    protected function fieldCovered(string $field, string $text): bool
    {
        $patterns = config('gap_detection.field_patterns', []);
        $fieldPatterns = $patterns[$field] ?? [];
        if (!is_array($fieldPatterns) || empty($fieldPatterns)) {
            return true;
        }
        foreach ($fieldPatterns as $pattern) {
            if (@preg_match("/{$pattern}/u", $text) === 1) {
                return true;
            }
        }
        return false;
    }

    protected function termsForMissingFields(array $missing): array
    {
        if (empty($missing)) {
            return [];
        }
        $termsMap = config('gap_detection.field_query_terms', []);
        $terms = [];
        foreach ($missing as $field) {
            $vals = $termsMap[$field] ?? [];
            if (!is_array($vals)) {
                continue;
            }
            foreach ($vals as $term) {
                $term = trim((string) $term);
                if ($term !== '') {
                    $terms[] = $term;
                }
            }
        }
        return array_values(array_unique($terms));
    }

    protected function missingGraphConcepts(array $concepts, string $text): array
    {
        $max = (int) config('graphrag.concept_gap_max_terms', 6);
        $max = max(0, min($max, 20));
        $stop = [
            'aortic', 'artery', 'disease', 'management', 'treatment', 'clinical', 'guideline',
            'patient', 'patients', 'therapy', 'repair', 'surgery',
        ];

        $missing = [];
        foreach ($concepts as $concept) {
            $term = trim((string) $concept);
            if ($term === '' || mb_strlen($term) < 4) {
                continue;
            }
            $termLower = mb_strtolower($term);
            if (in_array($termLower, $stop, true)) {
                continue;
            }
            if (!str_contains($text, $termLower)) {
                $missing[] = $term;
                if (count($missing) >= $max) {
                    break;
                }
            }
        }

        return $missing;
    }
}
