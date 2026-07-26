<?php

namespace App\Ai\Gate\State;

final class StateLedgerDiff
{
    /**
     * @param array<string, mixed> $orient
     * @param array<string, mixed> $ledger
     * @param array<int, array<string, mixed>> $contradictions
     * @return array<string, mixed>
     */
    public function report(array $orient, array $ledger, array $contradictions = []): array
    {
        $orient = $this->flatten($orient);
        $ledger = $this->flatten($ledger);
        $dropped = [];
        $added = [];

        foreach ($ledger as $field => $value) {
            if ($this->missing($orient[$field] ?? null) && ! $this->missing($value)) {
                $dropped[$field] = $value;
            }
        }

        foreach ($orient as $field => $value) {
            if ($this->missing($ledger[$field] ?? null) && ! $this->missing($value)) {
                $added[$field] = $value;
            }
        }

        return [
            'orient_dropped_ledger_retained' => $dropped,
            'orient_added' => $added,
            'contradictions_blocked' => array_values($contradictions),
        ];
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $field => $value) {
            $path = $prefix === '' ? (string) $field : $prefix.'.'.$field;
            if (is_array($value) && ! array_is_list($value)) {
                $flat += $this->flatten($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    private function missing(mixed $value): bool
    {
        return $value === null
            || $value === []
            || (is_string($value) && in_array(
                mb_strtolower(trim($value)),
                ['', 'unknown', 'not stated', 'not provided', 'n/a'],
                true,
            ));
    }
}
