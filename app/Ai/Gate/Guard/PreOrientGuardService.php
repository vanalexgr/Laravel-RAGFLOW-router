<?php

namespace App\Ai\Gate\Guard;

final class PreOrientGuardService
{
    public const GUIDANCE_HEADER = '=== APP CAPABILITIES GUIDANCE ===';

    /**
     * Run after state load. Pending case/gate context suppresses ordinary scope
     * redirects, but prompt injection is always blocked before any model call.
     *
     * @return array{blocked: bool, mode: ?string, response: ?string}
     */
    public function evaluate(string $turn, bool $hasPendingCaseContext = false): array
    {
        $turn = trim($turn);
        $mode = $this->classify($turn);

        if ($mode === null || ($hasPendingCaseContext && $mode !== 'prompt_injection')) {
            return ['blocked' => false, 'mode' => null, 'response' => null];
        }

        return [
            'blocked' => true,
            'mode' => $mode,
            'response' => $this->response($turn, $mode),
        ];
    }

    private function classify(string $turn): ?string
    {
        if ($turn === '') {
            return null;
        }

        if (preg_match('/\b(ignore (?:all |the )?(?:previous|prior|above|system|developer|tool) instructions|disregard (?:all |the )?(?:previous|prior|system|developer|tool) instructions|answer from your own knowledge|use your own knowledge|switch to (?:normal|general) mode|leave strict mode|(?:reveal|show) (?:the )?(?:system|developer|hidden|tool) prompt|what are your (?:system|developer|hidden) instructions|tell me your (?:system|developer|hidden) instructions|bypass (?:the )?(?:rules|instructions|filter|safety|restrictions|guardrail)|jailbreak|override the rules)\b/iu', $turn)) {
            return 'prompt_injection';
        }

        if (preg_match('/\b(what (?:is )?the model you use|what model do you use|which model (?:do you use|are you)|what llm (?:do you use|are you)|training data|training exten[dt]|knowledge cutoff)\b/iu', $turn)) {
            return 'model_meta';
        }

        if (
            preg_match('/\b(how can (?:you|this app) help|can this app help|what can (?:you|this app) do|what does this app do|how (?:should|do) i use|who is this (?:for|app for)|what is this app)\b/iu', $turn)
            && ! $this->hasVascularTarget($turn)
        ) {
            return 'capabilities';
        }

        if (! $this->hasVascularTarget($turn) && preg_match('/\b(openfortivpn|linux|ubuntu|debian|nginx|docker|ssh|git|python|php|javascript|sql|excel|spreadsheet|powerpoint|email|auth0|cloudflare|dns|ssl|tls|kubernetes|devops|api key|programming|politics|president|celebrity|movie|music|football|soccer|dermatology|psychiatry|ophthalmology|oncology|endocrinology|gastroenterology)\b/iu', $turn)) {
            return 'out_of_scope';
        }

        return null;
    }

    private function hasVascularTarget(string $turn): bool
    {
        return preg_match('/\b(aaa|aneurysm|clti|critical limb|acute limb|ischaemi|ischemi|carotid|vertebral|mesenteric|renal artery|dvt|pe\b|vte|venous thrombosis|saphenous|varicose|venous ulcer|antithrombotic|aspirin|doac|dapt|vascular trauma|graft infection|endograft|vascular access|avf|fistula|tevar|evar|endarterectomy|stenting|stroke|tia|peripheral arterial disease|pad|esvs|vascular)\b/iu', $turn) === 1;
    }

    private function response(string $turn, string $mode): string
    {
        $reason = match ($mode) {
            'prompt_injection' => 'This interface cannot ignore its ESVS scope or reveal hidden instructions.',
            'model_meta' => 'This interface supports ESVS retrieval rather than model or runtime introspection.',
            'out_of_scope' => 'This app is limited to vascular ESVS guideline support.',
            default => 'This app retrieves and explains ESVS vascular guideline evidence.',
        };

        return implode("\n", [
            self::GUIDANCE_HEADER,
            '',
            $reason,
            '',
            'Ask about a vascular condition, anatomy, and decision, including material case facts when available.',
            '',
            'Scope note: this tool supports evidence retrieval and does not replace clinical judgment or local protocols.',
        ]);
    }
}
