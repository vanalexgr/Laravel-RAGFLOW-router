<?php

namespace App\Ai\Gate\State;

use InvalidArgumentException;

final class ContradictionGuard
{
    /**
     * @param  array<string, array<string, array<int, string>>>  $rules
     * @param  array<string, string>  $fieldAliases
     * @param  array<int, string>  $allowedFields
     */
    public function __construct(
        private readonly array $rules = [],
        private readonly array $fieldAliases = [],
        private readonly array $allowedFields = [],
    ) {}

    public function conflicts(string $field, mixed $established, mixed $proposed): bool
    {
        $field = $this->canonicalField($field);
        if (! array_key_exists($field, $this->rules) || $established === null) {
            return false;
        }

        return $this->canonical($field, $established) !== $this->canonical($field, $proposed);
    }

    public function canonicalField(string $field): string
    {
        $normalized = mb_strtolower(trim($field));
        $canonical = $this->fieldAliases[$normalized] ?? $normalized;
        if ($this->allowedFields !== [] && ! in_array($canonical, $this->allowedFields, true)) {
            throw new InvalidArgumentException("Unknown clinical field name: {$normalized}");
        }

        return $canonical;
    }

    private function canonical(string $field, mixed $value): string
    {
        $normal = $this->normalize($value);

        // Exact matches win, especially explicit negated aliases such as
        // "not symptomatic", before any phrase search is attempted.
        foreach ($this->rules[$field] as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if ($normal === $this->normalize($alias)) {
                    return $canonical;
                }
            }
        }

        foreach ($this->rules[$field] as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $alias = $this->normalize($alias);
                if ($alias !== '' && $this->containsWholePhrase($normal, $alias)) {
                    return $canonical;
                }
            }
        }

        return $normal;
    }

    private function containsWholePhrase(string $text, string $phrase): bool
    {
        $pattern = '/(?<![\pL\pN])'.preg_quote($phrase, '/').'(?![\pL\pN])/u';
        if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return false;
        }

        // A positive alias must not invert an immediately negated statement.
        $prefix = substr($text, 0, (int) $match[0][1]);

        return preg_match('/(?:^|\s)(?:not|no|without|denies|denied|deny)\s*$/u', $prefix) !== 1;
    }

    private function normalize(mixed $value): string
    {
        if (! is_scalar($value) && $value !== null) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
    }
}
