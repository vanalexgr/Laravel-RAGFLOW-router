<?php

namespace App\GateEval;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class ScenarioRepository
{
    private const MODES = [
        'knowledge',
        'case_new',
        'case_followup_substantive',
        'case_followup_vague',
        'gate_reply',
        'capabilities',
        'out_of_scope',
        'model_meta',
        'prompt_injection',
    ];

    private const GUIDELINE_KEYS = [
        'aortic_arch',
        'descending_thoracic_aorta',
        'abdominal_aortic_aneurysm',
        'mesenteric_renal',
        'asymptomatic_pad',
        'clti',
        'acute_limb_ischaemia',
        'carotid_vertebral',
        'venous_thrombosis',
        'chronic_venous_disease',
        'antithrombotic_therapy',
        'vascular_trauma',
        'vascular_graft_infections',
        'vascular_access',
    ];

    private const COVERAGE = [
        'covered',
        'partial_principles',
        'interaction_gap',
        'not_covered',
        'retrieval_uncertain',
    ];

    private const GRADES = ['FAIL', 'PASS_WITH_MINOR', 'PASS'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function load(?string $path = null): array
    {
        $path ??= (string) config('gate-eval.scenarios_path');

        if (! File::isDirectory($path)) {
            throw new InvalidArgumentException("Scenario directory does not exist: {$path}");
        }

        $scenarios = [];
        foreach (File::allFiles($path) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $decoded = json_decode(File::get($file->getPathname()), true, flags: JSON_THROW_ON_ERROR);
            $items = array_is_list($decoded) ? $decoded : [$decoded];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    throw new InvalidArgumentException("Scenario must be an object: {$file->getPathname()}");
                }
                $item['_source'] = $file->getPathname();
                $this->validate($item);
                $scenarios[] = $item;
            }
        }

        $ids = array_column($scenarios, 'id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('Scenario ids must be unique.');
        }

        usort($scenarios, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $scenarios;
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function validate(array $scenario): void
    {
        foreach (['id', 'tags', 'turns'] as $key) {
            if (! array_key_exists($key, $scenario)) {
                $this->invalid($scenario, "missing {$key}");
            }
        }

        if (! is_string($scenario['id']) || $scenario['id'] === '') {
            $this->invalid($scenario, 'id must be a non-empty string');
        }
        if (! is_array($scenario['tags']) || ! is_array($scenario['turns']) || $scenario['turns'] === []) {
            $this->invalid($scenario, 'tags and non-empty turns arrays are required');
        }

        $priorFacts = [];
        foreach ($scenario['turns'] as $index => $turn) {
            if (! is_array($turn) || ! is_string($turn['user'] ?? null) || ! is_array($turn['expected'] ?? null)) {
                $this->invalid($scenario, "turn {$index} must contain user and expected");
            }

            $expected = $turn['expected'];
            foreach ([
                'mode', 'same_case', 'guideline_keys', 'must_include_facts', 'must_not_include',
                'expected_questions_semantic', 'max_questions', 'evidence_status',
            ] as $key) {
                if (! array_key_exists($key, $expected)) {
                    $this->invalid($scenario, "turn {$index} expected missing {$key}");
                }
            }

            if (! in_array($expected['mode'], self::MODES, true)) {
                $this->invalid($scenario, "turn {$index} has invalid mode");
            }
            if (! is_bool($expected['same_case']) && $expected['same_case'] !== null) {
                $this->invalid($scenario, "turn {$index} same_case must be boolean or null");
            }
            foreach ($expected['guideline_keys'] as $key) {
                if (! in_array($key, self::GUIDELINE_KEYS, true)) {
                    $this->invalid($scenario, "turn {$index} has invalid guideline key {$key}");
                }
            }
            foreach (['guideline_keys', 'must_include_facts', 'must_not_include', 'expected_questions_semantic'] as $key) {
                if (! is_array($expected[$key])) {
                    $this->invalid($scenario, "turn {$index} {$key} must be an array");
                }
            }
            if (! is_int($expected['max_questions']) || $expected['max_questions'] < 0) {
                $this->invalid($scenario, "turn {$index} max_questions must be a non-negative integer");
            }

            $coverage = Arr::get($expected, 'evidence_status.coverage');
            $allowedCoverage = is_array($coverage) ? $coverage : [$coverage];
            if ($allowedCoverage === [] || array_diff($allowedCoverage, self::COVERAGE) !== []) {
                $this->invalid($scenario, "turn {$index} has invalid evidence coverage");
            }

            if (isset($expected['baseline_grade']) && ! in_array($expected['baseline_grade'], self::GRADES, true)) {
                $this->invalid($scenario, "turn {$index} has invalid baseline grade");
            }

            $currentFacts = $expected['must_include_facts'];
            $superseded = $expected['must_not_include'];
            foreach ($priorFacts as $fact) {
                if (! in_array($fact, $currentFacts, true) && ! in_array($fact, $superseded, true)) {
                    $this->invalid($scenario, "turn {$index} drops cumulative fact '{$fact}' without superseding it");
                }
            }
            $priorFacts = $currentFacts;
        }
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function invalid(array $scenario, string $message): never
    {
        $id = $scenario['id'] ?? $scenario['_source'] ?? 'unknown';
        throw new InvalidArgumentException("Invalid scenario {$id}: {$message}");
    }
}
