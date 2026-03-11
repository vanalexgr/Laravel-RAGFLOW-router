<?php

namespace Tests\Feature;

use App\Services\RetrievalService;
use Tests\TestCase;

class RetrievalServiceFocusTest extends TestCase
{
    protected function makeService(): RetrievalService
    {
        return new class extends RetrievalService
        {
            public function pruneForTest(array $selectedGuidelines, string $question): array
            {
                return $this->pruneSelectedGuidelines($selectedGuidelines, $question);
            }

            public function needsAntithromboticForTest(string $question): bool
            {
                return $this->queryNeedsAntithromboticCompanion($question);
            }

            public function boostForTest(string $query, array $selectedGuidelines, string $channel = 'narrative', bool $definitionIntent = false): string
            {
                return $this->applyRetrievalQueryBoosts($query, $selectedGuidelines, $channel, $definitionIntent);
            }
        };
    }

    public function test_it_prunes_antithrombotic_for_broad_carotid_management_question(): void
    {
        $service = $this->makeService();

        $selected = [
            'carotid_vertebral' => ['name' => 'Carotid & Vertebral'],
            'antithrombotic_therapy' => ['name' => 'Antithrombotic Therapy'],
        ];

        $pruned = $service->pruneForTest(
            $selected,
            "Patient following major stroke due to carotid stenosis. He hasn't yet mobilised. What is the best management?"
        );

        $this->assertArrayHasKey('carotid_vertebral', $pruned);
        $this->assertArrayNotHasKey('antithrombotic_therapy', $pruned);
    }

    public function test_it_keeps_antithrombotic_when_anticoagulation_is_explicitly_asked(): void
    {
        $service = $this->makeService();

        $selected = [
            'carotid_vertebral' => ['name' => 'Carotid & Vertebral'],
            'antithrombotic_therapy' => ['name' => 'Antithrombotic Therapy'],
        ];

        $pruned = $service->pruneForTest(
            $selected,
            'Stroke patient needs urgent carotid plan but also has acute proximal DVT on ultrasound. How to balance anticoagulation needs with CEA timing?'
        );

        $this->assertArrayHasKey('antithrombotic_therapy', $pruned);
        $this->assertTrue($service->needsAntithromboticForTest('How should we manage aspirin, clopidogrel, and peri-procedural anticoagulation around CEA?'));
    }

    public function test_it_adds_disabling_stroke_boost_terms_for_carotid_queries(): void
    {
        config([
            'ragflow.retrieval.query_boosts.enabled' => true,
            'ragflow.retrieval.query_boosts.carotid_disabling_stroke_enabled' => true,
        ]);

        $service = $this->makeService();
        $query = "Patient following major stroke due to carotid stenosis. He hasn't yet mobilised. What is the best management?";

        $boosted = $service->boostForTest($query, ['carotid_vertebral'], 'narrative', false);

        $this->assertStringContainsString('disabling stroke', $boosted);
        $this->assertStringContainsString('modified Rankin Scale', $boosted);
        $this->assertStringContainsString('defer carotid intervention', $boosted);
    }

    public function test_it_does_not_add_disabling_stroke_boosts_for_tia_or_minor_stroke_queries(): void
    {
        config([
            'ragflow.retrieval.query_boosts.enabled' => true,
            'ragflow.retrieval.query_boosts.carotid_disabling_stroke_enabled' => true,
        ]);

        $service = $this->makeService();
        $query = '63F had TIA yesterday with symptomatic carotid stenosis 70% (NASCET) — recommended timing of CEA vs CAS?';

        $boosted = $service->boostForTest($query, ['carotid_vertebral'], 'narrative', false);

        $this->assertSame($query, $boosted);
    }
}
