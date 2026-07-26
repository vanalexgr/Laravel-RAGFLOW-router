<?php

namespace App\Ai\Gate\State\Events;

interface StateEvent
{
    public function type(): string;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
