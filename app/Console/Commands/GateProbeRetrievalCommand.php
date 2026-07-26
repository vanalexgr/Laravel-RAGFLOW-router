<?php

namespace App\Console\Commands;

use App\Ai\Gate\Retrieval\GateRetrievalQueryBuilder;
use App\Ai\Gate\Tools\RetrieveEsvsSnippetsTool;
use Illuminate\Console\Command;
use InvalidArgumentException;

class GateProbeRetrievalCommand extends Command
{
    protected $signature = 'gate:probe-retrieval
        {--case=aaa : Fixture to probe (aaa|s2)}
        {--guideline= : Probe one guideline ad hoc, bypassing fixtures}
        {--query= : Raw query to use with --guideline (both narrative and citation)}
        {--top-k=24 : Candidate pool; the gate uses 12 on attempt 1 and 24 on the retry}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Probe gate retrieval query shape, citation supply, and cleaned chunk signal';

    public function handle(
        GateRetrievalQueryBuilder $queryBuilder,
        RetrieveEsvsSnippetsTool $retrieval,
    ): int {
        $adHocGuideline = trim((string) $this->option('guideline'));
        $adHocQuery = trim((string) $this->option('query'));

        if ($adHocGuideline !== '') {
            if ($adHocQuery === '') {
                $this->error('--guideline requires --query.');

                return self::FAILURE;
            }
            $case = "adhoc:{$adHocGuideline}";
            $fixture = [
                'orient' => [],
                'guidelines' => [$adHocGuideline],
                'min_citations' => 1,
                'min_signal_ratio' => 0.0,
                'expect_recommendation' => null,
                'expect_marker' => null,
            ];
            // Answers "does this document supply recommendations for this query at
            // all", so the raw query is used verbatim on both sides.
            $queries = ['narrative' => $adHocQuery, 'citation' => $adHocQuery];
        } else {
            $case = (string) $this->option('case');
            try {
                $fixture = $this->fixture($case);
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $queries = $queryBuilder->build($fixture['orient']);
        }
        $branches = [];
        $passed = true;
        // The gate's attempt_top_k is [12, 24], so a probe at 24 does not reproduce
        // what attempt 1 actually sends. `citation_top_k` is min(top_k, 16).
        $topK = max(4, (int) $this->option('top-k'));

        foreach ($fixture['guidelines'] as $guideline) {
            $result = $retrieval->retrieve(
                $guideline,
                $queries['narrative'],
                false,
                $topK,
                60,
                $queries['citation'],
            );
            $snippets = (array) ($result['snippets'] ?? []);
            $diagnostics = (array) ($result['diagnostics'] ?? []);
            $signalRatio = (float) ($diagnostics['signal_ratio'] ?? 0);
            $citationCount = (int) ($diagnostics['citation_count'] ?? 0);

            $branch = [
                'guideline' => $guideline,
                'snippet_count' => count($snippets),
                // The Run 8 root-cause metric: a branch can look healthy on
                // snippet_count while supplying no verbatim recommendations at all.
                'citation_count' => $citationCount,
                'citation_available' => (int) ($diagnostics['citation_available'] ?? 0),
                'narrative_available' => (int) ($diagnostics['narrative_available'] ?? 0),
                'top_5_similarity' => array_map(
                    static fn (array $snippet): float => (float) ($snippet['similarity'] ?? 0),
                    array_slice($snippets, 0, 5),
                ),
                'signal_ratio' => $signalRatio,
                'recommendation_ids' => array_values(array_filter(array_map(
                    static fn (array $s): ?string => ((array) ($s['metadata'] ?? []))['recommendation_id'] ?? null,
                    $snippets,
                ))),
                'expected_marker_present' => $fixture['expect_marker'] === null
                    ? null
                    : $this->containsMarker($snippets, $fixture['expect_marker']),
            ];

            if ($citationCount < $fixture['min_citations'] || $signalRatio < $fixture['min_signal_ratio']) {
                $passed = false;
            }
            if ($fixture['expect_recommendation'] !== null
                && ! $this->containsRecommendation($snippets, $fixture['expect_recommendation'])) {
                $passed = false;
            }

            $branches[] = $branch;
        }

        $payload = [
            'case' => $case,
            'top_k' => $topK,
            'citation_top_k' => min($topK, 16),
            'narrative_query' => $queries['narrative'],
            'narrative_query_chars' => mb_strlen($queries['narrative']),
            'citation_query' => $queries['citation'],
            'citation_query_chars' => mb_strlen($queries['citation']),
            'branches' => $branches,
            'passed' => $passed,
        ];

        if ($this->option('json')) {
            $this->line(json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));

            return $passed ? self::SUCCESS : self::FAILURE;
        }

        // The two query lengths are the headline: the recommendations dataset holds
        // short verbatim rows, so a citation query as long as the narrative one is
        // itself the defect.
        $this->line("citation query ({$payload['citation_query_chars']} chars): ".$queries['citation']);
        $this->newLine();
        $this->line("narrative query: {$payload['narrative_query_chars']} chars");
        $this->newLine();

        $this->table(
            ['Guideline', 'Chunks', 'Recs delivered', 'Recs available', 'rec ids', 'Top-5 similarity', 'Signal'],
            array_map(static fn (array $b): array => [
                $b['guideline'],
                $b['snippet_count'],
                $b['citation_count'],
                $b['citation_available'],
                $b['recommendation_ids'] === [] ? '—' : implode(',', $b['recommendation_ids']),
                implode(' / ', array_map(
                    static fn (float $s): string => number_format($s, 1),
                    $b['top_5_similarity'],
                )),
                number_format($b['signal_ratio'] * 100, 1).'%',
            ], $branches),
        );

        foreach ($branches as $branch) {
            if ($branch['expected_marker_present'] !== null) {
                $this->line(sprintf(
                    '  %s — expected marker "%s": %s',
                    $branch['guideline'],
                    $fixture['expect_marker'],
                    $branch['expected_marker_present'] ? 'PRESENT' : 'ABSENT',
                ));
            }
        }

        $this->newLine();
        $this->line($passed ? '<info>PASS</info>' : '<error>FAIL</error>');

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<int, array<string, mixed>> $snippets */
    private function containsRecommendation(array $snippets, string $id): bool
    {
        foreach ($snippets as $snippet) {
            $metadata = (array) ($snippet['metadata'] ?? []);
            $haystack = mb_strtolower(implode(' ', [
                (string) ($metadata['recommendation_id'] ?? ''),
                (string) ($snippet['text'] ?? ''),
            ]));
            if (preg_match('/\b(?:rec(?:ommendation)?[_\s-]*)?'.preg_quote($id, '/').'\b/u', $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array<string, mixed>> $snippets */
    private function containsMarker(array $snippets, string $marker): bool
    {
        foreach ($snippets as $snippet) {
            if (str_contains(mb_strtolower((string) ($snippet['text'] ?? '')), mb_strtolower($marker))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Probe fixtures. Each declares its own pass rule so adding a case cannot
     * silently change another case's contract.
     *
     * @return array{orient: array<string, mixed>, guidelines: array<int, string>, min_citations: int, min_signal_ratio: float, expect_recommendation: ?string, expect_marker: ?string}
     */
    private function fixture(string $case): array
    {
        return match ($case) {
            // R7.1/R7.2 baseline: query similarity and chunk cleaning.
            'aaa' => [
                'orient' => [
                    'core_question' => 'What management is recommended for a 74-year-old man with an asymptomatic 5.8 cm abdominal aortic aneurysm?',
                    'patient_model' => [
                        'demographics' => '74-year-old man',
                        'lesion' => '5.8 cm asymptomatic abdominal aortic aneurysm',
                        'other_findings' => [],
                        'symptom_status' => 'asymptomatic',
                        'timing' => 'newly discovered',
                        'fitness' => 'requires formal assessment',
                        'imaging' => 'ultrasound',
                        'comorbidities' => ['active smoker', 'hypertension', 'dyslipidaemia'],
                        'medications' => [],
                        'prior_interventions' => [],
                    ],
                    'expansion_terms' => [
                        'abdominal aortic aneurysm',
                        'AAA 58 mm',
                        'elective repair threshold 55 mm',
                    ],
                    'interpretation_terms' => ['men with asymptomatic AAA', 'fitness for repair'],
                    'must_include_terms' => ['aneurysm diameter threshold', 'elective repair candidacy'],
                ],
                'guidelines' => ['abdominal_aortic_aneurysm'],
                'min_citations' => 0,
                'min_signal_ratio' => 0.9,
                'expect_recommendation' => '22',
                'expect_marker' => null,
            ],
            // Run 8's cleanest citation-starvation failure: FAIL on both branches
            // with ZERO recommendations delivered (clti 0/6, antithrombotic 0/6).
            // Orient state copied from run8_arm_a_merge_external_20260725_233343.
            's2' => [
                'orient' => [
                    'core_question' => 'What is the recommended antithrombotic therapy after vein below-knee bypass for critical limb-threatening ischemia with rest pain and no high bleeding risk?',
                    'patient_model' => [
                        'demographics' => 'unknown',
                        'lesion' => 'peripheral arterial disease with lower limb ischemia requiring vein below-knee bypass',
                        'other_findings' => [],
                        'symptom_status' => 'rest pain preoperative',
                        'timing' => 'postoperative for revascularization',
                        'fitness' => 'no high bleeding risk',
                        'imaging' => 'unknown',
                        'comorbidities' => [],
                        'medications' => [],
                        'prior_interventions' => ['vein BK bypass'],
                    ],
                    'expansion_terms' => [
                        'peripheral arterial disease',
                        'lower limb revascularization',
                        'vein below-knee bypass',
                        'critical limb-threatening ischemia',
                        'rest pain',
                        'antithrombotic therapy',
                        'antiplatelet therapy',
                        'anticoagulation',
                    ],
                    'interpretation_terms' => [
                        'rest pain',
                        'critical limb-threatening ischemia',
                        'vein bypass',
                        'bleeding risk',
                        'postoperative anticoagulation',
                    ],
                    'must_include_terms' => [
                        'antithrombotic therapy',
                        'peripheral arterial disease',
                        'vein bypass',
                        'critical limb-threatening ischemia',
                    ],
                ],
                'guidelines' => ['clti', 'antithrombotic_therapy'],
                // The whole hypothesis: every branch must now deliver at least one
                // verbatim recommendation. Signal ratio is not gated here — this
                // fixture tests supply, not cleaning.
                'min_citations' => 1,
                'min_signal_ratio' => 0.0,
                'expect_recommendation' => null,
                // Informational: the benchmark's expected regimen. Reported, not
                // gated, because guideline wording may differ from the drug name.
                'expect_marker' => 'rivaroxaban',
            ],
            default => throw new InvalidArgumentException("Unknown --case '{$case}'. Use aaa or s2."),
        };
    }
}
