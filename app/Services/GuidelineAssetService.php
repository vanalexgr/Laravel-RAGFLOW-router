<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GuidelineAssetService
{
    /**
     * Return a list of assets (figures/tables) relevant to the retrieved chunks.
     *
     * Assets are intended for user display only. They are not "evidence" and
     * should not be quoted as text.
     *
     * @param string $question
     * @param array $narrativeChunks RetrievalService formatted narrative chunks
     * @param array $citationChunks RetrievalService formatted citation chunks (unused currently)
     * @param array $selectedGuidelines key => ['id'=>..., 'name'=>...]
     * @return array
     */
    public function findRelevantAssets(
        string $question,
        array $narrativeChunks,
        array $citationChunks,
        array $selectedGuidelines,
        array $preferredGuidelineKeys = []
    ): array {
        $maxAssets = (int) (config('guideline_assets.max_assets', 3) ?: 3);
        if ($maxAssets <= 0) {
            return [];
        }

        $manifest = $this->loadManifest();
        if (empty($manifest)) {
            return [];
        }

        $selectedKeys = array_keys($selectedGuidelines);
        $preferredGuidelineKeys = array_values(array_unique(array_filter($preferredGuidelineKeys)));
        $scopedKeys = !empty($preferredGuidelineKeys)
            ? array_values(array_intersect($selectedKeys, $preferredGuidelineKeys))
            : $selectedKeys;
        if (empty($scopedKeys)) {
            $scopedKeys = $selectedKeys;
        }
        $nameToKey = $this->buildGuidelineNameToKeyMap();

        $results = [];
        $seen = [];

        // 1) Strong match: explicit "Figure X" / "Table Y" references in narrative chunks.
        foreach ($narrativeChunks as $chunk) {
            $content = (string) ($chunk['content'] ?? '');
            if ($content === '') {
                continue;
            }

            $sourceName = (string) ($chunk['source_guideline'] ?? '');
            $sourceKey = $this->mapSourceGuidelineToKey($sourceName, $nameToKey, $scopedKeys);
            if ($sourceKey === null) {
                continue;
            }

            $refs = $this->extractAssetReferences($content);
            if (empty($refs)) {
                continue;
            }

            foreach ($refs as $ref) {
                $asset = $this->lookupByReference($manifest, $sourceKey, $ref);
                if (!$asset) {
                    continue;
                }

                $asset = $this->hydrateAsset($asset, $sourceKey);
                $assetId = (string) ($asset['id'] ?? ($asset['url'] ?? ''));
                if ($assetId === '' || isset($seen[$assetId])) {
                    continue;
                }

                $seen[$assetId] = true;
                $results[] = $asset;

                if (count($results) >= $maxAssets) {
                    return $results;
                }
            }
        }

        // 2) Fallback/supplement: keyword overlap (caption/keywords) scoped to selected guidelines.
        // Run this both when there are no explicit refs and when explicit refs produced fewer
        // than max assets, so we can fill remaining slots with intent-matching visuals.
        if (count($results) < $maxAssets && (bool) config('guideline_assets.enable_keyword_fallback', true)) {
            $contextText = implode(
                "\n",
                array_map(fn ($c) => (string) ($c['content'] ?? ''), array_slice($narrativeChunks, 0, 6))
            );
            $questionBag = $this->tokenBag($question);
            $contextBag = $this->tokenBag($contextText);
            $questionRefs = $this->extractAssetReferences($question);

            $idfIndex = $this->buildScopedIdfIndex($manifest, $scopedKeys);
            $tokenDf = $idfIndex['df'];
            $tokenDocs = $idfIndex['docs'];

            $scored = [];
            foreach ($scopedKeys as $key) {
                $assets = $manifest[$key] ?? [];
                foreach ($assets as $asset) {
                    $assetBag = $this->tokenBag($this->assetSearchText($asset));
                    if (empty($assetBag)) {
                        continue;
                    }

                    $questionKeywordScore = $this->keywordScore($questionBag, $asset);
                    $contextKeywordScore = $this->keywordScore($contextBag, $asset);
                    $directRefScore = $this->directReferenceScore($asset, $questionRefs);

                    $questionTokenScore = 0.0;
                    foreach (array_keys($questionBag) as $token) {
                        if (!isset($assetBag[$token])) {
                            continue;
                        }
                        $questionTokenScore += $this->idfWeight($token, $tokenDf, $tokenDocs);
                    }

                    // Prioritize the user question strongly over broad narrative context.
                    $querySignal = ($questionKeywordScore * 2.0) + $questionTokenScore + $directRefScore;
                    $totalScore = (int) round($querySignal * 100.0) + $contextKeywordScore;
                    if ($totalScore <= 0) {
                        continue;
                    }

                    $scored[] = [
                        'score' => $totalScore,
                        'query_signal' => $querySignal,
                        'context_score' => $contextKeywordScore,
                        'asset' => $asset,
                        'guideline_key' => $key,
                    ];
                }
            }

            usort($scored, function ($a, $b) {
                $queryCmp = $b['query_signal'] <=> $a['query_signal'];
                if ($queryCmp !== 0) {
                    return $queryCmp;
                }

                $scoreCmp = $b['score'] <=> $a['score'];
                if ($scoreCmp !== 0) {
                    return $scoreCmp;
                }

                return strcmp((string) ($a['asset']['id'] ?? ''), (string) ($b['asset']['id'] ?? ''));
            });

            if ((bool) config('guideline_assets.log_scoring', false) && !empty($scored)) {
                Log::info('[GUIDELINE ASSETS] Scored fallback candidates', [
                    'question' => $question,
                    'selected_guidelines' => $scopedKeys,
                    'top' => array_map(function ($row) {
                        return [
                            'id' => $row['asset']['id'] ?? null,
                            'label' => $row['asset']['label'] ?? null,
                            'guideline_key' => $row['guideline_key'],
                            'query_signal' => $row['query_signal'],
                            'context_score' => $row['context_score'],
                            'total_score' => $row['score'],
                        ];
                    }, array_slice($scored, 0, 12)),
                ]);
            }

            foreach ($scored as $row) {
                $asset = $this->hydrateAsset($row['asset'], $row['guideline_key']);
                $assetId = (string) ($asset['id'] ?? ($asset['url'] ?? ''));
                if ($assetId === '' || isset($seen[$assetId])) {
                    continue;
                }
                $seen[$assetId] = true;
                $results[] = $asset;
                if (count($results) >= $maxAssets) {
                    break;
                }
            }
        }

        return $results;
    }

    protected function loadManifest(): array
    {
        $path = (string) config('guideline_assets.manifest_path');
        if ($path === '' || !is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            Log::warning('[GUIDELINE ASSETS] Manifest JSON invalid', ['path' => $path]);
            return [];
        }

        // Expected shape: { "guideline_key": [ {asset...}, ... ], ... }
        return $decoded;
    }

    protected function buildGuidelineNameToKeyMap(): array
    {
        $categories = config('guidelines.categories', []);
        $map = [];

        foreach ($categories as $category) {
            foreach (($category['guidelines'] ?? []) as $key => $info) {
                $name = strtolower((string) ($info['name'] ?? ''));
                if ($name !== '') {
                    $map[$name] = $key;
                }
            }
        }

        return $map;
    }

    protected function mapSourceGuidelineToKey(string $sourceName, array $nameToKey, array $selectedKeys): ?string
    {
        $sourceName = trim($sourceName);
        if ($sourceName === '') {
            return null;
        }

        // Prefer exact display-name to key mapping.
        $k = $nameToKey[strtolower($sourceName)] ?? null;
        if ($k && in_array($k, $selectedKeys, true)) {
            return $k;
        }

        // If the router selected only one guideline, treat that as scope.
        if (count($selectedKeys) === 1) {
            return $selectedKeys[0];
        }

        return null;
    }

    /**
     * Extract explicit references like "Figure 3", "Fig. 2", "Table 1", "Algorithm 4".
     * Returns normalized labels like "figure 3", "table 1".
     */
    protected function extractAssetReferences(string $text): array
    {
        $out = [];

        $patterns = [
            // Figure/Fig.
            '/\b(fig(?:ure)?\.?)\s*([0-9]{1,3}(?:\.[0-9]{1,2})?[A-Za-z]?)\b/i',
            // Table
            '/\b(table)\s*([0-9]{1,3}(?:\.[0-9]{1,2})?[A-Za-z]?)\b/i',
            // Algorithm (often used for flowcharts)
            '/\b(algorithm)\s*([0-9]{1,3}(?:\.[0-9]{1,2})?[A-Za-z]?)\b/i',
        ];

        foreach ($patterns as $p) {
            if (preg_match_all($p, $text, $m, PREG_SET_ORDER)) {
                foreach ($m as $match) {
                    $kind = strtolower($match[1]);
                    if ($kind === 'fig' || str_starts_with($kind, 'fig')) {
                        $kind = 'figure';
                    }
                    $num = strtolower($match[2]);
                    $out[] = "{$kind} {$num}";
                }
            }
        }

        // De-dupe while preserving order.
        $uniq = [];
        $seen = [];
        foreach ($out as $x) {
            if (!isset($seen[$x])) {
                $seen[$x] = true;
                $uniq[] = $x;
            }
        }

        return $uniq;
    }

    protected function lookupByReference(array $manifest, string $guidelineKey, string $normalizedRef): ?array
    {
        $assets = $manifest[$guidelineKey] ?? null;
        if (!is_array($assets) || empty($assets)) {
            return null;
        }

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $label = strtolower(trim((string) ($asset['label'] ?? '')));
            if ($label !== '' && $this->normalizeLabel($label) === $normalizedRef) {
                return $asset;
            }

            $aliases = $asset['aliases'] ?? [];
            if (is_array($aliases)) {
                foreach ($aliases as $alias) {
                    $a = strtolower(trim((string) $alias));
                    if ($a !== '' && $this->normalizeLabel($a) === $normalizedRef) {
                        return $asset;
                    }
                }
            }
        }

        return null;
    }

    protected function normalizeLabel(string $label): string
    {
        $label = strtolower($label);
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;
        $label = str_replace(['fig.', 'fig '], ['figure ', 'figure '], $label);
        return trim($label);
    }

    protected function hydrateAsset(array $asset, string $guidelineKey): array
    {
        $disk = (string) config('guideline_assets.disk', 'public');

        if (empty($asset['url']) && !empty($asset['path'])) {
            $path = (string) $asset['path'];
            try {
                $asset['url'] = Storage::disk($disk)->url($path);
            } catch (\Throwable $e) {
                Log::warning('[GUIDELINE ASSETS] Failed to build asset URL', [
                    'disk' => $disk,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $asset['guideline_key'] = $guidelineKey;

        return $asset;
    }

    protected function tokenBag(string $text): array
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text) ?? $text;
        $parts = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $bag = [];
        foreach ($parts as $w) {
            if (strlen($w) < 4) {
                continue;
            }
            // very light stemming
            $w = rtrim($w, 's');
            $bag[$w] = true;
        }
        return $bag;
    }

    protected function keywordScore(array $bag, array $asset): int
    {
        $score = 0;

        $caption = (string) ($asset['caption'] ?? '');
        if ($caption !== '') {
            $capBag = $this->tokenBag($caption);
            foreach ($capBag as $w => $_) {
                if (isset($bag[$w])) {
                    $score += 2;
                }
            }
        }

        $keywords = $asset['keywords'] ?? [];
        if (is_array($keywords)) {
            foreach ($keywords as $kw) {
                $kw = strtolower((string) $kw);
                $kw = preg_replace('/[^a-z0-9\s]/', ' ', $kw) ?? $kw;
                $kw = trim($kw);
                if ($kw === '') {
                    continue;
                }

                $kwParts = preg_split('/\s+/', $kw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $all = true;
                foreach ($kwParts as $p) {
                    if (strlen($p) < 4) {
                        continue;
                    }
                    $p = rtrim($p, 's');
                    if (!isset($bag[$p])) {
                        $all = false;
                        break;
                    }
                }
                if ($all) {
                    $score += 4;
                }
            }
        }

        return $score;
    }

    protected function assetSearchText(array $asset): string
    {
        $parts = [];

        foreach (['label', 'caption', 'description'] as $field) {
            $v = trim((string) ($asset[$field] ?? ''));
            if ($v !== '') {
                $parts[] = $v;
            }
        }

        $aliases = $asset['aliases'] ?? [];
        if (is_array($aliases)) {
            foreach ($aliases as $alias) {
                $a = trim((string) $alias);
                if ($a !== '') {
                    $parts[] = $a;
                }
            }
        }

        $keywords = $asset['keywords'] ?? [];
        if (is_array($keywords)) {
            foreach ($keywords as $kw) {
                $k = trim((string) $kw);
                if ($k !== '') {
                    $parts[] = $k;
                }
            }
        }

        return implode("\n", $parts);
    }

    protected function buildScopedIdfIndex(array $manifest, array $scopedKeys): array
    {
        $df = [];
        $docs = 0;

        foreach ($scopedKeys as $key) {
            $assets = $manifest[$key] ?? [];
            if (!is_array($assets)) {
                continue;
            }

            foreach ($assets as $asset) {
                if (!is_array($asset)) {
                    continue;
                }

                $bag = $this->tokenBag($this->assetSearchText($asset));
                if (empty($bag)) {
                    continue;
                }

                $docs++;
                foreach (array_keys($bag) as $token) {
                    $df[$token] = ($df[$token] ?? 0) + 1;
                }
            }
        }

        return ['df' => $df, 'docs' => $docs];
    }

    protected function idfWeight(string $token, array $df, int $docs): float
    {
        $docs = max(1, $docs);
        $tokenDf = (int) ($df[$token] ?? 0);
        return log(($docs + 1.0) / ($tokenDf + 1.0)) + 1.0;
    }

    protected function directReferenceScore(array $asset, array $questionRefs): float
    {
        if (empty($questionRefs)) {
            return 0.0;
        }

        $labels = [];
        $label = trim((string) ($asset['label'] ?? ''));
        if ($label !== '') {
            $labels[] = $this->normalizeLabel($label);
        }

        $aliases = $asset['aliases'] ?? [];
        if (is_array($aliases)) {
            foreach ($aliases as $alias) {
                $a = trim((string) $alias);
                if ($a !== '') {
                    $labels[] = $this->normalizeLabel($a);
                }
            }
        }

        if (empty($labels)) {
            return 0.0;
        }

        foreach ($questionRefs as $ref) {
            if (in_array($ref, $labels, true)) {
                return 10.0;
            }
        }

        return 0.0;
    }
}
