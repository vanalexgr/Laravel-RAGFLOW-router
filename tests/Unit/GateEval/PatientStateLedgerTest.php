<?php

namespace Tests\Unit\GateEval;

use App\Ai\Gate\State\ContradictionGuard;
use App\Ai\Gate\State\Events\AddFact;
use App\Ai\Gate\State\Events\CorrectFact;
use App\Ai\Gate\State\Events\MessageReceived;
use App\Ai\Gate\State\Events\RecordAssumption;
use App\Ai\Gate\State\Events\RecordDeclinedQuestion;
use App\Ai\Gate\State\MessageIdentity;
use App\Ai\Gate\State\PatientStateLedger;
use DateTimeImmutable;
use Tests\TestCase;

class PatientStateLedgerTest extends TestCase
{
    public function test_aaa_three_turn_replay_loses_no_previously_established_field(): void
    {
        $ledger = new PatientStateLedger;

        $this->recordTurn($ledger, 'aaa-case', 'msg-1', 1, [
            'age' => 78,
            'sex' => 'male',
            'aneurysm_diameter_mm' => 68,
            'symptom_status' => 'asymptomatic',
        ]);
        // Orient omitted the four earlier facts and emitted only new anatomy.
        $this->recordTurn($ledger, 'aaa-case', 'msg-2', 2, [
            'anatomy' => 'juxtarenal',
            'evar_suitability' => 'unsuitable',
        ]);
        // Orient omitted all earlier facts and emitted only renal function.
        $this->recordTurn($ledger, 'aaa-case', 'msg-3', 3, [
            'egfr' => 28,
        ]);

        $this->assertSame([
            'age' => 78,
            'sex' => 'male',
            'aneurysm_diameter_mm' => 68,
            'symptom_status' => 'asymptomatic',
            'anatomy' => 'juxtarenal',
            'evar_suitability' => 'unsuitable',
            'egfr' => 28,
        ], $ledger->projection()->patientModel);
    }

    public function test_contradictory_add_is_rejected_but_quoted_explicit_correction_is_accepted(): void
    {
        $ledger = new PatientStateLedger(
            guard: new ContradictionGuard([
                'symptom_status' => [
                    'asymptomatic' => ['asymptomatic'],
                    'symptomatic' => ['symptomatic'],
                ],
            ]),
        );
        $ledger->apply(new AddFact('symptom_status', 'asymptomatic', 1, 'No pain or embolic symptoms.'));

        $rejected = $ledger->apply(new AddFact(
            'symptom_status',
            'symptomatic',
            2,
            'What intervention is recommended for symptomatic AAA?',
        ));

        $this->assertFalse($rejected->accepted);
        $this->assertSame('contradiction_requires_explicit_correction', $rejected->reason);
        $this->assertSame('asymptomatic', $ledger->projection()->patientModel['symptom_status']);

        $accepted = $ledger->apply(new CorrectFact(
            'symptom_status',
            'asymptomatic',
            'symptomatic',
            3,
            'Correction: he developed new abdominal pain this morning.',
            'Clinician explicitly reports new attributable abdominal pain.',
        ));

        $this->assertTrue($accepted->accepted);
        $this->assertSame('symptomatic', $ledger->projection()->patientModel['symptom_status']);
    }

    public function test_declined_question_and_operating_assumption_survive_a_subsequent_turn(): void
    {
        $ledger = new PatientStateLedger;
        $ledger->apply(new RecordDeclinedQuestion(
            'frailty',
            'Can you provide a frailty score?',
            1,
            'I cannot obtain a frailty score.',
        ));
        $ledger->apply(new RecordAssumption(
            'frailty',
            'treat as potentially frail',
            1,
            'Proceed conservatively because the clinician cannot provide the score.',
        ));
        $ledger->apply(new AddFact('aneurysm_diameter_mm', 61, 2, 'Diameter is 61 mm.'));

        $projection = $ledger->projection();
        $this->assertSame(
            'Can you provide a frailty score?',
            $projection->declinedQuestions['frailty']['question'],
        );
        $this->assertSame(
            'treat as potentially frail',
            $projection->assumptions['frailty']['value'],
        );
        $this->assertSame(61, $projection->patientModel['aneurysm_diameter_mm']);
    }

    public function test_duplicate_message_is_recognised_and_merged_exactly_once(): void
    {
        $ledger = new PatientStateLedger;
        $receivedAt = new DateTimeImmutable('2026-07-26T12:34:20+00:00');
        $key = MessageIdentity::dedupeKey(
            'aaa-case',
            '  Diameter   is 68 MM. ',
            null,
            $receivedAt,
        );

        $first = $ledger->apply(new MessageReceived(
            $key,
            'aaa-case',
            1,
            $receivedAt->format(DATE_ATOM),
        ));
        if (! $first->duplicate) {
            $ledger->apply(new AddFact('aneurysm_diameter_mm', 68, 1, 'Diameter is 68 mm.'));
        }

        $duplicate = $ledger->apply(new MessageReceived(
            MessageIdentity::dedupeKey(
                'aaa-case',
                'diameter is 68 mm.',
                null,
                new DateTimeImmutable('2026-07-26T12:34:55+00:00'),
            ),
            'aaa-case',
            1,
            '2026-07-26T12:34:55+00:00',
        ));
        if (! $duplicate->duplicate) {
            $ledger->apply(new AddFact('aneurysm_diameter_mm', 68, 1, 'Diameter is 68 mm.'));
        }

        $this->assertTrue($first->accepted);
        $this->assertTrue($duplicate->duplicate);
        $this->assertCount(2, $ledger->events());
        $this->assertSame(68, $ledger->projection()->patientModel['aneurysm_diameter_mm']);
    }

    /** @param array<string, mixed> $facts */
    private function recordTurn(
        PatientStateLedger $ledger,
        string $conversationId,
        string $messageId,
        int $turn,
        array $facts,
    ): void {
        $receipt = $ledger->apply(new MessageReceived(
            MessageIdentity::dedupeKey($conversationId, '', $messageId),
            $conversationId,
            $turn,
            "2026-07-26T12:0{$turn}:00+00:00",
        ));
        if ($receipt->duplicate) {
            return;
        }

        foreach ($facts as $field => $value) {
            $ledger->apply(new AddFact($field, $value, $turn, "Clinician supplied {$field}."));
        }
    }
}
