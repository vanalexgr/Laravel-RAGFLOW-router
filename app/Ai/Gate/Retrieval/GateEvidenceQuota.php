<?php

namespace App\Ai\Gate\Retrieval;

/**
 * Reserves a share of every evidence budget for verbatim recommendations.
 *
 * The legacy adapter's dual retrieval reserved capacity per bucket — its
 * `ragflow.retrieval.evidence_caps` are narrative 16 / citation 12, so roughly
 * 60% guideline prose to 40% verbatim recommendations on every query. Gate v2
 * had instead flattened both buckets into one list at three separate points
 * (retrieval tool, retry merge, prompt compaction), and each flattening was
 * free to drop recommendations. In Run 8, 10 of 16 guideline branches reached
 * the answering stage with **zero** recommendations.
 *
 * Applying the same minimum share at every cap keeps the guarantee end to end.
 */
final class GateEvidenceQuota
{
    /**
     * Fraction of a budget reserved for the citation (recommendations) bucket.
     */
    public static function citationShare(): float
    {
        $share = (float) config('gate-v2.retrieval.citation_share', 0.4);

        return max(0.0, min(1.0, $share));
    }

    /**
     * Citation slots to reserve out of `$capacity`. The configured share is a
     * minimum guarantee, so fractional slots round up. At least one is reserved
     * whenever the budget and share both allow it.
     */
    public static function citationSlots(int $capacity): int
    {
        if ($capacity <= 0) {
            return 0;
        }
        $share = self::citationShare();
        if ($share <= 0.0) {
            return 0;
        }

        return max(1, min($capacity, (int) ceil($capacity * $share)));
    }

    /**
     * Fill `$capacity` from both buckets, honouring the citation reservation and
     * backfilling from whichever bucket under-delivers. Recommendations lead the
     * returned list so any later head-truncation keeps them.
     *
     * Never returns fewer items than a plain first-come fill would have, so this
     * can only change the mix, never shrink the evidence.
     *
     * @param  array<int, array<string, mixed>>  $citation
     * @param  array<int, array<string, mixed>>  $narrative
     * @return array<int, array<string, mixed>>
     */
    public static function fill(array $citation, array $narrative, int $capacity): array
    {
        if ($capacity <= 0) {
            return [];
        }

        $citationSlots = self::citationSlots($capacity);
        $taken = array_slice($citation, 0, $citationSlots);
        $takenNarrative = array_slice($narrative, 0, $capacity - $citationSlots);

        $spare = $capacity - count($taken) - count($takenNarrative);
        if ($spare > 0) {
            $taken = array_merge($taken, array_slice($citation, count($taken), $spare));
            $spare = $capacity - count($taken) - count($takenNarrative);
        }
        if ($spare > 0) {
            $takenNarrative = array_merge(
                $takenNarrative,
                array_slice($narrative, count($takenNarrative), $spare),
            );
        }

        return array_merge($taken, $takenNarrative);
    }

    /**
     * Partition a mixed list back into its two buckets, preserving order.
     *
     * @param  array<int, array<string, mixed>>  $snippets
     * @return array{citation: array<int, array<string, mixed>>, narrative: array<int, array<string, mixed>>}
     */
    public static function partition(array $snippets): array
    {
        $buckets = ['citation' => [], 'narrative' => []];
        foreach ($snippets as $snippet) {
            $key = ($snippet['bucket'] ?? null) === 'citation' ? 'citation' : 'narrative';
            $buckets[$key][] = $snippet;
        }

        return $buckets;
    }
}
