<?php

namespace App\Ai\Gate\State;

/**
 * Carries decomposed clinical facts across turns and re-renders the narrative
 * `lesion` string from them.
 *
 * MEASURED on a live 3-turn AAA case: turn 1 produced
 *   lesion "asymptomatic abdominal aortic aneurysm, 5.8 cm diameter, discovered on ultrasound"
 * and turn 2 produced
 *   lesion "juxtarenal abdominal aortic aneurysm with inadequate infrarenal neck for standard EVAR"
 * — gaining the anatomy and losing the diameter permanently, because the whole prose
 * string was overwritten. The state ledger cannot help: `lesion` is one opaque value,
 * so there is no contradiction to detect, only a replacement.
 *
 * Decomposing the facts means a turn that does not restate the diameter simply leaves
 * that field empty, and the established value is retained here. `lesion` is then
 * re-rendered from the durable fields, so every downstream consumer — retrieval query
 * construction, routing, the answer prompt, the Critic's state check — keeps reading
 * the narrative string it always read.
 *
 * The three empty-ish states are deliberately NOT equivalent:
 *   ""        the turn did not mention it            -> retain whatever was established
 *   "unknown" explicitly unknown or unmeasured       -> a fact; overwrites
 *   "n/a"     does not apply to this pathology       -> a fact; overwrites
 */
final class PatientModelProjection
{
    /** Fields carried forward when the current turn leaves them blank. */
    private const DURABLE = ['anatomy', 'laterality', 'diameter_value', 'diameter_unit'];

    /**
     * Evidence that the CURRENT turn spoke to a durable field. If the turn clearly
     * discusses one and the extraction came back empty, the extraction missed it —
     * which is categorically different from the clinician not mentioning it.
     */
    private const MENTIONED = [
        'diameter_value' => '/\b\d+(?:[.,]\d+)?\s*(?:mm|cm|millimet\w*|centimet\w*)\b/iu',
        'laterality' => '/\b(?:left|right|bilateral)\b/iu',
    ];

    /**
     * @param  array<string, mixed>  $prior       patient_model from the previous turn
     * @param  array<string, mixed>  $current     patient_model as Orient just emitted it
     * @param  string  $turnText                  the current turn, to detect a missed extraction
     * @param  array<int, string>  $suppressed    OUT: fields deliberately NOT carried forward
     * @return array<string, mixed>
     */
    public static function merge(
        array $prior,
        array $current,
        string $turnText = '',
        ?array &$suppressed = null,
    ): array {
        $suppressed = [];

        foreach (self::DURABLE as $field) {
            if (! self::isUnstated($current[$field] ?? null) || self::isUnstated($prior[$field] ?? null)) {
                continue;
            }

            // THE STALE-MODEL TRAP. Retention is safe when the clinician simply did
            // not mention the field again. It is DANGEROUS when they did mention it
            // and extraction missed it: silently restoring the old value answers a
            // premise-changing question ("what if it is now 6.5 cm?") against stale
            // clinical state, and because the model then looks complete it slips past
            // the refusal path with no degradation recorded. Refuse to retain, and
            // let the caller declare it.
            if (self::turnMentions($field, $turnText)) {
                $suppressed[] = $field;

                continue;
            }

            $current[$field] = $prior[$field];
        }

        $rendered = self::renderLesion($current);
        if ($rendered !== '') {
            $current['lesion'] = $rendered;
        }

        return $current;
    }

    /**
     * Rebuild the narrative lesion string from the durable fields, keeping any extra
     * clinical detail the model wrote that the structured fields do not capture.
     */
    public static function renderLesion(array $model): string
    {
        $anatomy = self::stated($model['anatomy'] ?? null);
        $diameter = self::renderDiameter($model);
        $narrative = trim((string) ($model['lesion'] ?? ''));

        // Nothing structured to assert — leave the model's own prose alone.
        if ($anatomy === '' && $diameter === '') {
            return '';
        }

        $parts = array_values(array_filter([$anatomy, $diameter]));

        // Preserve detail the structured fields cannot hold (for example "inadequate
        // infrarenal neck for standard EVAR"), but never duplicate what we just stated.
        if ($narrative !== '' && ! self::alreadyCovered($narrative, $parts)) {
            $parts[] = $narrative;
        }

        return implode(', ', $parts);
    }

    /** Diameter as written, never converted — "5.8 cm", not "58 mm". */
    private static function renderDiameter(array $model): string
    {
        $value = self::stated($model['diameter_value'] ?? null);
        if ($value === '' || $value === 'n/a') {
            return '';
        }
        if ($value === 'unknown') {
            return 'diameter unknown';
        }

        $unit = self::stated($model['diameter_unit'] ?? null);

        return trim($value.' '.($unit === 'unknown' ? '' : $unit)).' diameter';
    }

    /**
     * Did the current turn visibly discuss this field? Only fields with a crisp,
     * low-false-positive detector are covered; anything else is left to retention,
     * because a vague detector that fires constantly would suppress the very
     * carry-forward this class exists to provide.
     */
    private static function turnMentions(string $field, string $turnText): bool
    {
        $pattern = self::MENTIONED[$field] ?? null;

        return $pattern !== null
            && trim($turnText) !== ''
            && preg_match($pattern, $turnText) === 1;
    }

    /** A value the current turn simply did not speak to. */
    private static function isUnstated(mixed $value): bool
    {
        return trim((string) ($value ?? '')) === '';
    }

    /** Normalised value, with placeholder-ish noise treated as unstated. */
    private static function stated(mixed $value): string
    {
        $clean = trim((string) ($value ?? ''));

        return in_array(mb_strtolower($clean), ['', 'none', 'null'], true) ? '' : $clean;
    }

    /** @param array<int, string> $parts */
    private static function alreadyCovered(string $narrative, array $parts): bool
    {
        $haystack = mb_strtolower($narrative);
        foreach ($parts as $part) {
            if ($part !== '' && ! str_contains($haystack, mb_strtolower($part))) {
                return false;
            }
        }

        return $parts !== [];
    }
}
