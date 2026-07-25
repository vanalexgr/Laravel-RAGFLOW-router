<?php

namespace App\Console\Commands;

use App\Ai\Gate\Retrieval\GateRetrievalQueryBuilder;
use App\Ai\Gate\Tools\RetrieveEsvsSnippetsTool;
use Illuminate\Console\Command;

class GateProbeRetrievalCommand extends Command
{
    protected $signature = 'gate:probe-retrieval {--json : Emit machine-readable JSON}';

    protected $description = 'Probe the R7 gate retrieval query and cleaned chunk signal';

    public function handle(
        GateRetrievalQueryBuilder $queryBuilder,
        RetrieveEsvsSnippetsTool $retrieval,
    ): int {
        $orient = [
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
            'interpretation_terms' => [
                'men with asymptomatic AAA',
                'fitness for repair',
            ],
            'must_include_terms' => [
                'aneurysm diameter threshold',
                'elective repair candidacy',
            ],
        ];
        $queries = $queryBuilder->build($orient);
        $result = $retrieval->retrieve(
            'abdominal_aortic_aneurysm',
            $queries['narrative'],
            false,
            24,
            60,
            $queries['citation'],
        );
        $snippets = (array) ($result['snippets'] ?? []);
        $topFive = array_map(
            static fn (array $snippet): float => (float) ($snippet['similarity'] ?? 0),
            array_slice($snippets, 0, 5),
        );
        $rec22 = array_values(array_filter(
            $snippets,
            static function (array $snippet): bool {
                $metadata = (array) ($snippet['metadata'] ?? []);
                $haystack = mb_strtolower(implode(' ', [
                    (string) ($metadata['recommendation_id'] ?? ''),
                    (string) ($snippet['text'] ?? ''),
                ]));

                return preg_match('/\b(?:rec(?:ommendation)?[_\s-]*)?22\b/u', $haystack) === 1;
            },
        ));
        $payload = [
            'narrative_query' => $queries['narrative'],
            'citation_query' => $queries['citation'],
            'top_5_similarity' => $topFive,
            'rec_22_present' => $rec22 !== [],
            'snippet_count' => count($snippets),
            'signal_ratio' => (float) ($result['diagnostics']['signal_ratio'] ?? 0),
            'snippets' => $snippets,
        ];

        if ($this->option('json')) {
            $this->line(json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));
        } else {
            $this->line($queries['narrative']);
            $this->table(
                ['Top-5 similarity', 'rec_22', 'Chunks', 'Signal ratio'],
                [[
                    implode(' / ', array_map(static fn (float $score): string => number_format($score, 1), $topFive)),
                    $rec22 === [] ? 'NO' : 'YES',
                    count($snippets),
                    number_format((float) ($result['diagnostics']['signal_ratio'] ?? 0) * 100, 1).'%',
                ]],
            );
        }

        return $rec22 !== [] && (float) ($result['diagnostics']['signal_ratio'] ?? 0) >= 0.9
            ? self::SUCCESS
            : self::FAILURE;
    }
}
