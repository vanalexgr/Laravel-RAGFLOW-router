<?php

namespace App\Ai\Gate\Presentation;

use App\Ai\Gate\Retrieval\GateChunkCleaner;

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
     * Deduplicate and number the exact snippets that will be supplied to an
     * answering agent. These IDs are the sole marker namespace for the answer.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $snippetDigests
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function numberForPrompt(array $snippetDigests): array
    {
        $numbered = [];
        $seen = [];
        $nextId = 1;

        foreach ($snippetDigests as $guidelineKey => $snippets) {
            foreach ($snippets as $snippet) {
                if (! is_array($snippet)) {
                    continue;
                }

                $identity = $this->identity($guidelineKey, $snippet);
                if ($identity === null || isset($seen[$identity['dedupe_key']])) {
                    continue;
                }
                $seen[$identity['dedupe_key']] = true;

                $snippet['citation_id'] = (string) $nextId++;
                $numbered[$guidelineKey][] = $snippet;
            }
        }

        return $numbered;
    }

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

                $identity = $this->identity($guidelineKey, $snippet);
                if ($identity === null) {
                    continue;
                }

                if (isset($seen[$identity['dedupe_key']])) {
                    continue;
                }
                $seen[$identity['dedupe_key']] = true;

                $id = $this->nullableString($snippet['citation_id'] ?? null)
                    ?? (string) (count($citations) + 1);
                $title = $identity['kind'] === 'recommendation'
                    ? $this->recommendationTitle($identity['recommendation_id'], $identity['guideline'])
                    : $this->narrativeTitle($identity['guideline']);

                $citations[] = [
                    'id' => $id,
                    'kind' => $identity['kind'],
                    'title' => $title,
                    'document' => $identity['kind'] === 'recommendation'
                        ? $this->recommendationPopup(
                            $identity['recommendation_id'],
                            $identity['guideline'],
                            $identity['class'],
                            $identity['level'],
                            $identity['text'],
                            $identity['metadata'],
                        )
                        : $identity['text'],
                    'metadata' => [
                        'guideline' => $identity['guideline'],
                        'recommendation_id' => $identity['recommendation_id'],
                        'class' => $identity['class'],
                        'level' => $identity['level'],
                    ],
                ];
            }
        }

        return $citations;
    }

    /**
     * @return array{
     *   kind: string,
     *   guideline: string,
     *   recommendation_id: ?string,
     *   class: ?string,
     *   level: ?string,
     *   text: string,
     *   metadata: array<string, mixed>,
     *   dedupe_key: string
     * }|null
     */
    private function identity(string $guidelineKey, array $snippet): ?array
    {
        $bucket = ($snippet['bucket'] ?? null) === 'citation'
            ? 'citation'
            : 'narrative';
        $metadata = (array) ($snippet['metadata'] ?? []);
        $text = $this->displayText(
            (string) ($snippet['text'] ?? ''),
            $bucket === 'citation',
        );
        if ($text === '') {
            return null;
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
            ? $this->canonicalClass($metadata['recommendation_class'] ?? null)
            : null;
        $level = $bucket === 'citation'
            ? $this->canonicalLevel($metadata['evidence_level'] ?? null)
            : null;
        $kind = $bucket === 'citation' ? 'recommendation' : 'narrative';

        return [
            'kind' => $kind,
            'guideline' => $guideline,
            'recommendation_id' => $recommendationId,
            'class' => $class,
            'level' => $level,
            'text' => $text,
            'metadata' => $metadata,
            'dedupe_key' => hash('sha256', implode("\0", [
                $kind,
                $guideline,
                $recommendationId ?? '',
                $text,
            ])),
        ];
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

    private function canonicalClass(mixed $value): ?string
    {
        return GateChunkCleaner::normaliseClass($this->nullableString($value) ?? '');
    }

    private function canonicalLevel(mixed $value): ?string
    {
        return GateChunkCleaner::normaliseLevel($this->nullableString($value) ?? '');
    }
}
