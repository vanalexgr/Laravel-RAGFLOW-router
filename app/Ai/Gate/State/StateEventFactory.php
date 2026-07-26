<?php

namespace App\Ai\Gate\State;

use App\Ai\Gate\State\Events\AddFact;
use App\Ai\Gate\State\Events\CorrectFact;
use App\Ai\Gate\State\Events\MessageReceived;
use App\Ai\Gate\State\Events\RecordAssumption;
use App\Ai\Gate\State\Events\RecordDeclinedQuestion;
use App\Ai\Gate\State\Events\StateEvent;
use InvalidArgumentException;

final class StateEventFactory
{
    /** @param array<string, mixed> $event */
    public static function fromArray(array $event): StateEvent
    {
        return match ($event['type'] ?? null) {
            'AddFact' => new AddFact(
                (string) $event['field'],
                $event['value'] ?? null,
                (int) $event['turn'],
                (string) ($event['quote'] ?? ''),
            ),
            'CorrectFact' => new CorrectFact(
                (string) $event['field'],
                $event['superseded_value'] ?? null,
                $event['value'] ?? null,
                (int) $event['turn'],
                (string) ($event['quote'] ?? ''),
                (string) ($event['justification'] ?? ''),
            ),
            'RecordAssumption' => new RecordAssumption(
                (string) $event['field'],
                $event['value'] ?? null,
                (int) $event['turn'],
                (string) ($event['rationale'] ?? ''),
            ),
            'RecordDeclinedQuestion' => new RecordDeclinedQuestion(
                (string) $event['field'],
                (string) ($event['question'] ?? ''),
                (int) $event['turn'],
                (string) ($event['quote'] ?? ''),
            ),
            'MessageReceived' => new MessageReceived(
                (string) $event['dedupe_key'],
                (string) $event['conversation_id'],
                (int) $event['turn'],
                (string) $event['received_at'],
            ),
            default => throw new InvalidArgumentException('Unknown state event type.'),
        };
    }
}
