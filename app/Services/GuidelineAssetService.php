<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GuidelineAssetService
{
    protected static array $manifestCache = [];

    public function __construct(protected BridgeRerankService $bridgeRerank) {}

    /**
     * Return a list of assets (figures/tables) relevant to the retrieved chunks.
     *
     * Assets are intended for user display only. They are not "evidence" and
     * should not be quoted as text.
     *
     * @param  array  $narrativeChunks  RetrievalService formatted narrative chunks
     * @param  array  $citationChunks  RetrievalService formatted citation chunks
     * @param  array  $selectedGuidelines  key => ['id'=>..., 'name'=>...]
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
        $scopedKeys = ! empty($preferredGuidelineKeys)
            ? array_values(array_intersect($selectedKeys, $preferredGuidelineKeys))
            : $selectedKeys;
        if (empty($scopedKeys)) {
            $scopedKeys = $selectedKeys;
        }
        $nameToKey = $this->buildGuidelineNameToKeyMap();
        $questionTokens = array_values(array_unique($this->tokenizeSearchText($question)));
        $questionIntent = $this->inferQuestionIntent($question);
        $questionRefs = $this->extractAssetReferences($question);
        $fallbackScopeKeys = $this->extractEvidenceScopedKeys(
            $narrativeChunks,
            $citationChunks,
            $nameToKey,
            $scopedKeys
        );
        if (empty($fallbackScopeKeys)) {
            $fallbackScopeKeys = $scopedKeys;
        }
        $contextText = $this->buildFallbackContextText(
            $narrativeChunks,
            $nameToKey,
            $fallbackScopeKeys
        );
        $questionTerritories = $this->inferVascularTerritories($question, $questionTokens);
        $contextTerritories = $this->inferVascularTerritories($contextText);
        $questionFocusAnchors = $this->extractSpecificAnatomyAnchors($question, $questionTokens);
        $contextFocusAnchors = $this->extractSpecificAnatomyAnchors($contextText);

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
                if (! $asset) {
                    continue;
                }
                if (! $this->shouldUseExplicitReferenceAsset(
                    $asset,
                    $ref,
                    $questionTokens,
                    $questionIntent,
                    $questionRefs,
                    $questionTerritories,
                    $contextTerritories
                )) {
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

        // 2) Fallback/supplement: local BM25-style reranking scoped to the
        // guidelines that actually contributed evidence to the answer.
        if (count($results) < $maxAssets && (bool) config('guideline_assets.enable_keyword_fallback', true)) {
            $contextTokens = array_values(array_unique($this->tokenizeSearchText($contextText)));
            $contextRefs = $this->extractAssetReferences($contextText);
            $guidelineUsageWeights = $this->buildGuidelineUsageWeights(
                $narrativeChunks,
                $citationChunks,
                $nameToKey,
                $fallbackScopeKeys
            );
            $bm25Index = $this->buildScopedBm25Index($manifest, $fallbackScopeKeys);

            $scored = [];
            foreach ($bm25Index['docs'] as $doc) {
                $questionBm25 = $this->bm25Score(
                    $questionTokens,
                    $doc['term_freq'],
                    $doc['length'],
                    $bm25Index['doc_freq'],
                    $bm25Index['doc_count'],
                    $bm25Index['avg_length']
                );
                $contextBm25 = $this->bm25Score(
                    $contextTokens,
                    $doc['term_freq'],
                    $doc['length'],
                    $bm25Index['doc_freq'],
                    $bm25Index['doc_count'],
                    $bm25Index['avg_length']
                );
                $questionRefScore = $this->directReferenceScore($doc['asset'], $questionRefs);
                $guidelineBoost = (float) (($guidelineUsageWeights[$doc['guideline_key']] ?? 0.0) * 1.5);
                $intentFit = $this->scoreQuestionIntentFit(
                    $doc['asset'],
                    $questionTokens,
                    $doc['term_freq'],
                    $questionIntent,
                    $questionTerritories,
                    $contextTerritories,
                    $questionFocusAnchors,
                    $contextFocusAnchors
                );
                $contextRefScore = $this->scaleContextReferenceScore(
                    $this->directReferenceScore($doc['asset'], $contextRefs),
                    $questionIntent,
                    $intentFit
                );
                $contextCarryScore = (empty($questionTerritories) && empty($questionFocusAnchors))
                    ? ($contextBm25 * 0.8)
                    : 0.0;

                // Prioritize the user question while still letting the answer context
                // steer the fallback toward visuals that match the retrieved evidence
                // without letting incidental figure/table mentions dominate the rank.
                $querySignal = ($questionBm25 * 2.0)
                    + $questionRefScore
                    + $contextRefScore
                    + $contextCarryScore
                    + $guidelineBoost
                    + $intentFit['boost'];
                $totalScore = $querySignal + ($contextBm25 * 0.4);
                if ($totalScore <= 0.0) {
                    continue;
                }

                $scored[] = [
                    'score' => $totalScore,
                    'query_signal' => $querySignal,
                    'question_score' => $questionBm25,
                    'context_score' => $contextBm25,
                    'question_ref_score' => $questionRefScore,
                    'context_ref_score' => $contextRefScore,
                    'context_carry_score' => $contextCarryScore,
                    'guideline_boost' => $guidelineBoost,
                    'intent_boost' => $intentFit['boost'],
                    'semantic_boost' => $intentFit['semantic_boost'],
                    'territory_boost' => $intentFit['territory_boost'],
                    'focus_boost' => $intentFit['focus_boost'],
                    'territory_conflict' => $intentFit['territory_conflict'],
                    'focus_overlap' => $intentFit['focus_overlap'],
                    'content_overlap' => $intentFit['content_overlap'],
                    'asset' => $doc['asset'],
                    'guideline_key' => $doc['guideline_key'],
                ];
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

            $scored = $this->applyFallbackAssetRerank($question, $scored);

            if ((bool) config('guideline_assets.log_scoring', false) && ! empty($scored)) {
                Log::info('[GUIDELINE ASSETS] Scored fallback candidates', [
                    'question' => $question,
                    'selected_guidelines' => $fallbackScopeKeys,
                    'question_territories' => $questionTerritories,
                    'context_territories' => $contextTerritories,
                    'question_focus_anchors' => $questionFocusAnchors,
                    'context_focus_anchors' => $contextFocusAnchors,
                    'top' => array_map(function ($row) {
                        return [
                            'id' => $row['asset']['id'] ?? null,
                            'label' => $row['asset']['label'] ?? null,
                            'guideline_key' => $row['guideline_key'],
                            'query_signal' => $row['query_signal'],
                            'question_score' => $row['question_score'],
                            'context_score' => $row['context_score'],
                            'question_ref_score' => $row['question_ref_score'],
                            'context_ref_score' => $row['context_ref_score'],
                            'context_carry_score' => $row['context_carry_score'],
                            'guideline_boost' => $row['guideline_boost'],
                            'intent_boost' => $row['intent_boost'],
                            'semantic_boost' => $row['semantic_boost'],
                            'territory_boost' => $row['territory_boost'],
                            'focus_boost' => $row['focus_boost'],
                            'territory_conflict' => $row['territory_conflict'],
                            'focus_overlap' => $row['focus_overlap'],
                            'content_overlap' => $row['content_overlap'],
                            'total_score' => $row['score'],
                        ];
                    }, array_slice($scored, 0, 12)),
                ]);
            }

            $bestQuerySignal = ! empty($scored) ? (float) ($scored[0]['query_signal'] ?? 0.0) : 0.0;
            $minAbsQuerySignal = (float) (config('guideline_assets.min_query_signal', 2.0) ?: 2.0);
            $minRelativeRatio = (float) (config('guideline_assets.min_query_signal_ratio', 0.45) ?: 0.45);
            $minRelativeRatio = max(0.0, min(1.0, $minRelativeRatio));
            $minRelativeSignal = $bestQuerySignal * $minRelativeRatio;

            $hasStrongQueryMatch = false;
            foreach ($scored as $row) {
                if ((float) ($row['query_signal'] ?? 0.0) >= $minAbsQuerySignal) {
                    $hasStrongQueryMatch = true;
                    break;
                }
            }

            foreach ($scored as $row) {
                $querySignal = (float) ($row['query_signal'] ?? 0.0);
                if ($hasStrongQueryMatch) {
                    if ($querySignal < $minAbsQuerySignal || $querySignal < $minRelativeSignal) {
                        continue;
                    }
                }

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

    protected function applyFallbackAssetRerank(string $question, array $scored): array
    {
        $rerankConfig = (array) config('guideline_assets.rerank', []);
        if (! (bool) ($rerankConfig['enabled'] ?? false)) {
            return $scored;
        }

        $candidatePool = (int) ($rerankConfig['candidate_pool'] ?? 8);
        $candidatePool = max(2, $candidatePool);
        if (count($scored) < 2) {
            return $scored;
        }

        $shortlist = array_slice($scored, 0, min(count($scored), $candidatePool));
        $documents = array_map(function (array $row): string {
            $asset = (array) ($row['asset'] ?? []);
            $parts = [
                'kind: '.(string) ($asset['kind'] ?? ''),
                'subtype: '.(string) ($asset['subtype'] ?? ''),
                'label: '.(string) ($asset['label'] ?? ''),
                'caption: '.(string) ($asset['caption'] ?? ''),
                'description: '.(string) ($asset['description'] ?? ''),
                'keywords: '.implode(', ', array_map('strval', (array) ($asset['keywords'] ?? []))),
                'aliases: '.implode(', ', array_map('strval', (array) ($asset['aliases'] ?? []))),
            ];

            return trim(implode("\n", $parts));
        }, $shortlist);

        $ranked = $this->bridgeRerank->rerankDocuments(
            $question,
            $documents,
            count($documents),
            'guideline_assets',
            $rerankConfig
        );

        if (empty($ranked)) {
            return $scored;
        }

        $rerankedShortlist = [];
        $used = [];
        foreach ($ranked as $row) {
            $index = $row['index'] ?? null;
            if (! is_int($index) || ! isset($shortlist[$index])) {
                continue;
            }

            $assetRow = $shortlist[$index];
            $assetRow['asset_rerank_score'] = $row['score'] ?? null;
            $rerankedShortlist[] = $assetRow;
            $used[$index] = true;
        }

        if (empty($rerankedShortlist)) {
            return $scored;
        }

        foreach ($shortlist as $index => $row) {
            if (! isset($used[$index])) {
                $rerankedShortlist[] = $row;
            }
        }

        return array_merge($rerankedShortlist, array_slice($scored, count($shortlist)));
    }

    protected function loadManifest(): array
    {
        $path = (string) config('guideline_assets.manifest_path');
        if ($path === '' || ! is_file($path)) {
            return [];
        }

        $cacheKey = $path.'|'.(string) filemtime($path);
        if (array_key_exists($cacheKey, self::$manifestCache)) {
            return self::$manifestCache[$cacheKey];
        }

        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            Log::warning('[GUIDELINE ASSETS] Manifest JSON invalid', ['path' => $path]);

            return [];
        }

        // Expected shape: { "guideline_key": [ {asset...}, ... ], ... }
        return self::$manifestCache[$cacheKey] = $decoded;
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

    protected function extractEvidenceScopedKeys(
        array $narrativeChunks,
        array $citationChunks,
        array $nameToKey,
        array $selectedKeys
    ): array {
        $seen = [];

        foreach (array_merge($narrativeChunks, $citationChunks) as $chunk) {
            $sourceName = (string) ($chunk['source_guideline'] ?? '');
            $sourceKey = $this->mapSourceGuidelineToKey($sourceName, $nameToKey, $selectedKeys);
            if ($sourceKey === null) {
                continue;
            }
            $seen[$sourceKey] = true;
        }

        return array_keys($seen);
    }

    protected function buildGuidelineUsageWeights(
        array $narrativeChunks,
        array $citationChunks,
        array $nameToKey,
        array $selectedKeys
    ): array {
        $counts = [];
        $total = 0;

        foreach (array_merge($narrativeChunks, $citationChunks) as $chunk) {
            $sourceName = (string) ($chunk['source_guideline'] ?? '');
            $sourceKey = $this->mapSourceGuidelineToKey($sourceName, $nameToKey, $selectedKeys);
            if ($sourceKey === null) {
                continue;
            }

            $counts[$sourceKey] = ($counts[$sourceKey] ?? 0) + 1;
            $total++;
        }

        if ($total <= 0) {
            return [];
        }

        foreach ($counts as $key => $count) {
            $counts[$key] = $count / $total;
        }

        return $counts;
    }

    protected function buildFallbackContextText(
        array $narrativeChunks,
        array $nameToKey,
        array $scopeKeys,
        int $limit = 6
    ): string {
        $parts = [];

        foreach ($narrativeChunks as $chunk) {
            $sourceName = (string) ($chunk['source_guideline'] ?? '');
            $sourceKey = $this->mapSourceGuidelineToKey($sourceName, $nameToKey, $scopeKeys);
            if ($sourceKey === null) {
                continue;
            }

            $content = trim((string) ($chunk['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $parts[] = $content;
            if (count($parts) >= $limit) {
                break;
            }
        }

        return implode("\n", $parts);
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
            if (! isset($seen[$x])) {
                $seen[$x] = true;
                $uniq[] = $x;
            }
        }

        return $uniq;
    }

    protected function lookupByReference(array $manifest, string $guidelineKey, string $normalizedRef): ?array
    {
        $assets = $manifest[$guidelineKey] ?? null;
        if (! is_array($assets) || empty($assets)) {
            return null;
        }

        foreach ($assets as $asset) {
            if (! is_array($asset)) {
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

        if (empty($asset['url']) && ! empty($asset['path'])) {
            $path = (string) $asset['path'];
            try {
                $asset['url'] = $this->buildAssetUrl($disk, $path);
            } catch (\Throwable $e) {
                Log::warning('[GUIDELINE ASSETS] Failed to build asset URL', [
                    'disk' => $disk,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (empty($asset['thumbnail_url']) && ! empty($asset['thumbnail_path'])) {
            $thumbnailPath = (string) $asset['thumbnail_path'];
            try {
                $asset['thumbnail_url'] = $this->buildAssetUrl($disk, $thumbnailPath);
            } catch (\Throwable $e) {
                Log::warning('[GUIDELINE ASSETS] Failed to build thumbnail URL', [
                    'disk' => $disk,
                    'path' => $thumbnailPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $asset['guideline_key'] = $guidelineKey;

        return $asset;
    }

    protected function buildAssetUrl(string $disk, string $path): string
    {
        $baseUrl = trim((string) config('guideline_assets.base_url', ''));
        if ($baseUrl !== '') {
            $prefix = '/'.trim((string) config('guideline_assets.url_prefix', '/storage'), '/');

            return rtrim($baseUrl, '/').$prefix.'/'.ltrim($path, '/');
        }

        return Storage::disk($disk)->url($path);
    }

    protected function shouldUseExplicitReferenceAsset(
        array $asset,
        string $ref,
        array $questionTokens,
        array $questionIntent,
        array $questionRefs,
        array $questionTerritories = [],
        array $contextTerritories = []
    ): bool {
        if (in_array($ref, $questionRefs, true)) {
            return true;
        }

        if ($this->countMeaningfulQuestionTokens($questionTokens) === 0) {
            return true;
        }

        $doc = $this->buildAssetDocument($asset);
        $intentFit = $this->scoreQuestionIntentFit(
            $asset,
            $questionTokens,
            $doc['term_freq'] ?? [],
            $questionIntent,
            $questionTerritories,
            $contextTerritories
        );
        $minimumOverlap = $questionIntent['management'] ? 2 : 1;

        if (($intentFit['content_overlap'] ?? 0) < $minimumOverlap) {
            return false;
        }

        if ($questionIntent['management'] && ($intentFit['semantic_boost'] ?? 0.0) < 2.0) {
            return false;
        }

        if (($intentFit['territory_conflict'] ?? false) && ($intentFit['content_overlap'] ?? 0) < 3) {
            return false;
        }

        return true;
    }

    protected function buildScopedBm25Index(array $manifest, array $scopedKeys): array
    {
        $docs = [];
        $docFreq = [];
        $docCount = 0;
        $totalLength = 0.0;

        foreach ($scopedKeys as $key) {
            $assets = $manifest[$key] ?? [];
            if (! is_array($assets)) {
                continue;
            }

            foreach ($assets as $asset) {
                if (! is_array($asset)) {
                    continue;
                }

                $doc = $this->buildAssetDocument($asset);
                if (($doc['length'] ?? 0) <= 0) {
                    continue;
                }

                $docs[] = [
                    'asset' => $asset,
                    'guideline_key' => $key,
                    'term_freq' => $doc['term_freq'],
                    'length' => $doc['length'],
                ];

                $docCount++;
                $totalLength += $doc['length'];
                foreach (array_keys($doc['term_freq']) as $token) {
                    $docFreq[$token] = ($docFreq[$token] ?? 0) + 1;
                }
            }
        }

        return [
            'docs' => $docs,
            'doc_freq' => $docFreq,
            'doc_count' => $docCount,
            'avg_length' => $docCount > 0 ? ($totalLength / $docCount) : 1.0,
        ];
    }

    protected function buildAssetDocument(array $asset): array
    {
        $tokens = [];

        $fieldWeights = [
            'label' => 4,
            'caption' => 5,
            'description' => 2,
            'kind' => 1,
            'subtype' => 1,
        ];

        foreach ($fieldWeights as $field => $weight) {
            $tokens = array_merge(
                $tokens,
                $this->buildWeightedTokenList((string) ($asset[$field] ?? ''), $weight)
            );
        }

        $aliases = $asset['aliases'] ?? [];
        if (is_array($aliases)) {
            foreach ($aliases as $alias) {
                $tokens = array_merge($tokens, $this->buildWeightedTokenList((string) $alias, 3));
            }
        }

        $keywords = $asset['keywords'] ?? [];
        if (is_array($keywords)) {
            foreach ($keywords as $kw) {
                $tokens = array_merge($tokens, $this->buildWeightedTokenList((string) $kw, 6));
            }
        }

        $termFreq = [];
        foreach ($tokens as $token) {
            $termFreq[$token] = ($termFreq[$token] ?? 0) + 1;
        }

        return [
            'term_freq' => $termFreq,
            'length' => count($tokens),
        ];
    }

    protected function buildWeightedTokenList(string $text, int $weight): array
    {
        $tokens = $this->tokenizeSearchText($text);
        if ($weight <= 1 || empty($tokens)) {
            return $tokens;
        }

        $weighted = [];
        foreach ($tokens as $token) {
            for ($i = 0; $i < $weight; $i++) {
                $weighted[] = $token;
            }
        }

        return $weighted;
    }

    protected function tokenizeSearchText(string $text): array
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text) ?? $text;
        $parts = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopwords = array_flip([
            'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'how',
            'if', 'in', 'into', 'is', 'it', 'of', 'on', 'or', 'that', 'the', 'their',
            'then', 'there', 'these', 'this', 'those', 'to', 'use', 'used', 'using',
            'what', 'when', 'where', 'which', 'with', 'without', 'your',
        ]);

        $tokens = [];
        foreach ($parts as $part) {
            if (strlen($part) < 2 || ctype_digit($part)) {
                continue;
            }

            $token = $part;
            if ($token === '' || isset($stopwords[$token])) {
                continue;
            }

            $tokens[] = $token;
            if (
                strlen($token) > 4
                && str_ends_with($token, 's')
                && ! preg_match('/(ss|us|is|os)$/', $token)
            ) {
                $tokens[] = substr($token, 0, -1);
            }

            foreach ($this->expandClinicalToken($token) as $expandedToken) {
                if ($expandedToken !== '' && ! isset($stopwords[$expandedToken])) {
                    $tokens[] = $expandedToken;
                }
            }
        }

        return $tokens;
    }

    protected function expandClinicalToken(string $token): array
    {
        return match ($token) {
            'ica' => ['internal', 'carotid', 'artery', 'carotid'],
            'cea' => ['carotid', 'endarterectomy'],
            'cas' => ['carotid', 'stenting', 'stent'],
            'tcar' => ['transcarotid', 'carotid', 'artery', 'revascularization', 'revascularisation'],
            'cabg' => ['coronary', 'bypass', 'grafting'],
            'pad' => ['peripheral', 'arterial', 'disease'],
            'clti' => ['limb', 'threatening', 'ischaemia', 'ischemia'],
            'tap' => ['target', 'arterial', 'path'],
            'glass' => ['global', 'limb', 'anatomic', 'staging', 'system'],
            'bmt' => ['best', 'medical', 'therapy'],
            'tia' => ['transient', 'ischaemic', 'ischemic', 'attack'],
            'btai' => ['blunt', 'thoracic', 'aortic', 'injury'],
            'tevar' => ['thoracic', 'endovascular', 'aortic', 'repair'],
            'evar' => ['endovascular', 'aneurysm', 'repair'],
            default => [],
        };
    }

    protected function inferQuestionIntent(string $question): array
    {
        $normalized = strtolower(trim($question));

        return [
            'management' => (bool) preg_match('/\b(treat|treatment|manage|management|approach|strategy|algorithm|pathway|recommend|recommended|indication|surveillance|follow\s*up|timing)\b/', $normalized),
            'risk' => (bool) preg_match('/\b(risk|rupture|outcome|likelihood|predict|prognos|mortality)\b/', $normalized),
            'definition' => str_starts_with($normalized, 'what is ')
                || (bool) preg_match('/\b(define|definition|defined|classification|classify|staging|stage|grading|grade|diagram|anatomy|measurement)\b/', $normalized),
            'procedure' => (bool) preg_match('/\b(procedure|technique|steps|perform|deployment|endarterectomy|stenting|angioplasty)\b/', $normalized),
            'diagnostic' => (bool) preg_match('/\b(imaging|image|diagnostic|diagnosis|diagnose|workup|cta|mra|duplex|ultrasound|pet|spect|modality)\b/', $normalized),
            'definitive_treatment' => (bool) preg_match('/\b(definitive|definite|reconstruction|reconstruct|reconstructive|explant|explantation|conduit|flap|bridge|curative|repair)\b/', $normalized),
        ];
    }

    protected function scoreQuestionIntentFit(
        array $asset,
        array $questionTokens,
        array $termFreq,
        array $questionIntent,
        array $questionTerritories = [],
        array $contextTerritories = [],
        array $questionFocusAnchors = [],
        array $contextFocusAnchors = []
    ): array {
        $assetText = strtolower($this->assetSearchText($asset).' '.($asset['kind'] ?? '').' '.($asset['subtype'] ?? ''));
        $kind = strtolower((string) ($asset['kind'] ?? ''));
        $subtype = strtolower((string) ($asset['subtype'] ?? ''));
        $contentOverlap = $this->countMeaningfulTokenOverlap($questionTokens, $termFreq);
        $semanticBoost = $contentOverlap * 0.7;
        $hasManagementSignal = $this->containsAnyPhrase($assetText, [
            'management',
            'algorithm',
            'flowchart',
            'pathway',
            'strategy',
            'recommend',
            'treatment algorithm',
        ]);
        $hasDiagnosticSignal = $this->containsAnyPhrase($assetText, [
            'imaging',
            'diagnostic',
            'diagnosis',
            'workup',
            'modality',
            'sensitivity',
            'specificity',
            'cta',
            'mra',
            'pet',
            'spect',
        ]);
        $hasDefinitiveSignal = $this->containsAnyPhrase($assetText, [
            'explant',
            'reconstruction',
            'reconstruct',
            'definitive treatment',
            'repair of the oesophagus',
            'repair of the esophagus',
            'viable tissue',
            'fistula',
        ]);

        if ($questionIntent['management']) {
            if ($subtype === 'flowchart' && $contentOverlap >= 2) {
                $semanticBoost += 2.5;
            }
            if ($kind === 'figure' && $contentOverlap >= 2) {
                $semanticBoost += 0.75;
            }
            if ($hasManagementSignal) {
                $semanticBoost += $contentOverlap >= 2 ? 3.0 : ($contentOverlap === 1 ? 1.0 : 0.0);
            }
            if (($hasManagementSignal || $hasDefinitiveSignal) && $subtype === 'flowchart' && $contentOverlap >= 1) {
                $semanticBoost += 1.5;
            }
            if ($questionIntent['definitive_treatment']) {
                if ($hasDefinitiveSignal) {
                    $semanticBoost += $contentOverlap >= 1 ? 4.0 : 2.0;
                }
                if ($hasDiagnosticSignal && ! $hasDefinitiveSignal) {
                    $semanticBoost -= 4.0;
                }
            }
            if ($contentOverlap === 0) {
                $semanticBoost -= 2.0;
            }
            if (
                $kind === 'table'
                && $this->containsAnyPhrase($assetText, ['outcome', 'trial', 'prevalence', 'predictive', 'feature', 'risk'])
            ) {
                $semanticBoost -= 1.5;
            }
            if (
                ! $hasManagementSignal
                && $hasDiagnosticSignal
            ) {
                $semanticBoost -= 3.0;
            }
        }

        if ($questionIntent['diagnostic']) {
            if ($hasDiagnosticSignal) {
                $semanticBoost += $contentOverlap >= 1 ? 3.0 : 1.0;
            }
            if (($hasManagementSignal || $hasDefinitiveSignal) && ! $hasDiagnosticSignal) {
                $semanticBoost -= 1.0;
            }
        }

        if ($questionIntent['risk']) {
            if ($kind === 'table') {
                $semanticBoost += 1.0;
            }
            if ($this->containsAnyPhrase($assetText, ['risk', 'rupture', 'outcome', 'predictive', 'severity', 'mortality'])) {
                $semanticBoost += 2.0;
            }
        }

        if ($questionIntent['definition']) {
            if (in_array($subtype, ['diagram', 'illustration'], true) && $contentOverlap >= 1) {
                $semanticBoost += 2.0;
            }
            if ($this->containsAnyPhrase($assetText, ['diagram', 'classification', 'staging', 'measurement', 'anatomy', 'illustrat']) && $contentOverlap >= 1) {
                $semanticBoost += 2.0;
            }
        }

        if ($questionIntent['procedure']) {
            if (in_array($subtype, ['diagram', 'illustration'], true) && $contentOverlap >= 1) {
                $semanticBoost += 1.5;
            }
            if ($this->containsAnyPhrase($assetText, ['procedure', 'deployment', 'illustrat', 'technique', 'stent', 'endarterectomy', 'angioplasty']) && $contentOverlap >= 1) {
                $semanticBoost += 2.0;
            }
        }
        $territoryFit = $this->scoreVascularTerritoryFit($asset, $questionTerritories, $contextTerritories);
        $focusFit = $this->scoreSpecificAnatomyFit($asset, $questionFocusAnchors, $contextFocusAnchors);
        $boost = $semanticBoost + $territoryFit['boost'] + $focusFit['boost'];

        return [
            'boost' => $boost,
            'semantic_boost' => $semanticBoost,
            'content_overlap' => $contentOverlap,
            'territory_boost' => $territoryFit['boost'],
            'focus_boost' => $focusFit['boost'],
            'focus_overlap' => $focusFit['overlap'],
            'territory_conflict' => $territoryFit['conflict'],
        ];
    }

    protected function scoreVascularTerritoryFit(
        array $asset,
        array $questionTerritories,
        array $contextTerritories
    ): array {
        $assetTerritories = $this->inferVascularTerritories($this->assetSearchText($asset));
        if (empty($assetTerritories)) {
            return [
                'boost' => 0.0,
                'conflict' => false,
            ];
        }

        $boost = 0.0;
        $matchesQuestion = ! empty(array_intersect($assetTerritories, $questionTerritories));
        $matchesContext = ! empty(array_intersect($assetTerritories, $contextTerritories));

        if (! empty($questionTerritories)) {
            if ($matchesQuestion) {
                $boost += 3.0;
            } else {
                $boost -= 4.0;
            }
        }

        if (! empty($contextTerritories)) {
            if ($matchesContext) {
                $boost += empty($questionTerritories) ? 2.0 : 1.0;
            } elseif (empty($questionTerritories)) {
                $boost -= 2.0;
            }
        }

        $conflict = (! empty($questionTerritories) && ! $matchesQuestion)
            || (empty($questionTerritories) && ! empty($contextTerritories) && ! $matchesContext);

        return [
            'boost' => $boost,
            'conflict' => $conflict,
        ];
    }

    protected function scoreSpecificAnatomyFit(
        array $asset,
        array $questionFocusAnchors,
        array $contextFocusAnchors
    ): array {
        $assetAnchors = $this->extractSpecificAnatomyAnchors($this->assetSearchText($asset));
        $questionOverlap = array_values(array_intersect($assetAnchors, $questionFocusAnchors));
        $contextOverlap = array_values(array_intersect($assetAnchors, $contextFocusAnchors));
        $boost = 0.0;

        if (! empty($questionFocusAnchors)) {
            if (! empty($questionOverlap)) {
                $boost += count($questionFocusAnchors) === 1 ? 2.0 : 1.0;
            } elseif (count($questionFocusAnchors) === 1) {
                $boost -= 2.0;
            }
        } elseif (! empty($contextFocusAnchors) && ! empty($contextOverlap)) {
            $boost += 1.0;
        }

        return [
            'boost' => $boost,
            'overlap' => ! empty($questionFocusAnchors) ? count($questionOverlap) : count($contextOverlap),
        ];
    }

    protected function inferVascularTerritories(string $text, array $tokens = []): array
    {
        $normalized = strtolower($text);
        $tokenSet = array_fill_keys(
            array_values(array_unique(! empty($tokens) ? $tokens : $this->tokenizeSearchText($text))),
            true
        );
        $territories = [];

        if ($this->matchesTerritory($normalized, $tokenSet, ['carotid', 'vertebral', 'vertebrobasilar', 'tcar'], [
            'carotid artery',
            'internal carotid',
            'common carotid',
            'vertebral artery',
        ])) {
            $territories[] = 'carotid_vertebral';
        }

        if ($this->matchesTerritory($normalized, $tokenSet, ['thoracic', 'thoracoabdominal', 'btai', 'tevar'], [
            'thoracic aorta',
            'thoracic aortic',
            'descending thoracic',
            'type b dissection',
            'non a non b dissection',
            'left subclavian',
            'blunt thoracic aortic injury',
        ])) {
            $territories[] = 'thoracic_aorta';
        }

        if ($this->matchesTerritory($normalized, $tokenSet, ['innominate'], [
            'aortic arch',
            'arch aneurysm',
            'arch dissection',
            'supra aortic',
            'supra-aortic',
            'debranching',
            'frozen elephant trunk',
        ])) {
            $territories[] = 'aortic_arch';
        }

        if ($this->matchesTerritory($normalized, $tokenSet, ['infrarenal', 'juxtarenal', 'pararenal', 'evar', 'iliac'], [
            'abdominal aortic',
            'abdominal aneurysm',
            'iliac aneurysm',
            'iliac artery',
        ])) {
            $territories[] = 'abdominal_aorta';
        }

        if ($this->matchesTerritory($normalized, $tokenSet, ['mesenteric', 'coeliac', 'celiac', 'renal', 'visceral', 'sma'], [
            'renal artery',
            'visceral artery',
            'mesenteric ischemia',
            'mesenteric ischaemia',
        ])) {
            $territories[] = 'mesenteric_renal';
        }

        if ($this->matchesTerritory($normalized, $tokenSet, ['claudication', 'femoropopliteal', 'popliteal', 'tibial', 'wound', 'wifi', 'glass'], [
            'lower limb',
            'acute limb ischemia',
            'acute limb ischaemia',
            'chronic limb threatening',
            'rest pain',
            'foot ulcer',
        ])) {
            $territories[] = 'lower_limb_arterial';
        }

        if ($this->matchesTerritory($normalized, $tokenSet, ['venous', 'varicose', 'saphenous', 'dvt', 'pe'], [
            'deep vein thrombosis',
            'pulmonary embolism',
            'venous ulcer',
        ])) {
            $territories[] = 'venous';
        }

        return array_values(array_unique($territories));
    }

    protected function extractSpecificAnatomyAnchors(string $text, array $tokens = []): array
    {
        $normalized = strtolower($text);
        $tokenSet = array_fill_keys(
            array_values(array_unique(! empty($tokens) ? $tokens : $this->tokenizeSearchText($text))),
            true
        );
        $anchors = [];

        $definitions = [
            'carotid' => [['carotid'], ['carotid artery', 'internal carotid', 'common carotid']],
            'vertebral' => [['vertebral', 'vertebrobasilar'], ['vertebral artery']],
            'subclavian' => [['subclavian'], ['left subclavian', 'right subclavian']],
            'innominate' => [['innominate'], ['innominate artery', 'brachiocephalic']],
            'arch' => [[], ['aortic arch']],
            'thoracic' => [['thoracic', 'thoracoabdominal'], ['descending thoracic', 'thoracic aorta', 'thoracic aortic']],
            'abdominal' => [['infrarenal', 'juxtarenal', 'pararenal'], ['abdominal aortic']],
            'iliac' => [['iliac'], ['iliac artery', 'iliac aneurysm']],
            'mesenteric' => [['mesenteric', 'sma', 'coeliac', 'celiac'], ['mesenteric artery', 'coeliac artery', 'celiac artery']],
            'renal' => [['renal'], ['renal artery']],
            'femoropopliteal' => [['femoropopliteal', 'femoral'], ['femoropopliteal segment']],
            'popliteal' => [['popliteal'], ['popliteal artery']],
            'tibial' => [['tibial'], ['anterior tibial', 'posterior tibial']],
            'saphenous' => [['saphenous'], ['great saphenous', 'small saphenous']],
        ];

        foreach ($definitions as $anchor => [$anchorTokens, $anchorPhrases]) {
            if ($this->matchesTerritory($normalized, $tokenSet, $anchorTokens, $anchorPhrases)) {
                $anchors[] = $anchor;
            }
        }

        return array_values(array_unique($anchors));
    }

    protected function matchesTerritory(
        string $normalizedText,
        array $tokenSet,
        array $tokens,
        array $phrases
    ): bool {
        foreach ($tokens as $token) {
            if (isset($tokenSet[$token])) {
                return true;
            }
        }

        return $this->containsAnyPhrase($normalizedText, $phrases);
    }

    protected function scaleContextReferenceScore(float $rawScore, array $questionIntent, array $intentFit): float
    {
        if ($rawScore <= 0.0) {
            return 0.0;
        }

        $scaledScore = $rawScore * 0.2;

        if ($questionIntent['management'] && (($intentFit['semantic_boost'] ?? $intentFit['boost'] ?? 0.0) < 2.0)) {
            $scaledScore *= 0.2;
        }

        if (
            ($questionIntent['definition'] || $questionIntent['procedure'] || $questionIntent['risk'])
            && ($intentFit['content_overlap'] ?? 0) < 1
        ) {
            $scaledScore *= 0.2;
        }

        return $scaledScore;
    }

    protected function countMeaningfulTokenOverlap(array $questionTokens, array $termFreq): int
    {
        $count = 0;

        foreach (array_values(array_unique($questionTokens)) as $token) {
            if ($this->isIntentOnlyToken($token)) {
                continue;
            }
            if (isset($termFreq[$token])) {
                $count++;
            }
        }

        return $count;
    }

    protected function countMeaningfulQuestionTokens(array $questionTokens): int
    {
        $count = 0;

        foreach (array_values(array_unique($questionTokens)) as $token) {
            if (! $this->isIntentOnlyToken($token)) {
                $count++;
            }
        }

        return $count;
    }

    protected function isIntentOnlyToken(string $token): bool
    {
        static $intentTokens = [
            'approach',
            'algorithm',
            'anatomy',
            'classify',
            'classification',
            'define',
            'defined',
            'definition',
            'diagram',
            'grade',
            'grading',
            'indication',
            'likelihood',
            'manage',
            'management',
            'measurement',
            'mortality',
            'outcome',
            'pathway',
            'predict',
            'procedure',
            'prognos',
            'recommend',
            'recommended',
            'risk',
            'rupture',
            'stage',
            'staging',
            'steps',
            'strategy',
            'surveillance',
            'technique',
            'timing',
            'treat',
            'treatment',
        ];

        return in_array($token, $intentTokens, true);
    }

    protected function containsAnyPhrase(string $haystack, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if ($phrase !== '' && str_contains($haystack, $phrase)) {
                return true;
            }
        }

        return false;
    }

    protected function bm25Score(
        array $queryTokens,
        array $termFreq,
        int $docLength,
        array $docFreq,
        int $docCount,
        float $avgLength
    ): float {
        if (empty($queryTokens) || empty($termFreq) || $docCount <= 0) {
            return 0.0;
        }

        $k1 = 1.2;
        $b = 0.75;
        $avgLength = max(1.0, $avgLength);
        $docLength = max(1, $docLength);
        $score = 0.0;

        foreach (array_values(array_unique($queryTokens)) as $token) {
            $tf = (float) ($termFreq[$token] ?? 0.0);
            if ($tf <= 0.0) {
                continue;
            }

            $tokenDocFreq = (float) ($docFreq[$token] ?? 0.0);
            $idf = log((($docCount - $tokenDocFreq + 0.5) / ($tokenDocFreq + 0.5)) + 1.0);
            $norm = $k1 * (1.0 - $b + $b * ($docLength / $avgLength));
            $score += $idf * (($tf * ($k1 + 1.0)) / ($tf + $norm));
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
