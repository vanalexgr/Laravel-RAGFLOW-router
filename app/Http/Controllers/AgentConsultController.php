<?php

namespace App\Http\Controllers;

use App\Agents\VascularConsultAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vizra\VizraADK\Services\StateManager;

class AgentConsultController extends Controller
{
    public function __construct(private readonly StateManager $stateManager)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => 'required|string|max:2000',
            'session_key' => 'required|string|max:64',
            'guidelines' => 'nullable|array|max:3',
            'guidelines.*' => 'string',
            'history' => 'nullable|array|max:20',
            'history.*' => 'string|max:2000',
        ]);

        $question = trim($validated['question']);
        $sessionKey = $validated['session_key'];
        $guidelines = array_values(array_slice($validated['guidelines'] ?? [], 0, 3));
        $history = array_values(array_slice($validated['history'] ?? [], -20));

        if ($clarification = $this->buildClarificationPrompt($question, $guidelines, $history, $sessionKey)) {
            return response()->json([
                'response' => $clarification,
                'citations' => [],
                'narratives' => [],
                'assets' => [],
                'gap_assessment' => [],
                'mode' => 'CLARIFY',
                'session_key' => $sessionKey,
            ]);
        }

        $response = VascularConsultAgent::run($question)
            ->withSession($sessionKey)
            ->withContext([
                'requested_guidelines' => $guidelines,
                'client_history' => $history,
                'include_history' => true,
                'context_strategy' => 'recent',
                'history_depth' => 12,
            ])
            ->go();

        $context = $this->stateManager->loadContext('vascular_consult', $sessionKey);
        $toolResult = $context->getState('last_tool_result', []);
        $responseText = $this->normalizeResponse($response);

        return response()->json([
            'response' => $responseText,
            'citations' => $toolResult['citation_chunks'] ?? [],
            'narratives' => $toolResult['narrative_chunks'] ?? [],
            'assets' => $toolResult['assets'] ?? [],
            'gap_assessment' => $toolResult['gap_assessment'] ?? [],
            'mode' => $this->extractMode($responseText),
            'session_key' => $sessionKey,
        ]);
    }

    private function normalizeResponse(mixed $response): string
    {
        if (is_string($response)) {
            return $response;
        }

        return json_encode(
            $response,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: 'No response from agent.';
    }

    private function extractMode(string $text): string
    {
        if (preg_match('/\*\*Mode:\*\*\s*(COMPACT|STANDARD|FULL)/i', $text, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return 'STANDARD';
    }

    /**
     * @param  array<int, string>  $guidelines
     * @param  array<int, string>  $history
     */
    private function buildClarificationPrompt(string $question, array $guidelines, array $history, string $sessionKey): ?string
    {
        $input = mb_strtolower(trim($question));
        if ($input === '' || ! $this->isPatientSpecificCase($input)) {
            return null;
        }

        if ($history !== []) {
            return null;
        }

        $context = $this->stateManager->loadContext('vascular_consult', $sessionKey);
        if ($context->getConversationHistory()->count() > 0) {
            return null;
        }

        return match ($this->inferClarificationScenario($input, $guidelines)) {
            'carotid_stenosis' => $this->missingCarotidContext($input)
                ? 'This app provides ESVS vascular guideline decision support for vascular clinical questions.'."\n".
                    'For carotid stenosis management, please clarify whether your patient is symptomatic or asymptomatic and provide the degree of stenosis by NASCET criteria so I can give you an evidence-based recommendation.'
                : null,
            'aaa_treatment' => $this->missingAaaContext($input)
                ? 'This app provides ESVS vascular guideline decision support for vascular clinical questions.'."\n".
                    'For AAA management, please provide the maximum aneurysm diameter and whether the patient is fit for intervention or has major comorbidities that materially affect repair risk.'
                : null,
            'dvt_pe' => $this->missingDvtContext($input)
                ? 'This app provides ESVS vascular guideline decision support for vascular clinical questions.'."\n".
                    'For venous thrombosis management, please clarify whether this event is provoked or unprovoked and whether it is a first or recurrent VTE.'
                : null,
            'clti' => $this->missingCltiContext($input)
                ? 'This app provides ESVS vascular guideline decision support for vascular clinical questions.'."\n".
                    'For CLTI management, please provide the anatomical workup or classification (for example duplex or CTA runoff, ABI, Rutherford, or WIfI) and the patient fitness, frailty, or life expectancy relevant to revascularisation.'
                : null,
            'ali' => $this->missingAliContext($input)
                ? 'This app provides ESVS vascular guideline decision support for vascular clinical questions.'."\n".
                    'For acute limb ischaemia, please clarify the severity (Rutherford class, motor or sensory deficit, and duration) and whether the suspected cause is embolic or thrombotic.'
                : null,
            'type_b_dissection' => $this->missingTypeBDissectionContext($input)
                ? 'This app provides ESVS vascular guideline decision support for vascular clinical questions.'."\n".
                    'For type B dissection, please clarify whether the case is complicated or uncomplicated and whether it is acute, subacute, or chronic.'
                : null,
            'graft_infection' => $this->missingGraftInfectionContext($input)
                ? 'This app provides ESVS vascular guideline decision support for vascular clinical questions.'."\n".
                    'For suspected graft infection, please provide the presentation or imaging findings (for example fever, sepsis, CT or PET findings, fistula, or haemorrhage) and the prosthesis type plus timing from implantation.'
                : null,
            default => $this->missingGenericCaseContext($input)
                ? 'This app provides ESVS vascular guideline decision support for vascular clinical questions.'."\n".
                    'Please clarify the exact diagnosis, the current clinical presentation, the relevant anatomy or imaging findings, and the main management question you want answered.'
                : null,
        };
    }

    private function isPatientSpecificCase(string $input): bool
    {
        return preg_match(
            '/\b(my patient|this patient|the patient|patient\b|case\b|\d{1,3}\s*(?:year[- ]old|yo)|male\b|female\b|man\b|woman\b|presents? with|presented with|referred with)\b/i',
            $input
        ) === 1;
    }

    /**
     * @param  array<int, string>  $guidelines
     */
    private function inferClarificationScenario(string $input, array $guidelines): string
    {
        if (in_array('carotid_vertebral', $guidelines, true) || str_contains($input, 'carotid')) {
            return 'carotid_stenosis';
        }

        if (in_array('abdominal_aortic_aneurysm', $guidelines, true) || preg_match('/\b(aaa|aneurysm)\b/i', $input) === 1) {
            return 'aaa_treatment';
        }

        if (in_array('venous_thrombosis', $guidelines, true) || preg_match('/\b(dvt|pe|vte)\b/i', $input) === 1) {
            return 'dvt_pe';
        }

        if (in_array('clti', $guidelines, true) || str_contains($input, 'clti')) {
            return 'clti';
        }

        if (in_array('acute_limb_ischaemia', $guidelines, true) || preg_match('/\b(acute limb ischaemia|acute limb ischemia|ali)\b/i', $input) === 1) {
            return 'ali';
        }

        if (in_array('descending_thoracic_aorta', $guidelines, true) && preg_match('/\b(type b|dissection)\b/i', $input) === 1) {
            return 'type_b_dissection';
        }

        if (in_array('vascular_graft_infections', $guidelines, true) || preg_match('/\b(graft infection|endograft infection|fistula)\b/i', $input) === 1) {
            return 'graft_infection';
        }

        return 'generic_case';
    }

    private function missingCarotidContext(string $input): bool
    {
        $hasSymptoms = preg_match('/\b(symptomatic|asymptomatic|tia|stroke|amaurosis fugax)\b/i', $input) === 1;
        $hasSeverity = preg_match('/\b\d{1,3}\s*%|\bnascet\b|\b(severe|moderate|mild)\s+stenosis\b/i', $input) === 1;

        return ! ($hasSymptoms && $hasSeverity);
    }

    private function missingAaaContext(string $input): bool
    {
        $hasDiameter = preg_match('/\b\d+(?:\.\d+)?\s*(?:mm|cm)\b|\bdiameter\b/i', $input) === 1;
        $hasFitness = preg_match('/\b(fit|frail|comorbid|comorbidity|surgical candidate|operative risk|high risk)\b/i', $input) === 1;

        return ! ($hasDiameter && $hasFitness);
    }

    private function missingDvtContext(string $input): bool
    {
        $hasProvoked = preg_match('/\b(provoked|unprovoked)\b/i', $input) === 1;
        $hasEpisodeCount = preg_match('/\b(first|recurrent|repeat|previous)\b/i', $input) === 1;

        return ! ($hasProvoked && $hasEpisodeCount);
    }

    private function missingCltiContext(string $input): bool
    {
        $hasWorkup = preg_match('/\b(duplex|cta|runoff|abi|rutherford|wifi|wifi|glass)\b/i', $input) === 1;
        $hasFitness = preg_match('/\b(fit|frail|life expectancy|comorbid|comorbidity)\b/i', $input) === 1;

        return ! ($hasWorkup && $hasFitness);
    }

    private function missingAliContext(string $input): bool
    {
        $hasSeverity = preg_match('/\b(rutherford|motor|sensory|duration|6ps|6 ps)\b/i', $input) === 1;
        $hasCause = preg_match('/\b(embolic|embolus|thrombotic|thrombosis)\b/i', $input) === 1;

        return ! ($hasSeverity && $hasCause);
    }

    private function missingTypeBDissectionContext(string $input): bool
    {
        $hasComplexity = preg_match('/\b(complicated|uncomplicated)\b/i', $input) === 1;
        $hasTiming = preg_match('/\b(acute|subacute|chronic)\b/i', $input) === 1;

        return ! ($hasComplexity && $hasTiming);
    }

    private function missingGraftInfectionContext(string $input): bool
    {
        $hasPresentation = preg_match('/\b(fever|sepsis|ct|pet|fistula|haemorrhage|hemorrhage)\b/i', $input) === 1;
        $hasTiming = preg_match('/\b(days?|weeks?|months?|years?)\b/i', $input) === 1;

        return ! ($hasPresentation && $hasTiming);
    }

    private function missingGenericCaseContext(string $input): bool
    {
        return preg_match('/\b(symptomatic|asymptomatic|diameter|cta|duplex|rutherford|wifi|provoked|unprovoked|acute|chronic)\b/i', $input) !== 1;
    }
}
