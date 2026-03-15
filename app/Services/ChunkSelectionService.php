<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * ChunkSelectionService
 *
 * Post-retrieval chunk selection: intent-aware ranking, guideline diversification,
 * and LLM/UI tier splitting.  Ported from vascular_expert.py methods:
 *   _rank_chunks_by_intent, _score_chunk_for_intent, _diversify_chunks,
 *   _select_balanced_llm_chunks, _ensure_multi_guideline_citation_coverage,
 *   _find_must_include_citation, _format_rec_popup.
 *
 * All tuning constants live in config/chunk_scoring.php so they can be changed
 * per specialty without touching this class.
 */
class ChunkSelectionService
{
    // ── Public entry point ─────────────────────────────────────────────────

    /**
     * Select and split chunks into LLM-facing and UI-facing tiers.
     *
     * @param  array  $citationChunks    Citation chunks from the RAGFlow pipeline.
     * @param  array  $narrativeChunks   Narrative chunks from the RAGFlow pipeline.
     * @param  array  $queryNormalization The query_normalization object returned by
     *                                   GuidelineRouterService (contains intent,
     *                                   question_type, key_terms, normalized_query).
     * @param  int    $guidelineCount    Number of selected guidelines.
     * @return array{
     *     llm_citation_chunks: array,
     *     llm_narrative_chunks: array,
     *     ui_citation_chunks:  array,
     *     ui_narrative_chunks: array,
     *     must_include_chunk:  array|null,
     *     intent_profile:      array,
     * }
     */
    public function select(
        array $citationChunks,
        array $narrativeChunks,
        array $queryNormalization,
        int   $guidelineCount = 1
    ): array {
        $profile = $this->buildIntentProfile($queryNormalization);
        $caps    = $this->selectChunkCaps($guidelineCount);

        // 1. Intent-aware ranking
        $rankedCitations = $this->rankByIntent($citationChunks,  'citation',  $profile);
        $rankedNarrative = $this->rankByIntent($narrativeChunks, 'narrative', $profile);

        // 2. Diversify by guideline (round-robin interleaving)
        $diverseCitations = $this->diversify($rankedCitations, 'citation',  $guidelineCount);
        $diverseNarrative = $this->diversify($rankedNarrative, 'narrative', $guidelineCount);

        // 3. UI tier — hard cap after diversification
        $uiCitations = array_slice($diverseCitations, 0, $caps['ui_rec']);
        $uiNarrative = array_slice($diverseNarrative, 0, $caps['ui_narr']);

        // 4. LLM tier — balanced subset of the UI tier
        $llmCitations = $this->selectLlmSubset($uiCitations, 'citation',  $caps['llm_rec'],  $guidelineCount);
        $llmNarrative = $this->selectLlmSubset($uiNarrative, 'narrative', $caps['llm_narr'], $guidelineCount);

        // 5. Ensure every guideline label represented in LLM citation set
        if ($guidelineCount > 1) {
            $llmCitations = $this->ensureCoverage($uiCitations, $llmCitations, $caps['llm_rec']);
        }

        // 6. Must-include: force the highest-scoring citation into the LLM set
        $keyTerms = $profile['key_terms'] ?? [];
        [$mustInclude, $mustScore] = $this->findMustInclude($diverseCitations, $keyTerms, $profile);
        $minScore = (int) config('chunk_scoring.scoring.must_include_min_score', 1);
        if ($mustInclude !== null && $mustScore >= $minScore) {
            $llmCitations = $this->forceInclude($llmCitations, $mustInclude, 'citation', $caps['llm_rec']);
        }

        // Coverage seeding is useful, but the final LLM-facing set should still
        // be ordered by relevance so the best supporting recommendations lead.
        $llmCitations = $this->rankByIntent($llmCitations, 'citation', $profile);
        $llmNarrative = $this->rankByIntent($llmNarrative, 'narrative', $profile);

        Log::channel('retrieval')->debug('[CHUNK SELECTION] Tiers computed', [
            'guideline_count'      => $guidelineCount,
            'intent'               => $profile['intent'],
            'llm_citations'        => count($llmCitations),
            'llm_narrative'        => count($llmNarrative),
            'ui_citations'         => count($uiCitations),
            'ui_narrative'         => count($uiNarrative),
            'must_include_score'   => $mustScore,
        ]);

        return [
            'llm_citation_chunks' => $llmCitations,
            'llm_narrative_chunks'=> $llmNarrative,
            'ui_citation_chunks'  => $uiCitations,
            'ui_narrative_chunks' => $uiNarrative,
            'must_include_chunk'  => $mustInclude,
            'intent_profile'      => $profile,
        ];
    }

