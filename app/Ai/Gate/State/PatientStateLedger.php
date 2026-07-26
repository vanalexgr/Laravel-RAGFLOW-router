<?php

namespace App\Ai\Gate\State;

use App\Ai\Gate\State\Events\AddFact;
use App\Ai\Gate\State\Events\CorrectFact;
use App\Ai\Gate\State\Events\MessageReceived;
use App\Ai\Gate\State\Events\RecordAssumption;
use App\Ai\Gate\State\Events\RecordDeclinedQuestion;
use App\Ai\Gate\State\Events\StateEvent;

final class PatientStateLedger
{
    /** @var array<int, StateEvent> */
    private array $events;

    /**
     * @param array<int, StateEvent> $events
     */
    public function __construct(
        array $events = [],
        private readonly PatientStateReducer $reducer = new PatientStateReducer,
        private readonly ContradictionGuard $guard = new ContradictionGuard,
    ) {
        $this->events = array_values($events);
    }

    /** @param array<int, array<string, mixed>> $events */
    public static function restore(
        array $events,
        ?PatientStateReducer $reducer = null,
        ?ContradictionGuard $guard = null,
    ): self {
        return new self(
            array_map(StateEventFactory::fromArray(...), $events),
            $reducer ?? new PatientStateReducer,
            $guard ?? new ContradictionGuard,
        );
    }

    public function apply(StateEvent $event): EventApplication
    {
        if ($event instanceof MessageReceived) {
            foreach ($this->events as $recorded) {
                if ($recorded instanceof MessageReceived
                    && hash_equals($recorded->dedupeKey, $event->dedupeKey)) {
                    return new EventApplication(false, true, 'duplicate_message', $recorded);
                }
            }

            return $this->append($event);
        }

        $projection = $this->projection();

        if ($event instanceof AddFact) {
            $established = $this->reducer->get($projection->patientModel, $event->field);
            if ($this->guard->conflicts($event->field, $established, $event->value)) {
                return new EventApplication(
                    false,
                    reason: 'contradiction_requires_explicit_correction',
                    event: $event,
                    contradiction: [
                        'field' => $event->field,
                        'established_value' => $established,
                        'proposed_value' => $event->value,
                    ],
                );
            }
        }

        if ($event instanceof CorrectFact) {
            $established = $this->reducer->get($projection->patientModel, $event->field);
            if (! $this->same($established, $event->supersededValue)) {
                return new EventApplication(false, reason: 'superseded_value_does_not_match', event: $event);
            }
            if (trim($event->quote) === '' || trim($event->justification) === '') {
                return new EventApplication(false, reason: 'source_evidence_and_justification_required', event: $event);
            }
        }

        if ($event instanceof AddFact && trim($event->field) === '') {
            return new EventApplication(false, reason: 'field_required', event: $event);
        }

        if ($event instanceof RecordAssumption && trim($event->rationale) === '') {
            return new EventApplication(false, reason: 'assumption_rationale_required', event: $event);
        }

        if ($event instanceof RecordDeclinedQuestion
            && (trim($event->question) === '' || trim($event->quote) === '')) {
            return new EventApplication(false, reason: 'declined_question_and_quote_required', event: $event);
        }

        return $this->append($event);
    }

    public function projection(): PatientStateProjection
    {
        return $this->reducer->reduce($this->events);
    }

    /** @return array<int, StateEvent> */
    public function events(): array
    {
        return $this->events;
    }

    /** @return array<int, array<string, mixed>> */
    public function serialize(): array
    {
        return array_map(
            static fn (StateEvent $event): array => $event->toArray(),
            $this->events,
        );
    }

    private function append(StateEvent $event): EventApplication
    {
        $this->events[] = $event;

        return new EventApplication(true, event: $event);
    }

    private function same(mixed $left, mixed $right): bool
    {
        return json_encode($left, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            === json_encode($right, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
