<?php

namespace App\Ai\Gate\Decision;

final class DecisionContractValidator
{
    /** @var array<string, mixed> */
    private array $rules;

    /**
     * @param  array<string, mixed>|null  $rules
     */
    public function __construct(?array $rules = null)
    {
        $this->rules = $rules ?? (array) config('gate-decision');
    }

    /**
     * @param  array<string, mixed>  $decision
     * @param  array<string, mixed>|string  $context
     */
    public function validate(array $decision, array|string $context = []): DecisionValidationResult
    {
        $violations = [];
        $actionablePlan = is_array($decision['actionable_plan'] ?? null)
            ? $decision['actionable_plan']
            : [];

        $this->validateDeferralBoundary($actionablePlan, $violations);
        $this->validateActionablePlan($decision, $actionablePlan, $violations);

        $searchableText = $this->flattenText([$context, $decision]);
        $this->validateTriggeredChecklists($decision, $searchableText, $violations);
        $this->validateLongTermCombination($actionablePlan, $violations);

        return new DecisionValidationResult($this->uniqueViolations($violations));
    }

    /**
     * @param  array<string, mixed>  $actionablePlan
     * @param  array<int, array{code: string, message: string, path: string, trigger?: string}>  $violations
     */
    private function validateDeferralBoundary(array $actionablePlan, array &$violations): void
    {
        $text = $this->flattenText($actionablePlan);
        $usesDeferralLanguage = $this->matchesAny(
            $text,
            (array) ($this->rules['deferral_terms'] ?? []),
        );

        $deferralCode = trim((string) ($actionablePlan['deferral_justification'] ?? ''));
        $validCodes = (array) ($this->rules['deferral_codes'] ?? []);

        if ($usesDeferralLanguage && ! in_array($deferralCode, $validCodes, true)) {
            $violations[] = [
                'code' => 'UNWARRANTED_DEFERRAL',
                'message' => 'You deferred to an MDT, individualisation, clinical judgement, or a local protocol without a valid typed conflict. Synthesize the default plan and identify the precise arbitration need.',
                'path' => 'actionable_plan.deferral_justification',
            ];
        }

        if ($this->containsEvidenceAbsent($actionablePlan)
            && ! in_array($deferralCode, $validCodes, true)) {
            $violations[] = [
                'code' => 'UNCODED_EVIDENCE_ABSENCE',
                'message' => 'EVIDENCE_ABSENT is the non-fabricating escape hatch, but it must be paired with a valid typed deferral code.',
                'path' => 'actionable_plan.deferral_justification',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $decision
     * @param  array<string, mixed>  $actionablePlan
     * @param  array<int, array{code: string, message: string, path: string, trigger?: string}>  $violations
     */
    private function validateActionablePlan(
        array $decision,
        array $actionablePlan,
        array &$violations,
    ): void {
        $coverage = (string) ($decision['evidence_status']['coverage'] ?? '');

        if (! in_array($coverage, (array) ($this->rules['covered_evidence_statuses'] ?? []), true)) {
            return;
        }

        $meaningfulValues = array_filter(
            [
                $actionablePlan['timing'] ?? null,
                $actionablePlan['pharmacotherapy_regimen'] ?? null,
                $actionablePlan['what_not_to_do'] ?? null,
            ],
            fn (mixed $value): bool => $this->hasSubstantiveValue($value),
        );

        if ($meaningfulValues === []) {
            $violations[] = [
                'code' => 'EMPTY_ACTIONABLE_PLAN_WITH_COVERAGE',
                'message' => 'Evidence coverage exists, so state the supported default and patient-specific modification instead of leaving the actionable plan empty.',
                'path' => 'actionable_plan',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $decision
     * @param  array<int, array{code: string, message: string, path: string, trigger?: string}>  $violations
     */
    private function validateTriggeredChecklists(
        array $decision,
        string $searchableText,
        array &$violations,
    ): void {
        foreach ((array) ($this->rules['triggers'] ?? []) as $triggerName => $trigger) {
            if (! is_array($trigger) || ! $this->matchesAllGroups($searchableText, (array) ($trigger['all'] ?? []))) {
                continue;
            }

            foreach ((array) ($trigger['requirements'] ?? []) as $requirementName => $requirement) {
                if (! is_array($requirement) || $this->requirementIsMet($decision, $searchableText, $requirement)) {
                    continue;
                }

                $violations[] = [
                    'code' => 'CHECKLIST_ITEM_MISSING',
                    'message' => (string) ($requirement['message'] ?? "Address {$requirementName}."),
                    'path' => 'actionable_plan',
                    'trigger' => (string) $triggerName,
                ];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $actionablePlan
     * @param  array<int, array{code: string, message: string, path: string, trigger?: string}>  $violations
     */
    private function validateLongTermCombination(array $actionablePlan, array &$violations): void
    {
        $matrix = (array) ($this->rules['long_term_combination'] ?? []);
        $regimen = trim((string) ($actionablePlan['pharmacotherapy_regimen'] ?? ''));

        if (! $this->matchesAny($regimen, (array) ($matrix['anticoagulants'] ?? []))
            || ! $this->matchesAny($regimen, (array) ($matrix['antiplatelets'] ?? []))
            || ! $this->matchesAny($regimen, (array) ($matrix['long_term_terms'] ?? []))) {
            return;
        }

        $justification = trim((string) ($actionablePlan['antithrombotic_combination_justification'] ?? ''));
        $emptyJustifications = (array) ($matrix['empty_justifications'] ?? []);

        if (in_array($justification, $emptyJustifications, true)) {
            $violations[] = [
                'code' => 'UNJUSTIFIED_LONG_TERM_ANTICOAGULANT_ANTIPLATELET',
                'message' => 'Long-term anticoagulant plus antiplatelet therapy requires an explicit indication; otherwise remove the combination.',
                'path' => 'actionable_plan.antithrombotic_combination_justification',
                'trigger' => 'antithrombotic',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $requirement
     */
    private function requirementIsMet(array $decision, string $text, array $requirement): bool
    {
        return match ($requirement['type'] ?? null) {
            'patterns' => $this->matchesAny($text, (array) ($requirement['patterns'] ?? [])),
            'pattern_groups' => $this->matchesAllGroups($text, (array) ($requirement['groups'] ?? [])),
            'non_empty_path' => $this->hasSubstantiveValue(
                $this->valueAtPath($decision, (string) ($requirement['path'] ?? '')),
                allowEvidenceAbsent: true,
            ),
            default => false,
        };
    }

    /**
     * @param  array<int, array<int, string>>  $groups
     */
    private function matchesAllGroups(string $text, array $groups): bool
    {
        if ($groups === []) {
            return false;
        }

        foreach ($groups as $patterns) {
            if (! $this->matchesAny($text, (array) $patterns)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function matchesAny(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (@preg_match('~'.$pattern.'~iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function valueAtPath(array $value, string $path): mixed
    {
        $current = $value;

        foreach (array_filter(explode('.', $path), 'strlen') as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    private function hasSubstantiveValue(mixed $value, bool $allowEvidenceAbsent = false): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->hasSubstantiveValue($item, $allowEvidenceAbsent)) {
                    return true;
                }
            }

            return false;
        }

        if (! is_scalar($value)) {
            return false;
        }

        $normalized = trim((string) $value);

        return $normalized !== ''
            && ($allowEvidenceAbsent || $normalized !== 'EVIDENCE_ABSENT');
    }

    private function containsEvidenceAbsent(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsEvidenceAbsent($item)) {
                    return true;
                }
            }

            return false;
        }

        return is_string($value) && trim($value) === 'EVIDENCE_ABSENT';
    }

    private function flattenText(mixed $value): string
    {
        if (is_array($value)) {
            return implode(' ', array_map(
                fn (mixed $item): string => $this->flattenText($item),
                $value,
            ));
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param  array<int, array{code: string, message: string, path: string, trigger?: string}>  $violations
     * @return array<int, array{code: string, message: string, path: string, trigger?: string}>
     */
    private function uniqueViolations(array $violations): array
    {
        $seen = [];
        $unique = [];

        foreach ($violations as $violation) {
            $key = implode('|', [
                $violation['code'],
                $violation['path'],
                $violation['trigger'] ?? '',
                $violation['message'],
            ]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $violation;
        }

        return $unique;
    }
}