    // ── buildIntentProfile ─────────────────────────────────────────────────

    /**
     * Build an intent profile from the query_normalization object.
     *
     * Mirrors vascular_expert.py's inline profile construction plus
     * _key_term_candidates(): filters stop words, short terms, and generates
     * anatomic-modifier-stripped variants of multi-word terms.
     */
    public function buildIntentProfile(array $norm): array
    {
        $intent       = (string) ($norm['intent']           ?? 'general');
        $questionType = (string) ($norm['question_type']    ?? '');
        $normalizedQ  = (string) ($norm['normalized_query'] ?? '');
        $rawKeyTerms  = (array)  ($norm['key_terms']        ?? []);

        // --- Key-term filtering (port of _key_term_candidates) ---
        $stopWords = (array) config('chunk_scoring.key_term_stop_words', []);
        $anatomicModifiers = [
            'thoracic', 'abdominal', 'ascending', 'descending', 'arch',
            'thoracoabdominal', 'thoraco-abdominal', 'suprarenal',
            'infrarenal', 'juxtarenal', 'iliac',
        ];

        $filtered = [];
        $variants = [];
        foreach ($rawKeyTerms as $term) {
            $t = strtolower(trim((string) $term));
            if ($t === '' || mb_strlen($t) < 4 || in_array($t, $stopWords, true)) {
                continue;
            }
            $filtered[] = $t;
            if (str_contains($t, ' ')) {
                $words = array_filter(
                    explode(' ', $t),
                    fn(string $w): bool => !in_array($w, $anatomicModifiers, true)
                );
                $v = trim(implode(' ', $words));
                if ($v !== '' && $v !== $t && mb_strlen($v) >= 4 && !in_array($v, $stopWords, true)) {
                    $variants[] = $v;
                }
            }
        }

        $seen     = [];
        $keyTerms = [];
        foreach (array_merge($filtered, $variants) as $t) {
            if (!isset($seen[$t])) {
                $seen[$t]   = true;
                $keyTerms[] = $t;
            }
        }
        $keyTerms = array_slice($keyTerms, 0, 8);

        return [
            'intent'        => $intent,
            'question_type' => $questionType,
            'key_terms'     => $keyTerms,
            'combined_query'=> strtolower($normalizedQ),
        ];
    }

    // ── selectChunkCaps ────────────────────────────────────────────────────

    /**
     * Return LLM and UI chunk caps keyed by llm_rec, llm_narr, ui_rec, ui_narr.
     * Switches between single-guideline and multi-guideline limits.
     */
    public function selectChunkCaps(int $guidelineCount): array
    {
        $set = $guidelineCount > 1 ? 'multi' : 'single';
        $cfg = (array) config("chunk_scoring.caps.{$set}", []);
        return [
            'llm_rec'  => (int) ($cfg['llm_rec']  ?? ($guidelineCount > 1 ? 8  : 6)),
            'llm_narr' => (int) ($cfg['llm_narr'] ?? ($guidelineCount > 1 ? 8  : 4)),
            'ui_rec'   => (int) ($cfg['ui_rec']   ?? ($guidelineCount > 1 ? 18 : 12)),
            'ui_narr'  => (int) ($cfg['ui_narr']  ?? ($guidelineCount > 1 ? 12 : 8)),
        ];
    }

    // ── rankByIntent ───────────────────────────────────────────────────────

    /**
     * Sort chunks by intent-aware score (descending), stable by original index.
     * Port of _rank_chunks_by_intent.
     */
    public function rankByIntent(array $chunks, string $kind, array $profile): array
    {
        if (empty($chunks)) {
            return $chunks;
        }
        $scored = [];
        foreach ($chunks as $idx => $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            $scored[] = [
                'score' => $this->scoreChunk($chunk, $kind, $profile),
                'idx'   => $idx,
                'chunk' => $chunk,
            ];
        }
        usort($scored, static function (array $a, array $b): int {
            return $a['score'] !== $b['score']
                ? $b['score'] <=> $a['score']   // higher score first
                : $a['idx']   <=> $b['idx'];     // stable tie-break
        });
        return array_column($scored, 'chunk');
    }

