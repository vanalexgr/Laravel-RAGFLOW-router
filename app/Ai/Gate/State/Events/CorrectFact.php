<?php

namespace App\Ai\Gate\State\Events;

final readonly class CorrectFact implements StateEvent
{
    public function __construct(
        public string $field,
        public mixed $supersededValue,
        public mixed $value,
        public int $turn,
        public string $quote,
        public string $justification,
    ) {}

    public function type(): string
    {
        return 'CorrectFact';
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'field' => $this->field,
            'superseded_value' => $this->supersededValue,
            'value' => $this->value,
            'turn' => $this->turn,
            'quote' => $this->quote,
            'justification' => $this->justification,
        ];
    }
}
