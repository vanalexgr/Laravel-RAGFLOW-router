<?php

namespace App\Services;

use App\Contracts\LlmClient;
use App\ValueObjects\PreRetrievalResult;
use Illuminate\Support\Facades\Log;

class PreRetrievalService
{
    protected const CONFIRM_REPLY_LINE = 'Reply to confirm, or add details to refine the search.';
    protected const GUIDELINE_DISPLAY_NAMES = [
        'aortic_arch' => 'Aortic Arch',
        'descending_thoracic_aorta' => 'Thoracic Aorta',
        'abdominal_aortic_aneurysm' => 'Abdominal Aortic Aneurysm',
        'mesenteric_renal' => 'Mesenteric & Renal',
        'asymptomatic_pad' => 'Asymptomatic PAD',
        'clti' => 'Chronic Limb-Threatening Ischemia',
        'acute_limb_ischaemia' => 'Acute Limb Ischaemia',
        'carotid_vertebral' => 'Carotid & Vertebral',
        'venous_thrombosis' => 'Venous Thrombosis (DVT/PE)',
        'chronic_venous_disease' => 'Chronic Venous Disease',
        'antithrombotic_therapy' => 'Antithrombotic Therapy',
        'vascular_trauma' => 'Vascular Trauma',
        'vascular_graft_infections' => 'Vascular Graft Infections',
        'vascular_access' => 'Vascular Access',
    ];

    public function __construct(
        private readonly LlmClient $llm,
        ?array $availableGuidelines = null,
    ) {
        $this->availableGuidelines = $availableGuidelines ?? $this->loadAvailableGuidelines();
    }

    protected array $availableGuidelines = [];

    public function analyse(string $question, array $history = []): PreRetrievalResult
    {
        $question = trim($question);
        if ($question === '') {
            return PreRetrievalResult::fromArray($this->safeDefaults($question));
        }

        try {
            $prompt = $this->buildPrompt($question, $history, $this->availableGuidelines);
            $raw = $this->llm->complete($prompt, maxTokens: 600, temperature: 0);
            $data = $this->parseJson($raw);

            if ($data === null) {
                Log::channel('retrieval')->warning('[PRE-RETRIEVAL] JSON parse failed, using safe defaults', [
                    'question_preview' => substr($question, 0, 120),
                    'raw_preview' => substr($raw, 0, 240),
                ]);
                return PreRetrievalResult::fromArray($this->safeDefaults($question));
            }

            return PreRetrievalResult::fromArray($this->normalizeData($data, $question));
        } catch (\Throwable $e) {
            Log::channel('retrieval')->warning('[PRE-RETRIEVAL] LLM call failed, using safe defaults', [
                'question_preview' => substr($question, 0, 120),
                'error' => $e->getMessage(),
            ]);

            return PreRetrievalResult::fromArray($this->safeDefaults($question));
        }
    }

    public function applyRequestedGuidelines(PreRetrievalResult $result, ?array $requestedKeys = null): PreRetrievalResult
    {
        $guidelines = $this->normalizeGuidelineSelection(
            $result->guidelines,
            is_array($requestedKeys) ? $requestedKeys : [],
            '',
            $result->provisionalDiagnosis,
            $result->retrievalQuery
        );

        return PreRetrievalResult::fromArray([
            'proceed' => $result->proceed,
            'soft_warn' => $result->softWarn,
            'clarification_questions' => $result->clarificationQuestions,
            'provisional_diagnosis' => $result->provisionalDiagnosis,
            'guidelines' => $guidelines,
            'retrieval_query' => $result->retrievalQuery,
            'scope' => $result->scope,
            'confirmation_message' => $this->buildConfirmationMessage(
                $result->provisionalDiagnosis,
                $guidelines,
                $result->retrievalQuery,
                $result->softWarn,
                $result->clarificationQuestions
            ),
        ]);
    }