    // ── scoreChunk ─────────────────────────────────────────────────────────

    /**
     * Compute an intent-aware score for a single chunk.
     * Port of _score_chunk_for_intent.
     */
    public function scoreChunk(array $chunk, string $kind, array $profile): int
    {
        $text = $this->chunkTextForScoring($chunk, $kind);
        if ($text === '') {
            return 0;
        }

        $score = 0;
        $w     = (array) config('chunk_scoring.scoring', []);
        $intent = (string) ($profile['intent'] ?? 'general');

        // Intent-term hits (+4 each)
        $intentHit = (int) ($w['intent_term_hit'] ?? 4);
        foreach ($this->intentTerms($intent) as $term) {
            if ($term !== '' && str_contains($text, strtolower((string) $term))) {
                $score += $intentHit;
            }
        }

        // Key-term hits (+3 multi-word, +1 single, +length bonus capped at 3)
        $multiHit    = (int) ($w['key_term_hit_multiword']       ?? 3);
        $singleHit   = (int) ($w['key_term_hit_single']          ?? 1);
        $lenBonusPer = (int) ($w['key_term_length_bonus_per_10'] ?? 1);
        $lenBonusMax = (int) ($w['key_term_length_bonus_max']    ?? 3);
        foreach ((array) ($profile['key_terms'] ?? []) as $term) {
            $t = strtolower(trim((string) $term));
            if ($t !== '' && str_contains($text, $t)) {
                $score += str_contains($t, ' ') ? $multiHit : $singleHit;
                $score += min((int) (mb_strlen($t) / 10), $lenBonusMax) * $lenBonusPer;
            }
        }

        // Non-A non-B dissection boost (+12)
        $combinedQuery = (string) ($profile['combined_query'] ?? '');
        if ($this->matchesNonANonB($combinedQuery) && $this->matchesNonANonB($text)) {
            $score += (int) ($w['non_a_non_b_match'] ?? 12);
        }

        // Decisive cue phrase match (+2 per phrase)
        $cueBonus = (int) ($w['cue_match'] ?? 2);
        foreach ((array) config('chunk_scoring.cue_phrases', []) as $cue) {
            if (str_contains($combinedQuery, (string) $cue) && str_contains($text, (string) $cue)) {
                $score += $cueBonus;
            }
        }

        if ($kind === 'citation' && $this->isDefinitiveTreatmentFocus($profile)) {
            $normalizedText = $this->normalizePhraseText($text);
            $directMatch = $this->matchesConfiguredTerms($normalizedText, 'chunk_scoring.definitive_treatment_direct_terms');
            $contextMatch = $this->matchesConfiguredTerms($normalizedText, 'chunk_scoring.definitive_treatment_context_terms');
            $bridgeMatch = $this->matchesConfiguredTerms($normalizedText, 'chunk_scoring.definitive_treatment_bridge_terms');

            if ($directMatch) {
                $score += (int) ($w['definitive_treatment_direct_match'] ?? 10);
            }
            if ($contextMatch) {
                $score += (int) ($w['definitive_treatment_context_match'] ?? 5);
            }
            if ($bridgeMatch && !$directMatch) {
                $score += (int) ($w['definitive_treatment_bridge_penalty'] ?? -4);
            }
        }

        if ($kind === 'citation' && $this->isComplexAaaFocus($profile)) {
            $normalizedText = $this->normalizePhraseText($text);
            $directMatches = $this->countConfiguredTermMatches($normalizedText, 'chunk_scoring.complex_aaa_direct_terms');
            $contextMatches = $this->countConfiguredTermMatches($normalizedText, 'chunk_scoring.complex_aaa_context_terms');
            $primaryAnatomyMatches = $this->countConfiguredTermMatches($normalizedText, 'chunk_scoring.complex_aaa_primary_anatomy_terms');
            $mismatchMatch = $this->matchesConfiguredTerms($normalizedText, 'chunk_scoring.complex_aaa_mismatch_terms');

            if ($directMatches > 0) {
                $directWeight = (int) ($w['complex_aaa_direct_match'] ?? 9);
                $score += $directWeight;
                $score += max(0, $directMatches - 1) * max(1, intdiv($directWeight, 3));
            }
            if ($contextMatches > 0) {
                $contextWeight = (int) ($w['complex_aaa_context_match'] ?? 4);
                $score += $contextWeight;
                $score += max(0, $contextMatches - 1) * max(1, intdiv($contextWeight, 2));
            }
            if ($primaryAnatomyMatches > 0) {
                $primaryWeight = (int) ($w['complex_aaa_primary_anatomy_match'] ?? 6);
                $score += $primaryWeight;
                $score += max(0, $primaryAnatomyMatches - 1) * max(1, intdiv($primaryWeight, 3));
            }
            if ($mismatchMatch && $directMatches === 0) {
                $score += (int) ($w['complex_aaa_mismatch_penalty'] ?? -6);
            }
        }

        // Recommendation-type boost for citation chunks (+2)
        if ($kind === 'citation') {
            $qType = (string) ($profile['question_type'] ?? '');
            $recTypes = (array) config('chunk_scoring.recommendation_question_types', []);
            if (in_array($qType, $recTypes, true)) {
                $score += (int) ($w['recommendation_type_boost'] ?? 2);
            }
        }

        // Narrative front-matter / editor's choice penalties
        if ($kind === 'narrative') {
            if (str_contains($text, 'clinical practice guideline document') ||
                str_contains($text, 'methodology')) {
                $score += (int) ($w['narrative_frontmatter_penalty'] ?? -2);
            }
            if (str_contains($text, "editor's choice")) {
                $score += (int) ($w['narrative_editors_choice_penalty'] ?? -1);
            }
        }

        return $score;
    }

