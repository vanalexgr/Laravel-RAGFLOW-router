<?php

namespace App\Ai\Gate\Retrieval;

final class GateChunkCleaner
{
    /**
     * @return array{text: string, metadata: array<string, string>, raw_chars: int, clean_chars: int, signal_ratio: float}
     */
    public function clean(mixed $chunk, int $maxChars = 3000): array
    {
        $chunk = is_array($chunk) ? $chunk : ['text' => (string) $chunk];
        $raw = trim((string) ($chunk['content'] ?? $chunk['text'] ?? ''));
        $metadata = $this->structuredMetadata($chunk, $raw);
        $clinicalText = $metadata['rec_text_verbatim'] ?? $raw;
        $clean = $this->cleanNarrativeText($clinicalText);
        $clean = $this->truncateForLlm($clean, $maxChars);
        $signalChars = mb_strlen(str_replace('[...truncated...]', '', $clean));
        $cleanChars = mb_strlen($clean);

        return [
            'text' => $clean,
            'metadata' => $metadata,
            'raw_chars' => mb_strlen($raw),
            'clean_chars' => $cleanChars,
            'signal_ratio' => $cleanChars === 0 ? 0.0 : $signalChars / $cleanChars,
        ];
    }

    public function truncateForLlm(string $text, int $maxChars): string
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, max(1, $maxChars - 20)))."\n\n[...truncated...]";
    }

    public function htmlTableToText(string $html): string
    {
        if ($html === '' || stripos($html, '<table') === false) {
            return '';
        }
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/isu', $html, $rows);
        $output = [];
        foreach ($rows[1] ?? [] as $row) {
            preg_match_all('/<t[hd][^>]*>(.*?)<\/t[hd]>/isu', $row, $cells);
            $cleaned = [];
            foreach ($cells[1] ?? [] as $cell) {
                $cell = html_entity_decode(strip_tags((string) $cell), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $cell = preg_replace('/\s+/u', ' ', trim($cell)) ?? trim($cell);
                if ($cell !== '') {
                    $cleaned[] = $cell;
                }
            }
            if ($cleaned !== []) {
                $output[] = implode(' | ', $cleaned);
            }
        }

        return trim(implode("\n", $output));
    }

    public function cleanNarrativeText(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $text = preg_replace_callback(
            '/<table[^>]*>.*?<\/table>/isu',
            fn (array $match): string => $this->htmlTableToText($match[0]),
            $text,
        ) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $text) ?? $text;
        $text = preg_replace('/`([^`]+)`/u', '$1', $text) ?? $text;
        $text = preg_replace('/(?m)^\s{0,3}#{1,6}\s+/u', '', $text) ?? $text;
        $text = preg_replace('/(?m)^\s{0,3}>\s?/u', '', $text) ?? $text;
        $text = preg_replace('/\*\*([^*]+)\*\*/u', '$1', $text) ?? $text;
        $text = preg_replace('/__([^_]+)__/u', '$1', $text) ?? $text;
        $text = preg_replace('/(?<!\*)\*([^\s*][^*]*[^\s*])\*(?!\*)/u', '$1', $text) ?? $text;
        $text = preg_replace('/(?<!_)_([^\s_][^_]*[^\s_])_(?!_)/u', '$1', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /** @return array<string, string> */
    public function parseSemicolonKv(string $text): array
    {
        $metadata = [];
        if ($text === '' || ! str_contains($text, ':')) {
            return $metadata;
        }
        foreach (explode(';', $text) as $part) {
            if (! str_contains($part, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $part, 2));
            if ($key !== '' && $value !== '') {
                $metadata[$key] = $value;
            }
        }
        return $metadata;
    }

    /**
     * @param array<string, mixed> $chunk
     * @return array<string, string>
     */
    private function structuredMetadata(array $chunk, string $raw): array
    {
        $parsed = $this->parseSemicolonKv($raw);
        $aliases = [
            'recommendation_id' => ['recommendation_id', 'rec_id'],
            'category_name' => ['category_name', 'category'],
            'recommendation_class' => ['recommendation_class', 'class'],
            'evidence_level' => ['evidence_level', 'level'],
            'guideline' => ['guideline', 'guideline_name', 'source_guideline'],
            'rec_text_verbatim' => ['rec_text_verbatim'],
        ];
        $metadata = [];
        foreach ($aliases as $target => $sources) {
            foreach ($sources as $source) {
                $value = $chunk[$source] ?? $parsed[$source] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $metadata[$target] = trim((string) $value);
                    break;
                }
            }
        }

        return $metadata;
    }
}
