<?php

namespace App\Ai\Gate\Presentation;

use App\Services\GuidelineAssetService;

final class GateAssetPresenter
{
    public function __construct(
        private readonly GuidelineAssetService $assets,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $citations
     * @param  array<int, string>  $routedGuidelines
     * @return array<int, array{url: ?string, thumbnail_url: ?string, label: ?string, caption: ?string, guideline_key: ?string}>
     */
    public function build(string $question, array $citations, array $routedGuidelines): array
    {
        $narrative = [];
        $recommendations = [];
        foreach ($citations as $citation) {
            $chunk = [
                'content' => (string) ($citation['document'] ?? ''),
                'source_guideline' => (string) ($citation['metadata']['guideline'] ?? ''),
            ];
            if (($citation['kind'] ?? null) === 'narrative') {
                $narrative[] = $chunk;
            } else {
                $recommendations[] = $chunk;
            }
        }

        $selected = [];
        foreach ($routedGuidelines as $key) {
            $selected[(string) $key] = ['id' => (string) $key, 'name' => (string) $key];
        }
        if ($selected === []) {
            return [];
        }

        return array_values(array_map(
            static fn (array $asset): array => [
                'url' => isset($asset['url']) ? (string) $asset['url'] : null,
                'thumbnail_url' => isset($asset['thumbnail_url']) ? (string) $asset['thumbnail_url'] : null,
                'label' => isset($asset['label']) ? (string) $asset['label'] : null,
                'caption' => isset($asset['caption']) ? (string) $asset['caption'] : null,
                'guideline_key' => isset($asset['guideline_key']) ? (string) $asset['guideline_key'] : null,
            ],
            $this->assets->findRelevantAssets(
                $question,
                $narrative,
                $recommendations,
                $selected,
                array_keys($selected),
            ),
        ));
    }
}