    // ── diversify ──────────────────────────────────────────────────────────

    /**
     * Round-robin reorder chunks by guideline label so multi-guideline queries
     * expose early chunks from every dataset.
     * Port of _diversify_chunks.
     */
    public function diversify(array $chunks, string $kind, int $guidelineCount): array
    {
        if ($guidelineCount <= 1 || count($chunks) <= 1) {
            return $chunks;
        }

        $buckets   = [];
        $order     = [];
        $unlabeled = [];

        foreach ($chunks as $chunk) {
            if (!is_array($chunk)) {
                $unlabeled[] = $chunk;
                continue;
            }
            $label = $this->chunkGuidelineLabel($chunk, $kind);
            if ($label === '') {
                $unlabeled[] = $chunk;
                continue;
            }
            if (!isset($buckets[$label])) {
                $buckets[$label] = [];
                $order[]         = $label;
            }
            $buckets[$label][] = $chunk;
        }

        if (count($order) <= 1) {
            return $chunks;
        }

        $diversified = [];
        while (true) {
            $progressed = false;
            foreach ($order as $label) {
                if (!empty($buckets[$label])) {
                    $diversified[] = array_shift($buckets[$label]);
                    $progressed    = true;
                }
            }
            if (!$progressed) {
                break;
            }
        }

        return array_merge($diversified, $unlabeled);
    }

    // ── selectLlmSubset ────────────────────────────────────────────────────

    /**
     * Choose up to $llmLimit chunks from the UI tier for LLM consumption.
     * For multi-guideline: seeds one chunk per label first, then fills by rank.
     * Port of _select_balanced_llm_chunks.
     */
    public function selectLlmSubset(array $chunks, string $kind, int $llmLimit, int $guidelineCount): array
    {
        if ($llmLimit <= 0 || empty($chunks)) {
            return [];
        }

        // Deduplicate by key, preserving rank order
        $deduped  = [];
        $seenKeys = [];
        foreach ($chunks as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            $key = $this->chunkKey($chunk, $kind);
            if ($key !== '' && isset($seenKeys[$key])) {
                continue;
            }
            if ($key !== '') {
                $seenKeys[$key] = true;
            }
            $deduped[] = $chunk;
        }

        if (empty($deduped)) {
            return [];
        }
        if ($guidelineCount <= 1) {
            return array_slice($deduped, 0, $llmLimit);
        }

        // Multi-guideline: seed one chunk per guideline label, then fill by rank
        $selected = [];
        $usedKeys = [];

        // Collect ordered-unique labels
        $labels    = [];
        $seenLabel = [];
        foreach ($deduped as $chunk) {
            $lbl = $this->chunkGuidelineLabel($chunk, $kind);
            if ($lbl !== '' && !isset($seenLabel[$lbl])) {
                $seenLabel[$lbl] = true;
                $labels[]        = $lbl;
            }
        }

        foreach ($labels as $label) {
            if (count($selected) >= $llmLimit) {
                break;
            }
            foreach ($deduped as $chunk) {
                if ($this->chunkGuidelineLabel($chunk, $kind) !== $label) {
                    continue;
                }
                $key = $this->chunkKey($chunk, $kind);
                if ($key !== '' && isset($usedKeys[$key])) {
                    continue;
                }
                $selected[] = $chunk;
                if ($key !== '') {
                    $usedKeys[$key] = true;
                }
                break;
            }
        }

        // Fill remaining slots in rank order
        foreach ($deduped as $chunk) {
            if (count($selected) >= $llmLimit) {
                break;
            }
            $key = $this->chunkKey($chunk, $kind);
            if ($key !== '' && isset($usedKeys[$key])) {
                continue;
            }
            $selected[] = $chunk;
            if ($key !== '') {
                $usedKeys[$key] = true;
            }
        }

        return $selected;
    }

