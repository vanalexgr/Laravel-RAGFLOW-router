<?php

namespace App\Ai\Gate\Decision;

final readonly class DecisionValidationResult
{
    /**
     * @param  array<int, array{code: string, message: string, path: string, trigger?: string}>  $violations
     */
    public function __construct(public array $violations)
    {
    }

    public function accepted(): bool
    {
        return $this->violations === [];
    }

    public function rejected(): bool
    {
        return ! $this->accepted();
    }

    /**
     * Deterministic feedback suitable for the next Probe refinement pass.
     */
    public function revisionPrompt(): string
    {
        if ($this->accepted()) {
            return '';
        }

        $instructions = array_map(
            static fn (array $violation): string => sprintf(
                '[%s] %s',
                $violation['code'],
                $violation['message'],
            ),
            $this->violations,
        );

        return "DECISION CONTRACT REJECTED. Revise the structured decision without inventing unsupported content.\n"
            .implode("\n", $instructions);
    }
}
