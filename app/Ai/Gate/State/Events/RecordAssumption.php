<?php

namespace App\Ai\Gate\State\Events;

final readonly class RecordAssumption implements StateEvent
{
    public function __construct(
        public string $field,
        public mixed $value,
        public int $turn,
        public string $rationale,
    ) {}

    public function type(): string
    {
        return 'RecordAssumption';
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'field' => $this->field,
            'value' => $this->value,
            'turn' => $this->turn,
            'rationale' => $this->rationale,
        ];
    }
}
