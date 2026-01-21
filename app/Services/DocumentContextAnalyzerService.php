<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class DocumentContextAnalyzerService
{
    protected array $clinicalVocabulary;
    protected array $guidelineMapping;

    public function __construct()
    {
        $this->loadVocabulary();
    }

    protected function loadVocabulary(): void
    {
        $data = Cache::remember('document_analyzer_vocabulary', 3600, function () {
            return $this->buildVocabularyData();
        });

        $this->clinicalVocabulary = $data['vocabulary'];
        $this->guidelineMapping = $data['mapping'];
    }

    protected function buildVocabularyData(): array
    {
        $vocabulary = [];
        $mapping = [];

        $categories = config('guidelines.categories', []);

        foreach ($categories as $category) {
            foreach ($category['guidelines'] as $key => $guideline) {
                $mapping[$key] = [
                    'name' => $guideline['name'],
                    'id' => $guideline['id'],
                ];

                foreach ($guideline['key_concepts'] as $concept) {
                    $normalized = strtolower(trim($concept));
                    if (!isset($vocabulary[$normalized])) {
                        $vocabulary[$normalized] = [];
                    }
                    $vocabulary[$normalized][] = $key;
                }
            }
        }

        return [
            'vocabulary' => $vocabulary,
            'mapping' => $mapping,
        ];
    }

    public function analyze(string $patientContext): array
    {
        $startTime = microtime(true);
        $log = Log::channel('retrieval');

        if (empty(trim($patientContext))) {
            return [
                'entities' => [],
                'guideline_scores' => [],
                'recommended_guidelines' => [],
            ];
        }

        $textLower = strtolower($patientContext);
        $foundEntities = [];
        $guidelineScores = [];

        foreach ($this->clinicalVocabulary as $concept => $guidelineKeys) {
            if ($this->conceptMatchesText($concept, $textLower)) {
                $foundEntities[] = $concept;
                
                foreach ($guidelineKeys as $key) {
                    if (!isset($guidelineScores[$key])) {
                        $guidelineScores[$key] = 0;
                    }
                    $phraseBonus = str_contains($concept, ' ') ? 3 : 1;
                    $guidelineScores[$key] += $phraseBonus;
                }
            }
        }

        $additionalEntities = $this->extractAdditionalClinicalTerms($textLower);
        $foundEntities = array_unique(array_merge($foundEntities, $additionalEntities));

        arsort($guidelineScores);

        $recommended = [];
        foreach ($guidelineScores as $key => $score) {
            if ($score >= 1 && count($recommended) < 4) {
                $recommended[] = $key;
            }
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        
        $log->info('[DOCUMENT ANALYZER] Analysis complete', [
            'patient_context_length' => strlen($patientContext),
            'entities_found' => count($foundEntities),
            'entities_preview' => array_slice($foundEntities, 0, 10),
            'guideline_scores' => $guidelineScores,
            'recommended_guidelines' => $recommended,
            'duration_ms' => $duration,
        ]);

        return [
            'entities' => $foundEntities,
            'guideline_scores' => $guidelineScores,
            'recommended_guidelines' => $recommended,
        ];
    }

    protected function conceptMatchesText(string $concept, string $text): bool
    {
        if (strlen($concept) <= 3) {
            return preg_match('/\b' . preg_quote($concept, '/') . '\b/i', $text) === 1;
        }
        return str_contains($text, $concept);
    }

    protected function extractAdditionalClinicalTerms(string $text): array
    {
        $additionalTerms = [];
        
        $clinicalPatterns = [
            'diabetes' => '/\b(diabetes|diabetic|dm|dm2|type\s*2\s*diabetes|hba1c)\b/i',
            'hypertension' => '/\b(hypertension|htn|high\s*blood\s*pressure|bp\s*\d+\/\d+)\b/i',
            'smoking' => '/\b(smok(ing|er)|tobacco|cigarette|pack[\-\s]?year)\b/i',
            'renal_disease' => '/\b(renal|kidney|ckd|esrd|dialysis|creatinine|gfr)\b/i',
            'cardiac' => '/\b(cardiac|heart|mi|cad|coronary|chf|heart\s*failure|ejection\s*fraction|ef)\b/i',
            'stroke' => '/\b(stroke|cva|tia|cerebrovascular|hemiparesis|aphasia)\b/i',
            'ulcer' => '/\b(ulcer|wound|tissue\s*loss|necrosis|gangrene)\b/i',
            'claudication' => '/\b(claudication|leg\s*pain|walking\s*distance|walking\s*impairment)\b/i',
            'aneurysm' => '/\b(aneurysm|aneurysmal|aortic\s*dilatation)\b/i',
            'stenosis' => '/\b(stenosis|occlusion|narrowing|blocked)\b/i',
            'thrombosis' => '/\b(thrombosis|thrombus|clot|dvt|pe|embolism)\b/i',
            'surgery_history' => '/\b(bypass|stent|endarterectomy|angioplasty|amputation|revascularization)\b/i',
        ];

        foreach ($clinicalPatterns as $category => $pattern) {
            if (preg_match($pattern, $text)) {
                $additionalTerms[] = $category;
            }
        }

        return $additionalTerms;
    }

    public function getGuidelineMapping(): array
    {
        return $this->guidelineMapping;
    }

    public function mergeWithQuestionRouting(array $documentAnalysis, array $questionSelectedKeys): array
    {
        $docRecommended = $documentAnalysis['recommended_guidelines'] ?? [];
        $docScores = $documentAnalysis['guideline_scores'] ?? [];

        $merged = array_unique(array_merge($questionSelectedKeys, $docRecommended));

        usort($merged, function ($a, $b) use ($docScores, $questionSelectedKeys) {
            $scoreA = ($docScores[$a] ?? 0) + (in_array($a, $questionSelectedKeys) ? 10 : 0);
            $scoreB = ($docScores[$b] ?? 0) + (in_array($b, $questionSelectedKeys) ? 10 : 0);
            return $scoreB - $scoreA;
        });

        return array_slice($merged, 0, 4);
    }
}
