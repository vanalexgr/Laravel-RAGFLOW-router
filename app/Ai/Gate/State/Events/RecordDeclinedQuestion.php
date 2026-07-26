<?php

namespace App\Ai\Gate\State\Events;

final readonly class RecordDeclinedQuestion implements StateEvent
{
    public function __construct(
        public string $field,
        public string $question,
        public int $turn,
        public string $quote,
    ) {}

    public function type(): string
    {
        return 'RecordDeclinedQuestion';
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'field' => $this->field,
            'question' => $this->question,
            'turn' => $this->turn,
            'quote' => $this->quote,
        ];
    }
}
