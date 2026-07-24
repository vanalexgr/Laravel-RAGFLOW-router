<?php

namespace App\Ai\Gate\Routing;

class OrientRoutingPriorService
{
    /**
     * Canonical 14-guideline reference migrated from the adapter tool contract.
     *
     * @var array<string, string>
     */
    public const GUIDELINE_REFERENCE = [
        'aortic_arch' => 'Arch aneurysm, Zone 0-2, FET, hybrid arch repair; not the default for dissection management.',
        'descending_thoracic_aorta' => 'Type B/non-A non-B dissection, TEVAR, descending thoracic aneurysm and mural thrombus.',
        'abdominal_aortic_aneurysm' => 'AAA, EVAR, rupture, endoleaks and iliac aneurysm.',
        'mesenteric_renal' => 'Mesenteric ischaemia and renal artery disease.',
        'asymptomatic_pad' => 'Claudication, PAD screening and exercise therapy.',
        'clti' => 'Rest pain, tissue loss, gangrene, limb salvage, bypass and amputation decisions.',
        'acute_limb_ischaemia' => 'Acute limb ischaemia, sudden limb pain, embolism and acute thrombosis.',
        'carotid_vertebral' => 'Stroke, TIA, carotid stenosis, CEA, CAS and vertebral disease.',
        'venous_thrombosis' => 'DVT, PE, VTE and venous anticoagulation.',
        'chronic_venous_disease' => 'Varicose veins, venous reflux, ulcers and CEAP.',
        'antithrombotic_therapy' => 'Explicit antiplatelet, anticoagulation, bleeding-risk and perioperative medication decisions.',
        'vascular_trauma' => 'Penetrating, blunt and iatrogenic vascular injury.',
        'vascular_graft_infections' => 'Graft/endograft infection and aorto-enteric or aorto-oesophageal fistula.',
        'vascular_access' => 'Dialysis AVF/AVG, access stenosis, thrombosis, infection and steal.',
    ];

    /**
     * Deterministic pre-signals constrain Orient; they never replace its general reasoning.
     *
     * @return array<string, bool>
     */
    public function turnSignals(string $turn): array
    {
        $turn = trim($turn);

        return [
            'explicit_new_case' => preg_match('/\b(new|different|another)\s+(?:patient|case)\b/i', $turn) === 1,
            'answer_only' => $turn !== ''
                && ! str_contains($turn, '?')
                && mb_strlen($turn) <= 120
                && preg_match('/^(?:yes|no|unknown|not sure|symptomatic|asymptomatic|\d+(?:\.\d+)?\s*(?:%|mm|cm)?|[a-z0-9 .,;:\/()=%-]+)$/iu', $turn) === 1,
            'vague_management_followup' => preg_match('/^(?:so,?\s*)?(?:what (?:now|next)|what should (?:i|we) do|what is the plan|which option|how should (?:i|we) proceed)\??$/iu', $turn) === 1,
            'raw_knowledge' => preg_match('/\b(?:define|definition|criteria|classification|what is the threshold|which guidelines cover)\b/iu', $turn) === 1,
            'specific_patient' => preg_match('/\b(?:a|the|my|this)\s+patient\b|\bpatient\s+(?:has|with|who)\b|\b\d{1,3}[- ]?(?:year[- ]old|yo)\b/iu', $turn) === 1,
        ];
    }

