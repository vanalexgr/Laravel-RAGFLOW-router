<?php

namespace App\Agents\Tools;

use App\Services\CoverageAssessmentService;
use App\Services\GuidelineAssetService;
use App\Services\RetrievalService;
use App\ValueObjects\GapAssessment;
use Illuminate\Support\Collection;
use Vizra\VizraADK\Contracts\ToolInterface;
use Vizra\VizraADK\Memory\AgentMemory;
use Vizra\VizraADK\System\AgentContext;

class RetrieveClinicalEvidenceTool implements ToolInterface
{
    private const VALID_GUIDELINE_KEYS = [
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

    public function __construct(
        private readonly RetrievalService $retrievalService,
        private readonly GuidelineAssetService $guidelineAssetService,
        private readonly CoverageAssessmentService $coverageAssessmentService,
    ) {
    }

    public function definition(): array
    {
        return [
            'name' => 'retrieve_clinical_evidence',
            'description' => 'Retrieve ESVS vascular surgery guideline evidence for a clinical question. Returns structured evidence, guideline coverage assessment, and figures.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'question' => [
                        'type' => 'string',
                        'description' => 'The full clinical question, including all patient details gathered in this session.',
                    ],
                    'guideline_keys' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'One to three relevant guideline keys.',
                    ],
                ],
                'required' => ['question'],
            ],
        ];
    }

    public function execute(array $arguments, AgentContext $context, AgentMemory $memory): string
    {
        $question = trim((string) ($arguments['question'] ?? $context->getUserInput() ?? ''));
        $guidelineKeys = $this->sanitizeGuidelineKeys(
            $arguments['guideline_keys'] ?? $context->getState('requested_guidelines', [])
        );

        $result = $this->retrievalService->retrieve(
            $question,
            $this->buildHistoryLines($context),
            $guidelineKeys !== [] ? $guidelineKeys : null
        );

        $assets = $this->guidelineAssetService->findRelevantAssets(
            $result['question'] ?? $question,
            $result['narrative_chunks'] ?? [],
            $result['citation_chunks'] ?? [],
            $result['selected_guidelines'] ?? [],
            $guidelineKeys
        );

        $gapAssessment = $this->buildGapAssessment($result);
        $selectedGuidelines = $this->formatSelectedGuidelines($result['selected_guidelines'] ?? []);

        $toolPayload = [
            'question' => $result['question'] ?? $question,
            'retrieval_query' => $result['retrieval_query'] ?? $question,
            'query_type' => $result['query_type'] ?? 'complex_case',
            'selected_guidelines' => $selectedGuidelines,
            'gap_assessment' => $gapAssessment->toArray(),
            'narrative_chunks' => $result['llm_narrative_chunks'] ?? [],
            'citation_chunks' => $result['llm_citation_chunks'] ?? [],
            'assets' => $assets,
        ];

        $context->setState('last_tool_result', [
            'citation_chunks' => $result['citation_chunks'] ?? [],
            'narrative_chunks' => $result['narrative_chunks'] ?? [],
            'llm_citation_chunks' => $result['llm_citation_chunks'] ?? [],
            'llm_narrative_chunks' => $result['llm_narrative_chunks'] ?? [],
            'assets' => $assets,
            'gap_assessment' => $gapAssessment->toArray(),
            'query_type' => $result['query_type'] ?? 'complex_case',
            'intent_profile' => $result['intent_profile'] ?? null,
            'selected_guidelines' => $selectedGuidelines,
            'retrieval_query' => $result['retrieval_query'] ?? $question,
        ]);

        return json_encode(
            $toolPayload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: '{"error":"Unable to encode retrieval payload."}';
    }

    /**
     * @return array<int, string>
     */
    private function buildHistoryLines(AgentContext $context): array
    {
        $history = [];
        $conversationHistory = $context->getConversationHistory();

        if ($conversationHistory->count() <= 1) {
            foreach ($context->getState('client_history', []) as $line) {
                if (is_string($line) && trim($line) !== '') {
                    $history[] = trim($line);
                }
            }
        }

        foreach ($conversationHistory->take(-10) as $message) {
            if (!is_array($message)) {
                continue;
            }

            $text = $this->extractMessageText($message['content'] ?? '');
            if ($text === '') {
                continue;
            }

            $history[] = sprintf('%s: %s', $message['role'] ?? 'user', $text);
        }

        return array_slice($history, -10);
    }

    private function extractMessageText(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }

        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $item) {
            if (is_string($item) && trim($item) !== '') {
                $parts[] = trim($item);
                continue;
            }

            if (is_array($item)) {
                $text = $item['text'] ?? null;
                if (is_string($text) && trim($text) !== '') {
                    $parts[] = trim($text);
                }
            }
        }

        return implode("\n", $parts);
    }

    private function buildGapAssessment(array $result): GapAssessment
    {
        $facets = $result['intent_profile']['key_terms'] ?? [];
        $llmChunks = array_merge(
            $result['llm_citation_chunks'] ?? [],
            $result['llm_narrative_chunks'] ?? []
        );

        return $this->coverageAssessmentService->assess(
            question: $result['question'] ?? '',
            llmChunks: $llmChunks,
            facets: is_array($facets) ? $facets : [],
            queryType: $result['query_type'] ?? 'complex_case',
        );
    }

    /**
     * @param  mixed  $keys
     * @return array<int, string>
     */
    private function sanitizeGuidelineKeys(mixed $keys): array
    {
        if (!is_array($keys)) {
            return [];
        }

        $sanitized = [];
        foreach ($keys as $key) {
            if (!is_string($key)) {
                continue;
            }

            $normalized = trim($key);
            if ($normalized === '' || !in_array($normalized, self::VALID_GUIDELINE_KEYS, true)) {
                continue;
            }

            if (!in_array($normalized, $sanitized, true)) {
                $sanitized[] = $normalized;
            }

            if (count($sanitized) === 3) {
                break;
            }
        }

        return $sanitized;
    }

    /**
     * @param  array<string|int, mixed>  $selectedGuidelines
     * @return array<int, array{key:string,name:string}>
     */
    private function formatSelectedGuidelines(array $selectedGuidelines): array
    {
        $formatted = [];

        foreach ($selectedGuidelines as $key => $guideline) {
            if (is_string($key) && is_array($guideline)) {
                $formatted[] = [
                    'key' => $key,
                    'name' => (string) ($guideline['name'] ?? $key),
                ];
                continue;
            }

            if (is_array($guideline)) {
                $guidelineKey = (string) ($guideline['key'] ?? $guideline['slug'] ?? '');
                $guidelineName = (string) ($guideline['name'] ?? $guidelineKey);

                if ($guidelineKey !== '') {
                    $formatted[] = [
                        'key' => $guidelineKey,
                        'name' => $guidelineName,
                    ];
                }
            }
        }

        return $formatted;
    }
}
