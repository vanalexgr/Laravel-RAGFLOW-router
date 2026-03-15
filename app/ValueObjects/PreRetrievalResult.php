<?php

namespace App\ValueObjects;

class PreRetrievalResult
{
    public function __construct(
        public readonly bool $proceed,
        public readonly bool $softWarn,
        public readonly array $clarificationQuestions,
        public readonly string $provisionalDiagnosis,
        public readonly array $guidelines,
        public readonly string $retrievalQuery,
        public readonly string $scope,
        public readonly string $confirmationMessage,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $questions = $data['clarification_questions'] ?? [];
        if (!is_array($questions)) {
            $questions = [];
        }

        $guidelines = $data['guidelines'] ?? [];
        if (!is_array($guidelines)) {
            $guidelines = [];
        }

        return new self(
            proceed: (bool) ($data['proceed'] ?? true),
            softWarn: (bool) ($data['soft_warn'] ?? false),
            clarificationQuestions: array_values($questions),
            provisionalDiagnosis: (string) ($data['provisional_diagnosis'] ?? ''),
            guidelines: array_values($guidelines),
            retrievalQuery: (string) ($data['retrieval_query'] ?? ''),
            scope: (string) ($data['scope'] ?? 'single_guideline'),
            confirmationMessage: (string) ($data['confirmation_message'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'proceed' => $this->proceed,
            'soft_warn' => $this->softWarn,
            'clarification_questions' => $this->clarificationQuestions,
            'provisional_diagnosis' => $this->provisionalDiagnosis,
            'guidelines' => $this->guidelines,
            'retrieval_query' => $this->retrievalQuery,
            'scope' => $this->scope,
            'confirmation_message' => $this->confirmationMessage,
        ];
    }
}