    /**
     * Apply deterministic anatomy/acuity priors and return at most two candidates.
     *
     * The LLM may tighten/reorder these candidates, but the PHP orchestration owns
     * the antithrombotic prune and disabling-stroke signal so they cannot diverge.
     *
     * @param  array<int, string>  $modelCandidates
     * @return array<int, string>
     */
    public function candidates(string $serializedPatientModel, array $modelCandidates = []): array
    {
        $text = mb_strtolower($serializedPatientModel);
        $ranked = [];

        $add = static function (array &$items, string $key): void {
            if (! in_array($key, $items, true)) {
                $items[] = $key;
            }
        };

        // Cross-domain/safety overrides are ordered before generic anatomy.
        if ($this->matches($text, '/\b(gunshot|stab|motorcycle|mvc|blunt|penetrating|traumatic|trauma|iatrogenic|fracture)\b/u')) {
            $add($ranked, 'vascular_trauma');
        }
        if ($this->matches($text, '/\b(graft|endograft|prosthetic)\b.*\b(infect\w*|sepsis|fistula)\b|\baorto[- ]?(?:enteric|oesophageal|esophageal)\s+fistula\b/u')) {
            $add($ranked, 'vascular_graft_infections');
        }
        if ($this->matches($text, '/\b(dialysis|haemodialysis|hemodialysis|avf|avg|arteriovenous fistula|vascular access)\b/u')) {
            $add($ranked, 'vascular_access');
        }

        if ($this->matches($text, '/\b(type b|tbad|non[- ]?a non[- ]?b|descending thoracic|thoracoabdominal|taaa|tevar|thoracic aortic)\b/u')) {
            $add($ranked, 'descending_thoracic_aorta');
        }
        if ($this->matches($text, '/\b(aortic arch|arch aneurysm|zone [0-2]|frozen elephant|supra-aortic|debranching|innominate|subclavian)\b/u')) {
            $add($ranked, 'aortic_arch');
        }
        if ($this->matches($text, '/\b(aaa|abdominal aortic|infrarenal|juxtarenal|pararenal|evar|fevar|bevar|endoleak|iliac aneurysm)\b/u')) {
            $add($ranked, 'abdominal_aortic_aneurysm');
        }
        if ($this->matches($text, '/\b(mesenteric|sma|coeliac|celiac|renal artery|visceral artery)\b/u')) {
            $add($ranked, 'mesenteric_renal');
        }
        if ($this->matches($text, '/\b(clti|chronic limb.threat|rest pain|tissue loss|gangrene|wifi|limb salvage|primary amputation)\b/u')) {
            $add($ranked, 'clti');
        }
        if ($this->matches($text, '/\b(acute limb|rutherford ii|sudden (?:leg|limb) pain|6ps|six ps|acute arterial occlusion)\b/u')) {
            $add($ranked, 'acute_limb_ischaemia');
        }
        if ($this->matches($text, '/\b(claudication|asymptomatic pad|peripheral arterial disease|abi)\b/u')) {
            $add($ranked, 'asymptomatic_pad');
        }
        if ($this->matches($text, '/\b(carotid|vertebral|tia|stroke|cea|cas|tcar|endarterectomy)\b/u')) {
            $add($ranked, 'carotid_vertebral');
        }
        if ($this->matches($text, '/\b(dvt|deep vein|pulmonary embol|vte|venous thrombosis|phlegmasia)\b/u')) {
            $add($ranked, 'venous_thrombosis');
        }
        if ($this->matches($text, '/\b(?:brachial|axillary|subclavian|femoral|iliac|popliteal|calf)\s+vein\b/u')) {
            $add($ranked, 'venous_thrombosis');
        }
        if ($this->matches($text, '/\b(varicose|venous reflux|venous ulcer|ceap|gsv|saphenous|evla|rfa|ugfs|pelvic congestion)\b/u')) {
            $add($ranked, 'chronic_venous_disease');
        }

        foreach ($modelCandidates as $candidate) {
            if (isset(self::GUIDELINE_REFERENCE[$candidate])) {
                $add($ranked, $candidate);
            }
        }

        // CLTI is the advanced PAD pathway; do not spend the second slot on the
        // claudication/PAD reference when limb threat is already established.
        if (in_array('clti', $ranked, true)) {
            $ranked = array_values(array_diff($ranked, ['asymptomatic_pad']));
        }

        // Concern P: one owner for the disabling-stroke boost.
        if (
            $this->matches($text, '/\b(carotid|cea|cas|tcar|endarterectomy)\b/u')
            && $this->matches($text, '/\b(major|disabling|severe)\s+(?:ischaemic\s+|ischemic\s+)?stroke|\bmrs\b|modified rankin|large infarct|dense neurological deficit/u')
        ) {
            $ranked = array_values(array_diff($ranked, ['carotid_vertebral']));
            array_unshift($ranked, 'carotid_vertebral');
        }

        // Concern P: add antithrombotic only for an explicit decision, then cap at two.
        $explicitAntithromboticDecision = $this->matches(
            $text,
            '/\b(antithrombotic|antiplatelet|anticoagulation|perioperative|periprocedural|post[- ]?op(?:erative)? medication|bridg|dapt|sapt|triple therapy|bleeding risk)\b/u'
        ) || (
            $this->matches($text, '/\b(itp|antiphospholipid|aps|warfarin|sintrom|acenocoumarol|doac|apixaban|rivaroxaban)\b/u')
            && $this->matches($text, '/\b(surgery|bypass|revasculari[sz]ation|post[- ]?op|perioperative)\b/u')
        );
        $ranked = array_values(array_diff($ranked, ['antithrombotic_therapy']));
        if ($explicitAntithromboticDecision) {
            $add($ranked, 'antithrombotic_therapy');
        }

        return array_slice($ranked, 0, 2);
    }

    private function matches(string $text, string $pattern): bool
    {
        return preg_match($pattern, $text) === 1;
    }
}
