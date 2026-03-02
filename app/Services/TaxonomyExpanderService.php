<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class TaxonomyExpanderService
{
    protected static ?array $index = null;

    public function enabled(): bool
    {
        return (bool) config('taxonomy.enabled', false);
    }

    public function expand(string $query): array
    {
        $query = trim($query);
        if ($query === '' || !$this->enabled()) {
            return [
                'terms' => [],
                'matched_terms' => [],
                'matched_tags' => [],
            ];
        }

        $index = $this->loadIndex();
        if (empty($index['term_to_tags'])) {
            return [
                'terms' => [],
                'matched_terms' => [],
                'matched_tags' => [],
            ];
        }

        $queryLower = mb_strtolower($query);
        $tokens = $this->tokenize($queryLower);
        $candidates = $this->buildNgrams($tokens, 4);

        $matchedTerms = [];
        $tagScores = [];
        foreach ($candidates as $term) {
            if (!isset($index['term_to_tags'][$term])) {
                continue;
            }
            $matchedTerms[$term] = true;
            foreach ($index['term_to_tags'][$term] as $tag) {
                $tagScores[$tag] = ($tagScores[$tag] ?? 0) + 1;
            }
        }

        if (empty($tagScores)) {
            return [
                'terms' => [],
                'matched_terms' => [],
                'matched_tags' => [],
            ];
        }

        arsort($tagScores);
        $maxTags = (int) config('taxonomy.max_tags', 3);
        $selectedTags = array_slice(array_keys($tagScores), 0, max(1, $maxTags));

        $stopTokens = $this->buildStopTokenSet();
        $maxTerms = (int) config('taxonomy.max_terms', 8);
        $maxPerTag = (int) config('taxonomy.max_terms_per_tag', 4);

        $added = [];
        foreach ($selectedTags as $tag) {
            if (count($added) >= $maxTerms) {
                break;
            }
            $terms = $index['tag_to_terms'][$tag] ?? [];
            if (empty($terms)) {
                continue;
            }

            $anchorTokens = $this->anchorTokensFromMatchedTerms($matchedTerms, $stopTokens);
            if (empty($anchorTokens)) {
                continue;
            }

            $ranked = $this->rankRelatedTerms($terms, $anchorTokens, $queryLower);
            $picked = 0;
            foreach ($ranked as $term) {
                if (count($added) >= $maxTerms || $picked >= $maxPerTag) {
                    break;
                }
                if (isset($added[$term])) {
                    continue;
                }
                if (str_contains($queryLower, $term)) {
                    continue;
                }
                $added[$term] = true;
                $picked++;
            }
        }

        return [
            'terms' => array_keys($added),
            'matched_terms' => array_keys($matchedTerms),
            'matched_tags' => $selectedTags,
        ];
    }

    protected function loadIndex(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $path = (string) config('taxonomy.path', '');
        if ($path !== '' && !str_starts_with($path, '/')) {
            $path = base_path($path);
        }
        if ($path === '' || !is_file($path)) {
            Log::channel('retrieval')->warning('[TAXONOMY] Taxonomy file missing', ['path' => $path]);
            self::$index = ['term_to_tags' => [], 'tag_to_terms' => []];
            return self::$index;
        }

        $excluded = array_fill_keys((array) config('taxonomy.excluded_tags', []), true);
        $minLen = (int) config('taxonomy.min_term_len', 5);
        $minWords = (int) config('taxonomy.min_words', 2);
        $stopTerms = array_fill_keys((array) config('taxonomy.stop_terms', []), true);

        $termToTags = [];
        $tagToTerms = [];

        if (($handle = fopen($path, 'r')) !== false) {
            $header = fgetcsv($handle);
            if ($header === false) {
                fclose($handle);
                self::$index = ['term_to_tags' => [], 'tag_to_terms' => []];
                return self::$index;
            }
            $descIdx = array_search('Description', $header, true);
            $tagIdx = array_search('Tag', $header, true);

            while (($row = fgetcsv($handle)) !== false) {
                $desc = $descIdx !== false ? ($row[$descIdx] ?? '') : '';
                $tag = $tagIdx !== false ? ($row[$tagIdx] ?? '') : '';
                $tag = trim((string) $tag);
                if ($tag === '' || isset($excluded[$tag])) {
                    continue;
                }
                $parts = array_map('trim', explode(';', (string) $desc));
                foreach ($parts as $raw) {
                    $term = $this->normalizeTerm($raw);
                    if ($term === '') {
                        continue;
                    }
                    if (isset($stopTerms[$term])) {
                        continue;
                    }
                    if (mb_strlen($term) < $minLen) {
                        continue;
                    }
                    $wordCount = count($this->tokenize($term));
                    if ($wordCount < $minWords && mb_strlen($term) < ($minLen + 3)) {
                        continue;
                    }
                    if (preg_match('/^[\d\.\-]+$/', $term)) {
                        continue;
                    }
                    $termToTags[$term][$tag] = true;
                    $tagToTerms[$tag][$term] = true;
                }
            }
            fclose($handle);
        }

        foreach ($termToTags as $term => $tags) {
            $termToTags[$term] = array_keys($tags);
        }
        foreach ($tagToTerms as $tag => $terms) {
            $tagToTerms[$tag] = array_keys($terms);
        }

        self::$index = ['term_to_tags' => $termToTags, 'tag_to_terms' => $tagToTerms];
        return self::$index;
    }

    protected function normalizeTerm(string $term): string
    {
        $term = html_entity_decode($term, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $term = trim($term, " \t\n\r\0\x0B\"'`");
        $term = preg_replace('/\s+/', ' ', $term);
        $term = preg_replace('/[\p{P}\p{S}]+$/u', '', $term);
        $term = mb_strtolower($term);
        return trim($term);
    }

    protected function tokenize(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter($tokens, fn($t) => $t !== ''));
    }

    protected function buildNgrams(array $tokens, int $maxN = 4): array
    {
        $out = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (mb_strlen($token) >= (int) config('taxonomy.min_term_len', 5)) {
                $out[] = $token;
            }
            for ($n = 2; $n <= $maxN; $n++) {
                if ($i + $n > $count) {
                    break;
                }
                $phrase = implode(' ', array_slice($tokens, $i, $n));
                $out[] = $phrase;
            }
        }
        return array_values(array_unique($out));
    }

    protected function buildStopTokenSet(): array
    {
        $stop = [
            'aorta', 'aortic', 'aneurysm', 'aneurysms', 'thoracic', 'abdominal', 'artery', 'arteries',
            'disease', 'syndrome', 'patient', 'patients', 'management', 'strategy', 'optimal',
        ];
        foreach ((array) config('taxonomy.stop_terms', []) as $term) {
            $stop[] = $term;
        }
        $out = [];
        foreach ($stop as $term) {
            $out[mb_strtolower(trim((string) $term))] = true;
        }
        return $out;
    }

    protected function anchorTokensFromMatchedTerms(array $matchedTerms, array $stopTokens): array
    {
        $anchors = [];
        foreach (array_keys($matchedTerms) as $term) {
            $tokens = $this->tokenize($term);
            foreach ($tokens as $token) {
                if (!isset($stopTokens[$token])) {
                    $anchors[$token] = true;
                }
            }
        }
        return array_keys($anchors);
    }

    protected function rankRelatedTerms(array $terms, array $anchorTokens, string $queryLower): array
    {
        $scored = [];
        foreach ($terms as $term) {
            $termLower = mb_strtolower($term);
            if ($termLower === '' || str_contains($queryLower, $termLower)) {
                continue;
            }
            $termTokens = $this->tokenize($termLower);
            $overlap = 0;
            foreach ($anchorTokens as $token) {
                if (in_array($token, $termTokens, true)) {
                    $overlap++;
                }
            }
            if ($overlap === 0) {
                continue;
            }
            $score = $overlap * 3;
            if (count($termTokens) >= 2) {
                $score += 2;
            }
            if (mb_strlen($termLower) > 10) {
                $score += 1;
            }
            $scored[] = [$score, $termLower, $term];
        }

        usort($scored, function ($a, $b) {
            if ($a[0] === $b[0]) {
                return strcmp($a[1], $b[1]);
            }
            return $b[0] <=> $a[0];
        });

        return array_map(fn($row) => $row[2], $scored);
    }
}