    protected function buildPrompt(string $question, array $history, array $availableGuidelines): string
    {
        $historyText = '';
        if (!empty($history)) {
            $recent = array_slice(array_values(array_filter(array_map(
                fn($entry) => is_string($entry) ? trim($entry) : '',
                $history
            ), fn(string $entry): bool => $entry !== '')), -4);

            if (!empty($recent)) {
                $historyText = "Recent conversation:\n" . implode("\n", $recent) . "\n\n";
            }
        }

        return <<<PROMPT
You are a vascular surgery clinical query analyser for a guideline retrieval system.

Your role is to interpret the user's question like a vascular surgeon and prepare
the optimal guideline retrieval plan.

Analyse the question and return a JSON object with exactly these 8 fields:

proceed
soft_warn
clarification_questions
provisional_diagnosis
guidelines
retrieval_query
scope
confirmation_message

Return ONLY valid JSON.
No explanations.
No markdown.
No extra text.


CLINICAL REASONING PROCESS

Interpret the question as a vascular surgeon assessing a clinical scenario.

Internally determine:
1. Primary vascular disease or syndrome
2. Anatomical vascular territory
3. Acuity (acute vs chronic)
4. Symptomatic status
5. Whether the question concerns:
   - patient management
   - procedural decision
   - medical therapy
   - guideline knowledge

Choose guidelines based on vascular pathology and anatomy, not keywords alone.

If recent history already answers previously missing details, treat those details
as known and avoid asking for them again.


AVAILABLE GUIDELINES AND THEIR FOCUS

aortic_arch
Focus: aortic arch and supra-aortic branch pathology when the primary disease
involves the arch or the origins of the innominate, carotid, or subclavian arteries.
Typical conditions: aortic arch aneurysm, arch dissection, arch penetrating aortic ulcer,
intramural haematoma involving the arch, hybrid arch repair, frozen elephant trunk,
supra-aortic debranching, cerebral malperfusion due to arch disease.
Trigger phrases: arch aneurysm, arch dissection, supra-aortic branch involvement,
arch repair, debranching, innominate artery origin disease.

descending_thoracic_aorta
Focus: descending thoracic and thoracoabdominal aortic disease distal to the arch.
Typical conditions: descending thoracic aneurysm, thoracoabdominal aneurysm,
complex aortic aneurysm involving visceral branches, type B aortic dissection,
non-A non-B dissection, penetrating aortic ulcer, intramural haematoma,
TEVAR decisions, thoracoabdominal repair, extent I-V thoracoabdominal aneurysm.
Trigger phrases: type B dissection, thoracoabdominal aneurysm, descending thoracic aorta,
TEVAR, distal to left subclavian, complex aortic aneurysm, visceral segment aneurysm,
non A non B dissection.

abdominal_aortic_aneurysm
Focus: infrarenal and juxtarenal abdominal aortic aneurysm disease.
Typical conditions: infrarenal AAA, juxtarenal aneurysm, pararenal aneurysm,
AAA surveillance, ruptured AAA, EVAR vs open repair, neck anatomy, iliac extension.
Trigger phrases: AAA, infrarenal aneurysm, EVAR, AAA rupture, AAA surveillance.

mesenteric_renal
Focus: visceral and renal arterial disease.
Typical conditions: chronic mesenteric ischaemia, acute mesenteric ischaemia from arterial occlusion,
renal artery stenosis, renal artery aneurysm, visceral artery aneurysm, SMA stenosis, coeliac artery stenosis.
Trigger phrases: mesenteric ischemia, SMA, coeliac artery, renal artery stenosis, visceral artery aneurysm.

carotid_vertebral
Focus: extracranial carotid and vertebral artery disease.
Typical conditions: symptomatic carotid stenosis, asymptomatic carotid stenosis, TIA,
minor stroke, carotid dissection, carotid thrombus, vertebral artery disease,
carotid endarterectomy, carotid stenting.
Trigger phrases: carotid stenosis, TIA, stroke, CEA, CAS, carotid plaque,
carotid thrombus, vertebral artery stenosis.

asymptomatic_pad
Focus: peripheral arterial disease without limb threat.
Typical conditions: intermittent claudication, asymptomatic PAD, ABI screening,
exercise therapy, risk factor management.
Trigger phrases: claudication, ABI, walking distance limitation, asymptomatic lower limb arterial disease.

clti
Focus: chronic limb-threatening ischaemia.
Typical conditions: rest pain, tissue loss, ulcer, gangrene, WIfI classification,
limb salvage revascularisation.
Trigger phrases: rest pain, foot ulcer, gangrene, diabetic foot ischemia, WIfI.

acute_limb_ischaemia
Focus: acute arterial occlusion of a limb.
Typical conditions: embolism, acute thrombosis, bypass graft occlusion,
popliteal aneurysm thrombosis, Rutherford acute limb ischemia.
Trigger phrases: sudden painful leg, acute limb ischemia, embolus, thrombosed aneurysm.

antithrombotic_therapy
Focus: antiplatelet and anticoagulant therapy in vascular patients.
Typical conditions: antiplatelet therapy, anticoagulation, dual antiplatelet therapy,
peri-procedural antithrombotic management, DOAC or warfarin use in vascular disease,
post-operative antithrombotic regimen after bypass or endovascular intervention.
Trigger phrases: antithrombotic, antithrombotic therapy, antiplatelet, anticoagulation,
aspirin, clopidogrel, DAPT, rivaroxaban, DOAC, warfarin, post-operative medication,
perioperative anticoagulation, bleeding risk modifier (ITP, APS, recent GI bleed).

venous_thrombosis
Focus: venous thromboembolism.
Typical conditions: deep vein thrombosis, pulmonary embolism, upper limb DVT, iliofemoral thrombosis.
Trigger phrases: DVT, PE, venous thrombosis.

chronic_venous_disease
Focus: chronic venous insufficiency and superficial venous disease.
Typical conditions: varicose veins, venous reflux, venous ulcer, CEAP classification, endovenous ablation.
Trigger phrases: varicose veins, venous ulcer, saphenous reflux.

vascular_trauma
Focus: vascular injury due to trauma.
Typical conditions: penetrating arterial injury, blunt vascular trauma, iatrogenic vascular injury treated as trauma.
Trigger phrases: gunshot, stab wound, vascular trauma, arterial injury.

vascular_graft_infections
Focus: prosthetic graft or endograft infection.
Typical conditions: prosthetic graft infection, endograft infection, aortoenteric fistula, infected bypass.
Trigger phrases: graft infection, endograft infection, aortoenteric fistula.

vascular_access
Focus: haemodialysis access surgery.
Typical conditions: AV fistula creation, AV graft, access stenosis, dialysis access thrombosis, steal syndrome.
Trigger phrases: dialysis fistula, AV graft, haemodialysis access.


GUIDELINE SELECTION RULES

Select 1-3 guideline keys.

Prefer the guideline covering the primary anatomical disease.
Add a second or third guideline only if the clinical problem clearly spans another vascular territory.

Examples:
- arch dissection causing carotid stroke -> aortic_arch + carotid_vertebral
- EVAR antiplatelet therapy question -> abdominal_aortic_aneurysm + antithrombotic_therapy
- AAA with concomitant CLTI -> abdominal_aortic_aneurysm + clti (both conditions require guideline evidence)
- post-bypass or post-endovascular antithrombotic management -> primary procedure guideline + antithrombotic_therapy
- CLTI with comorbidity affecting antithrombotic choice (ITP, APS, AF) -> clti + antithrombotic_therapy

ANTITHROMBOTIC GUIDELINE RULE:
Always add antithrombotic_therapy when:
- The question asks about antithrombotic, antiplatelet, or anticoagulation therapy after any vascular procedure
- The question asks about post-operative or perioperative medication management
- The question mentions a bleeding-risk condition (ITP, APS, recent bleed, anticoagulation on warfarin/DOAC) in a surgical context
Do NOT rely only on drug names (aspirin, DOAC) as triggers — the words "antithrombotic therapy", "anticoagulation", "antiplatelet", or "post-op medication" are equally strong triggers.

CO-DISEASE MANDATORY RULE:
When two named vascular conditions appear in the same question, you MUST select a guideline for EACH.
This is not optional — both conditions require evidence.

MANDATORY combinations:
- AAA (any size) + CLTI (any Rutherford) → abdominal_aortic_aneurysm + clti
- AAA + gangrene or tissue loss → abdominal_aortic_aneurysm + clti
- AAA + rest pain → abdominal_aortic_aneurysm + clti
- CLTI + bypass or post-op management → clti + antithrombotic_therapy
- bypass surgery (any) + post-op medication question → primary guideline + antithrombotic_therapy
- claudication or IC + post-endovascular antithrombotic → asymptomatic_pad + antithrombotic_therapy

Do NOT select only the "dominant" condition when both are explicitly stated. Both need guideline evidence to answer the question.

Special aortic dissection rule:
- If the dissection is distal to or just above the left subclavian, or described as non-A non-B,
  descending_thoracic_aorta is usually the primary guideline.
- Add aortic_arch when the arch or supra-aortic branches are involved.
- Add carotid_vertebral when carotid extension, carotid thrombus, TIA, or stroke are part of the case.

Do not select more than 3 guidelines.


SOFT WARN RULES

soft_warn = true only when missing information would significantly change management
or materially improve retrieval quality.

Examples:
- symptomatic vs asymptomatic
- time from neurological event
- aneurysm size
- haemodynamic stability
- rupture suspicion

If soft_warn = true, clarification_questions must contain 1-3 short questions.
Ask only for missing details.
Never ask for information already present in the question or recent history.

Special venous rule:
- saphenous vein thrombosis is superficial by definition.
- Never ask whether saphenous thrombosis is superficial or deep.
- Instead ask about extent, distance from the SFJ/SPJ, and associated DVT or PE when missing.


PROVISIONAL DIAGNOSIS

One sentence describing the clinical situation like a vascular consultant would summarise it.
Always in English.


RETRIEVAL QUERY RULES

Write a query optimized for guideline retrieval.

- 15-30 words
- English
- lowercase
- no punctuation

Include disease, anatomy, acuity, procedural terms, and important measurements if present.

Expand abbreviations such as:
- TIA -> transient ischemic attack
- CEA -> carotid endarterectomy
- EVAR -> endovascular aneurysm repair
- TEVAR -> thoracic endovascular aortic repair
- CLTI -> chronic limb threatening ischemia

If non-A non-B dissection, distal-to-left-subclavian dissection, or arch-plus-thoracic dissection
is described, explicitly include non a non b dissection and descending thoracic aorta terms.


SCOPE

Return one of:
- knowledge_question
- single_guideline
- multi_guideline


CONFIRMATION MESSAGE FORMAT

Line 1
Clinical Query Checkpoint

Blank line
🩺 Understanding
[provisional_diagnosis]

Blank line
📚 Searching
[guideline display names]

Blank line
🏷️ Query Terms
[3-6 keywords]

Blank line only if soft_warn = true
❓ To Sharpen
- [clarification question 1]
- [clarification question 2]
- [clarification question 3]

Final line
✅ Reply to confirm, or add details to refine the search.


{$historyText}CURRENT QUESTION:
{$question}
PROMPT;
    }

