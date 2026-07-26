<?php

namespace App\Ai\Gate\State;

use App\Ai\Gate\State\Events\StateEvent;

final readonly class EventApplication
{
    /** @param array<string, mixed>|null $contradiction */
    public function __construct(
        public bool $accepted,
        public bool $duplicate = false,
        public ?string $reason = null,
        public ?StateEvent $event = null,
        public ?array $contradiction = null,
    ) {}
}