    // ── ensureCoverage ─────────────────────────────────────────────────────

    /**
     * Ensure at least one chunk per unique guideline label appears in $selected.
     * Swaps out the last slot whose label still has a spare representative.
     * Port of _ensure_multi_guideline_citation_coverage.
     */
    public function ensureCoverage(array $uiChunks, array $selected, int $llmLimit): array
    {
        if ($llmLimit <= 0 || empty($uiChunks)) {
            return [];
        }
        if (empty($selected)) {
            return array_slice($uiChunks, 0, $llmLimit);
        }
        if (count($selected) <= 1) {
            return $selected;
        }

        // Distinct labels present in the full UI set (determines target coverage)
        $uiLabels = [];
        $seenUi   = [];
        foreach ($uiChunks as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            $lbl = $this->chunkGuidelineLabel($chunk, 'citation');
            if ($lbl !== '' && !isset($seenUi[$lbl])) {
                $seenUi[$lbl] = true;
                $uiLabels[]   = $lbl;
            }
        }
        if (count($uiLabels) <= 1) {
            return $selected;
        }

        // Build a label-count map for the current selection
        $have = [];
        foreach ($selected as $c) {
            if (!is_array($c)) {
                continue;
            }
            $lbl = $this->chunkGuidelineLabel($c, 'citation');
            if ($lbl !== '') {
                $have[$lbl] = ($have[$lbl] ?? 0) + 1;
            }
        }

        foreach ($uiLabels as $missing) {
            if (isset($have[$missing])) {
                continue;
            }
            // Find a candidate for the missing label not already in selected
            $candidate = null;
            foreach ($uiChunks as $chunk) {
                if (!is_array($chunk)) {
                    continue;
                }
                if ($this->chunkGuidelineLabel($chunk, 'citation') !== $missing) {
                    continue;
                }
                if (!in_array($chunk, $selected, true)) {
                    $candidate = $chunk;
                    break;
                }
            }
            if ($candidate === null) {
                continue;
            }

            // Find the last slot whose label still has ≥2 representatives
            $replaceIdx = null;
            for ($i = count($selected) - 1; $i >= 0; $i--) {
                if (!is_array($selected[$i])) {
                    continue;
                }
                $lbl = $this->chunkGuidelineLabel($selected[$i], 'citation');
                if ($lbl !== '' && ($have[$lbl] ?? 0) >= 2) {
                    $replaceIdx = $i;
                    break;
                }
            }

            if ($replaceIdx !== null) {
                $removedLabel              = $this->chunkGuidelineLabel($selected[$replaceIdx], 'citation');
                $selected[$replaceIdx]     = $candidate;
                $have[$removedLabel]       = max(0, ($have[$removedLabel] ?? 1) - 1);
                $have[$missing]            = 1;
            } elseif (count($selected) < $llmLimit) {
                $selected[] = $candidate;
                $have[$missing] = 1;
            }
        }

        return $selected;
    }

    // ── findMustInclude ────────────────────────────────────────────────────

    /**
     * Return the citation chunk with the highest key-term match score.
     * Returns [chunk|null, score].
     * Port of _find_must_include_citation.
     */
    public function findMustInclude(array $chunks, array $keyTerms, array $profile = []): array
    {
        $best      = null;
        $bestScore = 0;
        foreach ($chunks as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            $text = $this->chunkTextForScoring($chunk, 'citation');
            $score = !empty($profile)
                ? $this->scoreChunk($chunk, 'citation', $profile)
                : $this->termMatchScore($text, $keyTerms);
            if ($score > $bestScore) {
                $best      = $chunk;
                $bestScore = $score;
            }
        }
        return [$best, $bestScore];
    }

