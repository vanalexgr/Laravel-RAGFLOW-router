<?php

namespace App\Ai\Gate\Evaluation;

use RuntimeException;

final class GateCandidateLedger
{
    /** @var array<string, mixed>|null */
    private ?array $bestCandidate = null;

    /** @var array<string, mixed>|null */
    private ?array $bestCritic = null;

    /**
     * Only Critic-scored candidates enter the ledger. Discrete approval outranks
     * scalar score; score breaks ties between candidates with the same status.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $critic
     */
    public function consider(array $candidate, array $critic): void
    {
        if (! is_numeric($critic['score'] ?? null) || ! is_bool($critic['approved'] ?? null)) {
            throw new RuntimeException('Critic result must contain approved and score.');
        }

        if ($this->bestCritic === null || $this->outranks($critic, $this->bestCritic)) {
            $this->bestCandidate = $candidate;
            $this->bestCritic = $critic;
        }
    }

    /**
     * @return array{candidate: array<string, mixed>, critic: array<string, mixed>}
     */
    public function best(): array
    {
        if ($this->bestCandidate === null || $this->bestCritic === null) {
            throw new RuntimeException('No candidate completed Critic scoring before the deadline.');
        }

        return [
            'candidate' => $this->bestCandidate,
            'critic' => $this->bestCritic,
        ];
    }

    /**
     * @param  array<string, mixed>  $challenger
     * @param  array<string, mixed>  $incumbent
     */
    private function outranks(array $challenger, array $incumbent): bool
    {
        $challengerApproved = (bool) $challenger['approved'];
        $incumbentApproved = (bool) $incumbent['approved'];
        if ($challengerApproved !== $incumbentApproved) {
            return $challengerApproved;
        }

        return (float) $challenger['score'] > (float) $incumbent['score'];
    }
}
