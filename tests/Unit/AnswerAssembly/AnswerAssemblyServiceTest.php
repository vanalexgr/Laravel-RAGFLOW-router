<?php

namespace Tests\Unit\AnswerAssembly;

use App\Ai\AnswerAssembly\AnswerAssemblyService;
use App\Ai\AnswerAssembly\AnswerFillAgent;
use App\Ai\AnswerAssembly\AnswerSkeletonRenderer;
use Laravel\Ai\Prompts\AgentPrompt;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnswerAssemblyServiceTest extends TestCase
{
    public function test_default_adapter_valve_prevents_laravel_fill_call(): void
    {
        config()->set('gate-v2.synthesis_owner', 'adapter');
        AnswerFillAgent::fake()->preventStrayPrompts();

        $this->expectException(LogicException::class);
        app(AnswerAssemblyService::class)->assemble([]);
    }

    public function test_laravel_path_makes_one_fill_call_and_php_renders_frames_and_assets(): void
    {
        config()->set('gate-v2.synthesis_owner', 'laravel');
        config()->set('gate-v2.audited_snippets.enabled', false);
        AnswerFillAgent::fake([[
            'direct_answer' => 'Direct result.',
            'guideline_grounded_answer' => 'Grounded result.',
            'interpretive_frame' => 'Interpretive result.',
            'practical_points' => ['One practical point.'],
            'evidence_used' => ['Supplied evidence item.'],
            'questions' => ['One?', 'Two?', 'Three?'],
        ]])->preventStrayPrompts();

        $result = app(AnswerAssemblyService::class)->assemble([
            'question' => 'Test question',
            'response_mode' => 'management',
            'retrieved_evidence' => ['Supplied evidence item.'],
            'evidence_status' => [
                'coverage' => 'interaction_gap',
                'core_question' => 'the combined decision',
                'gap_summary' => 'Components are covered separately.',
            ],
            'assets' => [['markdown' => '![figure](https://example.test/figure.png)']],
        ]);

        $this->assertStringContainsString('## Clinical Decision', $result['answer_markdown']);
        $this->assertStringContainsString('## ESVS-grounded answer', $result['answer_markdown']);
        $this->assertStringContainsString('Non-ESVS interpretation', $result['answer_markdown']);
        $this->assertStringContainsString('![figure](https://example.test/figure.png)', $result['answer_markdown']);
        $this->assertSame(['One?', 'Two?'], $result['questions']);
        $this->assertSame(1, $result['synthesis']['fill_calls']);
        AnswerFillAgent::assertPrompted(fn (AgentPrompt $prompt): bool => ! str_contains(
            $prompt->prompt,
            'Candidate ANTICOAG-DOAC-001',
        ));
    }

    #[DataProvider('gapTaxonomy')]
    public function test_php_renderer_preserves_gap_taxonomy(string $coverage, string $expected): void
    {
        $markdown = (new AnswerSkeletonRenderer)->render('case', [
            'coverage' => $coverage,
            'core_question' => 'the test interaction',
            'gap_summary' => '',
        ], [
            'direct_answer' => 'Direct.',
            'guideline_grounded_answer' => 'Grounded.',
            'interpretive_frame' => 'Interpretive.',
            'practical_points' => [],
            'evidence_used' => [],
        ]);

        $this->assertStringContainsString($expected, $markdown);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function gapTaxonomy(): array
    {
        return [
            'partial principles' => ['partial_principles', 'general perioperative principles still apply'],
            'interaction gap' => ['interaction_gap', 'no recommendation on the interaction'],
            'not covered' => ['not_covered', 'No applicable ESVS recommendation'],
            'uncertain' => ['retrieval_uncertain', 'absence of retrieved evidence is not treated as proof'],
        ];
    }
}