    protected function parseJson(string $raw): ?array
    {
        $clean = trim((string) preg_replace('/```(?:json)?|```/i', '', $raw));
        if ($clean === '') {
            return null;
        }

        if (preg_match('/\{[\s\S]*\}/u', $clean, $matches)) {
            $clean = $matches[0];
        }

        $decoded = json_decode($clean, true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function safeDefaults(string $question): array
    {
        return [
            'proceed' => true,
            'soft_warn' => false,
            'clarification_questions' => [],
            'provisional_diagnosis' => '',
            'guidelines' => [],
            'retrieval_query' => $question,
            'scope' => 'single_guideline',
            'confirmation_message' => 'Searching guidelines...',
        ];
    }

    protected function normalizeData(array $data, string $question): array
    {
        $guidelines = $data['guidelines'] ?? [];
        if (!is_array($guidelines)) {
            $guidelines = [];
        }

        $allowedKeys = array_keys($this->availableGuidelines);
        $guidelines = array_values(array_unique(array_filter(array_map(
            fn($key) => is_string($key) ? trim($key) : '',
            $guidelines
        ), fn(string $key): bool => in_array($key, $allowedKeys, true))));
        $guidelines = array_slice($guidelines, 0, 3);

        $questions = $data['clarification_questions'] ?? [];
        if (!is_array($questions)) {
            $questions = [];
        }
        $questions = array_values(array_filter(array_map(
            fn($item) => is_string($item) ? trim($item) : '',
            $questions
        ), fn(string $item): bool => $item !== ''));

        $scope = (string) ($data['scope'] ?? 'single_guideline');
        if (!in_array($scope, ['knowledge_question', 'single_guideline', 'multi_guideline'], true)) {
            $scope = 'single_guideline';
        }

        $retrievalQuery = trim((string) ($data['retrieval_query'] ?? ''));
        if ($retrievalQuery === '') {
            $retrievalQuery = $question;
        }

        $provisionalDiagnosis = trim((string) ($data['provisional_diagnosis'] ?? ''));
        [$provisionalDiagnosis, $retrievalQuery] = $this->normalizeHighPriorityDiagnosisAndQuery(
            $question,
            $provisionalDiagnosis,
            $retrievalQuery
        );
        $guidelines = $this->normalizeGuidelineSelection(
            $guidelines,
            [],
            $question,
            $provisionalDiagnosis,
            $retrievalQuery
        );
        $questions = $this->normalizeClarificationQuestions($questions, $question, $provisionalDiagnosis, $retrievalQuery);

        $softWarn = (bool) ($data['soft_warn'] ?? false);
        [$softWarn, $questions] = $this->enforceDeterministicClarificationRules(
            $softWarn,
            $questions,
            $question,
            $provisionalDiagnosis,
            $retrievalQuery
        );
        if (!$softWarn) {
            $questions = [];
        }

        $confirmationMessage = $this->buildConfirmationMessage(
            $provisionalDiagnosis,
            $guidelines,
            $retrievalQuery,
            $softWarn,
            $questions
        );

        return [
            'proceed' => (bool) ($data['proceed'] ?? true),
            'soft_warn' => $softWarn,
            'clarification_questions' => $questions,
            'provisional_diagnosis' => $provisionalDiagnosis,
            'guidelines' => $guidelines,
            'retrieval_query' => $retrievalQuery,
            'scope' => $scope,
            'confirmation_message' => $confirmationMessage,
        ];
    }

    protected function loadAvailableGuidelines(): array
    {
        $guidelines = [];

        foreach (config('guidelines.categories', []) as $category) {
            foreach (($category['guidelines'] ?? []) as $key => $info) {
                $guidelines[$key] = $info['name'] ?? $key;
            }
        }

        return $guidelines;
    }

    protected function normalizeHighPriorityDiagnosisAndQuery(
        string $question,
        string $provisionalDiagnosis,
        string $retrievalQuery
    ): array {
        $combined = implode(' ', array_filter([$question, $provisionalDiagnosis, $retrievalQuery]));

        if ($this->isNonANonBDissectionContext($combined)) {
            $provisionalDiagnosis = $this->buildNonANonBDiagnosis($combined);
            $retrievalQuery = $this->appendUniqueQueryTerms($retrievalQuery, [
                'non a non b dissection',
                'left subclavian',
                'descending thoracic aorta',
                preg_match('/\bcarotid\b/i', $combined) ? 'carotid extension' : null,
                preg_match('/\bthrombus\b/i', $combined) ? 'carotid thrombus' : null,
                preg_match('/\b(stroke|tia|ischaemic|ischemic)\b/i', $combined) ? 'ischemic stroke' : null,
            ]);
        }

        return [$provisionalDiagnosis, $retrievalQuery];
    }

    protected function normalizeGuidelineSelection(
        array $guidelines,
        array $requestedKeys,
        string $question,
        string $provisionalDiagnosis,
        string $retrievalQuery
    ): array {
        $allowedKeys = array_keys($this->availableGuidelines);
        $merged = array_values(array_unique(array_filter(array_merge($requestedKeys, $guidelines), function ($key) use ($allowedKeys): bool {
            return is_string($key) && in_array($key, $allowedKeys, true);
        })));

        $combined = implode(' ', array_filter([$question, $provisionalDiagnosis, $retrievalQuery]));

        if ($this->isThoracicDissectionContext($combined)) {
            $merged = $this->prependGuideline('descending_thoracic_aorta', $merged);
        }

        if ($this->isComplexAaaContext($combined)) {
            $merged = $this->prependGuideline('abdominal_aortic_aneurysm', $merged);
            $merged = $this->insertGuideline('descending_thoracic_aorta', $merged, 'abdominal_aortic_aneurysm');
        }

        if ($this->isNonANonBDissectionContext($combined) || $this->hasArchLandingZoneContext($combined)) {
            $merged = $this->insertGuideline('aortic_arch', $merged, 'descending_thoracic_aorta');
        }

        if ($this->hasCarotidNeurovascularContext($combined)) {
            $merged = $this->insertGuideline('carotid_vertebral', $merged, 'aortic_arch');
        }

        // AAA + CLTI co-disease: ensure both guidelines present
        if ($this->isAaaWithCltiContext($combined)) {
            $merged = $this->prependGuideline('abdominal_aortic_aneurysm', $merged);
            $merged = $this->insertGuideline('clti', $merged, 'abdominal_aortic_aneurysm');
        }

        // Post-bypass or post-endovascular antithrombotic management: always add antithrombotic_therapy
        if ($this->isPostProcedureAntithromboticContext($combined)) {
            if (!in_array('antithrombotic_therapy', $merged, true)) {
                $merged[] = 'antithrombotic_therapy';
            }
        }

        // Vein bypass antithrombotic context: bypass antithrombotic recs live in the CLTI guideline,
        // not asymptomatic_pad. Replace asymptomatic_pad with clti when bypass is confirmed.
        if ($this->isVeinBypassAntithromboticContext($combined)) {
            if (!in_array('clti', $merged, true)) {
                $merged[] = 'clti';
            }
            // asymptomatic_pad has no bypass-specific antithrombotic recs — remove to free a slot
            $merged = array_values(array_filter($merged, fn($k) => $k !== 'asymptomatic_pad'));
        }

        return array_slice($merged, 0, 3);
    }

    protected function enforceDeterministicClarificationRules(
        bool $softWarn,
        array $questions,
        string $question,
        string $provisionalDiagnosis,
        string $retrievalQuery
    ): array {
        $supplemental = $this->aorticFistulaClarificationQuestions($question, $provisionalDiagnosis, $retrievalQuery);
        if (!empty($supplemental)) {
            foreach ($supplemental as $item) {
                if (!in_array($item, $questions, true)) {
                    $questions[] = $item;
                }
            }
            $softWarn = true;
        }

        return [$softWarn, array_slice(array_values(array_unique($questions)), 0, 3)];
    }

    protected function normalizeClarificationQuestions(
        array $questions,
        string $question,
        string $provisionalDiagnosis,
        string $retrievalQuery
    ): array {
        $questions = array_values(array_unique(array_filter(array_map(
            fn($item) => trim((string) $item),
            $questions
        ), fn(string $item): bool => $item !== '')));

        if ($this->isSaphenousThrombosisContext($question, $provisionalDiagnosis, $retrievalQuery)) {
            $questions = $this->normalizeSaphenousClarificationQuestions($questions);
        }

        return array_slice($questions, 0, 3);
    }

    protected function aorticFistulaClarificationQuestions(
        string $question,
        string $provisionalDiagnosis,
        string $retrievalQuery
    ): array {
        $combined = implode(' ', array_filter([$question, $provisionalDiagnosis, $retrievalQuery]));
        if (
            !preg_match('/\b(aort|thoracic\s+aorta|thoracic\s+aneurysm|tevar|endograft|graft)\b/i', $combined)
            || !preg_match('/\b(haematemesis|hematemesis|haemorrhag|hemorrhag|oesophag|esophag|fistul)\b/i', $combined)
        ) {
            return [];
        }

        $source = $question !== '' ? $question : $combined;
        $questions = [];

        if (!preg_match('/\b(left\s+subclavian|distal\s+to\s+(?:the\s+)?left\s+subclavian|arch|zone\s*[0-2])\b/i', $source)) {
            $questions[] = 'Is the aneurysm or repair distal to the left subclavian artery or involving the arch?';
        }

        if (!preg_match('/\b(cta|ct\s+(?:scan|angiography|angio)|pet|endoscopy|confirmed|confirm|infection|infected|fever|sepsis|crp|wbc|white\s+cell)\b/i', $source)) {
            $questions[] = 'Is there confirmed aorto-oesophageal fistula or graft/endograft infection on CT, endoscopy, or PET, or is this suspected clinically?';
        }

        if (!preg_match('/\b(stable|unstable|haemodynamic|hemodynamic|shock|resuscitat|active\s+bleeding|ongoing\s+bleeding|hypotension|blood\s+pressure|bp\b)\b/i', $source)) {
            $questions[] = 'Is the patient haemodynamically stable, or is there ongoing active bleeding or shock?';
        }

        return array_slice($questions, 0, 3);
    }

    protected function isSaphenousThrombosisContext(
        string $question,
        string $provisionalDiagnosis,
        string $retrievalQuery
    ): bool {
        $combined = implode(' ', array_filter([$question, $provisionalDiagnosis, $retrievalQuery]));

        return (bool) preg_match(
            '/\b(saphen(?:ous)?|great saphenous|small saphenous|gsv|ssv|sfj|spj)\b/i',
            $combined
        ) && (bool) preg_match(
            '/\b(thrombus|thrombosis|svt|superficial vein thrombosis)\b/i',
            $combined
        );
    }

    protected function normalizeSaphenousClarificationQuestions(array $questions): array
    {
        $filtered = array_values(array_filter($questions, function (string $question): bool {
            return !preg_match('/\b(superficial|deep)\b.{0,24}\b(superficial|deep)\b/i', $question);
        }));

        $location = null;
        $extent = null;
        $dvt = null;
        $other = [];

        foreach ($filtered as $question) {
            if ($location === null && preg_match('/\b(sfj|spj|junction|distance|location|where|proximity)\b/i', $question)) {
                $location = $question;
                continue;
            }

            if ($extent === null && preg_match('/\b(length|extent|ultrasound|cm)\b/i', $question)) {
                $extent = $question;
                continue;
            }

            if ($dvt === null && preg_match('/\b(deep vein thrombosis|dvt|pulmonary embolism|pe)\b/i', $question)) {
                $dvt = $question;
                continue;
            }

            $other[] = $question;
        }

        $ordered = [];
        $ordered[] = $location ?? 'How far is the thrombus from the sapheno-femoral or sapheno-popliteal junction?';

        if ($extent !== null) {
            $ordered[] = $extent;
        }

        if ($dvt !== null) {
            $ordered[] = $dvt;
        }

        foreach ($other as $question) {
            $ordered[] = $question;
        }

        return array_values(array_unique(array_slice($ordered, 0, 3)));
    }

    protected function buildConfirmationMessage(
        string $provisionalDiagnosis,
        array $guidelines,
        string $retrievalQuery,
        bool $softWarn,
        array $questions
    ): string
    {
        if ($provisionalDiagnosis === '' && empty($guidelines) && trim($retrievalQuery) === '') {
            return 'Searching guidelines...';
        }

        $normalized = ['Clinical Query Checkpoint', ''];
        $normalized[] = '🩺 Understanding';
        $normalized[] = $provisionalDiagnosis !== '' ? $provisionalDiagnosis : 'Clinical vascular question';
        $normalized[] = '';
        $normalized[] = '📚 Searching';
        $normalized[] = $this->formatGuidelineDisplayNames($guidelines);

        $queryTerms = $this->extractQueryTerms($retrievalQuery);
        if (!empty($queryTerms)) {
            $normalized[] = '';
            $normalized[] = '🏷️ Query Terms';
            $normalized[] = implode(', ', $queryTerms);
        }

        if ($softWarn && !empty($questions)) {
            $normalized[] = '';
            $normalized[] = '❓ To Sharpen';
            foreach ($questions as $question) {
                $normalized[] = '- ' . $question;
            }
        }

        $normalized[] = '';
        $normalized[] = '✅ ' . self::CONFIRM_REPLY_LINE;

        return implode("\n", $normalized);
    }

    protected function formatGuidelineDisplayNames(array $guidelines): string
    {
        if (empty($guidelines)) {
            return 'Selected guidelines';
        }

        $names = array_map(function (string $key): string {
            return self::GUIDELINE_DISPLAY_NAMES[$key] ?? ($this->availableGuidelines[$key] ?? $key);
        }, array_values($guidelines));

        return implode(', ', array_values(array_unique($names)));
    }

    protected function extractQueryTerms(string $retrievalQuery): array
    {
        $query = trim($retrievalQuery);
        if ($query === '') {
            return [];
        }

        $terms = [];
        $patterns = [
            'non-A non-B dissection' => '/\bnon\s*a\s*non\s*b\s*dissection\b/i',
            'type B dissection' => '/\btype\s*b\s*dissection\b/i',
            'thoracic aorta' => '/\b(descending\s+thoracic(?:\s+aorta)?|thoracic\s+aorta)\b/i',
            'aortic arch' => '/\baortic\s+arch\b/i',
            'left subclavian' => '/\bleft\s+subclavian\b/i',
            'zone 2' => '/\bzone\s*2\b/i',
            'carotid extension' => '/\bcarotid\s+(?:extension|dissection)\b/i',
            'carotid thrombus' => '/\bcarotid\s+thrombus\b/i',
            'ischemic stroke' => '/\b(?:ischaemic|ischemic)\s+stroke\b/i',
            'stroke' => '/\bstroke\b/i',
            'saphenous vein thrombosis' => '/\bsaphenous\s+vein\s+thrombosis\b/i',
            'junction distance' => '/\b(junction|sfj|spj)\b/i',
        ];

        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $query)) {
                $terms[] = $label;
            }
        }

