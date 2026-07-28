<?php

namespace App\Ai\Gate\State;

use App\Ai\Gate\State\Events\AddFact;
use App\Ai\Gate\State\Events\CorrectFact;
use App\Ai\Gate\State\Events\MessageReceived;
use App\Ai\Gate\State\Events\RecordAssumption;
use App\Ai\Gate\State\Events\RecordDeclinedQuestion;
use DateTimeImmutable;

final class ShadowStateRecorder
{
    private readonly StateEventStore $store;

    public function __construct(
        ?StateEventStore $store = null,
    ) {
        $this->store = $store ?? new LedgerEventStore;
    }

    /**
     * @param  array<string, mixed>  $priorState
     * @param  array<string, mixed>  $orient
     * @return array<string, mixed>
     */
    public function record(string $message, array $priorState, array $orient): array
    {
        $conversationId = $this->conversationId($message, $priorState);
        $stored = $this->store->load($conversationId);
        $turnIdentity = array_key_exists('turn_index', $priorState)
            && is_numeric($priorState['turn_index'])
                ? (int) $priorState['turn_index'] + 1
                : null;
        $turn = $turnIdentity ?? $this->nextTurn($stored);
        $receivedAt = new DateTimeImmutable;
        $clientId = $this->clientMessageId($priorState);
        $dedupeKey = MessageIdentity::dedupeKey(
            $conversationId,
            $message,
            $clientId,
            $turnIdentity,
        );
        $rules = (array) config('gate-state.mutually_exclusive_fields', []);
        $fieldAliases = (array) config('gate-state.field_aliases', []);
        $allowedFields = (array) config('gate-state.canonical_fields', []);
        $guard = new ContradictionGuard($rules, $fieldAliases, $allowedFields);
        $ledger = PatientStateLedger::restore(
            $stored,
            guard: $guard,
        );
        $storedEventCount = count($stored);

        $receipt = $ledger->apply(new MessageReceived(
            $dedupeKey,
            $conversationId,
            $turn,
            $receivedAt->format(DATE_ATOM),
        ));
        $contradictions = [];

        if (! $receipt->duplicate) {
            foreach ((array) ($orient['patient_model'] ?? []) as $field => $value) {
                if ($this->missing($value)) {
                    continue;
                }

                $field = $guard->canonicalField((string) $field);
                $established = $this->valueAtPath($ledger->projection()->patientModel, $field);
                $sourceEvidence = $this->quoteFor($field, $message, $orient);
                $event = $guard->conflicts($field, $established, $value)
                    ? new CorrectFact(
                        $field,
                        $established,
                        $value,
                        $turn,
                        $sourceEvidence,
                        "Current clinician turn {$turn} reports a changed value; preserve both values and supersede the prior one.",
                    )
                    : new AddFact($field, $value, $turn, $sourceEvidence);

                $result = $ledger->apply($event);
                if ($result->contradiction !== null) {
                    $contradictions[] = $result->contradiction;
                } elseif (! $result->accepted) {
                    $contradictions[] = [
                        'field' => $field,
                        'established_value' => $established,
                        'proposed_value' => $value,
                        'reason' => $result->reason,
                    ];
                }
            }

            $this->recordDeclinedQuestions($ledger, $message, $turn, $orient);
            $this->recordPriorAssumptions($ledger, $turn, $priorState);
        }

        $projection = $ledger->projection();
        $this->store->append(
            $conversationId,
            $storedEventCount,
            array_slice($ledger->serialize(), $storedEventCount),
        );

        return [
            'duplicate' => $receipt->duplicate,
            'dedupe_key' => $dedupeKey,
            'orient_patient_model' => (array) ($orient['patient_model'] ?? []),
            'ledger_projection' => $projection->toArray(),
            'diff' => (new StateLedgerDiff)->report(
                (array) ($orient['patient_model'] ?? []),
                $projection->patientModel,
                $contradictions,
            ),
            'event_count' => count($ledger->events()),
        ];
    }

    /** @param  array<int, array<string, mixed>>  $events */
    private function nextTurn(array $events): int
    {
        $turns = array_map(
            static fn (array $event): int => (int) ($event['turn'] ?? 0),
            $events,
        );

        return max([0, ...$turns]) + 1;
    }

    /** @param array<string, mixed> $values */
    private function valueAtPath(array $values, string $path): mixed
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

    /** @param array<string, mixed> $orient */
    private function recordDeclinedQuestions(
        PatientStateLedger $ledger,
        string $message,
        int $turn,
        array $orient,
    ): void {
        foreach ((array) ($orient['open_questions'] ?? []) as $question) {
            if (! is_array($question) || ($question['status'] ?? null) !== 'declined') {
                continue;
            }

            $text = trim((string) ($question['question'] ?? ''));
            if ($text === '') {
                continue;
            }

            $field = 'question_'.substr(hash('sha256', MessageIdentity::normalizeMessage($text)), 0, 16);
            $projection = $ledger->projection();
            if (! isset($projection->declinedQuestions[$field])) {
                $ledger->apply(new RecordDeclinedQuestion($field, $text, $turn, $message));
            }
            $answer = trim((string) ($question['answer'] ?? ''));
            if ($answer !== ''
                && mb_strtolower($answer) !== 'declined'
                && ! isset($projection->assumptions[$field])) {
                $ledger->apply(new RecordAssumption($field, $answer, $turn, $answer));
            }
        }
    }

    /** @param array<string, mixed> $priorState */
    private function recordPriorAssumptions(
        PatientStateLedger $ledger,
        int $turn,
        array $priorState,
    ): void {
        foreach ((array) ($priorState['assumptions'] ?? []) as $assumption) {
            $assumption = trim((string) $assumption);
            if ($assumption === '') {
                continue;
            }

            $field = 'assumption_'.substr(
                hash('sha256', MessageIdentity::normalizeMessage($assumption)),
                0,
                16,
            );
            if (! isset($ledger->projection()->assumptions[$field])) {
                $ledger->apply(new RecordAssumption($field, $assumption, $turn, $assumption));
            }
        }
    }

    /** @param array<string, mixed> $orient */
    private function quoteFor(string $field, string $message, array $orient): string
    {
        foreach ((array) ($orient['provenance'] ?? []) as $provenance) {
            if (is_array($provenance) && ($provenance['field'] ?? null) === $field) {
                $quote = trim((string) ($provenance['verbatim_source'] ?? ''));

                return $quote !== '' ? $quote : $message;
            }
        }

        return $message;
    }

    /** @param array<string, mixed> $priorState */
    private function conversationId(string $message, array $priorState): string
    {
        foreach (['conversation_id', 'session_id', 'scenario_id'] as $key) {
            if (trim((string) ($priorState[$key] ?? '')) !== '') {
                return (string) $priorState[$key];
            }
        }

        $history = trim((string) ($priorState['raw_turn_text'] ?? ''));
        $firstMessage = $history === '' ? $message : (string) strtok($history, "\n");

        return 'synthetic-conversation:'.hash('sha256', MessageIdentity::normalizeMessage($firstMessage));
    }

    /** @param array<string, mixed> $priorState */
    private function clientMessageId(array $priorState): ?string
    {
        foreach (['idempotency_key', 'client_message_id', 'message_id'] as $key) {
            if (trim((string) ($priorState[$key] ?? '')) !== '') {
                return (string) $priorState[$key];
            }
        }

        return null;
    }

    private function missing(mixed $value): bool
    {
        return $value === null
            || $value === []
            || (is_string($value) && in_array(
                mb_strtolower(trim($value)),
                ['', 'unknown', 'not stated', 'not provided', 'n/a'],
                true,
            ));
    }
}
