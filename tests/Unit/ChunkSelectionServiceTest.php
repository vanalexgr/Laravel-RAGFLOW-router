<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\ChunkSelectionService;

/**
 * Unit tests for ChunkSelectionService.
 *
 * Expected values are derived by tracing the ported Python methods
 * (_rank_chunks_by_intent, _diversify_chunks, etc.) against the same
 * sample data used here.
 */
class ChunkSelectionServiceTest extends TestCase
{
    private ChunkSelectionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(ChunkSelectionService::class);
    }

    // ── buildIntentProfile ────────────────────────────────────────────────

    public function test_build_intent_profile_extracts_fields(): void
    {
        $norm = [
            'intent'           => 'threshold',
            'question_type'    => 'treatment_decision',
            'key_terms'        => ['carotid', 'stenosis', 'endarterectomy'],
            'normalized_query' => 'Diameter threshold for carotid endarterectomy',
        ];

        $profile = $this->svc->buildIntentProfile($norm);

        $this->assertEquals('threshold', $profile['intent']);
        $this->assertEquals('treatment_decision', $profile['question_type']);
        $this->assertContains('carotid', $profile['key_terms']);
        $this->assertContains('stenosis', $profile['key_terms']);
        $this->assertContains('endarterectomy', $profile['key_terms']);
        $this->assertStringContainsString('threshold', $profile['combined_query']);
    }

    public function test_build_intent_profile_filters_stop_words(): void
    {
        $norm = [
            'intent'           => 'general',
            'question_type'    => '',
            'key_terms'        => ['management', 'treatment', 'aorta', 'evar'],
            'normalized_query' => '',
        ];

        $profile = $this->svc->buildIntentProfile($norm);

        // 'management', 'treatment', 'aorta' are stop words — must not appear
        $this->assertNotContains('management', $profile['key_terms']);
        $this->assertNotContains('treatment',  $profile['key_terms']);
        $this->assertNotContains('aorta',      $profile['key_terms']);
        // 'evar' is 4 chars, not a stop word — must survive
        $this->assertContains('evar', $profile['key_terms']);
    }

    public function test_build_intent_profile_filters_short_terms(): void
    {
        $norm = [
            'intent'        => 'general',
        'question_type' => '',
            'key_terms'     => ['ct', 'aaa', 'carotid', 'dvt'],
            'normalized_query' => '',
        ];

        $profile = $this->svc->buildIntentProfile($norm);

        // 'ct' (2), 'aaa' (3) below min 4 chars — filtered
        $this->assertNotContains('ct',  $profile['key_terms']);
        $this->assertNotContains('aaa', $profile['key_terms']);
        // 'carotid' (7), 'dvt' (3 — also filtered) survive based on length
        $this->assertContains('carotid', $profile['key_terms']);
    }

    public function test_build_intent_profile_generates_anatomic_variants(): void
    {
        $norm = [
            'intent'           => 'general',
            'question_type'    => '',
            'key_terms'        => ['abdominal aortic stenosis'],
            'normalized_query' => '',
        ];

        $profile = $this->svc->buildIntentProfile($norm);

        // Original term kept
        $this->assertContains('abdominal aortic stenosis', $profile['key_terms']);
        // Variant without anatomic modifier 'abdominal' should also appear
        $this->assertContains('aortic stenosis', $profile['key_terms']);
    }

    // ── selectChunkCaps ───────────────────────────────────────────────────

    public function test_select_chunk_caps_single_guideline(): void
    {
        $caps = $this->svc->selectChunkCaps(1);
        $this->assertEquals(6,  $caps['llm_rec']);
        $this->assertEquals(4,  $caps['llm_narr']);
        $this->assertEquals(12, $caps['ui_rec']);
        $this->assertEquals(8,  $caps['ui_narr']);
    }

    public function test_select_chunk_caps_multi_guideline(): void
    {
        $caps = $this->svc->selectChunkCaps(3);
        $this->assertEquals(8,  $caps['llm_rec']);
        $this->assertEquals(8,  $caps['llm_narr']);
        $this->assertEquals(18, $caps['ui_rec']);
        $this->assertEquals(12, $caps['ui_narr']);
    }

    // ── scoreChunk / rankByIntent ─────────────────────────────────────────

    /**
     * Python baseline (traced manually):
     *  chunk_a: text contains "diameter" and "threshold" → +4 +4 = 8 for intent "threshold"
     *  chunk_b: text has no intent terms → 0
     *  Expected order: [chunk_a, chunk_b]
     */
    public function test_rank_by_intent_places_matching_chunk_first(): void
    {
        $chunkA = [
            'recommendation_id' => 'R1',
            'guideline'         => 'AAA',
            'text'              => 'Elective repair is indicated when diameter exceeds threshold of 5.5 cm',
            'class'             => 'I',
            'level'             => 'A',
        ];
        $chunkB = [
            'recommendation_id' => 'R2',
            'guideline'         => 'AAA',
            'text'              => 'Antibiotic prophylaxis should be given before procedure',
            'class'             => 'IIa',
            'level'             => 'B',
        ];

        $profile = $this->svc->buildIntentProfile([
            'intent'           => 'threshold',
            'question_type'    => '',
            'key_terms'        => [],
            'normalized_query' => 'diameter threshold for aaa repair',
        ]);

        $ranked = $this->svc->rankByIntent([$chunkB, $chunkA], 'citation', $profile);

        $this->assertEquals('R1', $ranked[0]['recommendation_id'],
            'chunk_a should rank first (contains diameter and threshold)');
        $this->assertEquals('R2', $ranked[1]['recommendation_id']);
    }

    public function test_score_chunk_key_term_multiword_scores_higher_than_single(): void
    {
        $chunkA = ['text' => 'carotid endarterectomy is recommended', 'guideline' => 'Carotid'];
        $chunkB = ['text' => 'carotid intervention', 'guideline' => 'Carotid'];

        $profile = [
            'intent'         => 'general',
            'question_type'  => '',
            'key_terms'      => ['carotid endarterectomy'],  // multi-word
            'combined_query' => '',
        ];

        $scoreA = $this->svc->scoreChunk($chunkA, 'citation', $profile);
        $scoreB = $this->svc->scoreChunk($chunkB, 'citation', $profile);

        $this->assertGreaterThan($scoreB, $scoreA,
            'Chunk containing the multi-word key term should score higher');
    }

    public function test_score_chunk_narrative_frontmatter_gets_penalty(): void
    {
        $generic = [
            'content'          => 'clinical practice guideline document methodology overview',
            'source_guideline' => 'AAA',
        ];
        $relevant = [
            'content'          => 'evar is recommended for patients with suitable anatomy',
            'source_guideline' => 'AAA',
        ];

        $profile = [
            'intent' => 'treatment', 'question_type' => '', 'key_terms' => [], 'combined_query' => '',
        ];

        $genericScore  = $this->svc->scoreChunk($generic,  'narrative', $profile);
        $relevantScore = $this->svc->scoreChunk($relevant, 'narrative', $profile);

        $this->assertLessThan($relevantScore, $genericScore,
            'Front-matter narrative chunk should score lower due to penalty');
    }

    public function test_rank_by_intent_prefers_definitive_treatment_vgei_recommendation(): void
    {
        $definitive = [
            'recommendation_id' => '29',
            'guideline' => 'Management of Vascular Graft and Endograft Infections',
            'text' => 'For patients with aorto-oesophageal fistula complicating thoracic/thoraco-abdominal vascular graft/endograft infection, explantation of the infected material, repair of the oesophagus, and coverage with viable tissue is recommended as definitive treatment.',
            'class' => 'I',
            'level' => 'B',
        ];
        $bridge = [
            'recommendation_id' => '30',
            'guideline' => 'Management of Vascular Graft and Endograft Infections',
            'text' => 'In the emergency setting with active bleeding complicating thoracic/thoraco-abdominal vascular graft/endograft infection with an aorto-oesophageal fistula, initial treatment with an aortic endograft, as a bridge to definitive treatment, should be considered.',
            'class' => 'IIa',
            'level' => 'B',
        ];
        $genericTevar = [
            'recommendation_id' => '64',
            'guideline' => 'Management of Descending Thoracic and Thoraco-Abdominal Aortic Diseases',
            'text' => 'Thoracic endovascular aortic repair is recommended as the first line surgical treatment option in patients with descending thoracic aortic aneurysms.',
            'class' => 'I',
            'level' => 'B',
        ];

        $profile = $this->svc->buildIntentProfile([
            'intent' => 'management',
            'question_type' => 'treatment_decision',
            'key_terms' => [
                'aorto-oesophageal fistula',
                'infected graft',
                'definitive treatment',
                'explantation',
                'reconstruction',
            ],
            'normalized_query' => 'What is the definitive treatment after TEVAR for aorto-oesophageal fistula with infected thoracic endograft?',
        ]);

        $ranked = $this->svc->rankByIntent([$genericTevar, $bridge, $definitive], 'citation', $profile);
        $ids = array_column($ranked, 'recommendation_id');

        $this->assertSame('29', $ids[0]);
        $this->assertSame(['29', '30', '64'], $ids);
    }

    public function test_rank_by_intent_prefers_urgent_complex_aaa_recommendations_over_generic_or_mismatched_chunks(): void
    {
        $mismatched = [
            'recommendation_id' => '152',
            'guideline' => 'Abdominal Aortic Aneurysm',
            'text' => 'In patients with abdominal aortic aneurysm and concomitant malignancy, a staged approach with endovascular aneurysm repair first may be considered.',
            'class' => 'IIb',
            'level' => 'C',
        ];
        $genericElective = [
            'recommendation_id' => '65',
            'guideline' => 'Abdominal Aortic Aneurysm',
            'text' => 'Endovascular repair is recommended as the preferred treatment modality in patients with suitable anatomy undergoing elective abdominal aortic aneurysm repair.',
            'class' => 'I',
            'level' => 'A',
        ];
        $urgentComplexAaa = [
            'recommendation_id' => '129',
            'guideline' => 'Abdominal Aortic Aneurysm',
            'text' => 'For patients with ruptured complex abdominal aortic aneurysm or urgent repair for any other reason, open surgical repair or endovascular repair with off the shelf branched stent grafts physician modified endografts or in situ fenestration may be considered.',
            'class' => 'IIb',
            'level' => 'C',
        ];
        $thoracicCompanion = [
            'recommendation_id' => '83',
            'guideline' => 'Management of Descending Thoracic and Thoraco-Abdominal Aortic Diseases',
            'text' => 'For patients with ruptured thoraco-abdominal aortic aneurysm, endovascular repair with off the shelf branched stent grafts physician modified endografts or in situ fenestration should be considered when feasible.',
            'class' => 'IIa',
            'level' => 'C',
        ];

        $profile = $this->svc->buildIntentProfile([
            'intent' => 'management',
            'question_type' => 'treatment_decision',
            'key_terms' => [
                'juxtarenal aneurysm',
                'symptomatic',
                'impending rupture',
                'open repair',
                'endovascular repair',
            ],
            'normalized_query' => 'symptomatic juxtarenal aneurysm 6 cm impending rupture stable urgent management open repair versus endovascular repair',
        ]);

        $ranked = $this->svc->rankByIntent(
            [$mismatched, $genericElective, $thoracicCompanion, $urgentComplexAaa],
            'citation',
            $profile
        );
        $ids = array_column($ranked, 'recommendation_id');

        $this->assertSame('129', $ids[0]);
        $this->assertSame('83', $ids[1]);
        $this->assertSame(['129', '83', '65', '152'], $ids);
    }

    // ── diversify ─────────────────────────────────────────────────────────

    /**
     * Python baseline:
     *  Input (order): [AAA-1, AAA-2, Carotid-1, Carotid-2]
     *  Round-robin: AAA-1, Carotid-1, AAA-2, Carotid-2
     */
    public function test_diversify_interleaves_guideline_buckets(): void
    {
        $chunks = [
            ['recommendation_id' => 'A1', 'guideline' => 'AAA'],
            ['recommendation_id' => 'A2', 'guideline' => 'AAA'],
            ['recommendation_id' => 'C1', 'guideline' => 'Carotid'],
            ['recommendation_id' => 'C2', 'guideline' => 'Carotid'],
        ];

        $result = $this->svc->diversify($chunks, 'citation', 2);

        $ids = array_column($result, 'recommendation_id');
        // First two must be one from each guideline (round-robin first pass)
        $this->assertEquals(['A1', 'C1', 'A2', 'C2'], $ids);
    }

    public function test_diversify_single_guideline_returns_unchanged(): void
    {
        $chunks = [
            ['recommendation_id' => 'A1', 'guideline' => 'AAA'],
            ['recommendation_id' => 'A2', 'guideline' => 'AAA'],
        ];
        $result = $this->svc->diversify($chunks, 'citation', 1);
        $this->assertEquals($chunks, $result);
    }

    // ── selectLlmSubset ───────────────────────────────────────────────────

    public function test_select_llm_subset_single_guideline_returns_top_n(): void
    {
        $chunks = array_map(fn($i) => [
            'recommendation_id' => "R{$i}",
            'guideline'         => 'AAA',
            'text'              => "recommendation {$i}",
        ], range(1, 10));

        $result = $this->svc->selectLlmSubset($chunks, 'citation', 4, 1);

        $this->assertCount(4, $result);
        $this->assertEquals('R1', $result[0]['recommendation_id']);
    }

    public function test_select_llm_subset_multi_guideline_seeds_one_per_label(): void
    {
        $chunks = [
            ['recommendation_id' => 'A1', 'guideline' => 'AAA',     'text' => 'a1'],
            ['recommendation_id' => 'C1', 'guideline' => 'Carotid', 'text' => 'c1'],
            ['recommendation_id' => 'A2', 'guideline' => 'AAA',     'text' => 'a2'],
            ['recommendation_id' => 'C2', 'guideline' => 'Carotid', 'text' => 'c2'],
        ];

        $result = $this->svc->selectLlmSubset($chunks, 'citation', 3, 2);

        $ids = array_column($result, 'recommendation_id');
        $this->assertContains('A1', $ids, 'AAA seed must be included');
        $this->assertContains('C1', $ids, 'Carotid seed must be included');
        $this->assertCount(3, $result);
    }

    public function test_select_llm_subset_deduplicates_by_rec_id(): void
    {
        $chunks = [
            ['recommendation_id' => 'R1', 'guideline' => 'AAA', 'text' => 'first'],
            ['recommendation_id' => 'R1', 'guideline' => 'AAA', 'text' => 'duplicate'],
            ['recommendation_id' => 'R2', 'guideline' => 'AAA', 'text' => 'second'],
        ];

        $result = $this->svc->selectLlmSubset($chunks, 'citation', 5, 1);
        $ids    = array_column($result, 'recommendation_id');

        $this->assertEquals(2, count(array_unique($ids)),
            'Duplicate rec_id should be deduplicated');
    }

    // ── ensureCoverage ────────────────────────────────────────────────────

    /**
     * Python baseline:
     *  UI set has AAA and Carotid chunks.
     *  Selected (LLM) has only AAA chunks → ensureCoverage must swap one AAA
     *  for a Carotid chunk.
     */
    public function test_ensure_coverage_adds_missing_guideline(): void
    {
        $uiChunks = [
            ['recommendation_id' => 'A1', 'guideline' => 'AAA'],
            ['recommendation_id' => 'A2', 'guideline' => 'AAA'],
            ['recommendation_id' => 'A3', 'guideline' => 'AAA'],
            ['recommendation_id' => 'C1', 'guideline' => 'Carotid'],
        ];
        $selected = [
            ['recommendation_id' => 'A1', 'guideline' => 'AAA'],
            ['recommendation_id' => 'A2', 'guideline' => 'AAA'],
            ['recommendation_id' => 'A3', 'guideline' => 'AAA'],
        ];

        $result = $this->svc->ensureCoverage($uiChunks, $selected, 3);

        $labels = array_unique(array_column($result, 'guideline'));
        $this->assertContains('Carotid', $labels,
            'Carotid must appear after ensureCoverage');
        $this->assertCount(3, $result, 'Total count must stay at llm_limit');
    }

    public function test_ensure_coverage_noop_when_all_labels_present(): void
    {
        $uiChunks = [
            ['recommendation_id' => 'A1', 'guideline' => 'AAA'],
            ['recommendation_id' => 'C1', 'guideline' => 'Carotid'],
        ];
        $selected = [
            ['recommendation_id' => 'A1', 'guideline' => 'AAA'],
            ['recommendation_id' => 'C1', 'guideline' => 'Carotid'],
        ];

        $result = $this->svc->ensureCoverage($uiChunks, $selected, 4);
        $this->assertEquals($selected, $result,
            'Already-covered set must not be modified');
    }

    // ── findMustInclude ───────────────────────────────────────────────────

    /**
     * Python baseline:
     *  key_terms = ['endarterectomy']
     *  chunk_a: text contains 'endarterectomy' → score = 1 (single-word, 13 chars → +1 len bonus)
     *  chunk_b: text does not → score = 0
     *  findMustInclude returns (chunk_a, score≥1)
     */
    public function test_find_must_include_returns_highest_scoring_chunk(): void
    {
        $chunkA = ['recommendation_id' => 'A', 'guideline' => 'Carotid',
                   'text' => 'carotid endarterectomy is recommended for symptomatic stenosis'];
        $chunkB = ['recommendation_id' => 'B', 'guideline' => 'Carotid',
                   'text' => 'antiplatelet therapy is recommended after intervention'];

        [$best, $score] = $this->svc->findMustInclude([$chunkB, $chunkA], ['endarterectomy']);

        $this->assertEquals('A', $best['recommendation_id'],
            'Chunk containing the key term must be selected');
        $this->assertGreaterThan(0, $score);
    }

    public function test_find_must_include_returns_null_when_no_chunks(): void
    {
        [$best, $score] = $this->svc->findMustInclude([], ['endarterectomy']);
        $this->assertNull($best);
        $this->assertEquals(0, $score);
    }

    // ── formatRecPopup ────────────────────────────────────────────────────

    /**
     * Python baseline:
     *  Input: "rec_id:6.38; guideline_name:AAA; class:I; level:A; rec_text_verbatim:Repair is indicated"
     *  Expected: header "Recommendation 6.38 — AAA", Strength line, Text section
     */
    public function test_format_rec_popup_parses_kv_format(): void
    {
        $raw = 'rec_id:6.38; guideline_name:AAA; guideline_year:2019; ' .
               'category_name:Endovascular; class:I; level:A; ' .
               'evidence_first_authors:Smith, Jones; ' .
               'rec_text_verbatim:Repair is indicated when diameter exceeds 5.5 cm';

        $result = $this->svc->formatRecPopup($raw, 'fallback');

        $this->assertStringContainsString('Recommendation 6.38', $result);
        $this->assertStringContainsString('AAA', $result);
        $this->assertStringContainsString('2019', $result);
        $this->assertStringContainsString('Class I', $result);
        $this->assertStringContainsString('Level A', $result);
        $this->assertStringContainsString('Repair is indicated', $result);
        $this->assertStringContainsString('Category: Endovascular', $result);
    }

    public function test_format_rec_popup_uses_fallback_when_no_colons(): void
    {
        $result = $this->svc->formatRecPopup('plain text with no key value pairs', 'Fallback Title');
        $this->assertEquals('plain text with no key value pairs', $result);
    }

    public function test_format_rec_popup_empty_raw_returns_fallback(): void
    {
        $result = $this->svc->formatRecPopup('', 'Fallback Title');
        $this->assertEquals('Fallback Title', $result);
    }

    public function test_format_rec_popup_strips_author_brackets(): void
    {
        $raw = 'rec_id:1; evidence_first_authors:["Smith","Jones"]; rec_text_verbatim:test';
        $result = $this->svc->formatRecPopup($raw);
        $this->assertStringNotContainsString('[', $result);
        $this->assertStringNotContainsString('"', $result);
    }

    // ── Full select() pipeline ─────────────────────────────────────────────

    public function test_select_returns_all_six_keys(): void
    {
        $citations = [
            ['recommendation_id' => 'R1', 'guideline' => 'AAA',
             'text' => 'diameter threshold for repair', 'class' => 'I', 'level' => 'A'],
            ['recommendation_id' => 'R2', 'guideline' => 'AAA',
             'text' => 'evar is preferred in suitable anatomy', 'class' => 'I', 'level' => 'B'],
        ];
        $narratives = [
            ['content' => 'Elective repair reduces rupture risk', 'source_guideline' => 'AAA'],
        ];
        $norm = [
            'intent'           => 'threshold',
            'question_type'    => 'treatment_decision',
            'key_terms'        => ['diameter', 'evar'],
            'normalized_query' => 'threshold for aaa repair',
        ];

        $result = $this->svc->select($citations, $narratives, $norm, 1);

        $this->assertArrayHasKey('llm_citation_chunks',  $result);
        $this->assertArrayHasKey('llm_narrative_chunks', $result);
        $this->assertArrayHasKey('ui_citation_chunks',   $result);
        $this->assertArrayHasKey('ui_narrative_chunks',  $result);
        $this->assertArrayHasKey('must_include_chunk',   $result);
        $this->assertArrayHasKey('intent_profile',       $result);
    }

    public function test_select_prioritizes_definitive_treatment_citation_in_multi_guideline_case(): void
    {
        $citations = [
            [
                'recommendation_id' => '64',
                'guideline' => 'Management of Descending Thoracic and Thoraco-Abdominal Aortic Diseases',
                'text' => 'Thoracic endovascular aortic repair is recommended as the first line surgical treatment option in patients with descending thoracic aortic aneurysms.',
                'class' => 'I',
                'level' => 'B',
            ],
            [
                'recommendation_id' => '30',
                'guideline' => 'Management of Vascular Graft and Endograft Infections',
                'text' => 'In the emergency setting with active bleeding complicating thoracic/thoraco-abdominal vascular graft/endograft infection with an aorto-oesophageal fistula, initial treatment with an aortic endograft, as a bridge to definitive treatment, should be considered.',
                'class' => 'IIa',
                'level' => 'B',
            ],
            [
                'recommendation_id' => '29',
                'guideline' => 'Management of Vascular Graft and Endograft Infections',
                'text' => 'For patients with aorto-oesophageal fistula complicating thoracic/thoraco-abdominal vascular graft/endograft infection, explantation of the infected material, repair of the oesophagus, and coverage with viable tissue is recommended as definitive treatment.',
                'class' => 'I',
                'level' => 'B',
            ],
        ];

        $norm = [
            'intent' => 'management',
            'question_type' => 'treatment_decision',
            'key_terms' => [
                'aorto-oesophageal fistula',
                'infected graft',
                'definitive treatment',
                'explantation',
                'reconstruction',
            ],
            'normalized_query' => 'What is the definitive treatment after TEVAR for aorto-oesophageal fistula with infected thoracic endograft?',
        ];

        $result = $this->svc->select($citations, [], $norm, 2);

        $this->assertNotEmpty($result['llm_citation_chunks']);
        $this->assertSame('29', $result['llm_citation_chunks'][0]['recommendation_id']);
        $this->assertSame('29', $result['must_include_chunk']['recommendation_id']);
    }

    public function test_select_prioritizes_urgent_complex_aaa_recommendation_and_must_include_chunk(): void
    {
        $citations = [
            [
                'recommendation_id' => '152',
                'guideline' => 'Abdominal Aortic Aneurysm',
                'text' => 'In patients with abdominal aortic aneurysm and concomitant malignancy, a staged approach with endovascular aneurysm repair first may be considered.',
                'class' => 'IIb',
                'level' => 'C',
            ],
            [
                'recommendation_id' => '65',
                'guideline' => 'Abdominal Aortic Aneurysm',
                'text' => 'Endovascular repair is recommended as the preferred treatment modality in patients with suitable anatomy undergoing elective abdominal aortic aneurysm repair.',
                'class' => 'I',
                'level' => 'A',
            ],
            [
                'recommendation_id' => '83',
                'guideline' => 'Management of Descending Thoracic and Thoraco-Abdominal Aortic Diseases',
                'text' => 'For patients with ruptured thoraco-abdominal aortic aneurysm, endovascular repair with off the shelf branched stent grafts physician modified endografts or in situ fenestration should be considered when feasible.',
                'class' => 'IIa',
                'level' => 'C',
            ],
            [
                'recommendation_id' => '129',
                'guideline' => 'Abdominal Aortic Aneurysm',
                'text' => 'For patients with ruptured complex abdominal aortic aneurysm or urgent repair for any other reason, open surgical repair or endovascular repair with off the shelf branched stent grafts physician modified endografts or in situ fenestration may be considered.',
                'class' => 'IIb',
                'level' => 'C',
            ],
        ];

        $norm = [
            'intent' => 'management',
            'question_type' => 'treatment_decision',
            'key_terms' => [
                'juxtarenal aneurysm',
                'symptomatic',
                'impending rupture',
                'open repair',
                'endovascular repair',
            ],
            'normalized_query' => 'symptomatic juxtarenal aneurysm 6 cm impending rupture stable urgent management open repair versus endovascular repair',
        ];

        $result = $this->svc->select($citations, [], $norm, 2);
        $ids = array_column($result['llm_citation_chunks'], 'recommendation_id');

        $this->assertNotEmpty($ids);
        $this->assertSame('129', $ids[0]);
        $this->assertContains('83', $ids);
        $this->assertSame('129', $result['must_include_chunk']['recommendation_id']);
        $this->assertLessThan(array_search('152', $ids, true), array_search('129', $ids, true));
    }

    public function test_select_llm_subset_not_larger_than_ui_subset(): void
    {
        $citations = array_map(fn($i) => [
            'recommendation_id' => "R{$i}",
            'guideline'         => 'AAA',
            'text'              => "recommendation {$i}",
            'class'             => 'I',
            'level'             => 'A',
        ], range(1, 15));
        $norm = ['intent' => 'threshold', 'question_type' => '', 'key_terms' => [], 'normalized_query' => ''];

        $result = $this->svc->select($citations, [], $norm, 1);

        $this->assertLessThanOrEqual(
            count($result['ui_citation_chunks']),
            count($result['llm_citation_chunks']),
            'LLM tier must not exceed UI tier'
        );
    }
}
