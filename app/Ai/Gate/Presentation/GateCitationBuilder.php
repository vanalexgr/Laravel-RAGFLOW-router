<?php

namespace App\Ai\Gate\Presentation;

/**
 * Deterministic citation projection over retrieved, cleaned snippet digests.
 *
 * The model never supplies citation identities to this class. Recommendation
 * citations can only originate in the citation bucket; narrative excerpts are
 * retained as narrative citations so the UI can show everything processed.
 */
final class GateCitationBuilder
{
    /**
     * @param  array<string, array<int, array<string, mixed>>>  $snippetDigests
     * @return array<int, array<string, mixed>>
     */
    public function build(array $snippetDigests): array
    {
        $citations = [];
        $seen = [];

        foreach ($snippetDigests as $guidelineKey => $snippets) {
            foreach ($snippets as $snippet) {
                if (! is_array($snippet)) {
                    continue;
                }

                $bucket = ($snippet['bucket'] ?? null) === 'citation'
                    ? 'citation'
                    : 'narrative';
                $metadata = (array) ($snippet['metadata'] ?? []);
                $text = $this->displayText(
                    (string) ($snippet['text'] ?? ''),
                    $bucket === 'citation',
                );
                if ($text === '') {
                    continue;
                }

                $guideline = trim((string) (
                    $metadata['guideline']
                    ?? $snippet['source']
                    ?? $guidelineKey
                ));
                $recommendationId = $bucket === 'citation'
                    ? $this->nullableString($metadata['recommendation_id'] ?? null)
                    : null;
                $class = $bucket === 'citation'
                    ? $this->nullableString($metadata['recommendation_class'] ?? null)
                    : null;
                $level = $bucket === 'citation'
                    ? $this->nullableString($metadata['evidence_level'] ?? null)
                    : null;
                $kind = $bucket === 'citation' ? 'recommendation' : 'narrative';
                $dedupeKey = hash('sha256', implode("\0", [
                    $kind,
                    $guideline,
                    $recommendationId ?? '',
                    $text,
                ]));
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;

                $id = (string) (count($citations) + 1);
                $title = $kind === 'recommendation'
                    ? $this->recommendationTitle($recommendationId, $guideline)
                    : $this->narrativeTitle($guideline);

                $citations[] = [
                    'id' => $id,
                    'kind' => $kind,
                    'title' => $title,
                    'document' => $kind === 'recommendation'
                        ? $this->recommendationPopup(
                            $recommendationId,
                            $guideline,
                            $class,
                            $level,
                            $text,
                            $metadata,
                        )
                        : $text,
                    'metadata' => [
                        'guideline' => $guideline,
                        'recommendation_id' => $recommendationId,
                        'class' => $class,
                        'level' => $level,
                    ],
                ];
            }
        }

        return $citations;
    }

    private function displayText(string $text, bool $hasIdentityHeader): string
    {
        // RetrieveEsvsSnippetsTool prepends a deterministic identity header to
        // recommendation text. It is useful to agents but redundant in a popup.
        if (! $hasIdentityHeader) {
            return trim($text);
        }

        return trim((string) preg_replace('/^\[[^\]\r\n]+\]\s*(?:\r?\n)?/u', '', trim($text), 1));
    }

    private function recommendationTitle(?string $id, string $guideline): string
    {
        $label = $id === null ? 'Retrieved recommendation' : 'Recommendation '.$id;

        return $guideline === '' ? $label : $label.' — '.$guideline;
    }

    private function narrativeTitle(string $guideline): string
    {
        return $guideline === '' ? 'Guideline narrative excerpt' : $guideline.' — narrative excerpt';
    }

    /**
     * @param  array<string, mixed>  $sourceMetadata
     */
    private function recommendationPopup(
        ?string $id,
        string $guideline,
        ?string $class,
        ?string $level,
        string $text,
        array $sourceMetadata,
    ): string {
        $lines = [$this->recommendationTitle($id, $guideline)];

        $category = $this->nullableString($sourceMetadata['category_name'] ?? null);
        if ($category !== null) {
            $lines[] = 'Category: '.$category;
        }

        $strength = [];
        if ($class !== null) {
            $strength[] = 'Class '.$class;
        }
        if ($level !== null) {
            $strength[] = 'Level '.$level;
        }
        if ($strength !== []) {
            $lines[] = 'Strength: '.implode('; ', $strength);
        }

        $lines[] = '';
        $lines[] = $text;

        return implode("\n", $lines);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== ''
            ? trim((string) $value)
            : null;
    }
}
