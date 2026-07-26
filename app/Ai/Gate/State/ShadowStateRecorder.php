<?php

namespace App\Ai\Gate\State;

use App\Ai\Gate\State\Events\AddFact;
use App\Ai\Gate\State\Events\MessageReceived;
use App\Ai\Gate\State\Events\RecordAssumption;
use App\Ai\Gate\State\Events\RecordDeclinedQuestion;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;

final class ShadowStateRecorder
{
    /**
     * @param array<string, mixed> $priorState
     * @param array<string, mixed> $orient
     * @return array<string, mixed>
     */
    public function record(string $message, array $priorState, array $orient): array
    {
        $conversationId = $this->conversationId($message, $priorState);
        $turn = (int) ($priorState['turn_index'] ?? 0) + 1;
        $receivedAt = new DateTimeImmutable;
        $clientId = $this->clientMessageId($priorState);
        $dedupeKey = MessageIdentity::dedupeKey($conversationId, $message, $clientId, $receivedAt);
        $cacheKey = (string) config('gate-state.cache_prefix', 'gate-state:shadow:')
            .hash('sha256', $conversationId);
        $stored = Cache::get($cacheKey, []);
        $rules = (array) config('gate-state.mutually_exclusive_fields', []);
        $ledger = PatientStateLedger::restore(
            is_array($stored) ? $stored : [],
            guard: new ContradictionGuard($rules),
        );

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

                $result = $ledger->apply(new AddFact(
                    (string) $field,
                    $value,
                    $turn,
                    $this->quoteFor((string) $field, $message, $orient),
                ));
                if ($result->contradiction !== null) {
                    $contradictions[] = $result->contradiction;
                }
            }

            $this->recordDeclinedQuestions($ledger, $message, $turn, $orient);
            $this->recordPriorAssumptions($ledger, $turn, $priorState);
        }

        $projection = $ledger->projection();
        Cache::put(
            $cacheKey,
            $ledger->serialize(),
            max(1, (int) config('gate-state.retention_seconds', 86400)),
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
