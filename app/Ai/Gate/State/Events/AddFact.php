<?php

namespace App\Ai\Gate\State\Events;

final readonly class AddFact implements StateEvent
{
    public function __construct(
        public string $field,
        public mixed $value,
        public int $turn,
        public string $quote,
    ) {}

    public function type(): string
    {
        return 'AddFact';
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'field' => $this->field,
            'value' => $this->value,
            'turn' => $this->turn,
            'quote' => $this->quote,
        ];
    }
}
