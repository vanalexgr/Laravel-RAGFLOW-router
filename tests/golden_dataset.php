<?php

return [
    // --- BATCH 1: THE ORIGINAL STRESS TEST 10 (Proven Hard Cases) ---
    [
        'query' => 'management of graft infection after TEVAR',
        'expected' => 'vascular_graft_infections',
        'desc' => 'Post-TEVAR Infection (Target: VGEI, Avoid: DTA)'
    ],
    [
        'query' => 'type B aortic dissection with visceral malperfusion',
        'expected' => 'descending_thoracic_aorta',
        'desc' => 'Type B + Complication (Target: DTA, Avoid: Arch)'
    ],
    [
        'query' => 'ruptured abdominal aortic aneurysm management',
        'expected' => 'abdominal_aortic_aneurysm',
        'desc' => 'Ruptured AAA (Target: AAA, Avoid: Trauma)'
    ],
    [
        'query' => 'motorcycle crash with acute limb ischaemia',
        'expected' => 'vascular_trauma',
        'desc' => 'Trauma + ALI (Target: Trauma, Avoid: ALI)'
    ],
    [
        'query' => 'acute limb ischaemia classification and treatment',
        'expected' => 'acute_limb_ischaemia',
        'desc' => 'ALI Standard (Target: ALI)'
    ],
    [
        'query' => 'CLTI vs intermittent claudication diagnosis',
        'expected' => 'clti',
        'desc' => 'CLTI vs Claudication (Target: CLTI)'
    ],
    [
        'query' => 'medical therapy for intermittent claudication',
        'expected' => 'asymptomatic_pad',
        'desc' => 'Claudication Therapy (Target: Asymptomatic PAD)'
    ],
    [
        'query' => 'antithrombotic therapy for carotid stenosis',
        'expected' => 'carotid_vertebral',
        'desc' => 'Carotid + Drugs (Target: Carotid)'
    ],
    [
        'query' => 'chronic venous disease and DVT management',
        'expected' => 'venous_thrombosis',
        'desc' => 'DVT + Chronic (Target: Venous Thrombosis)'
    ],
    [
        'query' => 'infected dialysis access fistula',
        'expected' => 'vascular_access',
        'desc' => 'Access Infection (Target: Access, Avoid: VGEI)'
    ],

    // --- BATCH 2: ACRONYM HEAVY (Phase 5 Verification) ---
    [
        'query' => 'management of sTBAD',
        'expected' => 'descending_thoracic_aorta',
        'desc' => 'sTBAD -> Symptomatic Type B (Target: DTA)'
    ],
    [
        'query' => 'EVLA for GSV reflux',
        'expected' => 'chronic_venous_disease',
        'desc' => 'EVLA/GSV -> Varicose Veins (Target: CVD)'
    ],
    [
        'query' => 'CAS vs CEA for symptomatic carotid stenosis',
        'expected' => 'carotid_vertebral',
        'desc' => 'CAS/CEA Acronyms (Target: Carotid)'
    ],
    [
        'query' => 'rAAA emergency repair guidelines',
        'expected' => 'abdominal_aortic_aneurysm',
        'desc' => 'rAAA -> Ruptured AAA (Target: AAA)'
    ],
    [
        'query' => 'DOAC therapy for VTE',
        'expected' => 'venous_thrombosis',
        'desc' => 'DOAC/VTE (Target: Venous Thrombosis)'
    ],
    [
        'query' => 'BEVAR for juxtarenal aneurysm',
        'expected' => 'abdominal_aortic_aneurysm',
        'desc' => 'BEVAR -> Branched EVAR (Target: AAA)'
    ],
    [
        'query' => 'FEVAR for pararenal AAA',
        'expected' => 'abdominal_aortic_aneurysm',
        'desc' => 'FEVAR -> Fenestrated EVAR (Target: AAA)'
    ],
    [
        'query' => 'medical management of uTBAD',
        'expected' => 'descending_thoracic_aorta',
        'desc' => 'uTBAD -> Uncomplicated Type B (Target: DTA)'
    ],
    [
        'query' => 'IVUS during EVAR',
        'expected' => 'abdominal_aortic_aneurysm',
        'desc' => 'IVUS/EVAR (Target: AAA)'
    ],
    [
        'query' => 'TAAA open repair complications',
        'expected' => 'descending_thoracic_aorta',
        'desc' => 'TAAA -> Thoracoabdominal (Target: DTA)'
    ],

    // --- BATCH 3: TRAUMA VS ELECTIVE BOUNDARIES ---
    [
        'query' => 'blunt thoracic aortic injury management',
        'expected' => 'vascular_trauma',
        'desc' => 'BTAI (Target: Trauma, Avoid: DTA)'
    ],
    [
        'query' => 'gunshot wound to the femoral artery',
        'expected' => 'vascular_trauma',
        'desc' => 'GSW Femoral (Target: Trauma, Avoid: PAD)'
    ],
    [
        'query' => 'traumatic carotid dissection',
        'expected' => 'vascular_trauma',
        'desc' => 'Traumatic Carotid (Target: Trauma, Avoid: Carotid)'
    ],
    [
        'query' => 'popliteal artery entrapment syndrome',
        'expected' => 'asymptomatic_pad',
        'desc' => 'Non-trauma pathology (Target: PAD, Avoid: Trauma)'
    ],
    [
        'query' => 'compartment syndrome after tibial fracture',
        'expected' => 'vascular_trauma',
        'desc' => 'Orthopaedic Trauma (Target: Trauma)'
    ],
    [
        'query' => 'iatrogenic femoral artery pseudoaneurysm',
        'expected' => 'vascular_trauma',
        'desc' => 'Iatrogenic Trauma (Target: Trauma)'
    ],

    // --- BATCH 4: INFECTION & COMPLEX CONCEPTS ---
    [
        'query' => 'aorto-enteric fistula diagnosis',
        'expected' => 'vascular_graft_infections',
        'desc' => 'AE Fistula (Target: VGEI)'
    ],
    [
        'query' => 'mycotic aortic aneurysm',
        'expected' => 'vascular_graft_infections',
        'desc' => 'Mycotic/Infected Aneurysm (Target: VGEI)'
    ],
    [
        'query' => 'central venous catheter infection',
        'expected' => 'vascular_access',
        'desc' => 'CVC Infection (Target: Access, Avoid: VGEI)'
    ],
    [
        'query' => 'prosthetic graft infection in the groin',
        'expected' => 'vascular_graft_infections',
        'desc' => 'Groin Graft Infection (Target: VGEI)'
    ],

    // --- BATCH 5: AORTIC ZONES & ARCH ---
    [
        'query' => 'aneurysm involving the subclavian artery',
        'expected' => 'aortic_arch',
        'desc' => 'Subclavian/Arch (Target: Arch)'
    ],
    [
        'query' => 'zone 2 TEVAR landing zone',
        'expected' => 'aortic_arch',
        'desc' => 'Zone 2 -> Arch involvement (Target: Arch)'
    ],
    [
        'query' => 'frozen elephant trunk procedure',
        'expected' => 'aortic_arch',
        'desc' => 'FET Procedure (Target: Arch)'
    ],
    [
        'query' => 'type A aortic dissection',
        'expected' => 'aortic_arch', // Actually, usually Type A is Arch/Ascending.
        'desc' => 'Type A (Target: Arch)'
    ],
    [
        'query' => 'retrograde type A dissection',
        'expected' => 'aortic_arch',
        'desc' => 'Retrograde Type A (Target: Arch)'
    ],

    // --- BATCH 6: MESENTERIC & RENAL ---
    [
        'query' => 'chronic mesenteric ischaemia symptoms',
        'expected' => 'mesenteric_renal',
        'desc' => 'CMI (Target: Mesenteric)'
    ],
    [
        'query' => 'renal artery stenosis hypertension',
        'expected' => 'mesenteric_renal',
        'desc' => 'RAS (Target: Mesenteric)'
    ],
    [
        'query' => 'acute mesenteric ischaemia diagnosis',
        'expected' => 'mesenteric_renal',
        'desc' => 'AMI (Target: Mesenteric)'
    ],

    // --- BATCH 7: VENOUS & LYMPHATIC ---
    [
        'query' => 'superficial vein thrombosis treatment',
        'expected' => 'venous_thrombosis',
        'desc' => 'SVT (Target: Venous Thrombosis)'
    ],
    [
        'query' => 'compression therapy for venous ulcers',
        'expected' => 'chronic_venous_disease',
        'desc' => 'Venous Ulcers (Target: CVD)'
    ],
    [
        'query' => 'may-thurner syndrome management',
        'expected' => 'chronic_venous_disease', // Or thrombosis? Usually CVD/DVT overlap.
        'desc' => 'May-Thurner (Target: CVD/Thrombosis)'
    ],
    [
        'query' => 'pelvic congestion syndrome',
        'expected' => 'chronic_venous_disease',
        'desc' => 'Pelvic Venous (Target: CVD)'
    ],
    // --- BATCH 8: DOMAIN SPECIFIC 2024-2025 (Mass Precision Tuning) ---
    [
        'query' => '72M with infrarenal AAA 5.6 cm and common iliac aneurysm — should we proceed with EVAR vs OSR, and what’s the diameter threshold for repair?',
        'expected' => 'abdominal_aortic_aneurysm',
        'desc' => 'AAA 2024 Size/Threshold'
    ],
    [
        'query' => 'Persistent venous leg ulcer (CEAP C6) despite multilayer compression — when should we consider superficial reflux ablation (EVLA/RFA/UGFS)?',
        'expected' => 'chronic_venous_disease',
        'desc' => 'CVD 2022 Ulcer/Reflux'
    ],
    [
        'query' => '63F had TIA yesterday with symptomatic carotid stenosis 70% (NASCET) — recommended timing of CEA vs CAS?',
        'expected' => 'carotid_vertebral',
        'desc' => 'Carotid 2023 Timing'
    ],
    [
        'query' => 'ABI 0.72 with classic intermittent claudication — what’s first-line (SET, smoking cessation, statin), and when is revascularisation appropriate?',
        'expected' => 'asymptomatic_pad',
        'desc' => 'PAD 2024 Claudication'
    ],
    [
        'query' => 'Post femoropopliteal endovascular intervention with DCB — what’s the recommended antithrombotic regimen (SAPT vs DAPT vs dual pathway inhibition) and for how long?',
        'expected' => 'antithrombotic_therapy',
        'desc' => 'Antithrombotic 2023 Regimen'
    ],
    [
        'query' => 'Sudden onset leg pain 6 hours, Rutherford IIb ALI, no pulses — should we start immediate heparin, and choose CDT vs surgical thrombectomy?',
        'expected' => 'acute_limb_ischaemia',
        'desc' => 'ALI 2020 Heparin/CDT'
    ],
    [
        'query' => 'Acute type B dissection (TBAD) with malperfusion and persistent pain — indications for TEVAR vs medical therapy?',
        'expected' => 'descending_thoracic_aorta',
        'desc' => 'Thoracic 2017 TBAD'
    ],
    [
        'query' => 'Diabetic foot tissue loss + infection, toe pressure low, WIfI suggests severe limb threat — how do WIfI + GLASS + PLAN/EBR guide endovascular vs bypass?',
        'expected' => 'clti',
        'desc' => 'CLTI 2019 WIfI/GLASS'
    ],
    [
        'query' => 'Postprandial pain + weight loss, CTA shows SMA + coeliac stenosis — workup and role of mesenteric stenting vs open bypass for chronic mesenteric ischaemia (CMI)?',
        'expected' => 'mesenteric_renal',
        'desc' => 'Mesenteric 2025 CMI'
    ],
    [
        'query' => 'Post-EVAR fever, CTA shows perigraft gas/fluid — how do MAGIC criteria and FDG PET/CT or WBC SPECT/CT confirm endograft infection, and what’s the surgical strategy (EAR vs ISR)?',
        'expected' => 'vascular_graft_infections',
        'desc' => 'VGEI 2020 MAGIC/Strategy'
    ],

    // --- BATCH 9: COMBINATIONS & MULTI-GUIDELINE ---
    [
        'query' => 'Elective EVAR scheduled for AAA 5.8 cm — patient on rivaroxaban for AF. How should we manage peri-procedural antithrombotics, and what’s the post-EVAR antithrombotic plan?',
        'expected' => 'abdominal_aortic_aneurysm',
        'desc' => 'AAA + Antithrombotic (Target: AAA)'
    ],
    [
        'query' => 'Two years after EVAR: fever + back pain, CTA shows perigraft gas and sac inflammation. Workup for endograft infection and definitive management options?',
        'expected' => 'vascular_graft_infections',
        'desc' => 'AAA + VGEI (Target: VGEI)'
    ],
    [
        'query' => 'Unstable blunt trauma with retroperitoneal hematoma and suspected ruptured AAA vs iliac arterial injury — immediate hemorrhage control strategy and repair approach?',
        'expected' => 'vascular_trauma',
        'desc' => 'AAA + Trauma (Target: Trauma)'
    ],
    [
        'query' => 'Patient with TIA and carotid stenosis 60–70%, already on DAPT after coronary stent. Best CEA/CAS strategy and peri-procedural antiplatelet management?',
        'expected' => 'carotid_vertebral',
        'desc' => 'Carotid + Antithrombotic (Target: Carotid)'
    ],
    [
        'query' => 'Stroke patient needs urgent carotid plan but also has acute proximal DVT on ultrasound. How to balance anticoagulation needs with CEA timing?',
        'expected' => 'carotid_vertebral',
        'desc' => 'Carotid + Venous Thrombosis (Target: Carotid)'
    ],
    [
        'query' => 'Asymptomatic PAD (ABI 0.65) with diabetes and high CV risk: optimal antithrombotic strategy (SAPT vs DPI) alongside risk-factor therapy?',
        'expected' => 'asymptomatic_pad',
        'desc' => 'PAD + Antithrombotic (Target: PAD)'
    ],
    [
        'query' => 'Dialysis patient with new claudication and low ABI — how to manage PAD medically/exercise-wise without jeopardizing AVF flow or cannulation strategy?',
        'expected' => 'asymptomatic_pad', // Hard: PAD vs Access. Usually PAD management is the focus.
        'desc' => 'PAD + Access (Target: PAD)'
    ],
    [
        'query' => 'Penetrating femoral injury: bleeding controlled with tourniquet, now pulseless limb and evolving Rutherford IIb — role of temporary shunt vs thrombectomy vs endovascular?',
        'expected' => 'vascular_trauma',
        'desc' => 'ALI + Trauma (Target: Trauma)'
    ],
    [
        'query' => 'After CDT for ALI, what’s recommended post-revascularisation antithrombotic regimen and duration?',
        'expected' => 'acute_limb_ischaemia',
        'desc' => 'ALI + Antithrombotic (Target: ALI)'
    ],
    [
        'query' => 'Post-EVAR patient develops sudden limb pain, imaging suggests limb graft occlusion. ALI workup/treatment plus EVAR limb-occlusion considerations?',
        'expected' => 'acute_limb_ischaemia',
        'desc' => 'ALI + AAA (Target: ALI)'
    ],
    [
        'query' => 'Dissection involves distal arch and proximal descending aorta — how do you decide between arch repair (zone 0/1/2) vs TEVAR strategy?',
        'expected' => 'aortic_arch',
        'desc' => 'DTA + Arch (Target: Arch/Zone logic)'
    ],
    [
        'query' => 'High-speed MVC with suspected BTAI on CTA — immediate BP/HR control and indications for TEVAR, plus follow-up imaging?',
        'expected' => 'vascular_trauma',
        'desc' => 'DTA + Trauma (Target: Trauma)'
    ],
    [
        'query' => 'Penetrating thoracic outlet trauma with suspected innominate/subclavian injury— imaging pathway and open vs endovascular options?',
        'expected' => 'vascular_trauma',
        'desc' => 'Arch + Trauma (Target: Trauma)'
    ],
    [
        'query' => 'Mesenteric stenting performed for CMI— what’s the recommended DAPT duration and longer-term antithrombotic plan?',
        'expected' => 'mesenteric_renal',
        'desc' => 'Mesenteric + Antithrombotic (Target: Mesenteric)'
    ],
    [
        'query' => 'Acute abdominal pain; CT suggests SMV/portal vein thrombosis — anticoagulation approach, duration, and when to consider thrombectomy/TIPS?',
        'expected' => 'mesenteric_renal',
        'desc' => 'Mesenteric + Venous Thrombosis (Target: Mesenteric)'
    ],
    [
        'query' => 'Penetrating abdominal trauma with suspected SMA injury — operative priorities and when endovascular embolisation/stent graft is acceptable?',
        'expected' => 'vascular_trauma',
        'desc' => 'Mesenteric + Trauma (Target: Trauma)'
    ],
    [
        'query' => 'Patient transitioned from claudication to rest pain + toe ulcer — how should the router shift from PAD/claudication to CLTI, and which staging frameworks apply?',
        'expected' => 'clti',
        'desc' => 'CLTI + PAD (Target: CLTI)'
    ],
    [
        'query' => 'Dialysis patient with infected toe ulcer and low toe pressure — limb salvage plan using WIfI/GLASS plus how to protect existing AV access during interventions?',
        'expected' => 'clti',
        'desc' => 'CLTI + Access (Target: CLTI)'
    ],
    [
        'query' => 'After tibial endovascular revascularisation for CLTI, what’s optimal antithrombotic regimen (SAPT/DAPT/DPI) considering bleeding risk?',
        'expected' => 'clti',
        'desc' => 'CLTI + Antithrombotic (Target: CLTI)'
    ],
    [
        'query' => 'Large chronic leg ulcer with edema; mixed picture — how do you distinguish venous ulcer vs ischaemic/CLTI tissue loss, and what tests guide routing?',
        'expected' => 'clti', // Hard boundary: CVD vs CLTI. 
        'desc' => 'CLTI + Venous Disease (Target: CLTI)'
    ],
    [
        'query' => 'Patient with chronic swelling and skin changes (CEAP C4) and history of DVT— evaluation for post-thrombotic syndrome and role of compression vs venous stenting?',
        'expected' => 'chronic_venous_disease',
        'desc' => 'Venous Disease + Venous Thrombosis (Target: CVD)'
    ],
    [
        'query' => 'After endovenous ablation for varicose veins, who needs thromboprophylaxis and what regimen?',
        'expected' => 'chronic_venous_disease',
        'desc' => 'Venous Disease + Antithrombotic (Target: CVD)'
    ],
    [
        'query' => 'Provoked proximal DVT treated 3 months — decide on extended anticoagulation vs stop; how do bleeding risk tools influence choice of DOAC dose?',
        'expected' => 'venous_thrombosis',
        'desc' => 'Venous Thrombosis + Antithrombotic (Target: Venous Thrombosis)'
    ],
    [
        'query' => 'Gunshot injury repaired with synthetic interposition graft; now fever and groin drainage — suspicion of prosthetic graft infection: imaging and surgical strategy?',
        'expected' => 'vascular_trauma', // Trauma + VGEI. 
        'desc' => 'VGEI + Trauma (Target: Trauma/VGEI)'
    ],
    [
        'query' => 'Dialysis patient with infected AVG and sepsis — when attempt salvage vs excision, and antimicrobial strategy?',
        'expected' => 'vascular_access',
        'desc' => 'VGEI + Access (Target: Access)'
    ],
    [
        'query' => 'New AVF created; surgeon asks about peri-op heparin/antiplatelets to improve maturation — what does guidance say, and how to balance bleeding?',
        'expected' => 'vascular_access',
        'desc' => 'Access + Antithrombotic (Target: Access)'
    ],
    [
        'query' => 'Patient with UEDVT around a dialysis catheter — manage anticoagulation and decide whether/when to remove catheter?',
        'expected' => 'vascular_access',
        'desc' => 'Access + Venous Thrombosis (Target: Access)'
    ],
    [
        'query' => 'Planned zone 2 TEVAR requiring LSA coverage; patient also has vertebrobasilar symptoms— how does vertebral/LSA circulation affect planning?',
        'expected' => 'aortic_arch',
        'desc' => 'Carotid + Arch (Target: Arch)'
    ],
    [
        'query' => 'Patient has arch aneurysm and infrarenal AAA — which pathology drives staging/sequence, and what terms should trigger arch vs AAA routing?',
        'expected' => 'aortic_arch',
        'desc' => 'AAA + Arch (Target: Arch)'
    ],
    [
        'query' => 'Type B dissection with abdominal pain and rising lactate— concern for mesenteric malperfusion. How do you route: dissection management plus mesenteric ischemia workup?',
        'expected' => 'descending_thoracic_aorta',
        'desc' => 'Thoracic + Mesenteric (Target: DTA)'
    ],
];