    // ── formatRecPopup ─────────────────────────────────────────────────────

    /**
     * Format a semicolon-delimited KV recommendation string into a readable popup.
     * Port of _format_rec_popup + _parse_semicolon_kv.
     */
    public function formatRecPopup(string $raw, string $fallbackTitle = ''): string
    {
        $kv = $this->parseSemicolonKv($raw);
        if (empty($kv)) {
            return trim($raw) !== '' ? trim($raw) : $fallbackTitle;
        }

        $recId   = (string) ($kv['rec_id']             ?? $kv['recommendation_id'] ?? '');
        $glName  = (string) ($kv['guideline_name']     ?? $kv['guideline']         ?? '');
        $glYear  = (string) ($kv['guideline_year']     ?? $kv['year']              ?? '');
        $catName = (string) ($kv['category_name']      ?? '');
        $cls     = (string) ($kv['class']              ?? '');
        $lvl     = (string) ($kv['level']              ?? '');
        $authors = (string) ($kv['evidence_first_authors'] ?? $kv['evidence_authors'] ?? '');
        $text    = (string) ($kv['rec_text_verbatim']  ?? $kv['text'] ?? $kv['content'] ?? $raw);

        // Clean authors: ["A", "B"] → A; B
        $authors = trim($authors);
        if (str_starts_with($authors, '[') && str_ends_with($authors, ']')) {
            $authors = substr($authors, 1, -1);
        }
        $authors = str_replace(['"', "'"], '', $authors);

        $header = 'Recommendation';
        if ($recId !== '') {
            $header .= " {$recId}";
        }
        if ($glName !== '') {
            $header .= " — {$glName}";
        }
        if ($glYear !== '') {
            $header .= " ({$glYear})";
        }

        $lines = [$header];
        if ($catName !== '') {
            $lines[] = "Category: {$catName}";
        }
        if ($cls !== '' || $lvl !== '') {
            $lines[] = 'Strength: Class ' . ($cls ?: 'N/A') . '; Level ' . ($lvl ?: 'N/A');
        }
        if ($authors !== '') {
            $lines[] = "Evidence (first authors): {$authors}";
        }
        if ($text !== '') {
            $lines[] = '';
            $lines[] = 'Text (verbatim):';
            $lines[] = trim($text);
        }

        return implode("\n", $lines);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function intentTerms(string $intent): array
    {
        return (array) config("chunk_scoring.intent_terms.{$intent}", []);
    }

    private function chunkGuidelineLabel(array $chunk, string $kind): string
    {
        if ($kind === 'citation') {
            return trim((string) ($chunk['guideline'] ?? $chunk['source_guideline'] ?? ''));
        }
        return trim((string) ($chunk['source_guideline'] ?? $chunk['guideline'] ?? ''));
    }

    private function chunkKey(array $chunk, string $kind): string
    {
        if ($kind === 'citation') {
            $recId    = trim((string) ($chunk['recommendation_id'] ?? ''));
            $guideline = trim((string) ($chunk['guideline'] ?? ''));
            if ($recId !== '') {
                return "{$guideline}|{$recId}";
            }
            $text = strtolower(trim((string) ($chunk['text'] ?? $chunk['content'] ?? '')));
            return "{$guideline}|" . substr($text, 0, 160);
        }
        $source  = trim((string) ($chunk['source_guideline'] ?? $chunk['guideline'] ?? ''));
        $content = strtolower(trim((string) ($chunk['content'] ?? '')));
        return "{$source}|" . substr($content, 0, 160);
    }

    private function chunkTextForScoring(array $chunk, string $kind): string
    {
        $fields = [];
        if ($kind === 'citation') {
            $fields[] = (string) ($chunk['text']          ?? $chunk['content'] ?? '');
            $fields[] = (string) ($chunk['guideline']     ?? '');
            $fields[] = (string) ($chunk['category']      ?? '');
            $fields[] = (string) ($chunk['category_name'] ?? '');
            $fields[] = (string) ($chunk['class']         ?? '');
            $fields[] = (string) ($chunk['level']         ?? '');
        } else {
            $fields[] = (string) ($chunk['content']          ?? '');
            $fields[] = (string) ($chunk['source_guideline'] ?? '');
        }
        return strtolower(implode(' ', $fields));
    }

    private function termMatchScore(string $text, array $terms): int
    {
        if ($text === '' || empty($terms)) {
            return 0;
        }
        $score       = 0;
        $w           = (array) config('chunk_scoring.scoring', []);
        $multiHit    = (int) ($w['key_term_hit_multiword']       ?? 3);
        $singleHit   = (int) ($w['key_term_hit_single']          ?? 1);
        $lenBonusPer = (int) ($w['key_term_length_bonus_per_10'] ?? 1);
        $lenBonusMax = (int) ($w['key_term_length_bonus_max']    ?? 3);
        foreach ($terms as $term) {
            $t = strtolower(trim((string) $term));
            if ($t !== '' && str_contains($text, $t)) {
                $score += str_contains($t, ' ') ? $multiHit : $singleHit;
                $score += min((int) (mb_strlen($t) / 10), $lenBonusMax) * $lenBonusPer;
            }
        }
        return $score;
    }

    private function matchesNonANonB(string $text): bool
    {
        return (bool) preg_match(
            '/\bnon\s*[-\x{2010}-\x{2015}\x{2212}\x{00ad}]?\s*a\s*[,\/\-]?\s*non\s*[-\x{2010}-\x{2015}\x{2212}\x{00ad}]?\s*b\b/iu',
            $text
        );
    }

    private function isDefinitiveTreatmentFocus(array $profile): bool
    {
        $query = (string) ($profile['combined_query'] ?? '');
        $keyTerms = implode(' ', array_map('strval', (array) ($profile['key_terms'] ?? [])));
        $normalized = $this->normalizePhraseText(trim($query . ' ' . $keyTerms));

        if ($normalized === '') {
            return false;
        }

        return $this->matchesConfiguredTerms($normalized, 'chunk_scoring.definitive_treatment_focus_terms')
            && $this->matchesConfiguredTerms($normalized, 'chunk_scoring.definitive_treatment_context_terms');
    }

    private function isComplexAaaFocus(array $profile): bool
    {
        $query = (string) ($profile['combined_query'] ?? '');
        $keyTerms = implode(' ', array_map('strval', (array) ($profile['key_terms'] ?? [])));
        $normalized = $this->normalizePhraseText(trim($query . ' ' . $keyTerms));

        if ($normalized === '') {
            return false;
        }

        if (!$this->matchesConfiguredTerms($normalized, 'chunk_scoring.complex_aaa_focus_terms')) {
            return false;
        }

        return (bool) preg_match(
            '/\b(symptomatic|urgent|urgently|impending rupture|rupture|ruptured|best management|open repair|endovascular repair|stable)\b/u',
            $normalized
        );
    }

    private function matchesConfiguredTerms(string $text, string $configKey): bool
    {
        foreach ((array) config($configKey, []) as $term) {
            $normalizedTerm = $this->normalizePhraseText((string) $term);
            if ($normalizedTerm !== '' && str_contains($text, $normalizedTerm)) {
                return true;
            }
        }

        return false;
    }

    private function countConfiguredTermMatches(string $text, string $configKey): int
    {
        $count = 0;

        foreach ((array) config($configKey, []) as $term) {
            $normalizedTerm = $this->normalizePhraseText((string) $term);
            if ($normalizedTerm !== '' && str_contains($text, $normalizedTerm)) {
                $count++;
            }
        }

        return $count;
    }

    private function normalizePhraseText(string $text): string
    {
        $normalized = mb_strtolower($text);
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);

        return $normalized;
    }

    private function parseSemicolonKv(string $s): array
    {
        $out = [];
        if ($s === '' || !str_contains($s, ':')) {
            return $out;
        }
        foreach (explode(';', $s) as $part) {
            $part = trim($part);
            if ($part === '' || !str_contains($part, ':')) {
                continue;
            }
            [$k, $v] = explode(':', $part, 2);
            $k = trim($k);
            $v = trim($v);
            if ($k !== '') {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function forceInclude(array $llmChunks, array $mustChunk, string $kind, int $llmLimit): array
    {
        $mustKey = $this->chunkKey($mustChunk, $kind);
        foreach ($llmChunks as $chunk) {
            if (is_array($chunk) && $this->chunkKey($chunk, $kind) === $mustKey) {
                return $llmChunks;  // already present
            }
        }
        if (count($llmChunks) < $llmLimit) {
            $llmChunks[] = $mustChunk;
        } elseif (!empty($llmChunks)) {
            $llmChunks[count($llmChunks) - 1] = $mustChunk;
        }
        return $llmChunks;
    }
}