        if (empty($terms)) {
            $segments = preg_split('/\s*,\s*/', strtolower($query)) ?: [];
            foreach ($segments as $segment) {
                $segment = trim($segment);
                if ($segment === '') {
                    continue;
                }
                $terms[] = $segment;
                if (count($terms) >= 5) {
                    break;
                }
            }
        }

        return array_slice(array_values(array_unique($terms)), 0, 6);
    }

    protected function appendUniqueQueryTerms(string $retrievalQuery, array $terms): string
    {
        $query = preg_replace('/\s+/', ' ', trim(strtolower($retrievalQuery))) ?: '';
        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }
            if (!preg_match('/\b' . preg_quote($term, '/') . '\b/i', $query)) {
                $query = trim($query . ' ' . strtolower($term));
            }
        }

        return $query;
    }

    protected function isComplexAaaContext(string $text): bool
    {
        return (bool) preg_match(
            '/\b(juxtarenal|pararenal|paravisceral|suprarenal|complex\s+aaa|fenestrated|branched|fevar|bevar|fbevar)\b/i',
            $text
        ) && (bool) preg_match(
            '/\b(aneurysm|aaa|abdominal\s+aortic)\b/i',
            $text
        );
    }

    protected function isThoracicDissectionContext(string $text): bool
    {
        return $this->hasDissectionContext($text)
            && (
                $this->isNonANonBDissectionContext($text)
                || preg_match('/\b(type\s*b|tbad|descending\s+thoracic|thoracic\s+aorta|tevar|distal\s+arch|zone\s*2)\b/i', $text)
            );
    }

    protected function isNonANonBDissectionContext(string $text): bool
    {
        if (!$this->hasDissectionContext($text)) {
            return false;
        }

        if (preg_match('/\bnon\s*[-\x{2010}-\x{2015}\x{2212}\x{00ad}\x{2011}]?\s*a\s*[,\-\/]?\s*non\s*[-\x{2010}-\x{2015}\x{2212}\x{00ad}\x{2011}]?\s*b\b/iu', $text)) {
            return true;
        }

        return (bool) preg_match(
            '/\b(zone\s*2|arch[- ]involving|distal\s+arch|just\s+above\s+the\s+left\s+subclavian|above\s+the\s+left\s+subclavian|distal\s+to\s+the\s+left\s+subclavian)\b/i',
            $text
        );
    }

    protected function hasArchLandingZoneContext(string $text): bool
    {
        return (bool) preg_match('/\b(aortic\s+arch|arch|left\s+subclavian|zone\s*[0-2])\b/i', $text);
    }

    protected function hasCarotidNeurovascularContext(string $text): bool
    {
        return (bool) preg_match('/\b(carotid|stroke|tia|neurologic|neurological|thrombus)\b/i', $text);
    }

    protected function hasDissectionContext(string $text): bool
    {
        return (bool) preg_match('/\bdissection\b/i', $text);
    }

    protected function isAaaWithCltiContext(string $text): bool
    {
        $hasAaa = (bool) preg_match('/\b(AAA|abdominal\s+aortic\s+aneurysm|infrarenal\s+aneurysm|EVAR)\b/i', $text);
        $hasClti = (bool) preg_match('/\b(CLTI|rest\s+pain|tissue\s+loss|gangrene|Rutherford\s+[456]|limb[- ]threatening|WIfI)\b/i', $text);
        return $hasAaa && $hasClti;
    }

    protected function isVeinBypassAntithromboticContext(string $text): bool
    {
        // Detects questions about antithrombotic therapy after surgical vein bypass.
        // Bypass antithrombotic recommendations (aspirin + rivaroxaban) live in the CLTI guideline,
        // not asymptomatic_pad. Fire when bypass is confirmed AND the question is about anticoagulation/
        // antiplatelet management post-procedure.
        $hasBypass = (bool) preg_match(
            '/\b(bypass|infrainguinal|femoropopliteal|femoroperoneal|femorotibial|'
            . 'vein\s+bypass|vein\s+graft|below.?knee\s+bypass|bk\s+bypass|'
            . 'above.?knee\s+bypass|open\s+revasculariz|surgical\s+revasculariz|'
            . 'open\s+repair\s+(?:of\s+)?(?:peripheral|limb|leg)|conduit)\b/i',
            $text
        );
        $hasAntithrombotic = (bool) preg_match(
            '/\b(antithrombotic|antiplatelet|anticoagul|aspirin|clopidogrel|rivaroxaban|DAPT|DOAC|warfarin)\b/i',
            $text
        );
        return $hasBypass && $hasAntithrombotic;
    }

    protected function isPostProcedureAntithromboticContext(string $text): bool
    {
        // Post-bypass or post-endovascular antithrombotic management
        $hasPostProcedure = (bool) preg_match(
            '/\b(post[- ]op|post[- ]operative|after\s+(bypass|revascularization|revascularisation|angioplasty|EVAR|stenting|endovascular)|bypass\s+surgery|infrainguinal\s+bypass|antithrombotic\s+after|antithrombotic\s+therapy\s+after)\b/i',
            $text
        );
        $hasAntithromboticQuestion = (bool) preg_match(
            '/\b(antithrombotic|antiplatelet|anticoagul|aspirin|clopidogrel|DAPT|rivaroxaban|DOAC|warfarin)\b/i',
            $text
        );
        return $hasPostProcedure || $hasAntithromboticQuestion;
    }

    protected function buildNonANonBDiagnosis(string $text): string
    {
        $diagnosis = 'Acute non-A non-B aortic dissection';

        if (preg_match('/\bleft\s+subclavian\b/i', $text)) {
            $diagnosis .= ' just above the left subclavian artery';
        }

        $details = [];
        if (preg_match('/\bcarotid\b/i', $text)) {
            $details[] = 'carotid extension';
        }
        if (preg_match('/\bthrombus\b/i', $text)) {
            $details[] = 'carotid thrombus';
        }
        if (preg_match('/\b(stroke|tia|ischaemic|ischemic)\b/i', $text)) {
            $details[] = 'ischemic stroke';
        }

        if (!empty($details)) {
            $diagnosis .= ' with ' . $this->implodeWithAnd($details);
        }

        return $diagnosis;
    }

    protected function prependGuideline(string $guideline, array $guidelines): array
    {
        $guidelines = array_values(array_filter($guidelines, fn($item): bool => $item !== $guideline));
        array_unshift($guidelines, $guideline);

        return $guidelines;
    }

    protected function insertGuideline(string $guideline, array $guidelines, ?string $after = null): array
    {
        $guidelines = array_values(array_filter($guidelines, fn($item): bool => $item !== $guideline));

        if ($after === null || !in_array($after, $guidelines, true)) {
            $guidelines[] = $guideline;
            return $guidelines;
        }

        $position = array_search($after, $guidelines, true);
        array_splice($guidelines, $position + 1, 0, [$guideline]);

        return $guidelines;
    }

    protected function implodeWithAnd(array $items): string
    {
        $items = array_values(array_filter(array_map('trim', $items), fn(string $item): bool => $item !== ''));
        $count = count($items);
        if ($count <= 1) {
            return $items[0] ?? '';
        }
        if ($count === 2) {
            return $items[0] . ' and ' . $items[1];
        }

        return implode(', ', array_slice($items, 0, -1)) . ', and ' . $items[$count - 1];
    }
}
