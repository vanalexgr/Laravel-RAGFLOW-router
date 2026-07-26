<?php

namespace App\Ai\Gate\State;

use App\Ai\Gate\State\Events\AddFact;
use App\Ai\Gate\State\Events\CorrectFact;
use App\Ai\Gate\State\Events\RecordAssumption;
use App\Ai\Gate\State\Events\RecordDeclinedQuestion;
use App\Ai\Gate\State\Events\StateEvent;

/**
 * A pure, deterministic left-fold. This class deliberately has no service,
 * model, prompt, clock, cache, or network dependency.
 */
final class PatientStateReducer
{
    /** @param array<int, StateEvent> $events */
    public function reduce(array $events): PatientStateProjection
    {
        $facts = [];
        $assumptions = [];
        $declined = [];
        $provenance = [];

        foreach ($events as $event) {
            if ($event instanceof AddFact) {
                $facts = $this->put($facts, $event->field, $this->merge(
                    $this->get($facts, $event->field),
                    $event->value,
                ));
                $provenance[$event->field] = [
                    'event' => $event->type(),
                    'turn' => $event->turn,
                    'quote' => $event->quote,
                ];
            } elseif ($event instanceof CorrectFact) {
                $facts = $this->put($facts, $event->field, $event->value);
                $provenance[$event->field] = [
                    'event' => $event->type(),
                    'turn' => $event->turn,
                    'quote' => $event->quote,
                    'justification' => $event->justification,
                    'superseded_value' => $event->supersededValue,
                ];
            } elseif ($event instanceof RecordAssumption) {
                $assumptions[$event->field] = [
                    'value' => $event->value,
                    'turn' => $event->turn,
                    'rationale' => $event->rationale,
                ];
            } elseif ($event instanceof RecordDeclinedQuestion) {
                $declined[$event->field] = [
                    'question' => $event->question,
                    'turn' => $event->turn,
                    'quote' => $event->quote,
                ];
            }
        }

        return new PatientStateProjection($facts, $assumptions, $declined, $provenance);
    }

    private function merge(mixed $prior, mixed $next): mixed
    {
        if ($prior === null) {
            return $next;
        }

        if (is_array($prior) && is_array($next) && array_is_list($prior) && array_is_list($next)) {
            $merged = $prior;
            foreach ($next as $item) {
                if (! in_array($item, $merged, true)) {
                    $merged[] = $item;
                }
            }

            return $merged;
        }

        return $next;
    }

    /** @param array<string, mixed> $values */
    public function get(array $values, string $path): mixed
    {
        $cursor = $values;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function put(array $values, string $path, mixed $value): array
    {
        $segments = explode('.', $path);
        $cursor = &$values;
        foreach ($segments as $segment) {
            if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
        $cursor = $value;

        return $values;
    }
}
