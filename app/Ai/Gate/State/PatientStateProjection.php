<?php

namespace App\Ai\Gate\State;

final readonly class PatientStateProjection
{
    /**
     * @param array<string, mixed> $patientModel
     * @param array<string, array<string, mixed>> $assumptions
     * @param array<string, array<string, mixed>> $declinedQuestions
     * @param array<string, array<string, mixed>> $provenance
     */
    public function __construct(
        public array $patientModel = [],
        public array $assumptions = [],
        public array $declinedQuestions = [],
        public array $provenance = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'patient_model' => $this->patientModel,
            'assumptions' => $this->assumptions,
            'declined_questions' => $this->declinedQuestions,
            'provenance' => $this->provenance,
        ];
    }
}
