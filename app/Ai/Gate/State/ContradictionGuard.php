<?php

namespace App\Ai\Gate\State;

final class ContradictionGuard
{
    /** @param array<string, array<string, array<int, string>>> $rules */
    public function __construct(private readonly array $rules = []) {}

    public function conflicts(string $field, mixed $established, mixed $proposed): bool
    {
        if (! array_key_exists($field, $this->rules) || $established === null) {
            return false;
        }

        return $this->canonical($field, $established) !== $this->canonical($field, $proposed);
    }

    private function canonical(string $field, mixed $value): string
    {
        $normal = $this->normalize($value);
        foreach ($this->rules[$field] as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $alias = $this->normalize($alias);
                if ($normal === $alias || ($alias !== '' && str_contains($normal, $alias))) {
                    return $canonical;
                }
            }
        }

        return $normal;
    }

    private function normalize(mixed $value): string
    {
        if (! is_scalar($value) && $value !== null) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
    }
}
